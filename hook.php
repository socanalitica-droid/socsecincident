<?php

// Profiles allowed to see/use the "Incidente de Seguridad" timeline button.
// Presence of a row = enabled (same convention as socautoassign's category
// config). Seeded on install with the profiles the SOC team asked for by
// default: Super-Admin (4), Especialistas (10), Analista TIER2 (11) — verified
// against this instance's glpi_profiles table. Admin can change this anytime
// from the plugin's config screen.
const PLUGIN_SOCSECINCIDENT_DEFAULT_PROFILES = [4, 10, 11];

function plugin_socsecincident_install() {
    global $DB;

    if (!$DB->tableExists('glpi_plugin_socsecincident_profiles')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_socsecincident_profiles` (
                `id`          int unsigned NOT NULL AUTO_INCREMENT,
                `profiles_id` int unsigned NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `profiles_id` (`profiles_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");
        foreach (PLUGIN_SOCSECINCIDENT_DEFAULT_PROFILES as $profiles_id) {
            $DB->insert('glpi_plugin_socsecincident_profiles', ['profiles_id' => $profiles_id]);
        }
    }

    // Audit trail of every "Incidente de Seguridad" classification made from
    // the timeline button, independent of the ticket's own followup text.
    if (!$DB->tableExists('glpi_plugin_socsecincident_incidents')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_socsecincident_incidents` (
                `id`              int unsigned NOT NULL AUTO_INCREMENT,
                `tickets_id`      int unsigned NOT NULL,
                `users_id`        int unsigned NOT NULL,
                `classification`  varchar(32) NOT NULL,
                `date_creation`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");
    }

    return true;
}

function plugin_socsecincident_uninstall() {
    global $DB;

    foreach (['glpi_plugin_socsecincident_profiles', 'glpi_plugin_socsecincident_incidents'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}
