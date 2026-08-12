# SOC Security Incident — GLPI Plugin

Plugin para GLPI 11 desarrollado por el **SOC Team de Linktic**. Agrega el botón **"Incidente de Seguridad"** al menú desplegable de acciones del timeline del ticket (el mismo menú "Respuesta ▾" donde ya viven "Crear una tarea", "Agregar una solución", "Agregar un documento", "Ask for approval" y "Escalate"), usando el mismo mecanismo oficial de GLPI 11 que usa el plugin `escalade` para el botón "Escalate". Permite declarar un incidente de seguridad desde el ticket y gestionarlo hasta su cierre, con el ciclo de vida completo del incidente.

## Funcionalidades

- **Botón en el timeline** — inyectado vía el hook oficial `timeline_answer_actions` (el mismo que usa `escalade`), sin tocar núcleo de GLPI ni el plugin `escalade`. Coloreado en rojo intenso (`#EF9A9A`), distinto del rosado que usa "Escalate", para resaltar como acción de mayor severidad.
- **Visibilidad por perfil, configurable** — desde la pantalla de configuración del plugin se marca qué perfiles ven el botón. Por defecto (al instalar) quedan habilitados: **Super-Admin, Especialistas, Analista TIER2**. Ningún otro perfil queda activo hasta que un admin lo marque explícitamente.
- **Ciclo de vida del incidente**, reutilizando el mismo botón en cada paso:

  ```
  (declarar) → Investigación → Contenido → Comprometido / Confirmado → Erradicado → En recuperación → Cerrado
  ```

  - **1er clic (declarar):** modal con comentario enriquecido, selector de plantilla de seguimiento (autocompleta el comentario) y clasificación obligatoria **Materializado / No materializado / Pendiente Veredicto**. Pasa el ticket a **En curso (asignada)**, cambia su categoría ITIL a **"Incidente de Seguridad"**, recalcula el SLA/OLA correspondiente, y dispara el ciclo de vida en el estado **Investigación**.
  - **Clics siguientes (avanzar estado):** el mismo botón muestra un modal distinto — selector con los estados que faltan por recorrer, plantilla de seguimiento opcional y comentario opcional. Cada avance agrega un seguimiento al ticket.
  - **Al seleccionar "Cerrado":** se pide una segunda clasificación obligatoria — **Materializado / No materializado** (ya sin "Pendiente Veredicto", porque no es una respuesta final válida). Antes de cerrar, valida que **Acción Tomada** y **Causa Raíz** (plugin `socfields`) estén diligenciados; si falta alguno, bloquea el cierre con un mensaje de error y no modifica el ticket. Si todo está en orden, cierra el ticket directamente.
  - **Ticket reabierto:** si un ticket que este plugin cerró se reabre (cambia de estado fuera de Cerrado), el ciclo de vida se reinicia — el botón vuelve a mostrar el modal de declarar desde cero.
- **Campos espejados en el plugin `fields`** (contenedor "SecOps" de la instancia):
  - `Estado Incidente Seguridad` — el estado actual del ciclo de vida.
  - `Incidente Seguridad` — la clasificación vigente (la elegida al declarar, sobrescrita por la clasificación final al cerrar).
- **Auditoría propia** — cada declaración inicial queda registrada (ticket, usuario, clasificación, fecha) en una tabla del plugin, además de quedar en el timeline del ticket.

## Compatibilidad

| GLPI | Plugin |
|------|--------|
| ~11.0 | 1.0.1 |

## Instalación

1. Copia la carpeta `socsecincident/` dentro de `/var/www/html/glpi/plugins/`
2. En GLPI: **Configuración → Plugins → SOC Security Incident → Instalar → Activar**
3. Ve a **Configuración → SOC Security Incident** y ajusta los perfiles habilitados si hace falta

Al subir la versión del plugin (`PLUGIN_SOCSECINCIDENT_VERSION` en `setup.php`) para forzar la actualización de assets con caché de navegador (CSS/JS), GLPI marca el plugin como "A actualizar" y lo **desactiva** hasta correr de nuevo `plugin:install` + `plugin:activate` — no basta con `git pull` + `cache:clear`.

## Estructura

```
socsecincident/
├── setup.php                                  # Registro de hooks (timeline_answer_actions, config_page, add_css)
├── hook.php                                   # Install/uninstall — crea tablas y siembra perfiles por defecto
├── inc/
│   ├── config.class.php                       # Perfiles, categoría destino, ciclo de vida, campos fields/socfields
│   └── ticketaction.class.php                 # addToTimeline() + showForm() — inyecta el botón y decide qué modal mostrar
├── templates/
│   ├── ticketaction_form.html.twig            # Modal: declarar incidente (comentario, plantilla, clasificación)
│   └── ticketaction_progress_form.html.twig   # Modal: avanzar estado (siguiente estado, clasificación final si es Cerrado)
├── public/css/
│   └── socsecincident.css                     # Color del ítem en el menú del timeline
└── front/
    ├── config.form.php                        # UI de configuración (Bootstrap 5 cards)
    └── ticketaction.form.php                  # Procesa ambos POST: declarar y avanzar/cerrar
```

## Tablas de base de datos

| Tabla | Descripción |
|-------|-------------|
| `glpi_plugin_socsecincident_profiles` | Perfiles habilitados para ver el botón (presencia de fila = habilitado) |
| `glpi_plugin_socsecincident_incidents` | Auditoría: ticket, usuario, clasificación y fecha de la declaración inicial |

No crea tablas propias para el estado del incidente ni la clasificación — esos se guardan en `glpi_plugin_fields_ticketsecops` (plugin `fields`), columnas `estadoincidenteseguridadfield` e `incidenteseguridadfield`.

## Notas técnicas

- El botón aparece **después** de "Escalate" siempre que este plugin cargue después de `escalade` en el orden de plugins de GLPI (orden alfabético por defecto: `escalade` < `socsecincident`). Los hooks `timeline_answer_actions` se van concatenando en el orden en que los plugins se registran.
- La categoría destino se busca por **nombre exacto** (`Incidente de Seguridad`) en `glpi_itilcategories` en cada ejecución, nunca por ID fijo — si el nombre cambia, hay que actualizar la constante `PluginSocsecincidentConfig::TARGET_CATEGORY_NAME`.
- El color del ítem del menú lo determina la **clave del arreglo** que retorna `addToTimeline()` (GLPI arma la clase CSS como `action-{clave}`), no el campo `class` del arreglo — ese solo se usa para el id del bloque colapsable.
- `PluginSocsecincidentConfig::getIncidentState()` es whitelist-based: solo cuenta como "declarado" un valor que sea literalmente uno de los estados del ciclo de vida (`STAGES`). Cualquier otro valor (el `default_value` que tenga configurado el campo en el plugin `fields`, "NA" u otro placeholder que escriba otra integración, vacío, null) se trata como "no declarado" — así funciona sin importar qué valor por defecto se use.
- El cierre final escribe directamente en `glpi_tickets` (no usa `Ticket::update()`) para evitar la política de campos obligatorios de GLPI core antes de cerrar — por eso el plugin replica manualmente la validación de `socfields` (Acción Tomada / Causa Raíz) antes de escribir el cierre.

## Autor

SOC Team — Linktic
Licencia: GPL v2+
