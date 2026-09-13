# Usuarios y onboarding

Este bloque amplía el backend de `4e9cdb07ad5b7b2b69201e9a6544cb7ae502307a`.
Mantiene JWT/Lexik, los roles de `UsuarioEstablecimiento`, PostgreSQL 18 y el ciclo operativo de tareas.

## Identidad y permisos

`Usuario` es la identidad global: email único normalizado, nombre, apellidos, actividad y hash de contraseña.
`UsuarioEstablecimiento` es una pertenencia única `(usuario, establecimiento)` con rol y actividad propios.
La baja afecta exclusivamente a esa pertenencia. No elimina usuarios, membresías ni relaciones históricas,
ni desactiva la identidad global o sus pertenencias a otros establecimientos.

Solo ADMIN gestiona invitaciones y membresías. RESPONSABLE, TRABAJADOR y AUDITOR no pueden crearlas,
cancelarlas, dar bajas, reactivar ni cambiar roles. Las invitaciones solo son consultables por ADMIN.
Se conservan los permisos de consulta de usuarios/membresías y configuración que ya existían.

Las operaciones autenticadas requieren JWT y `X-Establecimiento-Id`.
`CurrentEstablecimientoContext` valida usuario, pertenencia, establecimiento y entidad fiscal activos.
`TenantAuthorization::assertGestionUsuarios()` centraliza ADMIN y vuelve a consultar los permisos
tras adquirir el bloqueo del establecimiento: un snapshot anterior a una baja no concede acceso.
`TenantExtension` filtra las colecciones e ítems en SQL antes de paginarlos; los servicios de acciones
buscan por `id + establecimiento`. Un ID ajeno devuelve 404.

## Operaciones HTTP

Enviar `Content-Type: application/ld+json` y `Accept: application/ld+json` en POST.
PATCH utiliza `Content-Type: application/merge-patch+json`.

| Método | URI | Autenticación y rol | Input permitido | Éxito |
|---|---|---|---|---|
| POST | `/api/onboarding` | Pública, sin cabecera tenant | `entidadFiscal`, `establecimiento`, `administrador` | 201 |
| POST | `/api/auth/configurar-password` | Pública, token, sin cabecera tenant | `token`, `password`, `passwordConfirmation` | 204 sin cuerpo |
| POST | `/api/invitaciones` | JWT + tenant, ADMIN | `email`, `rol` | 201 |
| GET | `/api/invitaciones` | JWT + tenant, ADMIN | Paginación API Platform | 200 |
| GET | `/api/invitaciones/{id}` | JWT + tenant, ADMIN | Sin cuerpo | 200 |
| POST | `/api/invitaciones/aceptar` | Pública, token, sin cabecera tenant | `token`, `nombre`, `apellidos` | 200 |
| POST | `/api/invitaciones/{id}/cancelar` | JWT + tenant, ADMIN | Sin cuerpo | 200 |
| POST | `/api/usuarios-establecimientos/{id}/baja` | JWT + tenant, ADMIN | Sin cuerpo | 200 |
| POST | `/api/usuarios-establecimientos/{id}/reactivar` | JWT + tenant, ADMIN | Sin cuerpo | 200 |
| PATCH | `/api/usuarios-establecimientos/{id}` | JWT + tenant, ADMIN | Solo `rol` | 200 |

Las rutas de membresía conservan el plural `usuarios-establecimientos` existente.
El PATCH de rol ya existía y ahora usa DTO y servicio transaccional.

| Situación | HTTP |
|---|---|
| Sin JWT en una operación protegida | 401 |
| Sin cabecera tenant o con formato incorrecto | 400 |
| Membresía inactiva, contexto inactivo o rol insuficiente | 403 |
| Recurso ajeno o inexistente dentro del tenant | 404 |
| Campo JSON desconocido o tipo/enum no deserializable | 400 |
| Validación o regla de negocio, último ADMIN, token inválido/expirado | 422 |
| Duplicado, recurso ya consumido, transición repetida o conflicto concurrente | 409 |

Solo los tres POST públicos de esta tabla y el login están exceptuados en `security.yaml`.
No se abren otros métodos ni rutas `/api`. Los DTO públicos anulan explícitamente la seguridad
predeterminada de API Platform sin invocar el contexto tenant.

## Onboarding completo

