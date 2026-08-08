# Sentinel — GLPI 11 health-check plugin

Framework de "checks" de salud para GLPI (11.0.x, objetivo 11.0.8),
inspirado en el Site Health de WordPress. Cada check es independiente,
solo reporta (nunca corrige nada por sí solo), y se apoya en el cron
nativo de GLPI para re-evaluarse periódicamente.

## Arquitectura

- `CheckInterface`: contrato mínimo que implementa cada check
  (`getKey()`, `getCategory()`, `isEnabled()`, `run()`).
- `CheckRunner`: orquesta todos los checks registrados, resuelve
  automáticamente los `Issue` que ya no aplican (`date_last_seen`
  desactualizado respecto al scan actual), scoped por `check_key` para
  no pisar hallazgos de checks que estaban desactivados en esa corrida.
- `Issue` (antes `Finding`): un `CommonDBTM` genérico — una fila puede
  apuntar a un registro de BD (`source_table`+`source_id`) o a un
  archivo en disco (`path`), nunca ambos como identidad a la vez.
  `applyCleanup()` resuelve cuál de los dos borrar.
- `src/Checks/`: cada check vive en su propio archivo.
  - `OrphanRecordsCheck` — el motor original: relaciones polimórficas
    (`itemtype`/`items_id`) + FK clásicas (`xxx_id`), por introspección
    de `INFORMATION_SCHEMA`, sin listas hardcodeadas de tablas.
  - `DocumentsCheck` — nuevo: cruza `glpi_documents` contra el disco en
    las dos direcciones.

## DocumentsCheck en detalle

- **`missing_file`** (activado por defecto): fila en `glpi_documents`
  cuyo `filepath` ya no existe en disco. Limpieza = borra la fila (no
  hay nada que reasociar).
- **`orphan_file`** (**desactivado por defecto**): archivo físico bajo
  `GLPI_DOC_DIR` que ninguna fila de `glpi_documents` referencia.
  Limpieza = borra el archivo.

  ⚠️ Nota de seguridad: en una instalación estándar, `GLPI_DOC_DIR` es
  literalmente la misma carpeta raíz `files/` que también contiene
  `_cache`, `_sessions`, `_log`, `_pictures`, `_plugins`, etc. El
  recorrido excluye dinámicamente cualquier subcarpeta que coincida con
  otra constante `GLPI_*_DIR` definida (no es una lista fija — se
  adapta a la instalación real), nunca sigue symlinks, y está limitado
  por corrida. Aun así, **revisá el informe de tu primer escaneo antes
  de activar este check en producción**.

## Estado: primer framework + 2 checks

### Qué hace ya
- Todo lo de la versión anterior (orphan records), ahora como un check
  entre varios.
- `DocumentsCheck` con las dos direcciones descriptas arriba.
- Modo Analizador (botón manual) + tarea cron `scan` (modo
  `MODE_EXTERNAL`, no se dispara sola en cada carga de página).
- Informe unificado (`Search::show` sobre `glpi_plugin_sentinel_issues`)
  con columna `check_key`/`category` para filtrar por tipo de problema.
- Limpieza siempre manual, registro por registro, con el mismo patrón
  Ignorar / Descartar informe / Eliminar de verdad (requiere bit PURGE).

### Qué falta / a validar contra una instancia real
- No hay tests unitarios todavía.
- Confirmar `GLPI_DOC_DIR` en tu instalación específica (puede diferir
  de la config por defecto) antes de habilitar `scan_orphan_files`.
- Locales (`.po`/`.pot`) no generados aún.

## Añadir un check nuevo
1. Crear `src/Checks/MiCheck.php` implementando `CheckInterface`.
2. Agregar la clase a `CheckRunner::REGISTRY`.
3. Si necesita config propia, agregar las claves a `Config::getDefaults()`
   y sus campos en `templates/config.html.twig`.

Nada más del plugin necesita cambiar: informe, cron y limpieza ya
funcionan de forma genérica sobre `Issue`.

## Instalación
```
cd /ruta/a/glpi/plugins
# copiar/clonar este directorio como "sentinel"
```
Activar desde `Configurar > Plugins`. Si venís de una versión anterior
del plugin (`cleanorphans`), es una instalación nueva e independiente —
desinstalá/borrá la anterior primero, las tablas y namespaces no son
compatibles.
