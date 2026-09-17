# Consultas del frontend — MVP 0.1.0

OpenAPI publica **APPCC Demo ES API**, versión **`0.1.0-mvp`**. Los parámetros
son `QueryParameter` explícitos de API Platform, con esquemas y catálogos cerrados.
El documento puede exportarse con:

```sh
php bin/console api:openapi:export --output=var/openapi.json
```

## Autenticación, establecimiento y paginación

Tras [login → `/api/me` → selección de establecimiento](contexto-sesion.md),
todas las consultas siguientes necesitan:

```http
Authorization: Bearer <JWT>
X-Establecimiento-Id: 15
Accept: application/ld+json
```

Los roles `admin`, `responsable`, `trabajador` y `auditor` conservan su acceso de
lectura a estas colecciones. Los permisos de escritura permanecen en sus operaciones
existentes. Una membresía revocada, un establecimiento inactivo o una entidad fiscal
inactiva impiden el acceso al tenant.

Parámetros comunes: `page` (entero positivo, predeterminado **1**, máximo 2147483647)
e `itemsPerPage` (entero de **1 a 100**, predeterminado **30**). No se admite desactivar
la paginación ni solicitar paginación parcial. Un tamaño mayor de 100 devuelve `400`.
Una página posterior a la última devuelve `200`, `member: []` y el total filtrado.

La respuesta JSON-LD conserva el contrato de cada entidad y la envoltura de API Platform:

```json
{
  "@context": "/api/contexts/RegistroAPPCC",
  "@id": "/api/registros",
  "@type": "Collection",
  "totalItems": 62,
  "member": [
    { "@id": "/api/registros/41", "@type": "RegistroAPPCC", "id": 41 }
  ],
  "view": {
    "@id": "/api/registros?page=1",
    "@type": "PartialCollectionView",
    "first": "/api/registros?page=1",
    "last": "/api/registros?page=3",
    "next": "/api/registros?page=2"
  }
}
```

Ejemplo de envoltura abreviado: cada elemento conserva todos sus campos de lectura
actuales. `view` y sus enlaces dependen de la paginación y conservan los filtros.
`totalItems` cuenta los resultados **tras aplicar tenant y filtros**, antes de paginar.

## Contrato por colección

Todos los parámetros son opcionales salvo las cabeceras indicadas. Los filtros se
combinan con **AND** y admiten **un único valor**; no se admiten arrays ni claves repetidas.

| Colección GET | Filtros admitidos | Orden predeterminado | Orden configurable |
|---|---|---|---|
| `/api/registros` | `tareaProgramada`, `tarea`, `usuario`, `conforme`, `fechaHora[after]`, `fechaHora[before]` | `fechaHora DESC`, `id ASC` | `order[fechaHora]` |
| `/api/incidencias` | `estado`, `gravedad`, `registro`, `fechaApertura[after]`, `fechaApertura[before]` | `fechaApertura DESC`, `id ASC` | `order[fechaApertura]` |
| `/api/acciones-correctivas` | `incidencia`, `usuario`, `fechaHora[after]`, `fechaHora[before]` | `fechaHora ASC`, `id ASC` | Ninguno |
| `/api/historiales-incidencia` | `incidencia` | `createdAt ASC`, `id ASC` | `order[createdAt]` |
| `/api/evidencias` | `registro`, `incidencia`, `tipo` | `id ASC` | Ninguno |
| `/api/tareas` | `planControl`, `puntoControl`, `frecuencia`, `activa` | `id ASC` | Ninguno |
| `/api/planes-control` | `activo` | `nombre ASC`, `id ASC` | `order[nombre]` |
| `/api/puntos-control` | `activo` | `nombre ASC`, `id ASC` | `order[nombre]` |

La dirección de orden solo acepta `asc`, `desc`, `ASC` o `DESC`. El desempate siempre
es `id ASC`, también cuando se solicita dirección descendente. Los nombres se ordenan
según la colación de PostgreSQL. No se admite `order[id]` ni ordenar por otros campos.

Valores cerrados:

| Parámetro | Valores |
|---|---|
| `conforme`, `activa`, `activo` | `true`, `false`, `1`, `0` (minúsculas; omitir incluye ambos estados) |
| `estado` | `abierta`, `en_proceso`, `resuelta` |
| `gravedad` | `baja`, `media`, `alta`, `critica` |
| `tipo` de evidencia | `foto`, `documento`, `firma` |
| `frecuencia` | `diaria`, `semanal`, `mensual`, `por_turno`, `por_recepcion`, `bajo_demanda` |

