<?php

// Proceso independiente para los tests de concurrencia: abre su propia conexión,
// espera hasta el instante acordado y reserva. Imprime [código, cuerpo] en JSON.
// Uso: php tests/reserve.php <request_id> <product_id> <quantity> <start_at>

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Reservations;

[, $requestId, $productId, $quantity, $startAt] = $argv;

$reservations = new Reservations(Database::connect());
usleep(max(0, (int) (((float) $startAt - microtime(true)) * 1_000_000)));

echo json_encode($reservations->create($requestId, (int) $productId, (int) $quantity));
