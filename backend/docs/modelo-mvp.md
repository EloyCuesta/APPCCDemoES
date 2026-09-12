# Modelo MVP APPCC

Estado validado de fase 1, sobre `main` desde `3fee215`. Backend PHP 8.3,
Symfony 7.4, API Platform 4.3, Doctrine ORM 3.7/DBAL 4.4 y PostgreSQL **18.3**.
Se mantienen identificadores enteros y las tres migraciones existentes, que estaban
aplicadas en desarrollo. Las tablas operativas consultadas estaban vacías.

## Entidades y relaciones

Se conservan EntidadFiscal, Establecimiento, Usuario, UsuarioEstablecimiento,
PlanControl, PuntoControl, TareaAPPCC, RegistroAPPCC, Incidencia, AccionCorrectiva,
PlantillaAPPCC y las configuraciones fiscal y de establecimiento.
Se añaden TareaProgramada, Evidencia e HistorialIncidencia, con repositorios propios.

```mermaid
erDiagram
    TareaAPPCC ||--o{ TareaProgramada : define
    Establecimiento ||--o{ TareaProgramada : agenda
    Usuario o|--o{ TareaProgramada : asignado
    TareaProgramada ||--o| RegistroAPPCC : registra
    RegistroAPPCC ||--o| Incidencia : origina
    Incidencia ||--o{ AccionCorrectiva : corrige
    Incidencia ||--o{ HistorialIncidencia : transiciones
    RegistroAPPCC o|--o{ Evidencia : adjunta
    Incidencia o|--o{ Evidencia : adjunta
```

TareaAPPCC es una **definición recurrente**, con frecuencia, límites e instrucciones.
TareaProgramada representa una ejecución concreta. RegistroAPPCC referencia de forma
obligatoria y única la ejecución y conserva establecimiento y usuario.
La definición se obtiene mediante `getTarea()` de solo lectura.

`TareaProgramadaService::programar()` crea explícitamente una ocurrencia idempotente
por definición y fecha, con precisión de segundos. Un bloqueo sobre la definición y
UNIQUE protegen la concurrencia. No hay cron, Messenger ni generación automática,
tampoco para turnos, recepciones o demanda. El servicio permite gestionar estados y
detectar vencidas. La agenda consulta ocurrencias pendientes/vencidas hasta la fecha.

Registrar un control bloquea la ejecución, valida plazo/pertenencia y calcula la
conformidad numérica/booleana. Registro, COMPLETADA, completadaAt, incidencia automática
e historial se confirman juntos. Las violaciones de unicidad se traducen a HTTP 409.
Los valores decimales siguen siendo strings NUMERIC(12,3); no se recalculan históricos.

Evidencia guarda **solo metadatos**, con exactamente un padre (registro o incidencia),
FK RESTRICT, tamaño positivo y SHA-256 opcional. Symfony y CHECK PostgreSQL validan XOR.
No hay subida, lectura de archivos, S3 ni garantía atómica de `requiereFotoNoConforme`
o firma. HistorialIncidencia es de solo lectura por API; servicio y trigger PostgreSQL
impiden actualizar o borrar entradas. No se usan cascade remove ni orphanRemoval.

## Migraciones

El repositorio contiene actualmente cinco migraciones:

1. `Version20260911130812`: crea `EntidadFiscal`.
2. `Version20260911132708`: crea el modelo APPCC inicial.
3. `Version20260911151914`: añade las configuraciones de entidad fiscal y establecimiento.
4. `Version20260912083224`: introduce `TareaProgramada`, `Evidencia` e `HistorialIncidencia`, migra `RegistroAPPCC` para referenciar una ejecución concreta y añade restricciones e índices.
5. `Version20260912084920`: añade autenticación a `Usuario` mediante los campos `password` y `roles`.

La cuarta migración realiza un backfill de los registros existentes. Por cada `RegistroAPPCC` anterior crea una `TareaProgramada` completada conservando la definición de tarea, establecimiento, usuario, fecha y resultado.

Antes de eliminar la relación antigua comprueba que el backfill se haya realizado completamente. También bloquea la migración si existen duplicados históricos que requieran una decisión manual de conservación.

La relación entre `RegistroAPPCC` y `TareaProgramada` es única: una ejecución solo puede producir un registro. Igualmente, un registro solo puede originar una incidencia vinculada mediante la restricción `uniq_incidencia_registro`.

El historial de incidencias es append-only. La aplicación no permite modificarlo directamente y PostgreSQL dispone además de un trigger que impide `UPDATE` y `DELETE`.

Las migraciones están diseñadas específicamente para PostgreSQL. El estado real de cada entorno debe comprobarse antes de ejecutar cambios:

```powershell
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:list
```

