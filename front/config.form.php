<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

include_once Plugin::getPhpDir('socsecincident') . '/inc/config.class.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    PluginSocsecincidentConfig::saveProfileConfig(array_keys($_POST['profile'] ?? []));
    Session::addMessageAfterRedirect('Configuración guardada correctamente.', true, INFO);
    Html::back();
    exit();
}

Html::header('SOC Security Incident — Configuración', $_SERVER['REQUEST_URI'], 'config', 'PluginSocsecincidentConfig');

$profiles      = PluginSocsecincidentConfig::getAllProfiles();
$enabled_ids   = PluginSocsecincidentConfig::getEnabledProfileIds();
$category_id   = PluginSocsecincidentConfig::getTargetCategoryId();

?>
<div class="container-fluid mt-3" style="max-width:900px">

  <!-- ── Header ──────────────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header fw-bold">
      <i class="ti ti-shield-exclamation me-2"></i>SOC Security Incident — Configuración
    </div>
    <div class="card-body pb-1">
      <p class="text-muted small mb-0">
        Agrega el botón <strong>"Incidente de Seguridad"</strong> en el menú desplegable de
        acciones del timeline del ticket (justo debajo de "Escalate"). Al confirmarlo desde el
        formulario, el ticket queda con un seguimiento registrado, pasa a
        <strong>En curso (asignada)</strong> y su categoría ITIL cambia a
        <strong>"<?= htmlspecialchars(PluginSocsecincidentConfig::TARGET_CATEGORY_NAME) ?>"</strong>.
        Solo aplica a los perfiles marcados abajo — <strong>ningún perfil queda activo salvo los
        que marques aquí</strong>.
      </p>
<?php if ($category_id === 0): ?>
      <div class="alert alert-warning mt-2 mb-0 py-2 small">
        <i class="ti ti-alert-triangle me-1"></i>
        No se encontró la categoría ITIL "<?= htmlspecialchars(PluginSocsecincidentConfig::TARGET_CATEGORY_NAME) ?>"
        en este momento. Verifica que exista con ese nombre exacto antes de usar el botón.
      </div>
<?php endif; ?>
    </div>
  </div>

  <form method="post" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken()) ?>">

    <div class="card mb-4 border-primary">
      <div class="card-header bg-primary bg-opacity-10 d-flex align-items-center gap-2">
        <i class="ti ti-list-check"></i>
        <span class="fw-bold">Perfiles con acceso al botón</span>
      </div>
      <div class="card-body">
<?php if (empty($profiles)): ?>
        <p class="text-muted small mb-0">No hay perfiles configurados en GLPI.</p>
<?php else: ?>
        <div class="row g-2">
<?php foreach ($profiles as $prof):
    $pid     = (int) $prof['id'];
    $enabled = in_array($pid, $enabled_ids, true);
?>
          <div class="col-md-4">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox"
                     name="profile[<?= $pid ?>]" value="1"
                     id="ssi_prof_<?= $pid ?>" <?= $enabled ? 'checked' : '' ?>>
              <label class="form-check-label small" for="ssi_prof_<?= $pid ?>">
                <?= htmlspecialchars($prof['name']) ?>
              </label>
            </div>
          </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
      </div>
    </div>

    <hr>
    <div class="text-end mb-5">
      <button type="submit" name="save" class="btn btn-primary">
        <i class="ti ti-device-floppy me-1"></i>Guardar configuración
      </button>
    </div>
  </form>
</div>

<?php Html::footer(); ?>
