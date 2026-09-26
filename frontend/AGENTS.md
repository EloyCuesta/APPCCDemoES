# Instrucciones específicas del frontend

La entrada principal es [../AGENTS.md](../AGENTS.md). Leer primero `../docs/MVP.md`, `../docs/ARCHITECTURE.md` y `../docs/DEVELOPMENT.md`.

Reutilizar SessionProvider, el cliente API y los contratos de `src/features`; conservar cancelación y aislamiento por establecimiento. Testing Library y Playwright tienen opciones diferentes: `getByRole` de Testing Library no acepta `exact` (un nombre string ya es exacto). Los tests live escriben en la base demo dev y deben ejecutarse separados de `test:e2e`.

<!-- BEGIN:nextjs-agent-rules -->

# This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->
