# Configuración y entorno

PostgreSQL local confirmado: **18.3**. `.env` contiene únicamente ejemplos;
`.env.dev` no sobrescribe las credenciales. La conexión real reside en `.env.local`,
y la de pruebas en `.env.test.local`; ambos están ignorados por Git.
Si las contraseñas anteriormente versionadas se reutilizan en otro entorno, deben
rotarse. No se ha reescrito el historial Git.

Doctrine añade `_test` al nombre base en APP_ENV=test. En `.env.test.local`, configurar
DATABASE_URL con el nombre **sin ese sufijo** y APPCC_TEST_DATABASE con el nombre final
completo. El equipo debe crear una base exclusiva y vacía para estas pruebas, con el
mismo servidor PostgreSQL configurado en serverVersion. No utilizar desarrollo.
El entorno preparado para esta tarea usa `appcc_mvp_20260912_test`.

ConfiguracionEntidadFiscal conserva idioma, zona horaria, preferencias de avisos,
retención y gestión de establecimientos. ConfiguracionEstablecimiento conserva
jornada, plazos, observación no conforme e incidencia automática.
Cambiar configuración no elimina ni altera registros existentes.

`requiereFotoNoConforme` y `requiereFirmaRegistro` siguen pendientes de un flujo real de
subida/firma atómica. El modelo Evidencia únicamente registra metadatos; no comprueba
que exista un archivo. Notificaciones y retención automática tampoco se ejecutan.

Consultar [modelo-mvp.md](modelo-mvp.md) para las cuatro migraciones y comandos PHPUnit.
JWT y la cabecera X-Establecimiento-Id se documentarán con su implementación validada
en fase 2; no se consideran protección disponible en fase 1.