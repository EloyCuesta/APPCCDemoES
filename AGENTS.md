# APPCCDemoES: entrada para agentes

Aplicación online de autocontrol APPCC para establecimientos alimentarios. El objetivo inmediato es cerrar el MVP existente: corregir CI, comprobar el recorrido real y conservar trazabilidad y aislamiento. No añadir áreas secundarias mientras falle este recorrido.

Antes de realizar cambios sustanciales, leer `docs/MVP.md`, `docs/ARCHITECTURE.md` y `docs/DEVELOPMENT.md`.

No reimplementar funcionalidad existente sin comprobar previamente entidades, servicios, processors, providers, contratos frontend y tests existentes.

Ninguna tarea se considera terminada mientras exista una pipeline CI roja relacionada con el cambio.

## Mapa del repositorio

- `backend/`: Symfony 7.4, API Platform 4.3, Doctrine ORM 3.7, PostgreSQL 18 y JWT Lexik. `src/Entity` define el modelo; `Service` contiene los casos de uso y transacciones; `State/Processor` adapta escrituras; `State/Provider`, `Doctrine` y `Repository` resuelven consultas y ámbito tenant; `Security` aplica acceso. Migraciones incrementales en `migrations/`, catálogo en `resources/plantillas/`, pruebas en `tests/`.
- `frontend/`: Next.js 16, React 19, TypeScript estricto. Rutas en `src/app`, pantallas y contratos en `src/features`, sesión en `src/lib/auth` y `src/providers`, transporte HTTP en `src/lib/api`. Vitest/Testing Library en `tests/`; Playwright con dobles HTTP en `tests/e2e`, con Symfony/PostgreSQL en `tests/live`.
- `docs/`: referencia canónica de alcance, arquitectura, ejecución y prioridades. `backend/docs/` y `frontend/docs/` conservan contratos detallados e informes fechados; no interpretar una entrega antigua como estado actual.
- `.github/workflows/`: Backend CI (incluye aceptación integrada) y Frontend CI.

## Reglas que no se pueden romper

- JWT identifica al usuario; `GET /api/me` descubre membresías activas. `X-Establecimiento-Id` selecciona contexto, nunca concede permiso. Validar usuario, membresía, establecimiento y entidad fiscal; comprobar también relaciones/IRI.
- Los roles `admin`, `responsable`, `trabajador`, `auditor` pertenecen a `UsuarioEstablecimiento`, no al rol global Symfony. Consultar la matriz de [arquitectura](docs/ARCHITECTURE.md).
- Cambiar tenant o salir cancela peticiones y retira datos anteriores; una respuesta tardía no puede restaurarlos ni cerrar una sesión nueva.
- `TareaAPPCC` es definición; `TareaProgramada` es ejecución; una ejecución admite un registro. Históricos, evidencias e historial de incidencias no se editan ni se borran arbitrariamente.
- Toda nueva no conformidad exige foto válida. La observación y la confirmación siguen la configuración. Confirmar significa autor autenticado + fecha del servidor; no hay firma dibujada.
- Una incidencia como máximo por registro vinculado; aplicación de plantilla única por establecimiento/plantilla. Controles que requieren límites permanecen inactivos hasta configuración válida.
- Evidencias privadas fuera de `public/`: MIME real, consumo único, integridad y descarga autenticada con tenant. Nunca exponer claves de almacenamiento, hashes internos, JWT ni secretos.
- PostgreSQL real en pruebas. No SQLite, `schema:update --force`, eliminación de tests, casts arbitrarios ni desactivación de TypeScript para conseguir verde.

## Antes y después de modificar

1. Revisar estado Git y referencia de `main`; preservar cambios ajenos.
2. Leer los tres documentos de entrada y el contrato detallado del flujo afectado.
3. Seguir la ruta completa: entidad → servicio → processor/provider → contrato HTTP → frontend → pruebas. Reproducir el problema antes de corregirlo.
4. Hacer cambios pequeños con las convenciones existentes: PHP tipado, enums, DTO de escritura, decimales como strings, IRI canónicas, JSON-LD y merge-patch según contrato. No duplicar arquitectura ni dependencias.
5. Ejecutar las comprobaciones pertinentes y actualizar documentación con evidencia y limitaciones. Las pruebas que truncan datos requieren la base `_test` autorizada.

Validación completa (preparación y variables en [DEVELOPMENT](docs/DEVELOPMENT.md)):

```sh
# backend/
composer validate --strict
php bin/console lint:container
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:schema:validate
php bin/phpunit
# frontend/
npm run lint
npm run typecheck
npm test
npm run build
npm run test:e2e
npm run test:live
```

Consultar también `frontend/AGENTS.md` antes de modificar Next.js. El detalle de cierre y los resultados actuales viven en [MVP](docs/MVP.md); las prioridades en [ROADMAP](docs/ROADMAP.md).