```http
POST /api/onboarding
Content-Type: application/ld+json
Accept: application/ld+json

{
  "entidadFiscal": {
    "tipo": "empresa",
    "nif": "B99887766",
    "razonSocial": "Restaurante Ejemplo SL",
    "direccion": "Mayor 1",
    "codigoPostal": "28001",
    "localidad": "Madrid",
    "provincia": "Madrid"
  },
  "establecimiento": {
    "nombre": "Restaurante Ejemplo",
    "tipoActividad": "restaurante",
    "direccion": "Mayor 1",
    "codigoPostal": "28001",
    "localidad": "Madrid",
    "provincia": "Madrid"
  },
  "administrador": {
    "nombre": "María",
    "apellidos": "López",
    "email": "maria@example.com"
  }
}
```

La entidad fiscal admite también `nombreComercial`, `nombre`, `apellidos`, `telefono`, `email`.
Para `tipo: autonomo`, nombre y apellidos son obligatorios, de acuerdo con la validación existente.
El establecimiento admite además `telefono`, `email`, `registroSanitario`.
Los valores de tipo fiscal, actividad y rol reutilizan los enums existentes.

El processor llama a `OnboardingService::registrarPublico()`, que reutiliza `crearOnboarding()`.
En una única `TransaccionAPPCC` crea entidad fiscal, configuración fiscal, establecimiento,
configuración de establecimiento, usuario sin contraseña, membresía ADMIN activa y token inicial.
Se conserva el caso interno existente de creación controlada de otro establecimiento y aplicación
de plantillas; no se expone como una forma pública de vincular identidades existentes.

El cliente no puede enviar IDs, relaciones, actividad, roles globales, contraseña, fechas ni rol inicial.
El servidor fija ADMIN. Email existente o NIF existente devuelven 409, sin crear empresas ni membresías.
La normalización central de email es `trim + strtolower`, compartida por Usuario, login, comando,
invitaciones y onboarding. NIF conserva `trim + strtoupper`.

La respuesta 201 contiene `usuarioId`, `establecimientoId`, `membresiaId`,
`tokenConfiguracionPassword` y `passwordExpiresAt` (además de metadatos JSON-LD).
Solo esa respuesta entrega el secreto inicial; no hay endpoint para recuperarlo.

## Configuración inicial de contraseña

```http
POST /api/auth/configurar-password
Content-Type: application/ld+json

{
  "token": "<tokenConfiguracionPassword recibido en el alta>",
  "password": "Una frase privada de al menos 12 bytes",
  "passwordConfirmation": "Una frase privada de al menos 12 bytes"
}
```

Éxito: 204. Después se utiliza el contrato existente `POST /api/login_check` con email y contraseña.
La cuenta pendiente tiene `Usuario.password = null`; `UsuarioChecker` ya impide autenticación por login
y JWT para esa cuenta. No se introduce contraseña ficticia ni se modifica el mapping nullable existente.

Ambos tipos de token se generan con `bin2hex(random_bytes(32))`: 256 bits de entropía.
PostgreSQL guarda únicamente SHA-256, con UNIQUE y CHECK de 64 caracteres hexadecimales.
`TokenConfiguracionPassword` no es un recurso API; el hash de invitación se excluye de serialización.
Los secretos no se pasan a SQL, ni se registran desde los servicios, ni se devuelven en GET.
Las respuestas iniciales se marcan `private, no-store` para impedir su almacenamiento en caché.
El despliegue debe mantener desactivada la captura de cuerpos de estas peticiones/respuestas en logs
o herramientas de diagnóstico; en producción se usa `APP_DEBUG=0`.

La contraseña inicial caduca a las **24 horas**. Su consumo bloquea primero el token `FOR UPDATE`,
lo recarga, comprueba uso y expiración, bloquea/recarga el usuario y exige actividad y contraseña aún nula.
Valida entre 12 y 72 bytes y confirmación exacta; el límite superior evita truncamiento con bcrypt.
`UserPasswordHasherInterface` aplica el algoritmo configurado por Symfony. Hash de contraseña y
`consumedAt` se confirman juntos. Un segundo token de alta del mismo usuario tampoco puede reemplazar
una contraseña ya configurada. Esto no es recuperación de contraseña.

## Invitación y aceptación

```http
POST /api/invitaciones
Authorization: Bearer <jwt-admin>
X-Establecimiento-Id: 1
Content-Type: application/ld+json

{"email":"trabajador@example.com","rol":"trabajador"}
```

Respuesta 201: `id`, `email` normalizado, `rol`, `token`, `expiresAt`.
El establecimiento procede exclusivamente de la cabecera y `invitadaPor` del JWT.
La invitación caduca a los **7 días**. Una membresía activa ya existente devuelve 409.
Una membresía inactiva permite invitar y se reutiliza al aceptar, manteniendo su ID e histórico.