`firma` permite consultar metadatos históricos; no habilita nuevas subidas de ese tipo.
La confirmación del registro sigue usando el flujo de [evidencias y firma](evidencias-registros.md).

Las relaciones reciben IRI relativas canónicas, nunca identificadores sueltos:

| Parámetro | Formato |
|---|---|
| `tareaProgramada` | `/api/tareas-programadas/{id}` |
| `tarea` | `/api/tareas/{id}` |
| `usuario` | `/api/usuarios/{id}` |
| `registro` | `/api/registros/{id}` |
| `incidencia` | `/api/incidencias/{id}` |
| `planControl` | `/api/planes-control/{id}` |
| `puntoControl` | `/api/puntos-control/{id}` |

Cada IRI se resuelve como elemento mediante el provider ORM y `TenantExtension`.
Una IRI inexistente, de otro tipo o ajena al establecimiento seleccionado devuelve
**404**, incluso cuando el usuario pertenece a ambos establecimientos o la página
solicitada está vacía. El usuario referenciado requiere membresía activa en el tenant.
Una relación local válida sin coincidencias devuelve **200** con lista vacía.
`tarea` filtra mediante el JOIN SQL `registro.tareaProgramada.tarea`.

No se ocultan automáticamente tareas, planes ni puntos inactivos: pueden consultarse
para representar datos históricos. Usa `activa=true`/`activo=true` para los selectores.
En evidencias, `registro` e `incidencia` son padres alternativos; suministrar ambos
filtros válidos produce cero coincidencias por la regla XOR existente.

## Fechas

Los filtros de fecha admiten exclusivamente instantes ISO 8601 con precisión de
segundos y zona explícita: `YYYY-MM-DDTHH:MM:SSZ` o `YYYY-MM-DDTHH:MM:SS±HH:MM`.
Se rechazan días sin hora, fechas relativas, fechas imposibles, fracciones de segundo
y offsets fuera de ±14:00. El signo `+` debe codificarse como `%2B` en la URL.
Los offsets se convierten a la zona de persistencia antes de comparar en SQL.

`after` significa **mayor o igual** y `before` **menor o igual**. Para un día completo:
`after=2026-09-16T00:00:00+02:00`, `before=2026-09-16T23:59:59+02:00`.
Un rango invertido, sintácticamente válido, devuelve cero coincidencias.

## Subrecursos de solo lectura

| GET | Filtros adicionales | Orden |
|---|---|---|
| `/api/incidencias/{id}/acciones` | `usuario`, `fechaHora[after]`, `fechaHora[before]` | `fechaHora ASC`, `id ASC` |
| `/api/incidencias/{id}/historial` | `order[createdAt]` | `createdAt ASC`, `id ASC` por defecto |
| `/api/registros/{id}/evidencias` | `tipo` | `id ASC` |

Incluyen `page` e `itemsPerPage`, las mismas cabeceras, permisos y envoltura que las
colecciones principales. El padre fija la relación y se comprueba antes de consultar
los hijos: ajeno/inexistente → **404**; local sin hijos → **200** con lista vacía.
Las IRI de los elementos siguen siendo `/api/acciones-correctivas/{id}`,
`/api/historiales-incidencia/{id}` y `/api/evidencias/{id}`. POST/PATCH/DELETE sobre
estas rutas devuelven **405**. No se admite sobrescribir el padre con un filtro.

## Peticiones de ejemplo

En estos ejemplos, sustituye las IRI por las devueltas por tu API. `curl --data-urlencode`
preserva los corchetes, las barras y los offsets. Variables: `BASE=http://localhost:8000`,
`JWT=<token obtenido en login>` y `LOCAL=<id seleccionado tras /api/me>`.

