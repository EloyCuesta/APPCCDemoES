# Entrega: usuarios y onboarding

Repositorio `EloyCuesta/APPCCDemoES`, rama local `main`, base `4e9cdb07ad5b7b2b69201e9a6544cb7ae502307a`.
Cambios implementados y validados localmente; no se ha creado un commit ni realizado push.

## Resumen

Flujo completo de onboarding público transaccional, identidad global, invitaciones, aceptación,
contraseña inicial, baja/reactivación y cambio de rol de membresías. Se retiran los POST redundantes
de configuraciones y el alta genérica de membresías. Se mantienen JWT, aislamiento tenant, roles,
históricos, auditoría y servicios del ciclo operativo de tareas.

## Arquitectura

- `OnboardingService::registrarPublico()` amplía el servicio existente y reutiliza `crearOnboarding()`.
  No duplica su construcción del grafo fiscal, local, configuraciones y membresía.
- `TransaccionAPPCC` conserva el control exterior de flush/commit, rollback y traducción de UNIQUE/conflictos a 409.
- `TenantAuthorization` centraliza la gestión ADMIN y reconsulta permisos tras esperar el bloqueo.
  `CurrentEstablecimientoContext` y `TenantExtension` se reutilizan sin cambios.
- `InvitacionUsuarioService`, `PasswordInicialService` y `MembresiaService` implementan los nuevos casos de uso.
- DTO específicos cierran los campos de entrada; processors delegan en dominio. Los campos desconocidos reciben 400.
- `Usuario.password` ya era nullable y `UsuarioChecker` ya rechazaba cuentas pendientes. Se preservan ambos contratos.
- `EmailUsuario` centraliza la normalización para identidad, invitaciones, login y comando.
- `ProtegerRespuestaCredenciales` marca las respuestas iniciales `private, no-store`.

## Migración

[`Version20260913205925`](../migrations/Version20260913205925.php) añade:

- Tablas `invitacion_usuario` y `token_configuracion_password`.
- FK con RESTRICT e índices de relación.
- UNIQUE de hashes e índice parcial único de invitaciones pendientes por establecimiento/email.
- CHECK de emails normalizados, hashes, estados, roles y fechas.
- Normalización de emails existentes; aborta si hay identidades equivalentes que necesiten resolución explícita.
- `down()` que impide destruir invitaciones o tokens existentes.

No se modifica ninguna migración publicada. Se conservan los UNIQUE existentes de NIF, usuario/email,
usuario/establecimiento y ambas configuraciones, sin duplicarlos.
La migración está aplicada y el esquema validado en PostgreSQL 18.3, tanto en pruebas como en desarrollo.

## Endpoints

POST usa `application/ld+json`; PATCH usa `application/merge-patch+json`.
“JWT + tenant” exige `Authorization: Bearer …` y `X-Establecimiento-Id`.

| Método | URI | Autenticación | Rol | Input | Éxito |
|---|---|---|---|---|---|
| POST | `/api/onboarding` | Pública | ADMIN fijado por servidor | `entidadFiscal`, `establecimiento`, `administrador` | 201 |
| POST | `/api/auth/configurar-password` | Token público | — | `token`, `password`, `passwordConfirmation` | 204 |
| POST | `/api/invitaciones` | JWT + tenant | ADMIN | `email`, `rol` | 201 |
| GET | `/api/invitaciones` | JWT + tenant | ADMIN | Paginación | 200 |
| GET | `/api/invitaciones/{id}` | JWT + tenant | ADMIN | — | 200 |
| POST | `/api/invitaciones/aceptar` | Token público | Rol guardado en invitación | `token`; `nombre`, `apellidos` para identidad nueva | 200 |
| POST | `/api/invitaciones/{id}/cancelar` | JWT + tenant | ADMIN | — | 200 |
| POST | `/api/usuarios-establecimientos/{id}/baja` | JWT + tenant | ADMIN | — | 200 |
| POST | `/api/usuarios-establecimientos/{id}/reactivar` | JWT + tenant | ADMIN | — | 200 |
| PATCH, restringido | `/api/usuarios-establecimientos/{id}` | JWT + tenant | ADMIN | Solo `rol` | 200 |

