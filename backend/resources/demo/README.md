# Evidencia sintética de demostración

`muestra-tecnica.png` es una imagen PNG mínima, copiada de la muestra técnica
del propio repositorio (`tests/Fixtures/evidencia.png`). No representa una
inspección ni un alimento real. Permite probar almacenamiento, metadatos y descarga
autenticada sin depender de archivos externos. Para la demostración manual se
puede adjuntar una fotografía propia mediante el formulario del control.

`app:demo:seed` solo admite `dev` y `test`. Crea tres controles confirmados por
`trabajador@appccdemo.local`: cocción conforme, cocción no conforme con la imagen,
y recepción conforme con campos estructurados. La no conformidad crea una
incidencia abierta con una acción de `responsable@appccdemo.local`. Repetir el
comando conserva estos históricos y no duplica sus evidencias o acciones.

El restaurante tiene límites simulados (conservación 0–5, cocción 70–100); son
datos de prueba. El segundo establecimiento, `Obrador APPCC Demo`, comienza vacío
para demostrar la aplicación de una plantilla y la configuración de sus controles.
