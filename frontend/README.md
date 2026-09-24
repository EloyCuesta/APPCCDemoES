# APPCC Demo ES · Frontend

Next.js 16.3.6, React 19, TypeScript estricto y App Router. Autenticación Symfony/LexikJWT, contexto multiestablecimiento, dashboard privado y Agenda APPCC operativa: registro de controles, evidencias y confirmación del usuario autenticado. La aplicación no contiene datos mock; los dobles HTTP están exclusivamente en `tests/`.

El módulo **Incidencias APPCC** permite consultar, filtrar y paginar incidencias, visualizar el registro de origen y su historial, añadir acciones correctivas y solicitar las transiciones existentes hasta resolver. Auditor en solo lectura; trabajador puede añadir acciones; admin/responsable también cambian estados. Contratos, manejo de errores, validación y alcance pendiente en [entrega de incidencias](docs/incidencias.md). El flujo Agenda → Registro se mantiene cerrado.

## Requisitos y arranque

- Node.js 22.13+, 24.x o 26+; recomendado Node.js 24 LTS, utilizado en validación y CI.
- Backend Symfony configurado con base de datos, migraciones y claves JWT.
- Usuario activo con contraseña configurada. Para operar necesita membresía, establecimiento y entidad fiscal activos.

Desde la raíz del repositorio:

```sh
cd frontend
npm ci
cp .env.example .env.local
npm run dev
```

En PowerShell: `Copy-Item .env.example .env.local`. Si la política de ejecución bloquea `npm.ps1`, utiliza `npm.cmd` sin cambiar la política del equipo. Abre `http://localhost:3000`: `/` lleva a `/dashboard` y, sin sesión, a `/login`.

En otra terminal, desde `backend/`, con su entorno ya configurado:

```sh
php -S localhost:8000 -t public
```

Este servidor PHP es solo para desarrollo. Para preparar una cuenta real, sigue [usuarios y onboarding](../backend/docs/usuarios-onboarding.md). Para cuentas locales reproducibles, utiliza el siguiente comando demo.

### Entorno demo local

Requiere PostgreSQL y `backend/.env.local` con `DATABASE_URL` y la configuración JWT del entorno. PHP necesita `pdo_pgsql`, `fileinfo` y `sodium`; para ejecutar PHPUnit se requiere PHP 8.3+. En una instalación nueva ejecuta `composer install`, crea la base con `php bin/console doctrine:database:create --if-not-exists` y genera las claves con `php bin/console lexik:jwt:generate-keypair --skip-if-exists`.

PowerShell, terminal del backend, desde la raíz del repositorio:

```powershell
Set-Location D:\PROYECTOS\APPCCDemoES\backend
# Solo si PowerShell bloquea el helper; afecta exclusivamente a esta terminal:
Set-ExecutionPolicy -Scope Process Bypass -Force
. ./bin/local-env.ps1
$env:APP_ENV = 'dev'
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:demo:seed
php bin/console app:tareas:procesar
php -d upload_max_filesize=12M -d post_max_size=24M -S 127.0.0.1:8000 -t public
```

En otra terminal:

```powershell
Set-Location D:\PROYECTOS\APPCCDemoES\frontend
npm.cmd ci
$env:NEXT_PUBLIC_API_URL = 'http://127.0.0.1:8000'
npm.cmd run dev
```

También puedes configurar `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000` en `frontend/.env.local`. Abre `http://localhost:3000`, inicia sesión y entra en **Restaurante APPCC Demo**, entidad **APPCC Demo local** (usa el ID mostrado por el comando si hay otros locales con el mismo nombre).

| Cuenta de desarrollo | Rol |
| --- | --- |
| `admin@appccdemo.local` | admin |
| `responsable@appccdemo.local` | responsable |
| `trabajador@appccdemo.local` | trabajador |
| `auditor@appccdemo.local` | auditor, solo lectura |

Contraseña común: **`AppccDemo2026!`**. Son datos públicos de demostración local, nunca credenciales de producción. `app:demo:seed` solo funciona en `dev`/`test` y rechaza `prod` antes de acceder a la base.

