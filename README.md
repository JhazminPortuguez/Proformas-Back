# Proformas — Backend

API REST en Laravel 12 + Sanctum (autenticación por sesión) + PostgreSQL 17.

## Requisitos

- PHP 8.2+ con `pdo_pgsql` (recomendado también `intl`).
- Composer 2.
- Docker Desktop (para PostgreSQL).

## Puesta en marcha

```powershell
# 1. Base de datos (PostgreSQL 17 en el puerto 5434)
docker compose up -d

# 2. Dependencias y entorno
composer install
copy .env.example .env
php artisan key:generate

# 3. Esquema + datos iniciales
php artisan migrate --seed

# 4. Servidor
php artisan serve --port=8000
```

Credenciales de ejemplo (solo local):

| Usuario | Email | Password | Rol |
|---|---|---|---|
| Pepito Tilcayo | pepitotilcayo@example.com | `password` | admin |
| Operario | operario@example.com | `password` | operario |
| Cliente Demo | cliente.demo@example.com | `password` | cliente |

El password del admin se puede cambiar con `ADMIN_PASSWORD` en `.env`.
Los usuarios con rol `vendedor` de instalaciones anteriores se migran automáticamente a `operario`.

## Puertos

| Servicio | Puerto |
|---|---|
| API Laravel | 8000 |
| PostgreSQL | 5434 |
| Frontend Next.js | 3000 |

## Roles y permisos

Los roles definitivos viven en `App\Enums\Rol`:

- `admin`: administra la empresa y además opera.
- `operario`: realiza la operación diaria (clientes, productos y proformas).
- `cliente`: usuario externo del portal. En Fase 0 solo existe el registro y el placeholder del portal; **no** accede a rutas internas.

| Acción | Admin | Operario | Cliente |
|---|---|---|---|
| Ver clientes/productos/proformas | ✅ | ✅ | ❌ 403 |
| Crear/editar clientes, productos y proformas | ✅ | ✅ | ❌ 403 |
| Eliminar clientes, productos y proformas | ✅ | ❌ 403 | ❌ 403 |
| Ver servicios | ✅ | ✅ | ❌ 403 |
| Crear/editar/eliminar servicios | ✅ | ❌ 403 | ❌ 403 |
| Gestionar usuarios y asignar roles/activo | ✅ | ❌ 403 | ❌ 403 |
| Dashboard interno | ✅ | ✅ | ❌ 403 |

Reglas adicionales:

- No se puede eliminar un cliente con proformas (HTTP 409).
- No se puede eliminar un producto incluido en proformas (HTTP 409).
- Un admin no puede eliminarse a sí mismo, autodegradarse ni desactivar su propia cuenta.
- No se puede degradar ni desactivar al último administrador de la empresa.
- Al asignar rol `admin` u `operario` a un usuario se limpia su `cliente_id`.
- Login limitado a 5 intentos por minuto por email+IP; registro limitado a 5 por minuto por IP.
- Un usuario con `activo = false` recibe HTTP 403 al iniciar sesión: `"Tu cuenta está desactivada."`.

## Registro y portal cliente

`POST /api/v1/auth/register` es público y crea una cuenta de portal:

1. Valida `name`, `email`, `password` + `password_confirmation` y `telefono` opcional.
2. Busca un cliente de la empresa actual por email (sin distinguir mayúsculas).
   - Si no existe, crea un cliente en estado `prospecto` con `nombre_razon = name`.
   - Si existe, lo vincula sin duplicarlo y conserva su estado actual.
3. Crea el usuario con rol `cliente`, `activo = true`, `cliente_id` y `empresa_id`.
4. Autentica al usuario con la sesión de Sanctum y devuelve HTTP 201.

Estados de cliente: `prospecto`, `activo`, `inactivo`.

## Empresas y aislamiento

- Tabla `empresas` con la empresa 1 (`Empresa Demo`).
- `users`, `clientes`, `productos`, `proformas` y `servicios` tienen `empresa_id` (FK, index, default 1).
- El trait `App\Models\Concerns\PerteneceAEmpresa` aplica un global scope por empresa y autocompleta `empresa_id` al crear.
- `App\Support\EmpresaActual::id()` devuelve `auth()->user()->empresa_id` o `EMPRESA_DEFAULT_ID` para procesos públicos/CLI.
- La numeración de proformas es única por empresa y año (`proforma_sequences`: unique `empresa_id + anio`), formato `PR-2026-000001`.
- Esto prepara la futura multitenencia sin cambiar el contrato actual de una sola empresa.

## Roles: asignación desde Usuarios

`POST/PUT /api/v1/users` acepta `rol` (`admin`, `operario`, `cliente`), `activo` (boolean) y `cliente_id` opcional. Solo un admin puede gestionar usuarios. Si el rol asignado es interno, `cliente_id` se guarda como `null`.

## Flujo de una proforma

`BORRADOR → ENVIADA → ACEPTADA | RECHAZADA`.

