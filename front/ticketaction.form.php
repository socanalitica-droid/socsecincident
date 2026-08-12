<?php

use Glpi\Exception\Http\AccessDeniedHttpException;

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once Plugin::getPhpDir('socsecincident') . '/inc/config.class.php';

global $DB;

$tickets_id = (int) ($_POST['tickets_id'] ?? 0);

$ticket = new Ticket();
if (!$ticket->getFromDB($tickets_id)) {
    throw new AccessDeniedHttpException();
}

// Same right checks as PluginSocsecincidentTicketAction::addToTimeline().
$profiles_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
if (!$ticket->canUpdateItem() || !PluginSocsecincidentConfig::isProfileEnabled($profiles_id)) {
    throw new AccessDeniedHttpException();
}

$entities_id = (int) $ticket->fields['entities_id'];

// ── Advance an already-declared incident to its next lifecycle stage ───────

if (isset($_POST['confirm_security_incident_progress'])) {
    $current_state = PluginSocsecincidentConfig::getIncidentState($tickets_id);
    $next_stages   = PluginSocsecincidentConfig::getNextStages($current_state);
    $new_state     = $_POST['new_state'] ?? '';

    // Re-derive the allowed set server-side rather than trusting the client —
    // the form only ever renders stages ahead of the current one.
    if (!in_array($new_state, $next_stages, true)) {
        Session::addMessageAfterRedirect(
            __('Estado inválido o el incidente ya no puede avanzar.', 'socsecincident'),
            true,
            ERROR
        );
        Html::back();
        exit();
    }

    $comment = trim($_POST['comment'] ?? '');
    $content = '<strong>' . __('Estado del incidente actualizado a', 'socsecincident') . ':</strong> ' . $new_state;
    if ($comment !== '') {
        $content = $comment . '<br><br>' . $content;
    }

    $followup = new ITILFollowup();
    $followup->add([
        'itemtype'   => 'Ticket',
        'items_id'   => $tickets_id,
        'content'    => $content,
        'is_private' => 0,
    ]);

    PluginSocsecincidentConfig::setIncidentState($tickets_id, $entities_id, $new_state);

    if ($new_state === 'Cerrado') {
        // Direct write, same proven approach used for bulk-closing tickets on
        // this instance: Ticket::update() enforces GLPI's mandatory-fields-
        // before-close policy (e.g. technician assignment), which would
        // silently reject the close here. Bypassing it via a raw update is
        // deliberate — this is the plugin's own controlled close, not a
        // user-facing close action.
        $now = date('Y-m-d H:i:s');
        $close_fields = ['status' => Ticket::CLOSED, 'closedate' => $now];
        if (empty($ticket->fields['solvedate'])) {
            $close_fields['solvedate'] = $now;
        }
        $DB->update('glpi_tickets', $close_fields, ['id' => $tickets_id]);
    }

    Session::addMessageAfterRedirect(
        sprintf(__('Incidente actualizado a: %s', 'socsecincident'), $new_state),
        true,
        INFO
    );

    Html::back();
    exit();
}

// ── First-time declaration ──────────────────────────────────────────────────

if (!isset($_POST['confirm_security_incident'])) {
    Html::back();
    exit();
}

$comment        = trim($_POST['comment'] ?? '');
$classification = $_POST['classification'] ?? '';

$classification_labels = [
    'materialized'     => __('Materializado', 'socsecincident'),
    'not_materialized' => __('No materializado', 'socsecincident'),
    'pending_verdict'  => __('Pendiente Veredicto', 'socsecincident'),
];

if ($comment === '' || !isset($classification_labels[$classification])) {
    Session::addMessageAfterRedirect(
        __('Debes diligenciar el comentario y la clasificación.', 'socsecincident'),
        true,
        ERROR
    );
    Html::back();
    exit();
}

$target_category_id = PluginSocsecincidentConfig::getTargetCategoryId();
if ($target_category_id === 0) {
    Session::addMessageAfterRedirect(
        sprintf(
            __('No se encontró la categoría ITIL "%s". Revisa la configuración del plugin.', 'socsecincident'),
            PluginSocsecincidentConfig::TARGET_CATEGORY_NAME
        ),
        true,
        ERROR
    );
    Html::back();
    exit();
}

// Followup content = the analyst's comment plus the classification, so both
// stay visible in the ticket's own transcript, not just in our audit table.
$content = $comment . '<br><br><strong>' . __('Clasificación', 'socsecincident') . ':</strong> '
    . $classification_labels[$classification];

$followup = new ITILFollowup();
$followup->add([
    'itemtype'   => 'Ticket',
    'items_id'   => $tickets_id,
    'content'    => $content,
    'is_private' => 0,
]);

// Determine which SLA this status+category transition would get, via a
// standalone rule-engine call — reliable regardless of who's logged in.
// (Confirmed on this instance: Ticket::update() silently drops manually
// supplied SLA fields from prepareInputForUpdate() for non-admin sessions
// once another update() already ran earlier in the request — which is
// exactly what just happened via ITILFollowup::add() above. Same landmine
// documented in socautoassign; same workaround: compute via the rule engine
// and write straight to glpi_tickets.)
$rule_input = [
    'id'                => $tickets_id,
    'status'            => Ticket::ASSIGNED,
    'priority'          => $ticket->fields['priority'],
    'itilcategories_id' => $target_category_id,
    'entities_id'       => $entities_id,
];
$rule_output = (new RuleTicketCollection($entities_id))->processAllRules(
    $rule_input,
    $rule_input,
    ['recursive' => true, 'entities_id' => $entities_id],
    ['condition' => RuleCommonITILObject::ONUPDATE, 'only_criteria' => ['status']]
);

$ticket->update([
    'id'                => $tickets_id,
    'status'            => Ticket::ASSIGNED,
    'priority'          => $ticket->fields['priority'],
    'itilcategories_id' => $target_category_id,
]);

$direct_write = [];
foreach ([SLM::TTR => 'slas_id_ttr', SLM::TTO => 'slas_id_tto'] as $type => $field) {
    $slas_id = (int) ($rule_output[$field] ?? 0);
    if ($slas_id <= 0 || $slas_id === (int) $ticket->fields[$field]) {
        continue;
    }
    $direct_write[$field] = $slas_id;
    foreach ($ticket->getDatasToAddSLA($slas_id, $entities_id, $ticket->fields['date'], $type) as $k => $v) {
        $direct_write[$k] = $v;
    }
}
if (!empty($direct_write)) {
    $DB->update('glpi_tickets', $direct_write, ['id' => $tickets_id]);
    foreach ($direct_write as $k => $v) {
        $ticket->fields[$k] = $v;
    }
    foreach ([SLM::TTR => 'slas_id_ttr', SLM::TTO => 'slas_id_tto'] as $type => $field) {
        if (isset($direct_write[$field])) {
            $ticket->manageSlaLevel($direct_write[$field]);
        }
    }
}

$DB->insert('glpi_plugin_socsecincident_incidents', [
    'tickets_id'     => $tickets_id,
    'users_id'       => Session::getLoginUserID(),
    'classification' => $classification,
]);

// First stage of the lifecycle — matches PluginSocsecincidentConfig::STAGES[0].
PluginSocsecincidentConfig::setIncidentState($tickets_id, $entities_id, 'Investigación');

Session::addMessageAfterRedirect(
    __('Ticket marcado como Incidente de Seguridad.', 'socsecincident'),
    true,
    INFO
);

Html::back();