No debe utilizarse `doctrine:schema:update --force` para sustituir las migraciones.

## Autenticación y aislamiento multiempresa

La API utiliza autenticación JWT mediante `LexikJWTAuthenticationBundle`.

El inicio de sesión se realiza mediante:

```text
POST /api/login_check
```

con correo electrónico y contraseña.

Todas las rutas `/api` requieren un usuario autenticado con `ROLE_USER`, salvo el endpoint de login.

Las peticiones autenticadas deben indicar además el establecimiento sobre el que trabaja el usuario:

```http
X-Establecimiento-Id: 123
```

El backend valida que:

* el usuario esté activo;
* el establecimiento exista y esté activo;
* la entidad fiscal asociada esté activa;
* exista una membresía activa entre el usuario y el establecimiento solicitado.

El identificador recibido en `X-Establecimiento-Id` no concede acceso por sí mismo.

`CurrentEstablecimientoContext` resuelve el establecimiento actual y su membresía. `TenantExtension` aplica el ámbito directamente sobre las consultas Doctrine para evitar que colecciones, elementos individuales e IRI puedan acceder a datos pertenecientes a otro establecimiento.

Las operaciones de escritura pasan además por `TenantAuthorization`.

Los roles empresariales se mantienen en `UsuarioEstablecimiento`:

* `ADMIN`
* `RESPONSABLE`
* `TRABAJADOR`
* `AUDITOR`

Los roles globales de Symfony almacenados en `Usuario.roles` se reservan para permisos generales de plataforma.

De forma general:

* `ADMIN` puede gestionar configuración y membresías.
* `RESPONSABLE` puede gestionar configuración operativa, tareas e incidencias.
* `TRABAJADOR` puede ejecutar controles, registrar incidencias y acciones correctivas, pero no modificar configuración.
* `AUDITOR` dispone de acceso de lectura.

El backend evita además que un usuario suplante a otro al registrar controles, incidencias, acciones correctivas o evidencias. El usuario responsable de la operación se obtiene del JWT autenticado.

## JWT

Las claves JWT no se versionan.

El entorno espera:

```dotenv
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=
```

En una instalación nueva deben generarse las claves:

```powershell
php bin/console lexik:jwt:generate-keypair
```

Los archivos generados bajo `config/jwt/` están ignorados por Git.

## Pruebas

Las pruebas utilizan PHPUnit sobre una base PostgreSQL exclusiva de test.

La infraestructura de pruebas exige:

```text
APP_ENV=test
```

y una base cuyo nombre termine en `_test` y coincida exactamente con `APPCC_TEST_DATABASE`.

Esto evita que los tests destructivos puedan ejecutarse accidentalmente sobre la base de desarrollo.

Las pruebas cubren actualmente:

* entidades y relaciones;
* lógica de negocio;
* configuración;
* restricciones PostgreSQL;
* claves foráneas;
* índices;
* CHECK constraints;
* migraciones;
* backfill;
* rollback seguro;
* autenticación JWT;
* usuarios inactivos;
* selección de establecimiento;
* aislamiento entre tenants;
* permisos por rol;
* prevención de suplantación;
* CORS para el frontend.

Para ejecutar la validación:

```powershell
composer validate --strict
php bin/console lint:container

$env:APP_ENV='test'

php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/phpunit
```

Actualmente el repositorio no dispone de un workflow de GitHub Actions que ejecute automáticamente estas comprobaciones después de cada push. Por tanto, la existencia de las pruebas en el repositorio no equivale todavía a disponer de un check de CI asociado a cada commit.

## Funcionalidad pendiente

`TareaProgramadaService` permite crear ejecuciones concretas y detectar ejecuciones vencidas, pero no existe todavía generación recurrente automática basada en la frecuencia de `TareaAPPCC`.

No hay actualmente cron, Symfony Messenger ni proceso planificado que materialice automáticamente las tareas diarias, semanales, por turno o bajo demanda.

`Evidencia` representa actualmente los metadatos de una evidencia:

* tipo;
* `storageKey`;
* nombre original;
* MIME type;
* tamaño;
* SHA-256 opcional;
* usuario que la subió;
* registro o incidencia relacionada.

Todavía no existe almacenamiento físico de archivos, integración con S3 u otro object storage ni verificación de que `storageKey` corresponda realmente a un archivo existente.

Por ello, las opciones `requiereFotoNoConforme` y `requiereFirmaRegistro` todavía no pueden garantizarse de forma atómica.

También permanecen pendientes:

* generación automática de tareas programadas;
* almacenamiento y descarga real de evidencias;
* flujo de firma de registros;
* notificaciones;
* resumen diario;
* retención automática de registros;
* GitHub Actions / CI.