```http
POST /api/invitaciones/aceptar
Content-Type: application/ld+json

{"token":"<token de invitación>","nombre":"Juan","apellidos":"Pérez"}
```

Nombre y apellidos son obligatorios al crear una identidad nueva. Si el email ya existe, se reutiliza
el usuario y estos campos no cambian sus datos. Un usuario global inactivo produce 422; nunca se reactiva
silenciosamente. La aceptación no pide JWT ni tenant: la invitación identifica el establecimiento.
Aplica el rol guardado en ella. Un `rol` enviado en esta petición se rechaza.

La respuesta 200 tiene la misma estructura de alta que onboarding. Solo incluye un token inicial útil
cuando el usuario todavía carece de contraseña; para una identidad con contraseña, los campos de token
y caducidad son nulos (el serializador puede omitirlos).

| Estado | Transición permitida |
|---|---|
| `pendiente`, vigente | `aceptada` al aceptar; establece `acceptedAt` |
| `pendiente`, vigente | `cancelada` por ADMIN; establece `cancelledAt` |
| `pendiente`, vencida | `expirada` al intentar crear una nueva para el mismo local/email |
| `aceptada`, `cancelada`, `expirada` | Sin transiciones posteriores |

Una invitación pendiente vencida devuelve 422 al aceptar/cancelar, aunque todavía no se haya materializado
`expirada`. Al crear otra se actualizan las vencidas antes del INSERT, liberando el índice parcial.
No hay PATCH genérico de estado. Cancelar una aceptada/cancelada devuelve 409; cancelar una expirada, 422.
El índice parcial `uniq_invitacion_pendiente` impide dos pendientes de `(establecimiento, email)`.

No hay infraestructura de correo instalada. El MVP entrega el secreto una sola vez en la respuesta inicial
para facilitar su entrega al destinatario. **El envío de email queda pendiente de integración**; no se añade
un proveedor externo. No hay endpoint de recuperación del token en claro.

## Bajas, reactivación y último ADMIN

```http
POST /api/usuarios-establecimientos/25/baja
Authorization: Bearer <jwt-admin>
X-Establecimiento-Id: 1
```

La baja cambia la membresía a `activo=false` y desasigna mediante SQL condicional las ejecuciones
`PENDIENTE` y `VENCIDA` del mismo establecimiento: `asignadoA=null`.
Conserva íntegramente `COMPLETADA` y `OMITIDA`, sus asignaciones, autores, registros, incidencias y auditorías.
La baja y la desasignación comparten transacción; un fallo de la actualización revierte la baja.
La actualización no hidrata la agenda; solo refresca las entidades que el llamador ya tenía en memoria.

```http
POST /api/usuarios-establecimientos/25/reactivar
Authorization: Bearer <jwt-admin>
X-Establecimiento-Id: 1
```

Solo admite una membresía inactiva de un usuario global activo. Mantiene el mismo ID y rol.
No recupera asignaciones antiguas. Repetir una baja o reactivar una activa devuelve 409.

```http
PATCH /api/usuarios-establecimientos/25
Authorization: Bearer <jwt-admin>
X-Establecimiento-Id: 1
Content-Type: application/merge-patch+json

{"rol":"responsable"}
```

El PATCH solo acepta `rol`. Ni este PATCH ni las acciones cambian usuario o establecimiento.
Antes de dar de baja o degradar un ADMIN activo se comprueba que permanezca otro ADMIN activo cuya
identidad global también esté activa. El último devuelve 422. Dos bajas/degradaciones propias concurrentes
producen 200 y 422; en bajas mutuas, la segunda puede devolver 403 porque su autor acaba de perder acceso.

## Transacciones y concurrencia

Todos los casos de escritura utilizan `TransaccionAPPCC`, incluido crear/cancelar invitación.
La transacción exterior hace flush/commit y traduce UNIQUE, deadlock, serialization failure y conflicto
optimista a 409. Un fallo deja íntegro el estado previo. No se añade una abstracción transaccional duplicada.

