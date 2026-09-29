# Reservas de inventario

API REST en PHP 8.5 y MySQL 8.4 para reservar stock de productos, idempotente por `request_id` y segura ante solicitudes concurrentes.

## Levantar el entorno

Requiere Docker con Docker Compose.

```sh
cp .env.example .env      # completar MYSQL_ROOT_PASSWORD y MYSQL_PASSWORD
docker compose up -d --build --wait
```

- La API queda en `http://localhost:8080` (puerto configurable con `API_PORT`), accesible solo desde este equipo; con `API_HOST=0.0.0.0` acepta conexiones de otros equipos de la red.
- MySQL queda en `127.0.0.1:3306` (puerto configurable con `MYSQL_PORT`) con el usuario y la contraseña de `.env`.
- Al levantarse por primera vez, MySQL crea la base de desarrollo con `db/schema.sql` y `db/seed.sql` (productos 1 y 2), y también la base de test vacía `<MYSQL_DATABASE>_test`.

Para reiniciar la base desde cero (se borran los datos):

```sh
docker compose down -v
docker compose up -d --build --wait
```

## Crear una reserva

```sh
curl -i -X POST http://localhost:8080/reservations \
  -H 'Content-Type: application/json' \
  -d '{"request_id": "REQ-2026-0001", "product_id": 1, "quantity": 3}'
```

```json
{"reservation_id": 1, "status": "confirmed", "remaining_stock": 7}
```

Responde `201` al crear la reserva y `200` al repetir un `request_id` ya procesado con los mismos `product_id` y `quantity` (devuelve la misma reserva sin descontar stock de nuevo).
Los errores responden `{"error": "<código>", "message": "..."}`:

| Caso | HTTP | `error` |
|---|---|---|
| JSON inválido | 400 | `invalid_json` |
| Campos faltantes o inválidos | 422 | `missing_field`, `invalid_request_id`, `invalid_product_id`, `invalid_quantity` |
| Producto inexistente | 404 | `product_not_found` |
| Stock insuficiente | 409 | `insufficient_stock` |
| `request_id` ya usado con otro `product_id` o `quantity` | 409 | `request_id_conflict` |
| Conflicto temporal de concurrencia | 503 | `retry` |
| Error inesperado | 500 | `internal_error` |
| Cuerpo mayor a 4096 bytes | 413 | lo responde Apache en HTML; la reserva no se procesa |

Ante un `500` o un `503`, reintentar con el mismo `request_id` y el mismo cuerpo: si la reserva llegó a confirmarse se devuelve con `200` y el stock no se descuenta dos veces; si no, se procesa como nueva.

## Pruebas

```sh
docker compose exec api vendor/bin/phpunit --testdox
```

Los tests llaman directamente a `Reservations` sobre la base `_test`, que se recrea antes de cada test; no tocan la base de desarrollo.

Script del caso crítico (stock 1 y dos solicitudes HTTP en paralelo, contra la base de desarrollo):

```sh
docker compose exec api php scripts/concurrency.php
```

Termina con código 0 si hubo una reserva confirmada, una rechazada y el stock final es 0.
Cada corrida crea su propio producto de prueba.

## Decisiones técnicas

| Área | Decisión | Por qué |
|---|---|---|
| Entorno | Docker Compose con versiones fijas (PHP 8.5.11, MySQL 8.4.11 LTS) | Se levanta con un comando y con las mismas versiones en cualquier equipo |
| Entorno | Apache prefork, no `php -S` | Atiende solicitudes en paralelo; la prueba de concurrencia es real |
| Diseño | Sin framework; PDO con SQL escrito a mano | Un solo endpoint; la transacción y los locks quedan explícitos |
| Concurrencia | `UPDATE … SET stock = stock - ? WHERE stock >= ?` | Valida y descuenta en una sola operación atómica: no hay sobreventa |
| Integridad | `CHECK (stock >= 0)`, `CHECK (quantity > 0)` y FK a `products` | La base rechaza datos inválidos aunque falle el código |
| Concurrencia | Espera máxima por locks de 5 s (antes 50 s) | Una transacción colgada no bloquea el producto ni agota Apache; se falla rápido con 503 |
| Concurrencia | Deadlock → 503 sin reintento automático | Es un caso extremo; el rollback es completo y el cliente puede reintentar sin riesgo |
| Idempotencia | `UNIQUE(request_id)` con collation binaria | La garantía la da MySQL, no solo PHP; `REQ-1` y `req-1` son distintos |
| Idempotencia | Reintento → 200 con la misma respuesta (`remaining_stock` guardado) | El cliente recibe exactamente la respuesta original; 201 solo al crear |
| Idempotencia | Mismo `request_id` con otros datos → 409 `request_id_conflict` | Evita que un cliente reciba una reserva que no es suya |
| Idempotencia | Los rechazos no se guardan | Un `request_id` rechazado por falta de stock puede reintentarse después |
| Validación | Solo enteros JSON; 422 para validación y 400 para JSON inválido | Evita conversiones ambiguas (`"3"`, `3.0`) |
| Pruebas | Base `_test` separada; los tests llaman la clase directamente | No tocan los datos de desarrollo y necesitan menos infraestructura |
| Pruebas | Concurrencia con dos procesos PHP reales; script por HTTP contra desarrollo | La competencia por el stock es real; el script demuestra el caso crítico de punta a punta |
| Seguridad | Credenciales en `.env` (no versionado); MySQL solo en `127.0.0.1` | No hay secretos en git y la base no queda expuesta a la red |