# Agenda APPCC: auditoría y contrato

Auditoría previa a la implementación, sobre la rama `main` actual. No se modifica Symfony.

## Operaciones revisadas

- `TareaProgramada.php`: GET `/api/tareas-programadas`, `/api/tareas-programadas/agenda` y `/api/tareas-programadas/{id}`. El proveedor de lectura es el ORM estándar de API Platform. `AgendaExtension` intersecta la consulta con estados `pendiente` y `vencida`; `TenantExtension` aplica el establecimiento en SQL antes de paginar.
- La lectura no tiene DTO de salida independiente: serializa la entidad, con `id`, `tarea`, `establecimiento`, `asignadoA`, `fechaProgramada`, `fechaLimite`, `estado` y campos de auditoría. Las relaciones son IRI, según OpenAPI y `ProgramacionesApiTest`. La envoltura JSON-LD es `member`, `totalItems`, `view`; no hay grupos precomputados.
- El `SerializerContextBuilder` instalado activa `skip_null_values=true`: `asignadoA`, `fechaLimite` y `horaPrevista` pueden faltar si son nulos. El parser normaliza únicamente esos campos opcionales a null; no exige claves que la API omite legítimamente.
- Los DTO `CrearProgramacionInput`, `AsignarProgramacionInput` y `OmitirProgramacionInput` y sus processors corresponden a POST de creación, asignación y omisión. No se invocan en este bloque de consulta.
- `TareaAPPCC.php`: GET `/api/tareas` (colección con `ConsultaCollectionProvider`) y `/api/tareas/{id}`. Nombre, frecuencia, horaPrevista y planControl. `horaPrevista` es `HH:mm:ss` o null, pese a la descripción genérica date-time de OpenAPI; lo confirman el contexto de serialización y `SecurityTest`.
- `PlanControl.php`: GET `/api/planes-control/{id}`, con nombre y tipo APPCC. `Usuario.php`: GET `/api/usuarios` y `/api/usuarios/{id}`, con nombre y apellidos. La lectura está permitida para los cuatro roles. Usuarios se limita a membresías activas del tenant; un responsable cuya membresía se retiró puede devolver 404 aunque siga referenciado por una programación.

## Filtros y fechas

| Parámetro real | Contrato |
| --- | --- |
| `estado` | `pendiente`, `vencida`; la colección general también contempla `completada`, `omitida`, excluidas de agenda |
| `fechaProgramada[after]` / `[before]` | Límites inclusivos |
| `fechaLimite[before]` | Límite inclusivo; no se expone en esta primera UI |
| `asignadoA` | IRI `/api/usuarios/{id}` |
| `tarea` | IRI `/api/tareas/{id}` |
| `order[fechaProgramada]` | asc/desc, desempate id ASC; se usa asc |
| `page`, `itemsPerPage` | Página desde 1; 30 por defecto, máximo 100; la UI usa 20 |

`FechaAgendaFilter` utiliza `FechaAPPCC::parsear`: ISO con segundos y zona explícita, o día YYYY-MM-DD a medianoche UTC. Por ello la UI convierte inicio y final del día del navegador a instantes UTC explícitos, hasta 23:59:59, usando el offset de cada extremo (incluidos cambios de horario). No reutiliza el contrato más restrictivo de fechas de otras colecciones.

No existe prioridad. Frecuencias reales: diaria, semanal, mensual, por_turno, por_recepcion y bajo_demanda. Tipos APPCC proceden de `TipoPlanControl`.

## Decisiones de presentación

- Vencidas significa exclusivamente `estado=vencida`. No se ejecuta detección de vencimientos en cliente.
- Las pendientes se agrupan visualmente por fecha programada: días anteriores, hoy y próximas. Los grupos y sus contadores corresponden a la página visible, no al total de la agenda. La paginación mantiene accesibles todos los resultados.
- La hora mostrada es el instante de la ejecución (`fechaProgramada`), no el horario actual de una definición que puede haber cambiado.
- Se explicita la zona horaria del navegador para fechas, horas, agrupaciones y filtros. La zona fiscal está en `ConfiguracionEntidadFiscal`, cuya lectura se deniega al rol trabajador; `/api/me` no la expone. No se asume Europe/Madrid ni se amplían permisos. La futura exposición de zona operativa en contexto permitiría adoptar automáticamente la zona del establecimiento.
- Los selectores de tareas y responsables se cargan de colecciones reales y permiten cargar más opciones paginadas. No se limita la consulta de agenda a las opciones ya cargadas. No se filtran las definiciones inactivas, porque pueden conservar ejecuciones abiertas.
- Se resuelven las IRI únicas de la página en memoria, deduplicando peticiones dentro de cada carga y limitando la concurrencia. No se persisten datos operativos ni se comparten cachés entre establecimientos. Una referencia a un responsable no accesible (404) se presenta como no disponible, distinta de una ejecución sin asignar.
- Peticiones, filtros y paginación tienen AbortSignal; el layout existente desmonta la feature al cambiar de tenant. Las respuestas anteriores nunca sustituyen a la consulta actual.

No se crean enlaces de ejecución todavía. El modelo de programación conserva su id y queda separado de la presentación para el siguiente bloque de ejecución y registros.
