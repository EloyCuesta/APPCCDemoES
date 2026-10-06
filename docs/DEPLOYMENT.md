# Beta gratuita: Oracle Always Free

La preparación mantiene Symfony, Next.js y PostgreSQL, con evidencias locales privadas. Se puede ejecutar en Linux amd64 o arm64. No convierte la demo Windows en un servicio público ni incorpora sus contraseñas.

## Destino elegido y coste

Oracle Cloud Always Free permite una VM Ampere A1 en la región principal, dentro de sus límites. La documentación comprobada el **06/10/2026** indica **2 OCPU y 12 GB de RAM totales** y **200 GB de almacenamiento de volúmenes**, incluido el disco de arranque. No usar las cifras antiguas de 4 OCPU/24 GB. Para esta beta basta una VM de hasta 2 OCPU/12 GB y un disco de arranque de 50–80 GB, siempre que la consola confirme que está dentro de Always Free.

Crear una cuenta gratuita en [Oracle Cloud](https://www.oracle.com/cloud/free/). La cuenta requiere verificación de identidad con tarjeta; puede producir una retención temporal, sin un cargo de compra. Mantener la cuenta gratuita y crear únicamente recursos elegibles Always Free, sin actualizar a pago ni depender de los créditos de prueba de 30 días.

Hay dos límites que afectan al piloto: puede faltar capacidad A1 en la región y Oracle puede recuperar instancias consideradas inactivas. Mantener copias fuera de la VM y conservar esta instalación reproducible. No generar actividad artificial para evitar políticas de recuperación. Fuente: [Always Free Resources](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm) y [FAQ](https://www.oracle.com/cloud/free/faq/).

Para el hostname se puede usar un nombre que incluya la IP pública, como `appcc-203-0-113-10.sslip.io`, sustituyendo la IP ficticia por la real. [sslip.io/nip.io](https://sslip.io/) resuelve esos nombres a la IP incluida, sin comprar un dominio ni configurar una zona DNS. Otra opción es un subdominio de [DuckDNS](https://www.duckdns.org/). Caddy solicita y renueva HTTPS; los puertos 80 y 443 deben alcanzar la VM.

## Primera instalación

1. Crear Ubuntu sobre A1 dentro de Always Free. Guardar la clave SSH de forma privada.
2. Permitir TCP 80/443 en las reglas de red de OCI y el firewall del sistema. Limitar SSH a la IP del administrador. No abrir PostgreSQL, Apache, Nginx ni Next.js al exterior.
3. Instalar Docker Engine, el plugin Compose y Python 3 siguiendo la [guía oficial para Ubuntu](https://docs.docker.com/engine/install/ubuntu/). No usar `next dev` ni `php -S` como servicios públicos.
4. Desde SSH, preparar el checkout de la versión aprobada:

```sh
sudo mkdir -p /opt/appcc
sudo chown "$(id -u):$(id -g)" /opt/appcc
git clone https://github.com/EloyCuesta/APPCCDemoES.git /opt/appcc
cd /opt/appcc
# Seleccionar el SHA de la entrega revisada, que debe incluir estos archivos.
git checkout SHA_DE_LA_ENTREGA
python3 scripts/deploy/init.py TU_HOSTNAME TU_EMAIL
bash scripts/deploy/up.sh
```

El setup genera secretos nuevos en `.deploy/`, ignorado por Git y excluido de las imágenes. Repetirlo conserva los archivos; no cambia contraseñas de PostgreSQL ni claves existentes. Las variables privadas están en `.deploy/runtime.env`; el hostname/contacto HTTPS, en `.deploy/config.env`. No publicar el resultado de `docker compose config`: incluye secretos. El wrapper emplea `config --quiet` para validarlo.

La API utiliza `prod` sin debug y paquetes Composer `--no-dev`. El frontend se compila en modo standalone con la URL HTTPS del hostname **antes del build**. Al cambiar de hostname, revisar `DEFAULT_URI` y `CORS_ALLOW_ORIGIN` en runtime.env y volver a ejecutar `up.sh` para recompilar. La URL base no lleva `/api`.

## Primer administrador

El alta pública `/api/onboarding` está bloqueada en el proxy. El administrador del servidor la realiza internamente por SSH mediante los endpoints existentes. Copiar y rellenar el perfil con los datos del piloto:

```sh
cp deploy/onboarding.example.json .deploy/profile.json
chmod 600 .deploy/profile.json
# Editar .deploy/profile.json: sustituir TODOS los datos de ejemplo.
python3 scripts/deploy/bootstrap.py .deploy/profile.json
```

El script solicita una contraseña oculta, crea titular/establecimiento/membresía y consume el token de contraseña inicial, sin imprimirlo. Si el alta ya existe, se detiene en el conflicto y no sustituye datos. Ante un alta creada cuya configuración de contraseña falló, revisar el usuario/establecimiento y usar `app:usuario:administrador` sobre ese establecimiento; no repetir altas con identidades distintas para ocultar el fallo.

Abrir `https://TU_HOSTNAME/login` y comprobar login, selección, plantillas y el recorrido de [MVP](MVP.md). El comando `app:demo:seed` sigue prohibido en prod. No copiar la base demo ni sus usuarios públicos.

## Servicios y persistencia

| Servicio | Función | Exposición |
|---|---|---|
| Caddy | HTTPS y renovación del certificado | Únicos puertos publicados: 80/443 |
| Nginx | Enrutamiento, límites de subida y frecuencia de peticiones | Red Docker privada |
| Next.js | Frontend standalone con usuario no root | Red privada |
| Symfony/Apache | API de producción y JWT | Red privada; document root `public/` |
| PostgreSQL 18 | Datos | Volumen `postgres`, montado en `/var/lib/postgresql` |
| Scheduler | Symfony CLI con el mismo usuario/volúmenes que API | Sin puertos |

Los volúmenes `evidencias` y `jwt` conservan fotos y claves al sustituir contenedores. No ejecutar `down --volumes`, `docker volume prune` ni borrar volúmenes como parte de una actualización. La aplicación conserva su límite de evidencia de 10 MiB; PHP/proxy permiten el overhead multipart. Los logs de acceso no incluyen cuerpos, tokens ni query strings.

Nginx limita login/configuración de contraseña/aceptación de invitaciones a 10 peticiones/minuto por IP con ráfaga de 10; las demás rutas API tienen un límite independiente. Los servicios intermedios no publican puertos y solo confían en el proxy de entrada. Si se añade otra entrada/CDN, revisar la cadena de proxies y los límites por IP.

El scheduler ejecuta `app:tareas:procesar --json` cada cinco minutos, limpieza temporal cada hora y verificación de evidencias diaria. Un fallo hace salir el proceso y es visible en estado/logs; Docker lo reinicia. Su healthcheck exige una ejecución reciente. No equivale a una alerta externa: el responsable debe revisar estado/logs. Tras una interrupción prolongada, usar `--desde`/`--hasta` para conciliar posibles huecos del ciclo.

```sh
bash scripts/deploy/compose.sh ps
bash scripts/deploy/compose.sh logs --tail=100 scheduler
bash scripts/deploy/compose.sh run --rm backend php bin/console app:evidencias:verificar
```

Un 401 de `/api/me` sin token solo verifica disponibilidad HTTP. El login real y la consulta autenticada son necesarios para comprobar BD y JWT.

## Copias y restauración

`backup.sh` bloquea mantenimiento concurrente, pausa API/scheduler, guarda un dump PostgreSQL, evidencias, JWT y configuración, y reanuda los servicios aunque falle. Solo un conjunto con `COMPLETE` y hashes válidos se considera terminado. La copia incluye secretos: conservarla de forma privada.

```sh
bash scripts/deploy/backup.sh
sudo cp deploy/appcc-backup.service deploy/appcc-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now appcc-backup.timer
systemctl list-timers appcc-backup.timer
```

El timer crea una copia cada día a las 03:00 en la zona del servidor. Conserva todas las copias; revisar espacio y trasladarlas periódicamente al PC u otro destino privado fuera de la VM. Descargar por SSH/SFTP no tiene un servicio adicional de pago. **Una copia en la misma VM no protege frente a pérdida de esa VM.** La retención debe ajustarse al espacio gratuito disponible.

Restauración de ensayo, sin tocar la instancia actual ni abrir puertos:

```sh
# Usar el SHA registrado en commit.txt del conjunto, en un checkout apropiado.
bash scripts/deploy/restore-fresh.sh .deploy/backups/FECHA appcc_restore_ensayo
APPCC_PROJECT_NAME=appcc_restore_ensayo \
APPCC_DEPLOY_DIR="$PWD/.deploy/restore/appcc_restore_ensayo" \
bash scripts/deploy/compose.sh up -d --wait backend
```

El restaurador rechaza proyectos que no empiecen por `appcc_restore_`, volúmenes existentes y conjuntos incompletos. Crea almacenamiento aislado, restaura BD/archivos/secretos y valida esquema/evidencias. No admite restaurar sobre datos existentes. Para usar una restauración como instalación pública, revisar expresamente proyecto/hostname/puertos y detener la instalación anterior antes de habilitar su proxy.

## Actualizaciones y validación

Tomar una copia, obtener el commit aprobado y ejecutar `up.sh`. El script construye imágenes, pausa escritores durante migraciones y valida esquema/contenedor/catálogo/archivos antes del arranque. Un error de migración deja escritores detenidos: diagnosticar, no forzar un esquema nuevo. No retroceder código después de una migración sin comprobar compatibilidad.

La [Deployment CI](../.github/workflows/deployment-ci.yml) construye y ejecuta las imágenes de producción en **amd64 y arm64**. Comprueba HTTPS con CA interna solo en CI, alta interna, login/JWT, ausencia de PHPUnit en runtime, prohibición de demo, persistencia de una foto temporal tras sustituir el contenedor, copia/restauración aislada y rate limit. La aceptación funcional completa sigue en Backend CI (8 live) y Frontend CI. El certificado público de la VM y su disponibilidad en OCI requieren verificación en el destino.

La auditoría local del cambio dejó **0 avisos en dependencias npm de producción** (`sharp@0.35.5`, `source-map-js@1.2.2`); se actualizaron también las dos ramas afectadas de `brace-expansion`. El aviso de `braces`, dependiente de herramientas de desarrollo, permanece sin una versión corregida publicada. Se conserva Next.js/React y no se usa `audit fix --force` ni se reducen las pruebas. Frontend CI ahora falla por avisos altos de dependencias de producción; Deployment CI ejecuta también Composer audit sin paquetes dev.

No afirmar que el despliegue está completado por tener esta configuración. Antes de entregar una URL: obtener acceso a la VM, verificar HTTPS público, recorrer MVP, comprobar scheduler y restaurar un conjunto real del piloto.
