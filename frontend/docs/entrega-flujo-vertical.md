# Entrega del primer flujo vertical online

Validación local: 23 de septiembre de 2026. Repositorio `EloyCuesta/APPCCDemoES`, rama `main`, base `9eba95f318e01bb3460301f58c4564b538473775`. Se comprobó con `git ls-remote` que también era la última revisión de `origin/main`. Los cambios se entregan en el árbol de trabajo, sin publicar ni crear commits.

## Resultado y decisiones

El trabajador puede entrar desde Next, seleccionar su establecimiento, consultar Agenda, abrir una ejecución pendiente/vencida, introducir el resultado, adjuntar evidencias y confirmar el registro. Symfony calcula la conformidad, valida la fotografía obligatoria y la confirmación, completa la ejecución y crea la incidencia automática. Next vuelve a consultar Agenda después de guardar.

- `app:demo:seed` reutiliza `OnboardingService`, `PlantillaAPPCCService`, `TareaAPPCCService`, `GeneradorTareasProgramadasService`, `TareaProgramadaService`, el validador de dominio y el password hasher. No añade paquetes, fixtures, migraciones ni INSERT SQL. Un bloqueo asesor serializa semillas simultáneas; la identidad del local se deriva del NIF demo y su nombre. El guard de entorno rechaza producción antes de consultar datos.
- Se configura únicamente la aplicación inicial de tareas. Conserva sus IDs y los históricos al repetir; restablece las credenciales conocidas y las membresías demo activas. El generador usado por `app:tareas:procesar` se limita a las tareas demo. Las tareas bajo demanda reciben una ejecución por día por el servicio de programación, que ya evita duplicados.
- Formulario dentro de `/agenda`, sin nueva ruta. Estado y `AbortController` viven en el formulario y se desmontan al salir/cambiar de tenant. El rol procede de la membresía actual de `/api/me`.
- Respeta `CrearRegistroInput`: decimal string, `datos.resultado` booleano o `datos` con los nombres de `configuracion.campos` más `conforme` manual. La previsión local no se envía como autoridad en controles numéricos/booleanos. La fecha y la identidad proceden del servidor.
- `Establecimiento.configuracion` está marcado como no legible en Symfony. El formulario utiliza `GET /api/configuraciones-establecimiento`, la colección existente filtrada por tenant, y exige exactamente una configuración válida del establecimiento actual.
- `ApiClient` admite `FormData` conservando JWT, tenant, cancelación y timeout de 15 segundos. Deja el boundary multipart al navegador. Los tokens de subida permanecen solo en memoria; el POST incorpora exclusivamente `{token,tipo}`.
- No cambia la seguridad, processors ni reglas de registro del backend. No hay firma dibujada, usuario firmante elegible, segunda incidencia desde cliente ni actualización optimista de ejecuciones. Errores 422 conservan mensajes de dominio; 409/red/timeout/5xx de escritura requieren consultar Agenda antes de otro envío.

## Archivos creados

| Archivo | Función |
| --- | --- |
| `backend/src/Command/DemoSeedCommand.php` | Preparación idempotente dev/test y resumen de credenciales/recursos |
| `backend/tests/DemoSeedCommandTest.php` | Primera ejecución, IDs estables, roles, login real y rechazo de prod |
| `frontend/src/features/registros/contracts.ts` | Contrato de tarea, permisos, evidencia y previsión |
| `frontend/src/features/registros/service.ts` | Lectura del control/configuración y subida multipart |
| `frontend/src/features/registros/registro-screen.tsx` | Formulario, confirmación, errores y cancelación |
| `frontend/src/features/registros/registro.css` | Estilo responsive del registro |
| `frontend/tests/registros.test.tsx` | 20 casos de interfaz, escrituras y cancelaciones |
| `frontend/playwright.live.config.ts` | Servidores locales y aceptación real de escritorio/móvil |
| `frontend/tests/live/registro.spec.ts` | Login, registro conforme/no conforme, foto, Agenda e incidencia reales |
| `frontend/docs/entrega-flujo-vertical.md` | Esta entrega y resultados |

## Archivos modificados

| Archivo | Cambio |
| --- | --- |
| `frontend/src/lib/api/client.ts` | Envío de `FormData` sin cabecera JSON |
| `frontend/src/lib/api/endpoints.ts` | Ejecución, configuración, evidencias y registros existentes |
| `frontend/src/features/agenda/agenda-item.tsx` | Acción de registro en ejecuciones abiertas |
| `frontend/src/features/agenda/agenda-list.tsx` | Conexión de la acción con cada ejecución |
| `frontend/src/features/agenda/agenda-screen.tsx` | Rol, apertura/cierre, éxito y nueva consulta |
| `frontend/src/app/globals.css` | Importación de estilos del registro |
| `frontend/tests/api.test.ts` | Timeout de escritura sin reintento |
| `frontend/package.json` | Script explícito `test:live` |
| `frontend/README.md` | Agenda operativa, contratos, demo, credenciales, arranque y pruebas |

