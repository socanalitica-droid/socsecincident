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

    // "Incidente Seguridad" field, same container — mirrors the classification
    // chosen in this plugin's modals (Materializado/No materializado/
    // Pendiente Veredicto on declare, Materializado/No materializado on close).
    const CLASSIFICATION_COLUMN = 'incidenteseguridadfield';

    // Incident lifecycle, in forward-only order. Declaring the incident
    // (first use of the timeline button) jumps straight to the first stage;
    // every later use of the same button advances to a stage further down
    // this list. Reaching the last stage also closes the ticket.
    const STAGES = [
        'Nivel 1. Detección y Contención Inicial',
        'Nivel 2. Análisis y Respuesta Técnica',
        'Nivel 3. Coordinación y Remediación Estratégica',
        'Nivel 4. Escalamiento Ejecutivo y Legal',
        'Cerrado',
    ];

    // Shown as helper text under the "Siguiente estado" dropdown so analysts
    // don't have to remember what each level covers.
    const STAGE_DESCRIPTIONS = [
        'Nivel 1. Detección y Contención Inicial' =>
            'Identificación, validación inicial, registro, enriquecimiento básico y '
            . 'aplicación de medidas de contención inmediata cuando estas se encuentran '
            . 'dentro de las capacidades operativas del monitoreo.',
        'Nivel 2. Análisis y Respuesta Técnica' =>
            'Investigación técnica especializada, correlación de múltiples fuentes, '
            . 'determinación del alcance, validación de explotación, contención avanzada, '
            . 'coordinación técnica y verificación de las medidas implementadas.',
        'Nivel 3. Coordinación y Remediación Estratégica' =>
            'Situaciones que requieren coordinación entre diferentes áreas, remediaciones '
            . 'estructurales, gestión de riesgos significativos, participación de '
            . 'responsables de servicio, procesos de investigación de inteligencia, '
            . 'análisis de hipótesis de hunting, vigilancia digital de posible información '
            . 'comprometida, o decisiones que exceden la respuesta técnica.',
        'Nivel 4. Escalamiento Ejecutivo y Legal' =>
            'Situaciones con impacto significativo para la organización, compromiso '
            . 'confirmado de información sensible, obligaciones regulatorias, implicaciones '
            . 'legales, contractuales o reputacionales relevantes.',
    ];

    // Old lifecycle → new lifecycle, applied as a one-off data migration when
    // this taxonomy replaced the previous "Investigación / Contenido / ..."
    // stages, so tickets already in progress don't get silently read back as
    // "not declared". Kept here for reference, not applied automatically on
    // every request — see the migration run from the SSM session that shipped
    // this change.
    const LEGACY_STAGE_MIGRATION = [
        'Investigación'             => 'Nivel 1. Detección y Contención Inicial',
        'Contenido'                 => 'Nivel 1. Detección y Contención Inicial',
        'Comprometido / Confirmado' => 'Nivel 2. Análisis y Respuesta Técnica',
        'Erradicado'                => 'Nivel 2. Análisis y Respuesta Técnica',
        'En recuperación'           => 'Nivel 3. Coordinación y Remediación Estratégica',
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

    // Null means "not declared yet". Whitelist-based on purpose: whatever
    // placeholder value sits in the column before this plugin touches it —
    // the field's own default_value from the "fields" plugin config
    // (whatever that's currently set to), "NA" from another integration's
    // ticket-creation flow, null, empty — none of that needs to be
    // enumerated here. Only a value that IS one of our own STAGES counts as
    // "declared"; everything else means the incident hasn't been raised
    // through this plugin yet.
    static function getIncidentState(int $tickets_id): ?string {
        global $DB;
        foreach ($DB->request([
            'SELECT' => [self::FIELDS_STATE_COLUMN],
            'FROM'   => self::FIELDS_TABLE,
            'WHERE'  => ['items_id' => $tickets_id, 'itemtype' => 'Ticket'],
            'LIMIT'  => 1,
        ]) as $row) {
            $value = $row[self::FIELDS_STATE_COLUMN] ?? null;
            return in_array($value, self::STAGES, true) ? $value : null;
        }
        return null;
    }

    // Same as getIncidentState(), except "Cerrado" only counts as the
    // terminal stage while the ticket is actually still closed. Reopening a
    // ticket that this plugin closed resets its lifecycle back to "not
    // declared" — otherwise the button would stay hidden forever, since the
    // stored field value never changes on its own when a ticket reopens.
    static function getEffectiveIncidentState(Ticket $ticket): ?string {
        $state = self::getIncidentState((int) $ticket->getID());
        if ($state === 'Cerrado' && (int) $ticket->fields['status'] !== Ticket::CLOSED) {
            return null;
        }
        return $state;
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
        self::setFieldsColumn($tickets_id, $entities_id, self::FIELDS_STATE_COLUMN, $state);
    }

    // ── Classification ("Incidente Seguridad") ──────────────────────────────────

    static function setClassification(int $tickets_id, int $entities_id, string $label): void {
        self::setFieldsColumn($tickets_id, $entities_id, self::CLASSIFICATION_COLUMN, $label);
    }

    // Shared upsert for any column on the "secop" container row.
    static function setFieldsColumn(int $tickets_id, int $entities_id, string $column, string $value): void {
        global $DB;
        $exists = false;
        foreach ($DB->request(['FROM' => self::FIELDS_TABLE, 'WHERE' => ['items_id' => $tickets_id, 'itemtype' => 'Ticket'], 'LIMIT' => 1]) as $row) {
            $exists = true;
        }
        if ($exists) {
            $DB->update(self::FIELDS_TABLE, [$column => $value], ['items_id' => $tickets_id, 'itemtype' => 'Ticket']);
        } else {
            $DB->insert(self::FIELDS_TABLE, [
                'items_id'                    => $tickets_id,
                'itemtype'                    => 'Ticket',
                'plugin_fields_containers_id' => self::FIELDS_CONTAINER_ID,
                'entities_id'                 => $entities_id,
                $column                       => $value,
            ]);
        }
    }

    // ── socfields' close requirement ("Acción Tomada" / "Causa Raíz") ──────────

    // socfields normally blocks Ticket::update() itself (pre_item_update hook)
    // when a required cascade field isn't filled for a Closed transition. We
    // close tickets via a direct glpi_tickets update (see
    // front/ticketaction.form.php) to bypass GLPI's own mandatory-fields
    // policy on purpose — but that also skips socfields' hook entirely, so we
    // have to replicate its check here rather than silently violate it.
    // Returns the missing "Parent / Child" labels; empty array means OK to close.
    static function getMissingSocfieldsRequirements(int $tickets_id, int $itilcategories_id): array {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_socfields_fields')) {
            return [];
        }

        if ($DB->tableExists('glpi_plugin_socfields_category_config')) {
            foreach ($DB->request([
                'FROM'  => 'glpi_plugin_socfields_category_config',
                'WHERE' => ['itilcategories_id' => $itilcategories_id],
                'LIMIT' => 1,
            ]) as $row) {
                if (!$row['active']) {
                    return []; // socfields opted out for this category — nothing to require
                }
            }
        }

        $missing = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_fields', 'WHERE' => ['required' => 1]]) as $field) {
            $field_id = (int) $field['id'];
            $filled   = false;
            foreach ($DB->request([
                'FROM'  => 'glpi_plugin_socfields_ticket_values',
                'WHERE' => ['tickets_id' => $tickets_id, 'field_id' => $field_id],
                'LIMIT' => 1,
            ]) as $val) {
                if (!empty($val['parent_value']) && !empty($val['child_value'])) {
                    $filled = true;
                }
            }
            if (!$filled) {
                $missing[] = $field['label_parent'] . ' / ' . $field['label_child'];
            }
        }
        return $missing;
    }
}
