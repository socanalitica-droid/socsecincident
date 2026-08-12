<?php

use Glpi\Application\View\TemplateRenderer;

// Mirrors the pattern used by the "escalade" plugin's PluginEscaladeTicket
// class: a plain class (not a CommonDBTM) registered via the core
// 'timeline_answer_actions' hook, with a static addToTimeline() that builds
// the split-dropdown entry, and an instance showForm() that the core timeline
// twig calls directly (templates/components/itilobject/answer.html.twig falls
// back to `timeline_itemtype.item.showForm(-1, {'parent': item})` whenever no
// 'template' key is set in the returned array — same as escalade).
class PluginSocsecincidentTicketAction {

    public static function addToTimeline($options) {
        if (!($options['item'] instanceof Ticket)) {
            return [];
        }

        $ticket = $options['item'];

        if (!$ticket->canUpdateItem()) {
            return [];
        }

        $profiles_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (!PluginSocsecincidentConfig::isProfileEnabled($profiles_id)) {
            return [];
        }

        // Once the incident reaches the last stage (Cerrado) the ticket is
        // already closed by us — nothing left to advance to.
        $current_state = PluginSocsecincidentConfig::getIncidentState($ticket->getID());
        if ($current_state === 'Cerrado') {
            return [];
        }

        // GLPI's own footer.html.twig builds the dropdown-item's CSS class as
        // "action-{{ array key }}" (not from the 'class' field below, which
        // is only used for the collapsible block's HTML id) — so this key
        // must be hyphenated to match the .action-security-incident rule in
        // our CSS, not underscored.
        return [
            'security-incident' => [
                'type'        => 'PluginSocsecincidentTicketAction',
                'class'       => 'action-security-incident',
                'icon'        => 'ti ti-shield-exclamation',
                'label'       => __('Incidente de Seguridad', 'socsecincident'),
                'short_label' => __('Incidente de Seguridad', 'socsecincident'),
                'item'        => new self(),
            ],
        ];
    }

    public function showForm($ID, $options = []) {
        /** @var Ticket $ticket */
        $ticket = $options['parent'];

        $current_state = PluginSocsecincidentConfig::getIncidentState($ticket->getID());
        $rand          = mt_rand();
        $action_url    = Plugin::getWebDir('socsecincident') . '/front/ticketaction.form.php';

        // Not declared yet: show the original "declare incident" modal
        // (comment + followup template + Materializado/No materializado/
        // Pendiente Veredicto classification).
        if ($current_state === null) {
            TemplateRenderer::getInstance()->display('@socsecincident/ticketaction_form.html.twig', [
                'action' => $action_url,
                'ticket' => $ticket,
                'rand'   => $rand,
            ]);
            return;
        }

        // Already declared: show the "advance stage" modal instead, offering
        // only the stages still ahead of the current one.
        TemplateRenderer::getInstance()->display('@socsecincident/ticketaction_progress_form.html.twig', [
            'action'        => $action_url,
            'ticket'        => $ticket,
            'rand'          => $rand,
            'current_state' => $current_state,
            'next_stages'   => PluginSocsecincidentConfig::getNextStages($current_state),
        ]);
    }
}
