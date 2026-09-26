# Desarrollo y validación

Leer primero [MVP](MVP.md) y [arquitectura](ARCHITECTURE.md). Los comandos siguientes se ejecutan desde la carpeta indicada. Se requieren PHP 8.3 para la suite PHPUnit instalada, Composer 2, PostgreSQL 18, Node 24 (CI), npm y Chromium/Edge para Playwright. Habilitar `pdo_pgsql`, `fileinfo`, OpenSSL y las extensiones requeridas por Composer, incluida sodium para JWT.

## Arranque rápido en Windows (PowerShell)

Con PHP, Composer, Node/npm en PATH y PostgreSQL 18 local arrancado, desde la raíz del repositorio:

```powershell
git switch fix/mvp-ci-and-agent-docs
git pull --ff-only
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev-setup.ps1
```

La entrega está en esa rama mientras la PR #1 siga abierta. Tras integrarla, usar `main`. Cerrar los servidores Next/Playwright antes del setup: `npm ci` necesita sustituir sus dependencias. No hace falta ejecutar PowerShell como administrador.

En una instalación nueva el setup solicita **la contraseña de tu PostgreSQL local**, para el usuario `postgres` y puerto `5432`; la contraseña de la aplicación demo es otra. Para otra cuenta/puerto: añadir `-DatabaseUser tu_usuario -DatabasePort 5433`. Esa cuenta necesita permiso para crear la base, o debe existir previamente. El script escribe `.env.local` privado con la contraseña codificada en URL y un APP_SECRET aleatorio. Si ese archivo ya existe, lo conserva y usa su configuración. Se detiene ante cualquier fallo.

Instala los lockfiles, habilita sodium/OpenSSL en el proceso, genera JWT si falta, crea la base, aplica migraciones, valida esquema, carga catálogo/demo y procesa agenda. Solo admite el entorno efectivo `dev`, PostgreSQL en loopback y bases `appcc_demo_es` o `appcc_demo_es_<nombre>`; rechaza `_test`, destinos remotos y otros entornos antes de migrar/sembrar. No borra bases ni históricos. La política Bypass afecta únicamente al proceso invocado.

**Terminal 1 — backend**, desde la raíz:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev-start.ps1 backend
```

**Terminal 2 — frontend**, desde la raíz:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev-start.ps1 frontend
```

Abrir **http://localhost:3000/login**. API: **http://127.0.0.1:8000**. Cada servidor permanece en su terminal; Ctrl+C lo detiene. `frontend/.env.development` configura la API local para `next dev`; no necesitas crear `.env.local` frontend. Si ya tienes uno, sus valores prevalecen: debe apuntar a esa misma API. Producción sigue necesitando su URL explícita.