Operaciones eliminadas:

| Método | URI | Autenticación/rol/input | Resultado |
|---|---|---|---|
| POST | `/api/configuraciones-entidad-fiscal` | Ya no existe operación | 404/405 |
| POST | `/api/configuraciones-establecimiento` | Ya no existe operación | 404/405 |
| POST | `/api/usuarios-establecimientos` | Alta mediante invitación | 404/405 |

GET/PATCH de configuraciones se conservan. No pueden cambiar de entidad fiscal o establecimiento.
`POST /api/usuarios` y DELETE de usuarios/membresías siguen deshabilitados.

Errores comprobados: 401 sin JWT; 400 sin cabecera o JSON inválido; 403 por membresía/rol;
404 para un recurso ajeno; 409 por duplicado/consumo/conflicto; 422 por validación o regla de negocio.
Los tres POST públicos no requieren cabecera tenant.

## Modelo de invitación

`InvitacionUsuario` contiene establecimiento, email, rol, hash, estado, expiración, autor y fechas.
Estados: `pendiente`, `aceptada`, `cancelada`, `expirada`.

Una pendiente vigente puede aceptarse o cancelarse; fija respectivamente `acceptedAt` o `cancelledAt`.
Una pendiente vencida se materializa como expirada al crear una nueva del mismo establecimiento/email.
Su aceptación/cancelación ya devuelve 422 aunque todavía figure pendiente.
Los estados terminales no permiten otra transición. No existe PATCH genérico del estado.

Una identidad existente se reutiliza sin cambiar nombre, contraseña ni actividad global.
Una membresía inactiva se reactiva conservando su ID; una activa produce 409.
Un usuario global inactivo produce 422 y permanece inactivo.

## Contraseñas y tokens

Ambos secretos utilizan `random_bytes(32)` (256 bits), codificados en hexadecimal.
Solo se persiste SHA-256; el valor real únicamente aparece en la respuesta inicial.
No se registra desde los servicios ni se devuelve en GET.

Las invitaciones caducan a los siete días; la configuración inicial de contraseña, a las 24 horas.
El consumo bloquea/recarga el token y el usuario, comprueba estado, expiración y actividad,
valida 12–72 bytes y confirmación, y usa `UserPasswordHasherInterface`.
Hash de contraseña y consumo se confirman atómicamente.
Ni el mismo token ni otro token de alta del usuario pueden reemplazar una contraseña ya configurada.

## Concurrencia

- **Onboarding:** advisory lock por email compartido con aceptación; UNIQUE fiscal protege NIF.
  Dos peticiones equivalentes terminan en 201/409 y un solo grafo.
- **Crear invitación:** bloqueo del establecimiento e índice parcial PostgreSQL; 201/409.
- **Aceptar:** establecimiento, invitación FOR UPDATE y bloqueo de identidad por email.
  Una invitación se acepta una vez (200/409); invitaciones a dos locales crean una sola identidad.
- **Contraseña:** token y usuario FOR UPDATE, un solo consumo (204/409).
- **Último ADMIN:** serialización por establecimiento, permisos y recuento SQL revalidados.
  Dos bajas/degradaciones propias producen 200/422. En bajas mutuas, el perdedor obtiene 403
  porque su membresía ya quedó inactiva. Nunca quedan cero ADMIN activos.
- **Tareas y baja:** bloquea las ejecuciones ya asignadas antes de la membresía y actualiza
  condicionalmente al final. Una asignación que espera la baja vuelve a verificar la pertenencia.

Los tests arrancan procesos PHP separados y verifican `wait_event_type = 'Lock'` en
`pg_stat_activity` antes de liberar el bloqueo padre.
Las rutas se preparan antes de arrancar los workers para evitar una carrera de archivos de caché en Windows.

## Multiempresa

El establecimiento autenticado procede de `CurrentEstablecimientoContext`; la gestión requiere ADMIN
según `TenantAuthorization`, incluyendo una comprobación fresca dentro de la transacción.
Las colecciones usan `TenantExtension` antes de paginar; acciones buscan por ID y tenant.
Las operaciones públicas por token toman el establecimiento exclusivamente de la invitación.