```sh
# Registros no conformes de una tarea, en un intervalo y con los más recientes primero.
curl -G "$BASE/api/registros" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" -H 'Accept: application/ld+json' \
  --data-urlencode 'tarea=/api/tareas/8' --data-urlencode 'conforme=false' \
  --data-urlencode 'fechaHora[after]=2026-09-16T00:00:00+02:00' \
  --data-urlencode 'fechaHora[before]=2026-09-16T23:59:59+02:00' \
  --data-urlencode 'order[fechaHora]=desc' --data-urlencode 'itemsPerPage=30' --data-urlencode 'page=1'

curl -G "$BASE/api/incidencias" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'estado=abierta' --data-urlencode 'gravedad=alta' --data-urlencode 'order[fechaApertura]=desc'

curl -G "$BASE/api/acciones-correctivas" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'incidencia=/api/incidencias/12' --data-urlencode 'usuario=/api/usuarios/3' \
  --data-urlencode 'fechaHora[after]=2026-09-16T08:00:00Z'

curl -G "$BASE/api/historiales-incidencia" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'incidencia=/api/incidencias/12' --data-urlencode 'order[createdAt]=asc'

curl -G "$BASE/api/evidencias" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'registro=/api/registros/41' --data-urlencode 'tipo=foto'

curl -G "$BASE/api/tareas" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'planControl=/api/planes-control/2' --data-urlencode 'puntoControl=/api/puntos-control/5' \
  --data-urlencode 'frecuencia=diaria' --data-urlencode 'activa=true'

curl -G "$BASE/api/planes-control" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'activo=true' --data-urlencode 'order[nombre]=asc'

curl -G "$BASE/api/puntos-control" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'activo=true' --data-urlencode 'order[nombre]=asc'

curl -G "$BASE/api/incidencias/12/acciones" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'itemsPerPage=30' --data-urlencode 'page=2'
curl -G "$BASE/api/incidencias/12/historial" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'order[createdAt]=desc'
curl -G "$BASE/api/registros/41/evidencias" -H "Authorization: Bearer $JWT" -H "X-Establecimiento-Id: $LOCAL" \
  --data-urlencode 'tipo=foto'
```

## Errores y garantías

- **400**: cabecera tenant ausente/inválida; nombre de parámetro desconocido; array,
  duplicado, catálogo, orden, fecha o paginación inválidos.
- **401**: JWT ausente/inválido o cuenta no habilitada para autenticarse.
- **403**: sin membresía activa o tenant inactivo.
- **404**: IRI o padre inexistente/ajeno/tipo incorrecto.
- **200**: consulta válida, aunque no tenga resultados.

`ConsultaCollectionProvider` valida las claves completas (`order[fechaHora]`, etc.)
porque la validación estricta nativa de API Platform 4.3 compara claves raíz.
Después delega en el provider ORM estándar. Los filtros modifican el QueryBuilder;
`TenantExtension`, orden, COUNT y LIMIT/OFFSET se aplican en SQL. No se materializa
ni filtra una colección en PHP después de paginar. No cambian login, `/api/me`,
los permisos de escritura ni las migraciones. No se añaden dashboard, informes ni frontend.

Las pruebas HTTP de `ConsultasApiTest` cubren los filtros, sus combinaciones,
offsets, límites, desempates, subrecursos y aislamiento con varios tenants y roles.
`ConsultasOpenApiTest` comprueba que el contrato publicado coincide con las consultas.

## Validación de la entrega

Ejecutada el 17 de septiembre de 2026 sobre `main` (`be2b6e5`), con PHP 8.3.11,
PHPUnit 12.5.35 y PostgreSQL 18.3:

| Comprobación | Resultado |
|---|---|
| `composer validate --strict` | Correcto, código 0 |
| `php bin/console lint:container` | Correcto, código 0 |
| `php bin/console doctrine:migrations:migrate --no-interaction` | Base nueva con cero tablas: 10 migraciones y 161 consultas SQL, código 0 |
| `php bin/console doctrine:schema:validate` | Mapping correcto y esquema sincronizado, código 0 |
| `php bin/phpunit` | **465 pruebas, 2171 aserciones**, código 0 |
| `php bin/console api:openapi:export --output=var/consultas-openapi.json` | Exportación correcta con título, versión y límites verificados |

La suite completa incluye **93 casos HTTP nuevos y una prueba de OpenAPI**; no se
modificaron ni omitieron pruebas anteriores. Duración `03:58.705`, memoria `82.00 MB`.
Migraciones, esquema y suite se ejecutaron sobre la misma base PostgreSQL temporal
aislada, eliminada al finalizar. Los logs locales están en `var/consultas-validacion-*.log`
(fuera de Git). La validación es local; no se ejecutó CI remota.
