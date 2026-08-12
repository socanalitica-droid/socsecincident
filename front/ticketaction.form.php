<?php

use Glpi\Exception\Http\AccessDeniedHttpException;

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once Plugin::getPhpDir('socsecincident') . '/inc/config.class.php';

if (!isset($_POST['confirm_security_incident'])) {
    Html::back();
    exit();
}

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

$entities_id = (int) $ticket->fields['entities_id'];

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

global $DB;
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

Session::addMessageAfterRedirect(
    __('Ticket marcado como Incidente de Seguridad.', 'socsecincident'),
    true,
    INFO
);

Html::back();