- Solo se edita el contenido (ítems, montos, fechas) en `BORRADOR`.
- Los estados finales no vuelven atrás.
- El precio unitario se toma del producto en el servidor (el cliente no lo impone).
- Totales: `total = subtotal - descuento + impuesto`, redondeados a 2 decimales.
- Numeración correlativa por empresa y año: `PR-2026-000001`.

## Endpoints (API v1)

Todas las rutas cuelgan de `/api/v1`.

### Autenticación y sesión

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| POST | `/api/v1/auth/register` | pública | Registro de cliente portal (autologin, 201) |
| POST | `/api/v1/auth/login` | pública | Inicia sesión (requiere CSRF previo) |
| POST | `/api/v1/auth/logout` | sesión | Cierra sesión (204) |
| GET | `/api/v1/auth/me` | sesión | Usuario autenticado |

### Operación interna

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| GET | `/api/v1/dashboard` | admin, operario | Métricas del panel |
| GET/POST | `/api/v1/clientes` | admin, operario | Listado paginado / alta |
| GET/PUT/DELETE | `/api/v1/clientes/{id}` | admin, operario (DELETE solo admin) | Detalle / edición / borrado |
| GET/POST | `/api/v1/productos` | admin, operario | Listado paginado / alta |
| GET/PUT/DELETE | `/api/v1/productos/{id}` | admin, operario (DELETE solo admin) | Detalle / edición / borrado |
| GET/POST | `/api/v1/proformas` | admin, operario | Listado filtrable / alta |
| GET/PUT/DELETE | `/api/v1/proformas/{id}` | admin, operario (DELETE solo admin) | Detalle / edición / borrado |
| GET | `/api/v1/servicios` | admin, operario | Listado paginado / búsqueda |
| GET | `/api/v1/servicios/{id}` | admin, operario | Detalle |
| POST/PUT/DELETE | `/api/v1/servicios` y `/{id}` | solo admin | CRUD interno de servicios |
| GET/POST | `/api/v1/users` | solo admin | Listado / alta |
| GET/PUT/DELETE | `/api/v1/users/{id}` | solo admin | Detalle / edición / borrado |

Listados: `?page=1&per_page=15`.
Respuesta paginada: `{ "data": [...], "meta": { "current_page", "per_page", "total", "last_page", "from", "to" } }`.

Filtros por recurso:

| Recurso | Parámetros |
|---|---|
| `clientes` | `search` (nombre, NIT/CI, email), `estado` (`prospecto`, `activo`, `inactivo`) |
| `productos` | `search` (nombre), `activo` |
| `proformas` | `estado`, `cliente_id`, `desde`, `hasta` |
| `servicios` | `search` (nombre, descripción, categoría), `activo`, `visible_catalogo`, `categoria` |
| `users` | `search` (nombre, email), `rol`, `activo` |

### Contrato de `POST /api/v1/auth/register`

```json
{
  "name": "María Pérez",
  "email": "maria@example.com",
  "password": "secret123",
  "password_confirmation": "secret123",
  "telefono": "77712345"
}
```

Respuesta `201`:

```json
{
  "id": 10,
  "name": "María Pérez",
  "email": "maria@example.com",
  "email_verified_at": null,
  "rol": "cliente",
  "activo": true,
  "cliente_id": 5,
  "empresa_id": 1,
  "cliente": {
    "id": 5,
    "nombre_razon": "María Pérez",
    "nit_ci": null,
    "telefono": "77712345",
    "email": "maria@example.com",
    "direccion": null,
    "estado": "prospecto",
    "empresa_id": 1,
    "created_at": "2026-10-23T20:00:00.000000Z",
    "updated_at": "2026-10-23T20:00:00.000000Z"
  },
  "created_at": "2026-10-23T20:00:00.000000Z",
  "updated_at": "2026-10-23T20:00:00.000000Z"
}
```

### Contrato de servicios

`POST /api/v1/servicios`:

```json
{
  "nombre": "Instalación premium",
  "descripcion": "Instalación con soporte extendido.",
  "precio_base": 1200.50,
  "duracion_estimada_min": 180,
  "categoria": "Instalación",
  "activo": true,
  "visible_catalogo": true
}
```

Respuesta `201`:

```json
{
  "id": 1,
  "empresa_id": 1,
  "nombre": "Instalación premium",
  "descripcion": "Instalación con soporte extendido.",
  "precio_base": 1200.5,
  "duracion_estimada_min": 180,
  "categoria": "Instalación",
  "activo": true,
  "visible_catalogo": true,
  "created_at": "2026-10-23T20:00:00.000000Z",
  "updated_at": "2026-10-23T20:00:00.000000Z"
}
```

Los filtros booleanos aceptan `1/0`, `true/false`.

### Contrato de `GET /api/v1/dashboard`

Disponible para `admin` y `operario`.