Tras activar controles de una plantilla, ejecutar en una tercera terminal y volver a cargar Agenda:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev-start.ps1 tareas
```

El arranque backend también procesa tareas una vez. No instala un servicio ni un planificador de Windows. En sesiones largas se puede repetir `tareas`; en un despliegue operativo debe programarse periódicamente.

Para una demostración vacía sin borrar históricos, detener servidores, conservar el `.env.local` existente como `.env.before-demo.local` y volver a ejecutar setup con `-DatabaseName appcc_demo_es_demo2` (un nombre nuevo). Se solicitará de nuevo la conexión PostgreSQL; la base y evidencias anteriores permanecen intactas. No se incluye un reset destructivo.

### Recorrido de 5–10 minutos

1. Entrar como **trabajador**, seleccionar **Restaurante APPCC Demo** y revisar Dashboard.
2. Abrir Agenda, filtrar por un control de temperatura y ejecutar una tarea pasada/actual. Introducir una lectura dentro de los límites mostrados, confirmar y guardar. Comprobar que desaparece de pendientes y aparece en Registros.
3. Ejecutar otra tarea numérica con valor fuera de límites, escribir observación y subir una foto PNG/JPEG. Para una prueba técnica se puede usar `backend/tests/Fixtures/evidencia.png`. Confirmar y guardar: debe crear una incidencia.
4. Salir y entrar como **responsable**. En Incidencias abrir la nueva NC, añadir acción correctiva, poner en proceso y resolver; revisar las tres entradas del historial.
5. En Registros consultar el detalle NC y descargar la evidencia. Verificar también los ejemplos históricos del seed.
6. Cambiar a **Obrador APPCC Demo**. En Plantillas revisar y aplicar **Obrador · inicio APPCC v1**. Configurar un control pendiente con límites simulados 0–5, unidad °C e instrucciones de demostración, confirmar y activar.
7. Ejecutar el comando `tareas` anterior. Ir a Agenda y filtrar por ese control: habrá ejecuciones actuales o próximas según su horario. Aplicar de nuevo la plantilla no debe duplicar controles.
8. Volver al restaurante para comprobar el cambio de contexto. Entrar como **auditor**: puede consultar registros/incidencias y descargar evidencias; no registrar controles ni resolver incidencias. **Admin** también permite probar la gestión operativa.

Los valores del recorrido son simulaciones; las pantallas muestran los límites del control seleccionado. Las tareas futuras se registran al llegar su hora. Las pruebas live aplican la plantilla del obrador en su base; usar una demo nueva si se quiere repetir su primera aplicación.

### Evidencia local del 26/09/2026

Setup ejecutado y repetido en esta instalación; además, checkout limpio sin vendor/node_modules/JWT, instalación desde lockfiles, base nueva `appcc_demo_es_clean20260926`, 11 migraciones y claves nuevas. El resultado inicial contiene 6 planes, 7 controles, 3 registros (2 conformes y 1 NC), evidencia, incidencia/acción y 23 ejecuciones pendientes/vencidas en restaurante; obrador sin controles. Los cuatro usuarios se verifican por HTTP contra `/api/login_check`, no solo por hash. El frontend limpio arranca sin `.env.local`, usando `.env.development`.

## Entornos y bases

| Uso | APP_ENV | DATABASE_URL apunta a | Base efectiva |
|---|---|---|---|
| Desarrollo/demo local | dev | `appcc_demo_es` (ejemplo) | `appcc_demo_es` |
| PHPUnit local | test | base sin sufijo, p. ej. `appcc_demo_es` | `appcc_demo_es_test` |
| PHPUnit en Backend CI | test | `appcc_ci`, usuario de servicio `appcc` | `appcc_ci_test` |
| Playwright live en Backend CI | dev | `appcc_ci`, mismo servicio PostgreSQL | `appcc_ci` |
| Playwright live local | dev | base demo local preparada | base demo, escribe históricos reales |
| Playwright e2e | no arranca Symfony | dobles HTTP | ninguna |

Doctrine añade `_test` únicamente en `when@test` (`config/packages/doctrine.yaml`). No poner ese sufijo también en la URL: se duplicaría. `PostgresSafety` exige APP_ENV=test, PostgreSQL, nombre terminado en `_test`, coincidencia con `APPCC_TEST_DATABASE` y diferencia con desarrollo. La suite trunca y reconstruye datos de su base autorizada: nunca autorizar una base que se quiera conservar. No ejecutar suites PHPUnit concurrentes sobre la misma base.

Variables reales del proceso prevalecen sobre dotenv cuando PHP las expone. Se cargan `.env`, `.env.local` (excepto test), `.env.<entorno>` y `.env.<entorno>.local`; también puede existir un volcado `.env.local.php`. `.env.dev` versionado no redefine DATABASE_URL. `.env.test` define kernel/secret de pruebas, no credenciales PostgreSQL. Los archivos `.local` y claves JWT son privados e ignorados por Git.

## Preparación backend

Desde `backend/`, configurar `.env.local` con credenciales **propias de desarrollo**. Este ejemplo es ficticio; no copiar secretos reales a documentación:

```dotenv
APP_ENV=dev
DATABASE_URL="postgresql://USUARIO:PASSWORD@127.0.0.1:5432/appcc_demo_es?serverVersion=18&charset=utf8"
```

Crear `.env.test.local` con URL de la instalación de pruebas y allowlist explícita:

```dotenv
DATABASE_URL="postgresql://USUARIO_TEST:PASSWORD_TEST@127.0.0.1:5432/appcc_demo_es?serverVersion=18&charset=utf8"
APPCC_TEST_DATABASE=appcc_demo_es_test
APPCC_DEV_DATABASE=appcc_demo_es
```

La cuenta PostgreSQL debe poder crear las bases, o estas deben estar previamente creadas. Ejecutar cada comando y detenerse si falla:

```sh
composer install
composer validate --strict
php bin/console cache:clear
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
php bin/console doctrine:database:create --if-not-exists --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/console lint:container
php bin/console app:plantillas:cargar
php bin/console doctrine:database:create --env=test --if-not-exists --no-interaction
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/console doctrine:schema:validate --env=test
php bin/phpunit
```

En Windows puede cargarse `. ./bin/local-env.ps1`: habilita sodium y OPENSSL_CONF solo en ese proceso, sin cambiar php.ini global. Si la política de PowerShell impide ejecutar `npm.ps1`, usar `npm.cmd`; para el helper puede usarse una terminal PowerShell con `-ExecutionPolicy Bypass` limitado al proceso. No cambiar políticas globales. El entorno que arranque Playwright debe heredar también las extensiones PHP necesarias.

## Demo reproducible

En `backend/`, con APP_ENV=dev y la base demo seleccionada:

```sh
php bin/console app:demo:seed
php bin/console app:tareas:procesar
php -d variables_order=EGPCS -d upload_max_filesize=12M -d post_max_size=24M -S 127.0.0.1:8000 -t public
```

El seed solo admite dev/test y rechaza prod antes de consultar la base. Reutiliza usuarios, membresías, aplicaciones, tareas e históricos; no reinicia controles ya adaptados. Crea Restaurante APPCC Demo con controles utilizables, historial conforme/NC, incidencia, acción y fotografía. Crea Obrador APPCC Demo sin aplicar plantilla inicialmente, para hacerlo desde `/plantillas`. Repetir el seed conserva una aplicación manual posterior.

Credenciales deliberadamente públicas, **exclusivas de la demo de desarrollo**:

| Usuario | Rol en ambos establecimientos |
|---|---|
| `admin@appccdemo.local` | admin |
| `responsable@appccdemo.local` | responsable |
| `trabajador@appccdemo.local` | trabajador |
| `auditor@appccdemo.local` | auditor |

Contraseña común: `AppccDemo2026!`, definida en `DemoSeedCommand`. Nunca ejecutar esta demo con datos o credenciales de producción. Sus límites son simulaciones, no límites validados de un establecimiento real.

El ciclo `app:tareas:procesar` debe programarse periódicamente en un despliegue operativo; por ejemplo cada cinco minutos, usando el entorno correcto y supervisando su salida. Consultar [ciclo operativo](../backend/docs/ciclo-operativo-tareas.md). Mantener evidencias en volumen persistente privado, con copias coordinadas con la BD. Comprobación: `php bin/console app:evidencias:verificar`.

## Frontend

En `frontend/`, `.env.development` ya define `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000` para desarrollo normal. Solo crear `.env.local` para sobrescribirlo. Es una URL pública, nunca un lugar para secretos.

```sh
npm ci
npm run dev
```

Validación completa:

```sh
npm run lint
npm run typecheck
npm test
npm run build
npx playwright install chromium
npm run test:e2e
npm run test:live
```

En Linux/CI instalar con `npx playwright install --with-deps chromium`. Localmente se puede seleccionar un navegador instalado con `PLAYWRIGHT_CHANNEL=msedge` (PowerShell: `$env:PLAYWRIGHT_CHANNEL='msedge'`). No ejecutar e2e y live a la vez: ambos arrancan Next en el mismo árbol `.next/dev`.

`test:e2e` arranca Next en 3100 y dobla la API 8010. `test:live` arranca Symfony en 8011 y Next en 3101, fija APP_ENV=dev/APP_DEBUG=0, y necesita haber ejecutado seed/migraciones en esa misma base. Nunca reutiliza servidores abiertos. Ambos cubren desktop y Pixel 7; live crea registros y conserva históricos, y aplica/configura la plantilla del obrador. Por tanto, después de live el obrador ya no estará vacío. No borrar sus históricos para restaurar una demostración: usar una base demo nueva.

Las trazas están desactivadas para no capturar JWT/contraseñas. Las capturas locales quedan en `frontend/test-results/` (ignorado). En Windows el sandbox puede impedir cerrar los procesos auxiliares; ejecutar Playwright con permisos adecuados y cerrar solo sus propios servidores si una ejecución queda bloqueada.

## Regresión del servidor live y diagnóstico

En `3c92592`, el servidor se lanzaba con `php -S` sin fijar `variables_order`. Con `GPCS`, PHP cli-server conserva DATABASE_URL en `getenv()`, pero no la importa a `$_ENV` ni `$_SERVER`. `Dotenv::populate()` consulta esas superglobales y deliberadamente no usa getenv; por eso termina cargando la URL predeterminada de `.env` con usuario `app`, aunque Actions exporte la URL con `appcc`. El CLI de consola sí puede tener el entorno en `$_SERVER`, lo que explica que migraciones, seed y PHPUnit funcionen mientras el login HTTP falla con 500.

Comprobación controlada realizada con una URL ficticia inyectada, sin conexión ni impresión de credenciales:

| Servidor | getenv contiene la URL inyectada | $_ENV antes de dotenv | Valor preservado después |
|---|---|---|---|
| `php -d variables_order=GPCS -S …` | Sí | No | No |
| `php -d variables_order=EGPCS -S …` | Sí | Sí | Sí |

`playwright.live.config.ts` fija `-d variables_order=EGPCS`; no cambia las bases, contraseñas ni configuración de producción. Se conservan APP_ENV=dev en live y el sufijo exclusivo de PHPUnit. Las claves JWT temporales de CI continúan siendo las generadas por el workflow. Los logs originales de [Backend CI](https://github.com/EloyCuesta/APPCCDemoES/actions/runs/36144176058) confirman cuatro logins HTTP 500 y cuatro rechazos PostgreSQL para el usuario `app`.

Ante otro 500: comprobar estado HTTP, entorno efectivo y nombre/usuario de BD de forma saneada; revisar log local, claves JWT y extensiones. No volcar variables completas ni tokens en Actions. Un 401 de `/api/me` sin token solo demuestra que el servidor está listo; el login live es el que verifica BD y firma JWT.

Tras actualizar entidades/serialización, regenerar la caché del entorno que sirve HTTP: `php bin/console cache:clear --env=dev --no-debug` antes de arrancar live. Con APP_DEBUG=0 pueden persistir metadatos antiguos aunque PHPUnit, que usa otra caché, pase.

## CI

[Backend CI](../.github/workflows/backend-ci.yml) instala PHP/PostgreSQL, genera claves, valida contenedor/migraciones/esquema/catálogo, ejecuta PHPUnit y prepara otra base dev para aceptación real. [Frontend CI](../.github/workflows/frontend-ci.yml) ejecuta lint, tipos, Vitest, build y Playwright con dobles. Los dos se disparan por PR a main o push a main según sus filtros de rutas.

Consultar el resultado del **commit exacto** en GitHub; éxito local no significa pipeline remota verde. Los resultados y pendientes de la auditoría se registran en [MVP](MVP.md).
