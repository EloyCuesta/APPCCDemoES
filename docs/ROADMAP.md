# Prioridades

## P0 — bloquear MVP

- Corregidos `exact`, herencia de entorno PHP, selector de histórico y comparación de cabeceras CORS. Revalidación del 26/09: aceptación local desktop/mobile ampliada a 8 live y 4 e2e verdes, incluidos auditor y generación tras activar plantilla.
- Backend CI y Frontend CI verdes en la rama de revisión; evidencia en [MVP](MVP.md). No quedan bloqueos funcionales reproducidos en esta auditoría. Toda regresión de CI/tenant/recorrido vuelve a ser P0.

## P1 — cierre del MVP

- No se ha identificado otro flujo funcional esencial ausente en la auditoría y aceptación integrada, incluida aplicación/configuración de plantillas y cambio de tenant.
- La [PR de cierre](https://github.com/EloyCuesta/APPCCDemoES/pull/1) reúne las correcciones y la preparación Windows. El cierre de integración exige las pipelines verdes del commit resultante en `main`; los enlaces de evidencia histórica están en [MVP](MVP.md).
- Instalación Windows reproducible validada desde una copia limpia: scripts de setup/arranque, JWT nuevos, migraciones, seed y API de desarrollo configurada. Instrucciones exactas y recorrido en [DEVELOPMENT](DEVELOPMENT.md).

## P2 — post-MVP

- Offline/PWA y estrategia de sincronización.
- Interfaces de administración sobre las APIs existentes, correo y recuperación de contraseña.
- Almacenamiento distribuido y analítica adicional cuando exista una necesidad concreta.
- Preparación del despliegue de producción: planificador supervisado, volumen privado persistente, copias coordinadas de BD/evidencias y restauración probada. Es una tarea de despliegue; la demo Windows online se puede probar sin ese despliegue.

No convertir esta lista en un backlog de mejoras mientras quede un P0 abierto.
