<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Reservations;

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $error, string $message): never
{
    respond($status, ['error' => $error, 'message' => $message]);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/reservations/([^/]+)$#', $path, $matches) === 1) {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        header('Allow: DELETE');
        fail(405, 'method_not_allowed', 'Método no permitido.');
    }

    $requestId = rawurldecode($matches[1]);
    if (trim($requestId) === '' || mb_strlen($requestId) > 64) {
        fail(422, 'invalid_request_id', 'request_id debe ser un texto no vacío de hasta 64 caracteres.');
    }

    try {
        [$status, $body] = (new Reservations(Database::connect()))->cancel($requestId);
    } catch (Throwable $e) {
        error_log((string) $e);
        fail(500, 'internal_error', 'Error interno.');
    }

    respond($status, $body);
}

if ($path !== '/reservations') {
    fail(404, 'not_found', 'Ruta no encontrada.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail(405, 'method_not_allowed', 'Método no permitido.');
}

try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fail(400, 'invalid_json', 'El cuerpo no es un JSON válido.');
}
// {} se decodifica como [], así que [] cuenta como objeto vacío; una lista no.
if (!is_array($input) || ($input !== [] && array_is_list($input))) {
    fail(400, 'invalid_json', 'El cuerpo debe ser un objeto JSON.');
}

$missing = array_filter(['request_id', 'product_id', 'quantity'], fn ($field) => ($input[$field] ?? null) === null);
if ($missing !== []) {
    fail(422, 'missing_field', 'Faltan campos obligatorios: ' . implode(', ', $missing) . '.');
}

// Solo enteros JSON: "3", 3.0 o true se rechazan.
$requestId = $input['request_id'];
if (!is_string($requestId) || trim($requestId) === '' || mb_strlen($requestId) > 64) {
    fail(422, 'invalid_request_id', 'request_id debe ser un texto no vacío de hasta 64 caracteres.');
}
if (!is_int($input['product_id']) || $input['product_id'] <= 0) {
    fail(422, 'invalid_product_id', 'product_id debe ser un entero mayor que 0.');
}
if (!is_int($input['quantity']) || $input['quantity'] <= 0) {
    fail(422, 'invalid_quantity', 'quantity debe ser un entero mayor que 0.');
}

try {
    $reservations = new Reservations(Database::connect());
    [$status, $body] = $reservations->create($requestId, $input['product_id'], $input['quantity']);
} catch (Throwable $e) {
    error_log((string) $e);
    // 1213 = deadlock, 1205 = lock wait timeout: la transacción ya se revirtió y el cliente puede reintentar.
    if ($e instanceof PDOException && in_array($e->errorInfo[1] ?? null, [1205, 1213], true)) {
        fail(503, 'retry', 'Conflicto temporal de concurrencia. Reintenta la solicitud.');
    }
    fail(500, 'internal_error', 'Error interno.');
}

respond($status, $body);