El comando crea/reutiliza una entidad fiscal, un restaurante, su configuración, las cuatro cuentas y membresías. Aplica la plantilla restaurante mediante el servicio existente: seis planes y siete tareas. Configura límites simulados (conservación 0–5 °C, cocción 70–100 °C), confirmación, observaciones no conformes e incidencias automáticas. Esos límites sirven exclusivamente para probar la demo. Utiliza el generador del ciclo operativo, acotado a sus tareas, y el servicio de programación para las tareas bajo demanda. La primera aplicación comienza ayer para disponer de controles recurrentes ya ejecutables.

Repetirlo el mismo día conserva IDs y no duplica empresa, local, usuarios, membresías, catálogo, aplicación, puntos, planes, tareas ni ejecuciones. Restablece la contraseña demo y activa sus cuentas/membresías; conserva la configuración de tareas ya aplicadas y los registros completados. En días posteriores amplía la ventana de programación y añade la ejecución diaria demo de las tareas bajo demanda. No borra históricos ni reinicia controles ya registrados.

## Variables de entorno y conexión

```env
NEXT_PUBLIC_API_URL=http://localhost:8000
```

URL base pública del backend, **sin añadir `/api`**. No hay una URL alternativa hardcodeada. Si falta o es inválida, el acceso muestra un error de configuración. `.env.local` está ignorado y `.env.example` está versionado.

