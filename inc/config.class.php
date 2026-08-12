<?php

class PluginSocsecincidentConfig extends CommonGLPI {

    static $rightname = 'entity';

    // Category the ticket is moved into on confirm. Looked up by name at
    // runtime (never hardcoded) so it keeps working if the category's ID
    // ever changes.
    const TARGET_CATEGORY_NAME = 'Incidente de Seguridad';

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
}
