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

        return [
            'security_incident' => [
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

        TemplateRenderer::getInstance()->display('@socsecincident/ticketaction_form.html.twig', [
            'action' => Plugin::getWebDir('socsecincident') . '/front/ticketaction.form.php',
            'ticket' => $ticket,
            'rand'   => mt_rand(),
        ]);
    }
}
