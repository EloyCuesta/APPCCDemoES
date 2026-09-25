# Prioridades

## P0 — bloquear MVP

- Corregidos `exact`, herencia de entorno PHP, selector de histórico y comparación de cabeceras CORS. Aceptación local desktop/mobile completa: 6 live y 4 e2e verdes.
- Obtener Backend CI y Frontend CI verdes para el commit corregido; registrar resultados en [MVP](MVP.md).
- Resolver cualquier regresión adicional de ese recorrido antes de ampliar alcance.

## P1 — cierre del MVP

- No se ha identificado otro flujo funcional esencial ausente en la auditoría y aceptación integrada, incluida aplicación/configuración de plantillas y cambio de tenant.
- Verificar la preparación reproducible de una instalación: cron del ciclo operativo, volumen privado persistente y copias de BD/evidencias según [DEVELOPMENT](DEVELOPMENT.md).

## P2 — post-MVP

- Offline/PWA y estrategia de sincronización.
- Interfaces de administración sobre las APIs existentes, correo y recuperación de contraseña.
- Almacenamiento distribuido y analítica adicional cuando exista una necesidad concreta.

No convertir esta lista en un backlog de mejoras mientras quede un P0 abierto.
