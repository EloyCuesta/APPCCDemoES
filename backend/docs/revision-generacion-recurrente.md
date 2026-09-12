# Revisión de generación recurrente — 12/09/2026

Base: `main`, commit `cee933a`. Alcance: calendario, comando, servicios, entidades, repositorios, migraciones y pruebas backend. No se cambia frontend, JWT ni los componentes de aislamiento multiempresa.

## Hallazgos y correcciones

| Gravedad | Problema | Corrección |
| --- | --- | --- |
| Alta | Cambiar calendario dejaba pendientes futuras antiguas junto a las nuevas. | Retirada transaccional en `TareaAPPCCService` de futuras PENDIENTE sin registro; regeneración posterior con calendario actual. |
| Alta | El cálculo ocurría antes del bloqueo; un generador podía materializar la configuración anterior después de una edición o desactivación. | Bloqueo común sobre tarea, recarga después de esperar, protección de padres y zona fiscal, versión optimista para editores. |
| Alta | `horaPrevista` obligatoria solo en tareas nuevas; Symfony normalizaba horas inválidas. | Validación también en tareas existentes y deserialización estricta HH:MM:SS específica para este campo. |
| Media | PHP aceptaba fechas inexistentes/relativas y extremos individuales mezclaban UTC con días fiscales o con el reloj actual. | Parseo estricto, zona explícita para ISO, ventanas ancladas al límite suministrado, formatos mixtos rechazados. |
| Media | Plazos sumados como minutos locales variaban su duración durante cambios estacionales. | Suma de minutos transcurridos en UTC; política de hora inexistente/repetida cubierta con pruebas. |
| Media | Conteos basados en colección de historial podían estar desactualizados; repetición dentro de transacción exterior duplicaba inserciones pendientes. | Resultado explícito de creación bajo bloqueo y consulta de inserciones pendientes del UnitOfWork. |
| Media | Configuración temporal sin CHECK PostgreSQL. | Migración incremental `Version20260912110000`: rangos, coherencia, hora, plazo y versión; conserva definiciones heredadas incompletas. |
| Baja | Comparación por timestamp descartaba microsegundos de los límites/createdAt; faltaba filtrar relaciones heredadas incoherentes. | Comparación de DateTime y verificación de pertenencia plan/punto/local antes de generar. |
| Baja | Documentación incompleta y ausencia de pruebas reales de concurrencia. | Reglas operativas documentadas y procesos PHP independientes coordinados mediante bloqueos PostgreSQL. |

La frecuencia diaria, el día ISO semanal y el ajuste mensual al último día válido ya eran correctos. Se conservan; se amplían las pruebas de bisiestos, contexto inactivo, frecuencias de evento y zonas fiscales. `PlantillaAPPCCService` ya validaba los datos temporalmente: no necesita reescritura; se comprueba transferencia de configuración y rechazo de tipos inválidos. `Version20260912100000` se conserva intacta.

## Archivos

Código: `src/Command/GenerarTareasCommand.php`, `src/Entity/TareaAPPCC.php`, `src/Repository/TareaAPPCCRepository.php`, `src/Serializer/HoraPrevistaDenormalizer.php`, `src/Service/GeneradorTareasProgramadasService.php`, `src/Service/ResultadoGeneracion.php`, `src/Service/TareaAPPCCService.php`, `src/Service/TareaProgramadaService.php`, `src/Service/Support/CalendarioAPPCC.php`, `src/Service/Support/BloqueoCalendarioAPPCC.php` y `migrations/Version20260912110000.php`.

Pruebas: `GeneradorTareasProgramadasTest`, `CalendarioRecurrenteTest`, `ReconciliacionTareasTest`, `ConcurrenciaCalendarioTest`, `MigrationPlanTest`, `SecurityTest` (fixture con hora válida y PATCH de calendario) y `tests/Support/recurrence-worker.php`. Documentación: este informe, `modelo-mvp.md` y `configuracion-saas.md`.

## Pruebas y límites

Pruebas sobre PostgreSQL real, incluyendo dos generadores simultáneos, edición/generación en ambos órdenes, edición obsoleta, desactivación y registro concurrente. Se comprueban los cinco campos reconciliados, cambio a frecuencia de evento, conservación exacta de filas protegidas, aislamiento de tareas/empresas, rollback, UNIQUE y CHECK, migración desde vacío y con datos heredados, formatos CLI, zonas extremas, horario estacional, bisiestos, `createdAt`, validación, plantillas y PATCH real. También se preservan ejecuciones que dejan de ser futuras durante una espera y se rechaza generar con cambios de tarea sin guardar.

La retirada no reconstruye la agenda dentro del PATCH: debe ejecutarse `app:tareas:generar` con el horizonte deseado, manualmente o por cron. Los registros protegidos conservan su hora original aunque difiera del calendario actual. No se instala un scheduler. Los cambios de zona fiscal y las escrituras SQL/ORM que omitan `TareaAPPCCService` no disparan reconciliación; la API PATCH sí utiliza este servicio. Las restricciones NOT VALID requieren reparar explícitamente las definiciones heredadas antes de validarlas. Los bloqueos pueden producir espera o rollback ante un deadlock con otros procesos; repetir la operación es seguro.

## Resultado final

| Comprobación | Resultado |
| --- | --- |
| `composer validate --strict` | Correcto, exit 0. |
| `php bin/console lint:container` | Correcto en desarrollo y test, exit 0. |
| `doctrine:migrations:migrate --no-interaction` | Desarrollo y test en `Version20260912110000`, sin migraciones pendientes. En desarrollo se aplicaron las versiones 100000 y 110000; no había tareas, ejecuciones ni registros operativos. |
| `php bin/console doctrine:schema:validate` | Mapping correcto y base sincronizada, tanto en desarrollo como en PostgreSQL de test. |
| CHECK de `tarea_appcc` | Las cinco restricciones están validadas en ambos entornos (`convalidated = true`). |
| `php bin/phpunit --no-progress` | **142 tests, 524 aserciones, 0 fallos, 0 errores, 1 omitido; exit 0**, PHP 8.3.11 / PostgreSQL 18.3. Tiempo: 53,249 s. |
| `git diff --check` | Correcto. |

Se añaden 66 casos respecto a la base de 76 tests. El único omitido es preexistente: `AdministradorCommandTest::testCreaUsuarioConHashYMembresiaSinImprimirPassword`, porque Symfony CommandTester no admite preguntas ocultas `askHidden()` en Windows. Todas las pruebas nuevas, incluidas las de concurrencia real, se ejecutan y pasan. Los mensajes 400/403/404/422 de las pruebas API corresponden a rechazos esperados.

La ejecución local utiliza la configuración PHP de `backend/var/php-conf` para cargar Sodium, ya preparada en este entorno; no se cambia la instalación global de PHP ni se versionan credenciales o archivos de entorno locales.
