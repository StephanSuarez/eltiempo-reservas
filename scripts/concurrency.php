<?php

// Caso crítico por HTTP contra la base de desarrollo: stock 1 y dos reservas en paralelo.
// Cada corrida crea su propio producto, así no depende de datos previos ni los modifica.
// Uso: docker compose exec api php scripts/concurrency.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;

$pdo = Database::connect();
$pdo->exec("INSERT INTO products (name, stock) VALUES ('Prueba de concurrencia', 1)");
$productId = (int) $pdo->lastInsertId();
$run = bin2hex(random_bytes(4));

$multi = curl_multi_init();
$handles = [];
foreach (['A', 'B'] as $name) {
    $handle = curl_init('http://localhost/reservations');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['request_id' => "CONC-$run-$name", 'product_id' => $productId, 'quantity' => 1]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_multi_add_handle($multi, $handle);
    $handles[$name] = $handle;
}

// Ambas solicitudes salen a la vez y Apache (prefork) las atiende en procesos distintos.
do {
    $status = curl_multi_exec($multi, $running);
    if ($running) {
        curl_multi_select($multi);
    }
} while ($running && $status === CURLM_OK);

$codes = [];
foreach ($handles as $name => $handle) {
    $codes[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    printf("Solicitud %s: HTTP %d %s\n", $name, end($codes), curl_multi_getcontent($handle));
}

$select = $pdo->prepare('SELECT stock, (SELECT COUNT(*) FROM reservations WHERE product_id = ?) FROM products WHERE id = ?');
$select->execute([$productId, $productId]);
[$stock, $reservations] = $select->fetch(PDO::FETCH_NUM);
printf("Producto %d: stock final %d, reservas %d\n", $productId, $stock, $reservations);

sort($codes);
$ok = $codes === [201, 409] && $stock === 0 && $reservations === 1;
echo $ok
    ? "OK: una reserva confirmada, una rechazada y stock final 0.\n"
    : "FALLO: se esperaba una confirmada (201), una rechazada (409) y stock final 0.\n";
exit($ok ? 0 : 1);