## Endpoints consumidos

Se conservan login (`POST /api/login_check`), contexto (`GET /api/me`), dashboard (`GET /api/establecimientos/{id}`) y consultas de Agenda/tareas/planes/usuarios.

El nuevo flujo consume `GET /api/tareas-programadas/{id}`, `GET /api/tareas/{id}`, `GET /api/configuraciones-establecimiento`, `POST /api/evidencias/subidas` y `POST /api/registros`. Todos los recursos operativos pasan por `ApiClient` con Bearer y `X-Establecimiento-Id`.

La prueba de aceptación, exclusivamente bajo `tests/live`, prepara ejecuciones repetibles con `POST /api/tareas/{id}/programaciones` y comprueba históricos con `GET /api/registros` y `GET /api/incidencias`. No añade endpoints a Symfony.

## Validación ejecutada

| Comando | Resultado |
| --- | --- |
| `composer validate --strict` | Correcto |
| `php bin/console lint:container` | Correcto |
| `php bin/console doctrine:schema:validate` | Mapping y base sincronizados |
| `php bin/phpunit` | **511 pruebas, 2.582 aserciones; todas correctas** |
| `npm.cmd run lint` | Correcto, sin avisos |
| `npm.cmd run typecheck` | Correcto |
| `npm.cmd test` | **76 pruebas; todas correctas** |
| `npm.cmd run build` | Build de producción correcto |
| `npm.cmd run test:e2e` con Edge | **4 pruebas; todas correctas**, escritorio y móvil |
| `npm.cmd run test:live` con Edge | **2 pruebas reales; ambas correctas**, escritorio y móvil |
| `php bin/console app:demo:seed` repetido en dev | Local reutilizado, seis planes y siete tareas, sin duplicados |
| `php bin/console app:tareas:procesar --json` | Cero errores; cero duplicados creados |
| `git diff --check` | Correcto |

Las pruebas originales se conservaron. Los logs de PHPUnit incluyen rechazos y errores provocados expresamente por los casos negativos; la suite termina con código 0. Playwright se ejecutó fuera del aislamiento de procesos para permitir que cerrase sus servidores hijos en Windows. La API utilizada fue Symfony con PostgreSQL real para `test:live`, sin interceptaciones HTTP; las pruebas de sesión `test:e2e` conservan sus dobles.

## Aceptación demostrada

En cada dispositivo, la prueba entró por la pantalla de login como `trabajador@appccdemo.local`, seleccionó el restaurante sembrado, abrió controles reales de Agenda y guardó uno conforme sin foto y otro no conforme con PNG válido. Verificó respuesta **201**, `confirmadoPor === usuario`, fecha de confirmación, desaparición de la ejecución de la nueva Agenda, presencia en `/api/registros`, ninguna incidencia en el conforme y **exactamente una incidencia automática** en el no conforme.

Se inspeccionaron las capturas completas del formulario de escritorio y móvil. El test también verifica ausencia de desbordamiento horizontal. Las capturas se generan en `frontend/test-results/`; una copia local de esta validación se conserva en `backend/var/validacion-flujo/`, ambas rutas excluidas de Git. La prueba deja registros/evidencias demo reales y no elimina históricos.

## Arranque, cuentas y alcance pendiente

Los comandos exactos de PowerShell están en [Entorno demo local](../README.md#entorno-demo-local). Las cuentas `admin@appccdemo.local`, `responsable@appccdemo.local`, `trabajador@appccdemo.local` y `auditor@appccdemo.local` comparten **`AppccDemo2026!`**, solo para desarrollo local. El seed imprime el ID del establecimiento; en la validación local fue **2**. Había un establecimiento anterior con el mismo nombre, que se conservó.

Este flujo vertical online queda cerrado. Para el MVP completo de gestión siguen pendientes las pantallas de históricos/descarga, gestión y cierre de incidencias, adaptación de tareas/plantillas, usuarios, configuración y KPIs. Offline/PWA, correo y recuperación de contraseña siguen fuera del alcance solicitado. La operación en producción requiere su configuración habitual de JWT, base, almacenamiento persistente de evidencias, limpieza de temporales, copias y ejecución periódica del ciclo; el seed está expresamente prohibido en ese entorno.
