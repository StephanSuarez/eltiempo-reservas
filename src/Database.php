<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME')),
            getenv('DB_USER'),
            getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        // Las transacciones duran milisegundos: una espera más larga es una anomalía. Se corta a los 5 s
        // (el default es 50) para responder 503 en vez de retener la fila del producto y el proceso de Apache.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $pdo;
    }
}
