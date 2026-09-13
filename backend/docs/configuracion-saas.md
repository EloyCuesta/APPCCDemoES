# Configuración y entorno

El backend utiliza PostgreSQL y Symfony con API Platform.

Véase [usuarios y onboarding](usuarios-onboarding.md) para el alta transaccional, invitaciones,
configuración inicial de contraseña y gestión de membresías. Las configuraciones fiscal y de
establecimiento se crean en ese flujo; sus POST independientes están eliminados.

Las credenciales reales de cada desarrollador deben almacenarse en archivos locales ignorados por Git. `.env` contiene valores de ejemplo y `.env.local` puede utilizarse para la conexión de desarrollo.

Para pruebas debe utilizarse una base PostgreSQL independiente mediante `.env.test.local`.

## Credenciales

Las credenciales que aparecieron en commits anteriores fueron eliminadas de los archivos actuales, pero el historial Git no ha sido reescrito.

Por tanto, cualquier contraseña o secreto que haya sido utilizado fuera de un entorno local de desarrollo debe considerarse expuesto y rotarse.

No deben almacenarse en Git:

```text
.env.local
.env.test.local
config/jwt/private.pem
config/jwt/public.pem
```

## Base de datos de pruebas

Doctrine añade el sufijo `_test` al nombre de la base cuando se utiliza el entorno de pruebas.

La infraestructura añade comprobaciones adicionales para evitar ejecutar operaciones destructivas sobre una base incorrecta.

Se exige:

```dotenv
APP_ENV=test
APPCC_TEST_DATABASE=nombre_real_de_la_base_test
```

La base debe ser PostgreSQL, terminar en `_test` y coincidir exactamente con `APPCC_TEST_DATABASE`.

Además, no puede ser la misma base configurada para desarrollo.

Los tests pueden realizar `TRUNCATE ... RESTART IDENTITY`, pero únicamente después de superar estas comprobaciones.

Nunca utilizan `database:drop` ni necesitan mostrar la URL de conexión.

## Autenticación JWT

La API utiliza `LexikJWTAuthenticationBundle`.

En una instalación nueva deben generarse las claves:

```powershell
php bin/console lexik:jwt:generate-keypair
```

La configuración utiliza:

```dotenv
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=
```

El login se realiza mediante:

```text
POST /api/login_check
```

El correo electrónico se normaliza antes de realizar la autenticación.

Las cuentas inactivas y las cuentas existentes que todavía no tengan contraseña asignada no pueden autenticarse.

## Selección de establecimiento

Cada petición autenticada sobre la API debe indicar el establecimiento activo mediante:

```http
X-Establecimiento-Id: 123
```

La aplicación verifica que el usuario posea una membresía activa sobre ese establecimiento.

También se comprueba que tanto el establecimiento como su entidad fiscal estén activos.

Cambiar el valor de la cabecera no permite acceder a datos de otro establecimiento si el usuario no dispone de la membresía correspondiente.

El filtrado multiempresa se aplica en las consultas Doctrine antes de resolver colecciones, elementos individuales y relaciones.

## CORS

CORS permite las cabeceras necesarias para el frontend:

```text
Authorization
Content-Type
X-Establecimiento-Id
```

Las respuestas de API incluyen `X-Establecimiento-Id` en la configuración `Vary` para evitar reutilizar incorrectamente respuestas entre contextos de establecimiento diferentes.

## Configuración funcional

`ConfiguracionEntidadFiscal` conserva opciones generales de la organización, entre ellas:

* idioma;
* zona horaria;
* preferencias de notificaciones;
* conservación de registros;
* gestión multiestablecimiento.

`ConfiguracionEstablecimiento` contiene la configuración operativa específica del local, incluyendo:

* horarios;
* tolerancias temporales;
* registros atrasados;
* generación automática de incidencias;
* obligatoriedad de observaciones;
* obligatoriedad de fotografía;
* cierre de incidencias;
* avisos de tareas.

La modificación de estas configuraciones no altera los registros históricos existentes.

## Evidencias

El modelo `Evidencia` está implementado, pero actualmente representa únicamente los metadatos del archivo.

Puede asociarse exactamente a uno de estos elementos:

```text
RegistroAPPCC
Incidencia
```

No existe todavía un sistema de almacenamiento físico conectado al modelo.

Por tanto, actualmente no se comprueba que un `storageKey` corresponda a un archivo real y todavía no puede garantizarse `requiereFotoNoConforme` de forma atómica.

## Programación de tareas

`TareaAPPCC` representa la definición recurrente de un control.

`TareaProgramada` representa una ejecución concreta de esa definición.

El servicio permite crear una ejecución explícitamente, impedir duplicados para la misma tarea y fecha, gestionar su estado y detectar ejecuciones vencidas. `GeneradorTareasProgramadasService` materializa automáticamente las frecuencias `DIARIA`, `SEMANAL` y `MENSUAL`.

El comando operativo es:

```powershell
php bin/console app:tareas:procesar --horizonte-dias=7
php bin/console app:tareas:procesar --desde="2026-09-12" --hasta="2026-09-20"
```

`--desde` y `--hasta` limitan la ventana; si se omiten ambos se incluyen ayer, hoy y siete días futuros completos en cada zona fiscal. Con un único límite, el otro se obtiene sumando/restando el horizonte. Se aceptan fechas estrictas `YYYY-MM-DD` o instantes ISO con zona explícita, sin mezclar formatos. Tras generar idempotentemente, se marcan vencidas las pendientes con fecha límite anterior al reloj del servidor. Las tareas sin límite permanecen pendientes. El comando anterior `app:tareas:generar` conserva su comportamiento de solo generación.

La API permite crear `BAJO_DEMANDA`, asignar/desasignar y omitir con auditoría mediante operaciones específicas, además de filtrar y paginar la agenda. Véase [ciclo operativo de tareas](ciclo-operativo-tareas.md) para JSON, permisos, cron cada cinco minutos, concurrencia y tratamiento de históricos.

Los cambios de frecuencia, hora, día o plazo retiran transaccionalmente las futuras pendientes sin registro de esa tarea. Las completadas, históricas y cualquier ejecución con registro se conservan. Ejecutar de nuevo el generador para reconstruir el horizonte deseado. Los bloqueos y la versión de la tarea protegen generación y edición concurrentes. Véanse [las reglas de reconciliación](modelo-mvp.md) y [el informe técnico](revision-generacion-recurrente.md).

Las tareas históricas sin `horaPrevista` no se alteran: el generador las informa como ignoradas hasta que se configuren. `POR_TURNO` y `POR_RECEPCION` quedan pendientes para fases posteriores; `BAJO_DEMANDA` permanece manual por diseño.

## Funcionalidad todavía pendiente

Aunque autenticación y aislamiento multiempresa ya están implementados, todavía quedan fuera del flujo operativo completo:

* almacenamiento real de evidencias;
* fotografías obligatorias de no conformidades;
* firma de registros;
* notificaciones por correo;
* recordatorios de tareas;
* resúmenes diarios;
* políticas automáticas de retención;

El pipeline de validación backend con PostgreSQL ya está implementado en `.github/workflows/backend-ci.yml`.

Estas funcionalidades deben considerarse pendientes aunque sus campos de configuración o modelos de datos ya existan.
