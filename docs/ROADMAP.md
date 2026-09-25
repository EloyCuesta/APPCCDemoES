# Prioridades

## P0 — bloquear MVP

- Corregidos `exact`, herencia de entorno PHP, selector de histórico y comparación de cabeceras CORS. Aceptación local desktop/mobile completa: 6 live y 4 e2e verdes.
- Backend CI y Frontend CI verdes en la rama de revisión; evidencia en [MVP](MVP.md). No quedan bloqueos funcionales reproducidos en esta auditoría. Toda regresión de CI/tenant/recorrido vuelve a ser P0.

## P1 — cierre del MVP

- No se ha identificado otro flujo funcional esencial ausente en la auditoría y aceptación integrada, incluida aplicación/configuración de plantillas y cambio de tenant.
- Integrar la [PR validada](https://github.com/EloyCuesta/APPCCDemoES/pull/1) en `main` y comprobar las pipelines posteriores a la integración.
- Verificar la preparación reproducible de una instalación: cron del ciclo operativo, volumen privado persistente y copias de BD/evidencias según [DEVELOPMENT](DEVELOPMENT.md).

## P2 — post-MVP

- Offline/PWA y estrategia de sincronización.
- Interfaces de administración sobre las APIs existentes, correo y recuperación de contraseña.
- Almacenamiento distribuido y analítica adicional cuando exista una necesidad concreta.

No convertir esta lista en un backlog de mejoras mientras quede un P0 abierto.
