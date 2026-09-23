# Auditoría inicial del frontend

Revisión del repositorio `EloyCuesta/APPCCDemoES`, rama `main`, 23/09/2026, antes de implementar.

- Existe `frontend/` con el scaffold Next.js 16.3.4, React 19, App Router, TypeScript estricto y ESLint. No existe lógica de autenticación ni UI de negocio. Se reorganiza en `src/` y se mantiene App Router.
- Symfony 7.4, API Platform 4.3 y LexikJWT 3.2 ya tienen el contrato necesario. No es necesario modificar el backend.

## Contratos comprobados en código y pruebas

| Operación existente                                                 | Fuente                                                                                | Uso en este bloque                                            |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| `POST /api/login_check`                                             | `config/packages/security.yaml`, `config/routes/login.yaml`, `tests/SecurityTest.php` | JSON `{email,password}` → `{token}`                           |
| `GET /api/me`                                                       | `src/Controller/MeController.php`, `src/Dto/*SesionOutput.php`, `tests/MeApiTest.php` | Identidad, membresías autorizadas y selección inicial         |
| `GET /api/establecimientos/{id}`                                    | `src/Entity/Establecimiento.php`, `tests/MeApiTest.php`                               | Comprobar conexión y autorización del establecimiento actual  |
| `GET /api/establecimientos`                                         | `src/Entity/Establecimiento.php`                                                      | Revisado; requiere tenant, no sirve para descubrir membresías |
| `GET /api/plantillas-appcc`                                         | `src/Entity/PlantillaAPPCC.php`                                                       | Siguiente iteración                                           |
| `GET /api/tareas`                                                   | `src/Entity/TareaAPPCC.php`                                                           | Siguiente iteración                                           |
| `GET /api/tareas-programadas`, `GET /api/tareas-programadas/agenda` | `src/Entity/TareaProgramada.php`                                                      | Próxima Agenda APPCC                                          |
| `GET /api/registros`                                                | `src/Entity/RegistroAPPCC.php`                                                        | Siguiente iteración                                           |
| `GET /api/incidencias`                                              | `src/Entity/Incidencia.php`                                                           | Siguiente iteración                                           |

`/api/me` devuelve JSON simple: id, nombre, apellidos, email, membresías (id, IRI, rol empresarial, establecimiento con id, IRI, nombre, actividad y entidad fiscal resumida), y `establecimientoPredeterminadoId`. No devuelve roles globales ni secretos. Solo incluye membresías propias activas con establecimiento y entidad fiscal activos. Lista vacía es una sesión válida sin acceso operativo.

Login no requiere cabeceras de sesión. `/api/me` requiere `Authorization: Bearer <JWT>` sin tenant. Las consultas operativas añaden `X-Establecimiento-Id` (entero positivo) y `Accept: application/ld+json`. `/api/me` y login usan `Accept: application/json`. `CurrentEstablecimientoContext` valida membresía en servidor; una selección manipulada no concede acceso. CORS ya permite ambas cabeceras.

## Decisiones previas

El backend entrega exclusivamente un JWT en JSON, sin refresh ni cookie HTTP-only. Se usa un único almacén de token en `sessionStorage`, aislado por pestaña y accesible mediante un adaptador sustituible. Evita persistencia indefinida, pero sigue siendo accesible a JavaScript: no protege frente a XSS. Un futuro BFF/cookie real requiere diseñar ese contrato; no se simula aquí.

Al iniciar o restaurar se consulta `/api/me`. Se valida el establecimiento recordado contra esa respuesta; se selecciona automáticamente el único disponible y se pide selección cuando hay varios sin preferencia válida. El usuario, las membresías y los datos operativos permanecen en memoria. Cada cambio de sesión/tenant cancela peticiones anteriores y desmonta la vista dependiente.

El dashboard consulta solo el establecimiento seleccionado. Los futuros KPIs muestran explícitamente que aún no están disponibles, sin cifras inventadas ni consultas masivas para contarlos.
