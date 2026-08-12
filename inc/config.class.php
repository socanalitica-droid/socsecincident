<?php

class PluginSocsecincidentConfig extends CommonGLPI {

    static $rightname = 'entity';

    // Category the ticket is moved into on confirm. Looked up by name at
    // runtime (never hardcoded) so it keeps working if the category's ID
    // ever changes.
    const TARGET_CATEGORY_NAME = 'Incidente de Seguridad';

    // The "Estado Incidente Seguridad" field lives in the "fields" plugin
    // (Additional Fields), container "secop" (SecOps). Table/column names
    // verified directly against this instance's schema — the "fields"
    // plugin doesn't expose a lookup-by-name API for its per-itemtype value
    // tables, so these stay hardcoded rather than guessed at runtime.
    const FIELDS_TABLE        = 'glpi_plugin_fields_ticketsecops';
    const FIELDS_STATE_COLUMN = 'estadoincidenteseguridadfield';
    const FIELDS_CONTAINER_ID = 2;

    // Incident lifecycle, in forward-only order. Declaring the incident
    // (first use of the timeline button) jumps straight to the first stage;
    // every later use of the same button advances to a stage further down
    // this list. Reaching the last stage also closes the ticket.
    const STAGES = [
        'Investigación',
        'Contenido',
        'Comprometido / Confirmado',
        'Erradicado',
        'En recuperación',
        'Cerrado',
    ];

    static function getTypeName($nb = 0) { return 'SOC Security Incident'; }
    static function getMenuName()        { return 'SOC Security Incident'; }

    static function getMenuContent() {
        if (!Session::haveRight('entity', READ)) {
            return false;
        }
        $front = Plugin::getPhpDir('socsecincident', false) . '/front';
        return ['title' => self::getMenuName(), 'page' => "$front/config.form.php", 'icon' => 'ti ti-shield-exclamation'];
    }

    // ── Profiles ──────────────────────────────────────────────────────────────

    static function getAllProfiles(): array {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_profiles', 'ORDER' => ['name ASC']]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    // profiles_id[] currently enabled (a row exists = enabled).
    static function getEnabledProfileIds(): array {
        global $DB;
        $ids = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_socsecincident_profiles']) as $row) {
            $ids[] = (int) $row['profiles_id'];
        }
        return $ids;
    }

    static function isProfileEnabled(int $profiles_id): bool {
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_plugin_socsecincident_profiles', 'WHERE' => ['profiles_id' => $profiles_id], 'LIMIT' => 1]) as $row) {
            return true;
        }
        return false;
    }

    // $enabled_ids: profiles_id[] that came checked in the config form.
    static function saveProfileConfig(array $enabled_ids): void {
        global $DB;
        $enabled_ids = array_unique(array_map('intval', $enabled_ids));

        $DB->doQuery('DELETE FROM `glpi_plugin_socsecincident_profiles`');
        foreach ($enabled_ids as $pid) {
            if ($pid > 0) {
                $DB->insert('glpi_plugin_socsecincident_profiles', ['profiles_id' => $pid]);
            }
        }
    }

    // ── Target category ──────────────────────────────────────────────────────

    static function getTargetCategoryId(): int {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_itilcategories', 'WHERE' => ['name' => self::TARGET_CATEGORY_NAME], 'LIMIT' => 1]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }

    // ── Incident lifecycle state ("Estado Incidente Seguridad") ────────────────

    // The "fields" plugin materializes its own default_value ("No Confirmado")
    // into the row as soon as the ticket page is rendered/saved once — so an
    // undeclared incident isn't reliably represented by a null/empty column,
    // it shows up as this literal string too.
    const NOT_DECLARED_VALUE = 'No Confirmado';

    // Null means "not declared yet" (no row, empty column, or still at the
    // fields plugin's own "No Confirmado" default).
    static function getIncidentState(int $tickets_id): ?string {
        global $DB;
        foreach ($DB->request([
            'SELECT' => [self::FIELDS_STATE_COLUMN],
            'FROM'   => self::FIELDS_TABLE,
            'WHERE'  => ['items_id' => $tickets_id, 'itemtype' => 'Ticket'],
            'LIMIT'  => 1,
        ]) as $row) {
            $value = $row[self::FIELDS_STATE_COLUMN] ?? null;
            if ($value === null || $value === '' || $value === self::NOT_DECLARED_VALUE) {
                return null;
            }
            return $value;
        }
        return null;
    }

    // Stages strictly after $current_state. Empty array if not declared yet
    // (nothing to advance to) or already at the last stage (Cerrado).
    static function getNextStages(?string $current_state): array {
        $idx = $current_state === null ? false : array_search($current_state, self::STAGES, true);
        if ($idx === false) {
            return [];
        }
        return array_slice(self::STAGES, $idx + 1);
    }

    static function setIncidentState(int $tickets_id, int $entities_id, string $state): void {
        global $DB;
        $exists = false;
        foreach ($DB->request(['FROM' => self::FIELDS_TABLE, 'WHERE' => ['items_id' => $tickets_id, 'itemtype' => 'Ticket'], 'LIMIT' => 1]) as $row) {
            $exists = true;
        }
        if ($exists) {
            $DB->update(self::FIELDS_TABLE, [self::FIELDS_STATE_COLUMN => $state], ['items_id' => $tickets_id, 'itemtype' => 'Ticket']);
        } else {
            $DB->insert(self::FIELDS_TABLE, [
                'items_id'                     => $tickets_id,
                'itemtype'                     => 'Ticket',
                'plugin_fields_containers_id'  => self::FIELDS_CONTAINER_ID,
                'entities_id'                  => $entities_id,
                self::FIELDS_STATE_COLUMN      => $state,
            ]);
        }
    }
}
