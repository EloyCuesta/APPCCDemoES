# Contexto de sesión para iniciar el frontend

`GET /api/me` requiere un JWT válido de un usuario activo. **No requiere `X-Establecimiento-Id`**. Devuelve JSON simple (`application/json`), sin envoltorio JSON-LD ni paginación.

```http
GET /api/me
Authorization: Bearer <JWT>
Accept: application/json
```

Respuesta 200 con una membresía válida:

```json
{
  "id": 1,
  "nombre": "Ana",
  "apellidos": "García",
  "email": "ana@example.com",
  "membresias": [
    {
      "id": 4,
      "iri": "/api/usuarios-establecimientos/4",
      "rol": "admin",
      "establecimiento": {
        "id": 15,
        "iri": "/api/establecimientos/15",
        "nombre": "Obrador Centro",
        "tipoActividad": "obrador",
        "entidadFiscal": {
          "id": 2,
          "nombre": "Panadería Ejemplo SL"
        }
      }
    }
  ],
  "establecimientoPredeterminadoId": 15
}
```

Contrato:

- `id`, `nombre`, `apellidos` y `email` identifican exclusivamente al usuario del JWT.
- `membresias` es siempre una lista, ordenada por ID de membresía ascendente. Solo incluye membresías propias activas cuyo establecimiento y entidad fiscal también estén activos. No devuelve pertenencias de otros usuarios, aunque compartan establecimiento o entidad fiscal.
- `rol` es el valor del rol empresarial: `admin`, `responsable`, `trabajador` o `auditor`. Todos pueden consultar su contexto. `tipoActividad` usa los valores de `TipoActividad` existentes.
- Las IRI son referencias relativas con las rutas canónicas existentes. Conocer una IRI no concede permisos para consultarla: los endpoints correspondientes mantienen sus roles y aislamiento.
- `entidadFiscal.nombre` prioriza nombre comercial no vacío y, en su defecto, razón social. Para autónomos sin esos campos se utiliza nombre y apellidos fiscales. No se expone NIF, dirección, configuración ni otras relaciones fiscales.
- `establecimientoPredeterminadoId` siempre existe: contiene el ID cuando hay exactamente una membresía válida, y `null` cuando hay cero o varias. Es una ayuda de selección; no cambia el JWT ni establece una sesión de tenant en el servidor.
- Usuario activo sin establecimientos válidos: **200**, `membresias: []` y `establecimientoPredeterminadoId: null`. Se mantienen los cuatro campos de identidad.
- JWT ausente, inválido, expirado o de usuario inactivo: **401**. Se mantiene `UsuarioChecker`, incluida la restricción de cuentas sin contraseña configurada.
- Una cabecera tenant enviada accidentalmente no filtra ni amplía `/api/me`. Los parámetros de consulta tampoco permiten cambiar la identidad consultada.
- La respuesta contiene únicamente los DTO explícitos. No devuelve password, roles globales, hashes, tokens ni entidades internas. Se envía `Cache-Control: private, no-store` y `Vary: Authorization`.

## Flujo login → contexto → selección

1. Enviar `POST /api/login_check` con `email` y `password`. Se conserva su respuesta existente: `{"token":"<JWT>"}`.
2. Consultar `GET /api/me` con `Authorization: Bearer <JWT>`, sin cabecera tenant.
3. Si hay un establecimiento predeterminado, usar ese ID. Si hay varios, mostrar la selección. Si no hay ninguno, mostrar un estado sin acceso operativo y permitir cerrar sesión; no tratar la lista vacía como un error de login.
4. En las peticiones operativas posteriores enviar JWT **y** `X-Establecimiento-Id` con el ID seleccionado. Cambiar de establecimiento solo cambia esa cabecera.
5. Volver a consultar `/api/me` al recuperar el contexto o tras cambios de pertenencia. La lista refleja la base actual y no está incrustada en el JWT. Si se revoca acceso después de consultar `/api/me`, el endpoint operativo vuelve a validar la membresía y puede responder 403.

## Implementación y validación

`MeController` es un controlador Symfony autenticado, independiente del proveedor ORM de API Platform. Solo usa `CurrentEstablecimientoContext::usuario()`. La consulta del repositorio limita por identidad autenticada y filtra las tres actividades en SQL; carga establecimiento y fiscal mediante joins, sin consultas adicionales por cada membresía. No se añaden excepciones a `TenantExtension` ni se cambia la política de los demás recursos.

No requiere migraciones, cambios de entidades ni frontend. La selección persistida entre sesiones y su interfaz corresponden al frontend futuro; por ahora solo se propone automáticamente la única membresía válida.

`MeApiTest` cubre autenticación, cuatro roles, contrato exacto sin secretos, varias pertenencias, filtros de actividad, listas vacías, revocaciones, nombres fiscales y conservación de login/aislamiento. Se ejecuta junto con toda la suite sobre PostgreSQL real.

Validación local del 16 de septiembre de 2026 sobre `main` (`41f0909`), PHP 8.3.11 y PostgreSQL 18.3:

| Comprobación | Resultado |
|---|---|
| `composer validate --strict` | Correcto, código 0 |
| `php bin/console lint:container` | Correcto, código 0 |
| `php bin/console doctrine:migrations:migrate --no-interaction` | Base nueva con cero tablas: 10 migraciones, 161 consultas SQL, código 0 |
| `php bin/console doctrine:schema:validate` | Mapping correcto y esquema sincronizado, código 0 |
| `php bin/phpunit` | `OK (371 tests, 1549 assertions)`, código 0 |

La ejecución completa tardó `02:10.068`, con `74.00 MB`, e incluye 23 casos nuevos de `/api/me`. No se modificaron ni omitieron pruebas existentes. Migraciones, esquema y suite se ejecutaron con `APP_ENV=test` en una base PostgreSQL temporal independiente, eliminada al finalizar. Los logs locales quedan en `var/me-validacion-*.log`, fuera de Git. No se ha ejecutado CI remota.
