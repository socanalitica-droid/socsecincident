# SOC Security Incident — GLPI Plugin

Plugin para GLPI 11 desarrollado por el **SOC Team de Linktic**. Agrega el botón **"Incidente de Seguridad"** al menú desplegable de acciones del timeline del ticket (el mismo menú "Respuesta ▾" donde ya viven "Crear una tarea", "Agregar una solución", "Agregar un documento", "Ask for approval" y "Escalate"), usando el mismo mecanismo oficial de GLPI 11 que usa el plugin `escalade` para el botón "Escalate".

## Funcionalidades

- **Botón en el timeline** — inyectado vía el hook oficial `timeline_answer_actions` (el mismo que usa `escalade`), sin tocar núcleo de GLPI ni el plugin `escalade`.
- **Visibilidad por perfil, configurable** — desde la pantalla de configuración del plugin se marca qué perfiles ven el botón. Por defecto (al instalar) quedan habilitados: **Super-Admin, Especialistas, Analista TIER2**. Ningún otro perfil queda activo hasta que un admin lo marque explícitamente.
- **Modal de confirmación** con:
  - Editor de texto enriquecido para el comentario/seguimiento
  - Selector de plantilla de seguimiento (reutiliza el endpoint nativo de GLPI `ajax/itilfollowup.php`, igual que el formulario nativo de seguimientos — autocompleta el editor si la plantilla tiene contenido)
  - Clasificación obligatoria: **Materializado / No materializado / Pendiente Veredicto**
- **Al confirmar:**
  - Se agrega un seguimiento público al ticket con el comentario + la clasificación elegida
  - El estado del ticket cambia a **En curso (asignada)**
  - La categoría ITIL cambia a **"Incidente de Seguridad"** (buscada por nombre en tiempo real, nunca hardcodeada por ID)
  - Se recalcula el SLA/OLA que correspondería a la nueva categoría/estado (mismo patrón robusto usado en `socautoassign` para evitar que GLPI descarte el SLA en sesiones no-admin)
  - Queda un registro de auditoría (ticket, usuario, clasificación, fecha) en una tabla propia del plugin

## Compatibilidad

| GLPI | Plugin |
|------|--------|
| ~11.0 | 1.0.0 |

## Instalación

1. Copia la carpeta `socsecincident/` dentro de `/var/www/html/glpi/plugins/`
2. En GLPI: **Configuración → Plugins → SOC Security Incident → Instalar → Activar**
3. Ve a **Configuración → SOC Security Incident** y ajusta los perfiles habilitados si hace falta

## Estructura

```
socsecincident/
├── setup.php                          # Registro de hooks (timeline_answer_actions, config_page)
├── hook.php                           # Install/uninstall — crea tablas y siembra perfiles por defecto
├── inc/
│   ├── config.class.php               # CRUD de perfiles habilitados + lookup de categoría destino
│   └── ticketaction.class.php         # addToTimeline() + showForm() — inyecta el botón y su modal
├── templates/
│   └── ticketaction_form.html.twig    # Modal: comentario, plantilla, clasificación
└── front/
    ├── config.form.php                # UI de configuración (Bootstrap 5 cards)
    └── ticketaction.form.php          # Procesa el POST: followup + cambio de estado/categoría + SLA
```

## Tablas de base de datos

| Tabla | Descripción |
|-------|-------------|
| `glpi_plugin_socsecincident_profiles` | Perfiles habilitados para ver el botón (presencia de fila = habilitado) |
| `glpi_plugin_socsecincident_incidents` | Auditoría: ticket, usuario, clasificación y fecha de cada confirmación |

## Notas técnicas

- El botón aparece **después** de "Escalate" siempre que este plugin cargue después de `escalade` en el orden de plugins de GLPI (orden alfabético por defecto: `escalade` < `socsecincident`). Los hooks `timeline_answer_actions` se van concatenando en el orden en que los plugins se registran.
- La categoría destino se busca por **nombre exacto** (`Incidente de Seguridad`) en `glpi_itilcategories` en cada ejecución, nunca por ID fijo — si el nombre cambia, hay que actualizar la constante `PluginSocsecincidentConfig::TARGET_CATEGORY_NAME`.

## Autor

SOC Team — Linktic
Licencia: GPL v2+