```json
{
  "clientes": 12,
  "servicios": 4,
  "proformas": 30,
  "proformas_por_estado": {
    "BORRADOR": 5,
    "ENVIADA": 10,
    "ACEPTADA": 14,
    "RECHAZADA": 1
  },
  "monto_proformas_mes": 12500.75,
  "monto_cotizado_mes": 12500.75,
  "monto_aceptado_mes": 9800.00,
  "ordenes_pendientes": 2,
  "saldo_por_cobrar": 265.00,
  "ordenes": {
    "total": 3,
    "activas": 2,
    "pendientes": 1,
    "asignadas": 1,
    "en_proceso": 0,
    "finalizadas": 1,
    "canceladas": 1,
    "por_estado": {
      "PENDIENTE": 1,
      "ASIGNADA": 1,
      "EN_PROCESO": 0,
      "FINALIZADA": 1,
      "CANCELADA": 1
    },
    "finalizadas_mes": 1,
    "cerradas_mes": 1
  },
  "inventario": {
    "productos": 6,
    "stock_bajo": 1,
    "valor_inventario": 8470.00,
    "movimientos_mes": 7,
    "productos_bajo_stock": [
      {
        "id": 10,
        "nombre": "Ventilador de reemplazo",
        "stock_actual": 7,
        "stock_minimo": 10,
        "unidad_medida": "unidad"
      }
    ]
  },
  "cobros": {
    "cuentas": 1,
    "pendientes": 1,
    "vencidas": 0,
    "saldo": 265.00,
    "monto_vencido": 0,
    "cobrado_mes": 265.00,
    "monto_total": 530.00,
    "aging": {
      "al_dia": 265.00,
      "d1_30": 0,
      "d31_60": 0,
      "d61_90": 0,
      "d90_plus": 0
    }
  },
  "ingresos_mes": 265.00,
  "servicios_mas_solicitados": [
    {
      "servicio_id": 1,
      "nombre": "Instalación básica",
      "cantidad": 3,
      "total": 1050.00
    }
  ],
  "actividad_reciente": [
    {
      "tipo": "pago",
      "id": 10,
      "descripcion": "Pago CXC-2026-000001",
      "estado": null,
      "monto": 265.00,
      "cuenta_id": 1,
      "fecha": "2026-10-23T18:30:00.000000Z",
      "usuario": "Pepito Tilcayo"
    }
  ]
}
```

- `clientes`, `servicios`, `proformas`: totales de la empresa actual.
- `proformas_por_estado`: siempre incluye los cuatro estados, con `0` cuando no hay registros.
- `monto_proformas_mes`: alias legacy de `monto_cotizado_mes` (`BORRADOR + ENVIADA` del mes).
- `monto_aceptado_mes`: proformas `ACEPTADA` del mes por `fecha_emision`.
- `ordenes_pendientes`: alias legacy de `ordenes.activas` (`PENDIENTE + ASIGNADA + EN_PROCESO`).
- `ordenes.por_estado`: siempre incluye los cinco estados; `finalizadas_mes` usa `fecha_fin` y `cerradas_mes` usa `cerrada_at`.
- `inventario.stock_bajo`: productos con `stock_actual <= stock_minimo`; `productos_bajo_stock` devuelve hasta 5 ordenados por déficit.
- `saldo_por_cobrar`: alias legacy de `cobros.saldo` (suma de saldos mayores a cero).
- `cobros.aging`: franjas por días vencidos; `al_dia` incluye las cuentas no vencidas con saldo.
- `cobros.cobrado_mes` e `ingresos_mes`: pagos no anulados con `fecha_pago` dentro del mes actual.
- `servicios_mas_solicitados`: hasta 5 servicios por cantidad, excluyendo órdenes canceladas.
- `actividad_reciente`: hasta 10 eventos combinando proformas, órdenes, pagos, movimientos y clientes, ordenados por fecha descendente.

El frontend usa `GET /sanctum/csrf-cookie` y envía `X-XSRF-TOKEN` + cookies (`credentials: include`).

## Tests y estilo

```powershell
composer test     # 70 tests (SQLite en memoria)
composer lint     # Pint en modo verificación
composer format   # Pint aplica formato
```

Los tests usan SQLite `:memory:`; las restricciones/esquema específicos de PostgreSQL (checks y FKs agregadas a tablas existentes) están guardados por driver.

## Notas

- Zona horaria de negocio: `America/La_Paz` (`APP_TIMEZONE`).
- `EMPRESA_DEFAULT_ID=1` identifica la empresa usada por procesos públicos y seeders.
- `SESSION_COOKIE=laravel-session` debe coincidir con `SESSION_COOKIE_NAME` del front.
- CORS es configurable con `CORS_ALLOWED_ORIGINS`; en desarrollo el front usa proxy same-origin, por lo que CORS no es imprescindible.
- Producción: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (HTTPS), `SESSION_DOMAIN` del dominio real y credenciales distintas a las de ejemplo.
- `personal_access_tokens` queda disponible para una futura API por tokens; hoy la autenticación es solo por sesión.
- Catálogo público y solicitudes de cotización del cliente son Fase 1; en Fase 0 solo existe la tabla `servicios` y su CRUD interno.