Las variables `NEXT_PUBLIC_*` quedan incorporadas al JavaScript público durante el build: nunca deben contener credenciales ni claves. Configura la URL antes de compilar; cambiarla en producción requiere reconstruir. Véase la [documentación oficial](https://nextjs.org/docs/app/guides/environment-variables).

La API debe ser accesible desde el navegador del usuario, no solo desde el servidor de Next. Symfony ya permite `Authorization`, `Content-Type` y `X-Establecimiento-Id` mediante NelmioCorsBundle. Su `CORS_ALLOW_ORIGIN` de desarrollo admite localhost y 127.0.0.1 con distintos puertos. Para otro dominio configura el origen del frontend en Symfony. No se requieren cookies cross-origin. En producción ambos servicios deben utilizar HTTPS.

## Endpoints utilizados

| Método y ruta                    | Uso                                       | Cabeceras                                                     |
| -------------------------------- | ----------------------------------------- | ------------------------------------------------------------- |
| `POST /api/login_check`          | `{email,password}` → `{token}`            | `Accept` y `Content-Type: application/json`                   |
| `GET /api/me`                    | Identidad y membresías autorizadas        | Bearer y `Accept: application/json`; sin tenant               |
| `GET /api/establecimientos/{id}` | Comprobar conexión y acceso del dashboard | Bearer, `X-Establecimiento-Id`, `Accept: application/ld+json` |
| `GET /api/tareas-programadas/agenda` | Agenda paginada de pendientes/vencidas y filtros | Bearer y tenant, JSON-LD |
| `GET /api/tareas-programadas/{id}` | Recargar ejecución antes de registrar | Bearer y tenant, JSON-LD |
| `GET /api/tareas` y `/api/tareas/{id}` | Opciones, definición y contrato del control | Bearer y tenant, JSON-LD |
| `GET /api/planes-control/{id}` | Nombre y tipo de plan de la Agenda | Bearer y tenant, JSON-LD |
| `GET /api/usuarios` y `/api/usuarios/{id}` | Responsables de la Agenda | Bearer y tenant, JSON-LD |
| `GET /api/configuraciones-establecimiento` | Configuración única del tenant; requisito de observación no conforme | Bearer y tenant, JSON-LD |
| `POST /api/evidencias/subidas` | `FormData` con `archivo` y `tipo` (`foto`/`documento`) | Bearer y tenant; boundary generado por el navegador |
| `POST /api/registros` | Control, tokens de evidencia y `confirmarRegistro: true` → 201 | Bearer y tenant, JSON-LD |

La [auditoría inicial](docs/auditoria-inicial.md) documenta la fase inicial. El contrato operativo procede de [evidencias y registros](../backend/docs/evidencias-registros.md), [Agenda](docs/agenda.md) y [contexto de sesión](../backend/docs/contexto-sesion.md).

## Registrar un control

En `/agenda`, **Registrar control** abre un formulario dentro de la misma pantalla para la ejecución seleccionada. El rol se obtiene de la membresía actual en `/api/me`: admin, responsable y trabajador pueden registrar; auditor no recibe acciones operativas. Symfony vuelve a comprobar permisos, tenant, tarea y estado en cada petición.

- Numérico: muestra instrucciones, unidad, límites, valor decimal (hasta 9 cifras enteras y 3 decimales) y observaciones. Envía `valorNumerico` como string; acepta coma en la interfaz y la normaliza a punto.
- Booleano: envía `datos.resultado` como booleano real.
- Estructurado: genera los campos string de `configuracion.campos` y exige conformidad manual explícita. Conserva los nombres del contrato, incluidos los campos de recepción y trazabilidad.

La conformidad numérica/booleana mostrada es una previsión. Una no conformidad solicita fotografía y, cuando lo exige la configuración, observaciones. Se admiten fotos JPEG/PNG/WebP y PDF, hasta diez evidencias por registro y 10 MiB por archivo con la configuración inicial. El servidor valida el contenido, tamaño, pertenencia y caducidad; un PDF no sustituye una foto. Los tokens permanecen únicamente en estado del formulario, nunca en URLs ni almacenamiento del navegador; salir, cambiar establecimiento o cerrar sesión los descarta. El mantenimiento del backend elimina los temporales caducados.

Antes de enviar, el usuario marca «Confirmo que los datos introducidos son correctos y corresponden al control realizado». La identidad procede del JWT, sin selector de firmante ni firma dibujada. El POST envía `confirmarRegistro: true`; Symfony completa la ejecución y crea la incidencia automática cuando corresponde. Después de la respuesta correcta se vuelve a Agenda y se consulta otra vez el servidor, sin completar tareas de forma optimista.

Los 422 muestran el mensaje de dominio. Un 409 explica que el control pudo cambiar o haber sido registrado por otra sesión y ofrece volver a consultar Agenda. Tras red, timeout o 5xx del registro se bloquea otro envío desde ese formulario hasta comprobar el estado en Agenda. No se reintentan escrituras automáticamente. Cancelar el formulario aborta las peticiones del navegador; una escritura ya recibida por Symfony puede terminar igualmente, por lo que al volver siempre se consulta la Agenda.

## Autenticación y almacenamiento

1. Login envía email y contraseña a LexikJWT y guarda el token únicamente en `sessionStorage`, mediante `src/lib/auth/storage.ts`.
2. Consulta `/api/me`: id, nombre, apellidos, email y membresías, cada una con rol, establecimiento y entidad fiscal resumida. La identidad no se deduce del JWT.
3. Selecciona el único establecimiento automáticamente; si hay varios sin preferencia válida, pide elegir. Cero membresías conserva la sesión y muestra un estado sin acceso operativo.
4. Al recargar vuelve a consultar `/api/me` antes de montar contenido privado. Una caída de red permite reintentar sin perder el token; un 401 limpia la sesión.

El JWT no se duplica en React Context, localStorage ni cookies. `sessionStorage` permite recargar y limita la persistencia a la sesión de la pestaña, sujeta a la restauración de pestañas del navegador. **Es accesible a JavaScript y no protege frente a XSS**. El backend actual solo entrega JWT en JSON, sin refresh ni cookie HTTP-only; no se simulan esos mecanismos. El adaptador podrá sustituirse al diseñar un contrato real de cookie/BFF. Las sesiones de distintas pestañas son independientes.

Logout elimina JWT y preferencia, borra identidad y datos derivados, cancela peticiones y redirige a `/login`. El backend es stateless: esta operación local no revoca un JWT ya emitido; la API no dispone de un endpoint de revocación.

Los guards de cliente gestionan navegación y evitan parpadeos. **Symfony sigue siendo la autoridad**: el HTML inicial no incluye datos privados y toda consulta operativa exige JWT y tenant autorizados. No hay cookies de sesión que Next pueda validar en middleware/proxy.

## Establecimiento y estado

`SessionProvider` integra React Context y un pequeño almacén observable con una única fuente para sesión y establecimiento. Se crea por provider, nunca como singleton de servidor. `useAuth()` expone usuario, carga, autenticación, login y logout; `useEstablecimiento()` expone establecimientos, selección y actualización; `useApi()` proporciona el cliente de esa sesión.

La preferencia se guarda en `sessionStorage` y se valida contra la respuesta nueva de `/api/me`. No se guardan datos operativos. El cliente consulta directamente la selección actual para añadir `X-Establecimiento-Id`, sin que cada componente construya cabeceras. La validación local no concede permisos: Symfony vuelve a validar la membresía en cada petición.

Cambiar de establecimiento cancela peticiones pendientes y desmonta el contenido dependiente mediante una clave usuario/establecimiento. Se descartan respuestas tardías, incluidos 401 de sesiones anteriores. Las futuras features deben respetar las cancelaciones y asociar cualquier caché a usuario y establecimiento.

## Cliente HTTP, JSON-LD y errores

`src/lib/api/client.ts` es el único módulo de producción con `fetch`. Los endpoints declaran ámbito público, sesión o tenant. El cliente añade las cabeceras, usa `cache: no-store`, omite cookies, rechaza redirecciones y limita cada petición a 15 segundos.

`ApiError` expone `status`, mensaje seguro, `violations` y `retryable`. Maneja 400, 401, 403, 404, 409, 422, 429, 5xx, red y timeout. Los errores de permisos permiten actualizar accesos; conflictos y fallos transitorios ofrecen reintento explícito. Las validaciones 422 conservan mensajes de dominio y campos, descartando trazas y detalles técnicos. Nunca se muestran cuerpos internos de errores 5xx ni se reenvían escrituras automáticamente.

Login y contexto validan sus DTO también en ejecución. `readCollection()` adapta `member/totalItems/view` y `hydra:*` a `{items,total,next}` con un parser de elementos. Si el servidor no proporciona total, devuelve `null`.

## Estructura

```text
src/
  app/                     Layout raíz, /login y grupo privado /dashboard y /agenda
  components/layout/       Sidebar, cabecera, usuario y logout
  components/ui/           Marca, carga y errores
  features/auth/           Formulario, guard, recuperación y contratos
  features/establecimientos/ Selector y acceso al contexto operativo
  features/dashboard/      Resumen y conexión
  features/agenda/         Consulta paginada, filtros, agrupación y registro
  features/registros/      Contratos, formulario, subidas y confirmación
  features/incidencias/    Consulta, detalle, origen, acciones e historial
  hooks/                   Autenticación, establecimiento y API
  lib/api/                 Cliente, endpoints, errores y JSON-LD
  lib/auth/                Persistencia y ciclo de sesión
  providers/               React Context
  types/                   Contratos explícitos
tests/                     Pruebas unitarias y de componentes
  e2e/                     Pruebas en navegador
  live/                    Aceptación contra Symfony real, ejecución explícita
```

CSS organizado por componente/layout en `src/app/globals.css`, con variables de diseño, foco visible, formularios etiquetados y movimiento reducido. Se conserva Tailwind 4 del scaffold sin añadir librería de componentes. Las fuentes son del sistema y el build no descarga fuentes remotas.

## Validación y build

```sh
npm run lint
npm run typecheck
npm test
npm run build
npm start
```

Vitest y Testing Library cubren login correcto/incorrecto, logout, restauración, cero/uno/varios establecimientos, revocaciones, cabeceras, 401/403, validación, red y peticiones tardías. Configuración basada en la [guía de pruebas de Next.js](https://nextjs.org/docs/app/guides/testing/vitest).

Para navegador:

```sh
npx playwright install chromium
npm run test:e2e
```

En Windows con Edge instalado:

```powershell
$env:PLAYWRIGHT_CHANNEL = 'msedge'
npm.cmd run test:e2e
```

Playwright levanta Next en el puerto 3100, que debe estar libre, e intercepta la API de pruebas en `127.0.0.1:8010`. Comprueba teclado, rutas, restauración, selector, logout, 401/403 y ausencia de desbordamiento horizontal en escritorio y móvil. Las capturas se guardan en `test-results/`, ignorado por Git. No toca la base de datos de Symfony. **Estas pruebas no equivalen a un login contra Symfony real.**

`.github/workflows/frontend-ci.yml` configura lint, tipos, tests, build y Playwright Chromium para CI. La ejecución remota requiere subir los cambios.

Para la aceptación completa contra Symfony y PostgreSQL reales, prepara primero `app:demo:seed`. Desde `frontend/`:

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
. ../backend/bin/local-env.ps1
$env:PLAYWRIGHT_CHANNEL = 'msedge'
npm.cmd run test:live
```

Esta prueba arranca PHP en 8011 y Next en 3101. Mantén esos puertos libres y detén otros `next dev` de este repositorio durante las pruebas. Crea mediante la API dos ejecuciones bajo demanda por dispositivo, entra por la interfaz con trabajador, registra un control conforme sin foto y otro no conforme con PNG, exige dos 201, comprueba confirmación/autoría, desaparición de Agenda, consulta de registros y exactamente una incidencia en el no conforme. **Escribe datos demo reales y conserva sus históricos**. Los tokens y contraseñas no se incluyen en trazas. Cubre escritorio y móvil; las capturas están en `test-results/`. `test:e2e` conserva las pruebas con dobles HTTP y no requiere Symfony.

La suite real incluye además `incidencias.spec.ts`: login como responsable → Agenda → no conformidad con foto → Incidencias → registro de origen → acción correctiva → en proceso → resuelta, con reconsulta e historial reales en ambos dispositivos.

Comprobación manual: en Agenda abre una ejecución cuya fecha ya haya llegado, introduce un valor dentro de sus límites, confirma y registra; comprueba el éxito y que desaparece. Abre otra ejecución, introduce un valor fuera de límites, añade observación, sube `backend/tests/Fixtures/evidencia.png`, confirma y registra. En las herramientas de red comprueba 201 en ambos POST. `GET /api/registros?tareaProgramada=/api/tareas-programadas/ID` y `GET /api/incidencias?registro=/api/registros/ID` permiten verificar el histórico y la incidencia con el JWT y tenant correspondientes. Las ejecuciones futuras siguen sujetas a la validación temporal de Symfony.

Validación backend (desde `backend/`, con el helper cargado):

```powershell
composer validate --strict
php bin/console lint:container
php bin/console doctrine:schema:validate
php bin/phpunit
```

PHPUnit necesita una base de pruebas separada, migrada, con sufijo `_test` y `APPCC_TEST_DATABASE` autorizado en `.env.test.local`; nunca apuntes las pruebas a la base de desarrollo. Véase `backend/tests/Support/PostgresSafety.php`.

## Preparación PWA y siguientes bloques

Esta fase funciona online. No registra service worker ni persiste registros/evidencias para uso offline; no se anuncia como instalable todavía. Puntos de extensión:

- `src/app/manifest.ts` y `public/` para manifest, iconos de instalación y worker.
- Provider independiente para registrar el worker y gestionar conectividad.
- Capa separada de cola/persistencia cuando se definan idempotencia, conflictos y caducidad offline.
- Cachés y colas aisladas por usuario/establecimiento y limpiadas al salir; no cachear login ni `/api/me`.

El primer flujo vertical online está implementado: demo, login, establecimiento, Agenda, control, evidencias, confirmación y finalización. Para cerrar un MVP de gestión más amplio quedan las pantallas de históricos/descarga, gestión y cierre de incidencias, adaptación de tareas/plantillas, usuarios, configuración y KPIs reales. Offline/PWA, correo y recuperación de contraseña siguen fuera de este alcance. Las rutas futuras aparecen desactivadas y los indicadores no inventan cifras.
