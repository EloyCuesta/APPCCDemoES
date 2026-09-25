# MVP APPCCDemoES

El MVP es una aplicación online demostrable para operar controles APPCC en establecimientos aislados. No se considera terminado por tener pantallas: exige reglas de dominio protegidas, PostgreSQL real, recorrido integrado en escritorio/móvil y ambas pipelines verdes para el cambio.

## Recorrido de aceptación

```text
Login → seleccionar establecimiento → Dashboard → Agenda
  → abrir tarea programada → registrar control APPCC
     ├─ Conforme → confirmación del usuario → histórico
     └─ No conforme → observación → fotografía obligatoria
         → confirmación del usuario autenticado → incidencia
         → acción correctiva → resolución → histórico
         → descarga protegida de evidencia
```

La demo exige observación/confirmación/generación automática de incidencia. En otros establecimientos estas tres reglas siguen configuración; toda nueva NC exige foto. No existe firma dibujada. El usuario/fecha de confirmación los fija el backend.

## Qué significa terminado

- Login JWT válido; errores de credenciales y 401 controlados; cuenta inactiva rechazada. Logout elimina sesión y datos privados.
- Selección limitada a membresías vigentes. Cada operación lleva `X-Establecimiento-Id`, validado en servidor; al cambiar, se cancelan consultas y desaparecen datos anteriores.
- Dashboard con contadores de API y estados loading/error/retry. Agenda con pendientes, vencidas, próximas, filtros y navegación usable en escritorio y móvil.
- Registros numéricos, booleanos y estructurados respetan reglas, observaciones, plazos y confirmación. Una ejecución produce un solo registro y queda completada; históricos inmutables.
- Fotos temporales privadas, verificadas y consumidas una sola vez. NC sin foto válida rechazada. Descarga autenticada por tenant/permisos, sin hashes/rutas internos, con MIME y cabeceras seguras.
- NC genera una incidencia cuando está configurado. Listado, detalle, historial, acciones y resolución funcionan; no cerrar sin acción cuando está prohibido ni reabrir mediante PATCH genérico.
- Catálogo restaurante/obrador/catering, preview, compatibilidad e idempotencia. Límites propios y activación posterior de controles pendientes, con reconsulta del estado real.
- Recurrencia diaria/semanal/mensual y detección de vencidas mediante `app:tareas:procesar`; no duplicar ejecuciones. Preparación reproducible y comprobable.
- Roles admin/responsable/trabajador/auditor con la [matriz real](ARCHITECTURE.md). Backend protege permisos aunque se manipule el cliente.
- Demo repetible y limitada a dev/test, cuatro usuarios, Restaurante con datos/históricos/NC/acción/evidencia y Obrador inicialmente listo para aplicación manual. [Preparación y credenciales locales](DEVELOPMENT.md).
- Sin errores TypeScript, pruebas/build rotos ni 500 en el recorrido. Backend CI y Frontend CI verdes en GitHub para el commit revisado.

## Estado actual del MVP

Auditoría del 25/09/2026 sobre `main` `3c92592536d9a21bca1288ad60e9110d516ad66e` y cambios de esta entrega. ✅ terminado y validado en el nivel indicado; ⚠ implementado pero con problemas o validación de cierre pendiente; ❌ pendiente. Una prueba de componente no equivale a aceptación integrada.

