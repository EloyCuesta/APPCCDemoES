# Validación del bloque inicial

23/09/2026, rama local `main`, Windows, Node.js 24.13.0, npm 11.6.2, Next.js 16.3.6.

| Comprobación | Resultado |
| --- | --- |
| `npm run lint` | Correcto, sin errores ni avisos |
| `npm run typecheck` | Correcto: tipos de rutas y TypeScript estricto |
| `npm run build` | Correcto: `/`, `/login`, `/dashboard`, 404 e icono |
| `npm test` | 38 pruebas aprobadas en 3 archivos |
| `npm run test:e2e` | 4 pruebas aprobadas con Edge: 2 en escritorio y 2 en móvil |
| Revisión visual | Login y dashboard responsive; sin desbordamiento horizontal en las vistas comprobadas |
| Symfony real, `GET /api/me` sin JWT | 401 y `WWW-Authenticate: Bearer` |
| Symfony real, preflight OPTIONS desde localhost:3000 | 200; permite Authorization y X-Establecimiento-Id |
| Diff del backend | Sin cambios de código ni configuración |

Los tests de Vitest y Playwright utilizan dobles HTTP que reproducen el contrato auditado. Playwright navega por la aplicación Next real y comprueba login correcto/incorrecto, acceso privado, uno/varios establecimientos, cabeceras, recarga, logout, 403 y expiración 401. Las pruebas de sesión cubren también membresías revocadas, red, respuestas inválidas y respuestas tardías tras logout/cambio de establecimiento.

No se ha realizado login contra Symfony con una cuenta real: no se han facilitado credenciales. La verificación manual restante está descrita en el README. Tampoco se ha ejecutado CI remota.

Durante el primer arranque de Symfony, la caché de desarrollo superó el límite de ejecución de PHP. Tras el arranque en frío, las comprobaciones HTTP anteriores respondieron correctamente, sin modificar el backend. El primer proceso de Playwright tuvo problemas para cerrar auxiliares dentro del sandbox de Windows; la ejecución completa fuera del sandbox terminó correctamente con código 0.

Las capturas de los tests quedan en `frontend/test-results/`, ignoradas por Git; solo contienen fixtures. Los servidores temporales utilizados en la validación se detuvieron al finalizar.
