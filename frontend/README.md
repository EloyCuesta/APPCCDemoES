# APPCC Demo ES · Frontend

Next.js 16.3.6, React 19, TypeScript estricto y App Router. Base con autenticación Symfony/LexikJWT, contexto multiestablecimiento, dashboard privado y diseño responsive. La aplicación no contiene datos mock; los dobles HTTP están exclusivamente en `tests/`.

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

Este servidor PHP es solo para desarrollo. Para preparar una cuenta real, sigue [usuarios y onboarding](../backend/docs/usuarios-onboarding.md). El comando existente `php bin/console app:usuario:administrador --help` explica cómo habilitar un administrador en un establecimiento existente usando entrada oculta de contraseña. No se incluyen credenciales de demostración.

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

La [auditoría inicial](docs/auditoria-inicial.md) contrasta las rutas con código y pruebas de Symfony, incluyendo las rutas futuras de agenda, tareas, registros, incidencias y plantillas. Véase también el [contrato de sesión](../backend/docs/contexto-sesion.md).

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
  app/                     Layout raíz, /login y grupo privado /dashboard
  components/layout/       Sidebar, cabecera, usuario y logout
  components/ui/           Marca, carga y errores
  features/auth/           Formulario, guard, recuperación y contratos
  features/establecimientos/ Selector y acceso al contexto operativo
  features/dashboard/      Resumen y conexión
  hooks/                   Autenticación, establecimiento y API
  lib/api/                 Cliente, endpoints, errores y JSON-LD
  lib/auth/                Persistencia y ciclo de sesión
  providers/               React Context
  types/                   Contratos explícitos
tests/                     Pruebas unitarias y de componentes
  e2e/                     Pruebas en navegador
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

Comprobación manual con backend real: arrancar ambos servicios, abrir `/dashboard` sin sesión, iniciar sesión con una cuenta real, seleccionar un establecimiento autorizado, comprobar conexión, cambiar de establecimiento, recargar y cerrar sesión. El acceso privado debe volver a pedir login.

## Preparación PWA y siguientes bloques

Esta fase funciona online. No registra service worker ni persiste registros/evidencias para uso offline; no se anuncia como instalable todavía. Puntos de extensión:

- `src/app/manifest.ts` y `public/` para manifest, iconos de instalación y worker.
- Provider independiente para registrar el worker y gestionar conectividad.
- Capa separada de cola/persistencia cuando se definan idempotencia, conflictos y caducidad offline.
- Cachés y colas aisladas por usuario/establecimiento y limpiadas al salir; no cachear login ni `/api/me`.

El siguiente bloque es **Agenda APPCC**, sobre `/api/tareas-programadas/agenda`. Quedan pendientes agenda, tareas, registros, evidencias, incidencias, plantillas, usuarios, configuración y KPIs reales. Las rutas futuras aparecen desactivadas y los indicadores no inventan cifras.