## TareaProgramada

**Dar de baja un trabajador desasigna PENDIENTE/VENCIDA y preserva COMPLETADA/OMITIDA.**

No cambia `Usuario.activo`, no borra filas y conserva registros, incidencias, omisiones e historial.
Baja y actualización SQL comparten rollback. Reactivar no reasigna tareas anteriores.
No se modifican los servicios de generación, reconciliación, registro, asignación, omisión ni sus migraciones.

## Tests y validación exacta

Ejecutado con PHP 8.3.11 y PostgreSQL 18.3, `APP_ENV=test`, base independiente y allowlist `PostgresSafety`.

```text
OK (261 tests, 1211 assertions)

composer validate --strict                              -> OK
php bin/console lint:container                           -> OK
php bin/console doctrine:migrations:migrate --no-interaction -> OK
php bin/console doctrine:schema:validate                 -> OK
php bin/phpunit                                         -> OK
```

Sin fallos ni tests omitidos. 59 tests más que el baseline de 202; las aserciones superan 837.
También se ejecutaron migraciones y schema:validate en la base local de desarrollo.
El log local de la suite está en `backend/var/validacion-final-usuarios.log` (ignorado por Git).

El workflow existente de GitHub Actions conserva PHP 8.3, PostgreSQL 18 y los mismos cinco comandos.
Descubre automáticamente las nuevas pruebas/migración; no necesita dependencias, proveedores externos ni pcntl.
Se ha revisado su compatibilidad; no se afirma una ejecución remota nueva de Actions porque no se hizo push.

## Tests existentes

**No se han eliminado tests ni reducido aserciones para ocultar fallos.**

Dos adaptaciones justificadas:

1. `SecurityTest::testAdminGestionaMembresiasYResponsableNo`: prepara un segundo ADMIN para poder
   degradar al primero sin infringir la nueva regla. Conserva las dos aserciones originales.
   Nuevos tests comprueban explícitamente el último ADMIN y las carreras.
2. `AdministradorCommandTest`: conserva las siete aserciones y la prueba con pregunta oculta en Linux.
   En Windows sustituye la omisión anterior por un proceso real con `--password-stdin`.

## Archivos creados (35)

