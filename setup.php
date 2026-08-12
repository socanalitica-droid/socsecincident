<?php

define('PLUGIN_SOCSECINCIDENT_VERSION', '1.0.0');
define('PLUGIN_SOCSECINCIDENT_MIN_GLPI', '11.0');
define('PLUGIN_SOCSECINCIDENT_MAX_GLPI', '12.0');

function plugin_init_socsecincident() {
    global $PLUGIN_HOOKS;

    include_once Plugin::getPhpDir('socsecincident') . '/inc/config.class.php';
    include_once Plugin::getPhpDir('socsecincident') . '/inc/ticketaction.class.php';

    $PLUGIN_HOOKS['csrf_compliant']['socsecincident'] = true;
    $PLUGIN_HOOKS['config_page']['socsecincident']    = 'front/config.form.php';
    $PLUGIN_HOOKS['add_css']['socsecincident'][]      = 'css/socsecincident.css';

    if (isset($_SESSION['glpiactiveentities'])) {
        // Admin menu under Setup (same mechanism as socfields/socautoassign)
        $PLUGIN_HOOKS['menu_toadd']['socsecincident'] = ['config' => 'PluginSocsecincidentConfig'];
    }

    // Adds "Incidente de Seguridad" to the timeline answer-actions split dropdown
    // (same official hook the "escalade" plugin uses to add its "Escalate" entry;
    // plugins are iterated in load order and appended in sequence, so this entry
    // renders after Escalate's as long as this plugin loads after it).
    $PLUGIN_HOOKS['timeline_answer_actions']['socsecincident'] = [
        'PluginSocsecincidentTicketAction', 'addToTimeline',
    ];
}

function plugin_version_socsecincident() {
    return [
        'name'         => 'SOC Security Incident',
        'version'      => PLUGIN_SOCSECINCIDENT_VERSION,
        'author'       => 'SOC Team - Linktic',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SOCSECINCIDENT_MIN_GLPI,
                'max' => PLUGIN_SOCSECINCIDENT_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_socsecincident_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_SOCSECINCIDENT_MIN_GLPI, 'lt')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_SOCSECINCIDENT_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_socsecincident_check_config() {
    return true;
}
