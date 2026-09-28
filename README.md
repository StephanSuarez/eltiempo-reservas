# Reservas de inventario

API REST en PHP 8.5 y MySQL 8.4 para reservar stock de productos, idempotente por `request_id` y segura ante solicitudes concurrentes.

## Levantar el entorno

Requiere Docker con Docker Compose.

```sh
cp .env.example .env      # completar MYSQL_ROOT_PASSWORD y MYSQL_PASSWORD
docker compose up -d --build --wait
```

- La API queda en `http://localhost:8080` (puerto configurable con `API_PORT`).
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