- [backend/docs/entrega-usuarios-onboarding.md](../docs/entrega-usuarios-onboarding.md)
- [backend/docs/usuarios-onboarding.md](../docs/usuarios-onboarding.md)
- [backend/migrations/Version20260913205925.php](../migrations/Version20260913205925.php)
- [backend/src/Dto/AceptarInvitacionInput.php](../src/Dto/AceptarInvitacionInput.php)
- [backend/src/Dto/AdministradorInput.php](../src/Dto/AdministradorInput.php)
- [backend/src/Dto/AltaUsuarioOutput.php](../src/Dto/AltaUsuarioOutput.php)
- [backend/src/Dto/CambiarRolInput.php](../src/Dto/CambiarRolInput.php)
- [backend/src/Dto/ConfigurarPasswordInput.php](../src/Dto/ConfigurarPasswordInput.php)
- [backend/src/Dto/CrearInvitacionInput.php](../src/Dto/CrearInvitacionInput.php)
- [backend/src/Dto/EntidadFiscalInput.php](../src/Dto/EntidadFiscalInput.php)
- [backend/src/Dto/EstablecimientoInput.php](../src/Dto/EstablecimientoInput.php)
- [backend/src/Dto/InvitacionCreadaOutput.php](../src/Dto/InvitacionCreadaOutput.php)
- [backend/src/Dto/OnboardingInput.php](../src/Dto/OnboardingInput.php)
- [backend/src/Entity/InvitacionUsuario.php](../src/Entity/InvitacionUsuario.php)
- [backend/src/Entity/TokenConfiguracionPassword.php](../src/Entity/TokenConfiguracionPassword.php)
- [backend/src/Enum/EstadoInvitacion.php](../src/Enum/EstadoInvitacion.php)
- [backend/src/EventListener/ProtegerRespuestaCredenciales.php](../src/EventListener/ProtegerRespuestaCredenciales.php)
- [backend/src/Service/InvitacionUsuarioService.php](../src/Service/InvitacionUsuarioService.php)
- [backend/src/Service/MembresiaService.php](../src/Service/MembresiaService.php)
- [backend/src/Service/PasswordInicialService.php](../src/Service/PasswordInicialService.php)
- [backend/src/Service/Support/EmailUsuario.php](../src/Service/Support/EmailUsuario.php)
- [backend/src/State/Processor/AceptarInvitacionProcessor.php](../src/State/Processor/AceptarInvitacionProcessor.php)
- [backend/src/State/Processor/CancelarInvitacionProcessor.php](../src/State/Processor/CancelarInvitacionProcessor.php)
- [backend/src/State/Processor/ConfigurarPasswordProcessor.php](../src/State/Processor/ConfigurarPasswordProcessor.php)
- [backend/src/State/Processor/CrearInvitacionProcessor.php](../src/State/Processor/CrearInvitacionProcessor.php)
- [backend/src/State/Processor/MembresiaProcessor.php](../src/State/Processor/MembresiaProcessor.php)
- [backend/src/State/Processor/OnboardingProcessor.php](../src/State/Processor/OnboardingProcessor.php)
- [backend/tests/ConcurrenciaUsuariosTest.php](../tests/ConcurrenciaUsuariosTest.php)
- [backend/tests/ConfiguracionesApiTest.php](../tests/ConfiguracionesApiTest.php)
- [backend/tests/IntegridadUsuariosTest.php](../tests/IntegridadUsuariosTest.php)
- [backend/tests/InvitacionesTest.php](../tests/InvitacionesTest.php)
- [backend/tests/MembresiasTest.php](../tests/MembresiasTest.php)
- [backend/tests/OnboardingPasswordTest.php](../tests/OnboardingPasswordTest.php)
- [backend/tests/Support/UsuariosApiTestCase.php](../tests/Support/UsuariosApiTestCase.php)
- [backend/tests/Support/usuarios-worker.php](../tests/Support/usuarios-worker.php)

## Archivos modificados (15)

- [backend/config/packages/api_platform.yaml](../config/packages/api_platform.yaml)
- [backend/config/packages/security.yaml](../config/packages/security.yaml)
- [backend/config/services.yaml](../config/services.yaml)
- [backend/docs/configuracion-saas.md](../docs/configuracion-saas.md)
- [backend/docs/modelo-mvp.md](../docs/modelo-mvp.md)
- [backend/src/Command/CrearAdministradorCommand.php](../src/Command/CrearAdministradorCommand.php)
- [backend/src/Entity/ConfiguracionEntidadFiscal.php](../src/Entity/ConfiguracionEntidadFiscal.php)
- [backend/src/Entity/ConfiguracionEstablecimiento.php](../src/Entity/ConfiguracionEstablecimiento.php)
- [backend/src/Entity/Usuario.php](../src/Entity/Usuario.php)
- [backend/src/Entity/UsuarioEstablecimiento.php](../src/Entity/UsuarioEstablecimiento.php)
- [backend/src/EventListener/NormalizeLoginEmail.php](../src/EventListener/NormalizeLoginEmail.php)
- [backend/src/Security/TenantAuthorization.php](../src/Security/TenantAuthorization.php)
- [backend/src/Service/OnboardingService.php](../src/Service/OnboardingService.php)
- [backend/tests/AdministradorCommandTest.php](../tests/AdministradorCommandTest.php)
- [backend/tests/SecurityTest.php](../tests/SecurityTest.php)

## Pendientes fuera del MVP

No existe infraestructura de correo en este backend: el envío real queda pendiente de integración.
El MVP entrega los tokens solo en las respuestas iniciales. No se implementan frontend,
recuperación/reemisión de contraseña ni otros sistemas de autenticación.
El flujo completo y ejemplos HTTP/JSON están en [usuarios-onboarding.md](usuarios-onboarding.md).