| Caso | Protección |
|---|---|
| Onboarding del mismo email | Advisory lock transaccional sobre email normalizado y comprobación de existencia |
| Onboarding del mismo NIF | UNIQUE fiscal existente; el perdedor hace rollback completo y devuelve 409 |
| Crear invitación | Bloqueo del establecimiento, caducidad previa al INSERT y UNIQUE parcial PostgreSQL |
| Aceptar/cancelar | Establecimiento `FOR NO KEY UPDATE`, invitación `FOR UPDATE`, recarga y validación del estado |
| Identidad compartida por invitaciones de diferentes locales | Advisory lock de email, igual al onboarding; fila de usuario bajo bloqueo |
| Configurar contraseña | Token `FOR UPDATE`, usuario `FOR UPDATE`; consumo y hash en un commit |
| Último ADMIN, reactivación y rol | Establecimiento `FOR NO KEY UPDATE`, recuento SQL y membresía `FOR UPDATE` |
| Baja frente a tareas | Ejecuciones abiertas ya asignadas se bloquean antes de la membresía, siguiendo el orden de asignar/omitir; actualización condicional al final |

Tras esperar un bloqueo se vuelve a leer el estado real. Los bloqueos del establecimiento coordinan creación,
aceptación, cancelación y gestión de membresías. `FOR NO KEY UPDATE` evita interferir innecesariamente con
los bloqueos de clave de las FK. Una contención con otros casos operativos que cause deadlock se traduce a
409 y permite repetir la petición, conservando el rollback. No se cambian los servicios del ciclo operativo.

## Configuraciones y migración

Se eliminan estos POST independientes:

- `/api/configuraciones-entidad-fiscal`
- `/api/configuraciones-establecimiento`
- `/api/usuarios-establecimientos` (el alta normal utiliza invitación).

Se conservan GET de colección/ítem y PATCH de configuraciones, sus permisos y el origen inmutable.
`POST /api/usuarios` continúa deshabilitado. No se exponen DELETE de identidades ni pertenencias.
Las configuraciones nacen en el onboarding/creación controlada y mantienen sus UNIQUE originales.

`Version20260913205925` añade `invitacion_usuario` y `token_configuracion_password`, FK RESTRICT,
índices de acceso, UNIQUE de hashes, UNIQUE parcial de pendientes y CHECK de emails, estados, roles,
hashes y fechas. Reutiliza sin duplicar los UNIQUE de NIF, usuario/email, membresía y configuraciones.
Normaliza emails heredados y aborta antes si hay identidades equivalentes; no fusiona ni borra personas.
La normalización se conserva al revertir. `down()` rechaza eliminar invitaciones/tokens existentes y
permite revertir una base vacía, como las migraciones de seguridad anteriores.
No modifica las migraciones publicadas hasta `Version20260913100000`.

## Validación

Las pruebas usan PostgreSQL real con `PostgresSafety`, no SQLite. Cubren HTTP/JWT, identidades existentes,
mass assignment, aislamiento, estados, hashes, unicidad SQL, rollback forzado al final del alta/aceptación,
al consumir contraseña y al desasignar, además de conservación de registros e historial.
`ConcurrenciaUsuariosTest` inicia procesos PHP independientes y espera a que `pg_stat_activity` confirme
su bloqueo antes de liberar la transacción padre. Comprueba el resultado HTTP y las filas finales.

Se adapta la preparación de `SecurityTest::testAdminGestionaMembresiasYResponsableNo`: añade un
segundo ADMIN antes de degradar al primero para respetar la nueva regla. Conserva las dos aserciones
originales (ADMIN cambia rol; RESPONSABLE no puede volver a ADMIN). Las pruebas nuevas comprueban el
rechazo del último administrador. No se eliminan tests ni se reducen aserciones para ocultar fallos.
`AdministradorCommandTest` deja de omitirse en Windows: utiliza el proceso CLI con `--password-stdin`,
conserva las siete aserciones y mantiene la prueba de pregunta oculta con CommandTester en Linux.

Comandos, iguales a los del CI PHP 8.3 / PostgreSQL 18:

```bash
composer validate --strict
php bin/console lint:container
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/phpunit
```

Para ejecutar todo sobre la base de pruebas, establecer `APP_ENV=test` y las variables de conexión y
allowlist existentes. En PowerShell puede cargarse `bin/local-env.ps1` en el proceso para sodium/OpenSSL.
El workflow existente descubre automáticamente los nuevos tests y la migración; no necesita servicios
externos, pcntl ni cambios de dependencias.

Fuera de este bloque quedan el correo real, las pantallas y la recuperación/reemisión de credenciales
perdidas o caducadas. Una invitación puede volver a emitirse tras cancelar/caducar; la recuperación de una
cuenta que ya completó onboarding requiere un caso posterior, no repetir públicamente el onboarding.