| Área | Estado | Evidencia y alcance |
|---|---|---|
| Autenticación, JWT, inactivos, 401 y roles | ✅ | `SecurityTest`, `MeApiTest`, `DemoSeedCommandTest`; `session.test.ts`, `api.test.ts` |
| Membresías y aislamiento de recursos/IRI | ✅ | `MembresiasTest`, `SecurityTest`, consultas y evidencias API; sesión frontend y respuestas tardías |
| Dashboard: totales, errores y cambio de tenant | ✅ | `dashboard.test.tsx`, consultas SQL en `ConsultasApiTest`; indicadores según zona del dispositivo |
| Agenda: estados, filtros, fechas y contexto | ✅ | `agenda-contracts.test.ts`, `ui.test.tsx`, `ConsultasApiTest`, `ProgramacionesApiTest` |
| Registro conforme/NC, datos estructurados y confirmación | ✅ | `registros.test.tsx`, `BusinessLogicTest`, `NoConformidadesApiTest`, `EvidenciasApiTest`; triggers y unicidad PostgreSQL |
| Evidencias: subida, consumo, integridad, descarga, permisos | ✅ | `EvidenciasTest`, `EvidenciasApiTest`, `ConcurrenciaEvidenciasTest`, tests frontend de descarga/histórico |
| Incidencias: acciones, historial, cierre y tenant | ✅ | `incidencias.test.tsx`, `BusinessLogicTest`, `ConsultasApiTest`, concurrencia operativa |
| Plantillas de las tres actividades y configuración | ✅ | `PlantillasApiTest`, `ConcurrenciaPlantillasTest`, `MigracionPlantillasTest`, `plantillas.test.tsx`; tipo TS corregido |
| Recurrencia y vencimiento | ✅ | `GeneradorTareasProgramadasTest`, `CalendarioRecurrenteTest`, `CicloOperativoTareasTest`, pruebas de concurrencia y reconciliación |
| Demo idempotente y restricción dev/test | ✅ | `DemoSeedCommandTest`; seed repetido localmente conserva contadores e históricos; ciclo sin errores |
| Aceptación completa en navegador desktop/mobile | ✅ | 6 pruebas live con Chromium: registro conforme/NC, incidencia→acción→resolución→histórico→descarga y plantilla→límites→idempotencia→cambio tenant→logout |
| Backend CI y Frontend CI | ⚠ | El commit de partida tiene ambas pipelines rojas; pendiente validar el commit corregido en Actions |

La implementación existente cubre las áreas funcionales esenciales auditadas y pasa la aceptación local. El cierre global sigue abierto hasta confirmar CI remota; no añadir funcionalidades para ocultar ese bloqueo.

## Resultados de validación de esta entrega

Entorno local: Windows, PHP 8.3.11, PostgreSQL 18, Node 24.13.0. Las suites frontend usan las dependencias fijadas por el lockfile; la ejecución final de Playwright usa Chromium 153 instalado por Playwright.

| Comando | Resultado |
|---|---|
| `composer validate --strict` | ✅ |
| `php bin/console lint:container` | ✅ |
| Migraciones y `doctrine:schema:validate` | ✅ versión `20260922090000`, esquema sincronizado |
| `php bin/phpunit` | ✅ 513 tests, 2.625 aserciones |
| `npm run lint` | ✅ |
| `npm run typecheck` | ✅ |
| `npm test` | ✅ 229 pruebas, 10 archivos |
| `npm run build` | ✅ rutas MVP compiladas |
| `npm run test:e2e` | ✅ 4 pruebas: 2 desktop y 2 mobile |
| `npm run test:live` | ✅ 6 pruebas: 3 desktop y 3 mobile |

Regresiones comprobadas: dos opciones `exact` incompatibles con Testing Library; variables externas invisibles para Dotenv bajo PHP cli-server con GPCS; selector live que esperaba el nombre aislado en vez del encabezado completo del registro; comparación sensible a mayúsculas de los nombres de cabecera CORS. Además se regeneró la caché local dev sin debug, que conservaba metadatos anteriores a los campos de configuración de controles. Se mantienen las aserciones funcionales y PostgreSQL. Detalle de causa y entornos en [DEVELOPMENT](DEVELOPMENT.md).

## Fuera del cierre actual

Offline/PWA instalable, correo y recuperación de contraseña, interfaz completa de administración de usuarios/configuración, almacenamiento distribuido y mejoras analíticas no bloquean este recorrido online. Hay APIs de onboarding/usuarios/configuración existentes: no documentarlas como inexistentes ni duplicarlas para añadir pantallas. Prioridades en [ROADMAP](ROADMAP.md).

La validación responsive cubre los tamaños desktop y Pixel 7 automatizados; no implica certificación de todos los navegadores/dispositivos. El cron, volumen persistente, copias y restauración deben prepararse en cada despliegue; la demo local no acredita una instalación de producción.
