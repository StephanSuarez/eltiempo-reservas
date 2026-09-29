<?php

declare(strict_types=1);

use App\Database;
use App\Reservations;
use PHPUnit\Framework\TestCase;

final class ReservationsTest extends TestCase
{
    private PDO $pdo;
    private Reservations $reservations;

    protected function setUp(): void
    {
        // El reinicio borra las tablas: nunca ejecutarlo fuera de la base de test.
        if (!str_ends_with((string) getenv('DB_NAME'), '_test')) {
            self::fail('DB_NAME debe apuntar a la base de test (sufijo _test).');
        }
        $this->pdo = Database::connect();
        $this->pdo->exec('DROP TABLE IF EXISTS reservations, products');
        $this->pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));
        $this->reservations = new Reservations($this->pdo);
    }

    public function testCreatesReservationAndDiscountsStock(): void
    {
        $productId = $this->product(stock: 10);

        [$status, $body] = $this->reservations->create('REQ-1', $productId, 3);

        self::assertSame(201, $status);
        self::assertSame(['reservation_id' => 1, 'status' => 'confirmed', 'remaining_stock' => 7], $body);
        self::assertSame(7, $this->stock($productId));
        self::assertSame(1, $this->reservationCount());
    }

    public function testRejectsWhenStockIsInsufficient(): void
    {
        $productId = $this->product(stock: 2);

        [$status, $body] = $this->reservations->create('REQ-1', $productId, 3);

        self::assertSame(409, $status);
        self::assertSame('insufficient_stock', $body['error']);
        self::assertSame(2, $this->stock($productId));
        self::assertSame(0, $this->reservationCount());
    }

    public function testRejectsUnknownProduct(): void
    {
        [$status, $body] = $this->reservations->create('REQ-1', 999, 1);

        self::assertSame(404, $status);
        self::assertSame('product_not_found', $body['error']);
        self::assertSame(0, $this->reservationCount());
    }

    public function testRetryWithSameRequestIdReturnsOriginalReservation(): void
    {
        $productId = $this->product(stock: 10);
        [, $created] = $this->reservations->create('REQ-1', $productId, 3);

        $retry = $this->reservations->create('REQ-1', $productId, 3);

        self::assertSame([200, $created], $retry);
        self::assertSame(7, $this->stock($productId));
        self::assertSame(1, $this->reservationCount());
    }

    public function testSameRequestIdWithDifferentPayloadIsConflict(): void
    {
        $productId = $this->product(stock: 10);
        $otherProductId = $this->product(stock: 10);
        $this->reservations->create('REQ-1', $productId, 3);

        [$otherProductStatus, $otherProductBody] = $this->reservations->create('REQ-1', $otherProductId, 3);
        [$otherQuantityStatus, $otherQuantityBody] = $this->reservations->create('REQ-1', $productId, 5);

        self::assertSame(409, $otherProductStatus);
        self::assertSame('request_id_conflict', $otherProductBody['error']);
        self::assertSame(409, $otherQuantityStatus);
        self::assertSame('request_id_conflict', $otherQuantityBody['error']);
        self::assertSame(7, $this->stock($productId));
        self::assertSame(10, $this->stock($otherProductId));
        self::assertSame(1, $this->reservationCount());
    }

    public function testConcurrentReservationsForLastUnitDoNotOversell(): void
    {
        $productId = $this->product(stock: 1);

        $results = $this->reserveInParallel([['REQ-A', $productId, 1], ['REQ-B', $productId, 1]]);

        $statuses = array_column($results, 0);
        sort($statuses);
        self::assertSame([201, 409], $statuses);
        self::assertSame(0, $this->stock($productId));
        self::assertSame(1, $this->reservationCount());
    }

    public function testConcurrentRequestsWithSameRequestIdDiscountOnce(): void
    {
        $productId = $this->product(stock: 5);

        // Ambos procesos pasan la búsqueda previa a la vez; el UNIQUE de request_id decide.
        $results = $this->reserveInParallel([['REQ-1', $productId, 1], ['REQ-1', $productId, 1]]);

        $statuses = array_column($results, 0);
        sort($statuses);
        self::assertSame([200, 201], $statuses);
        self::assertSame($results[0][1], $results[1][1]);
        self::assertSame(4, $this->stock($productId));
        self::assertSame(1, $this->reservationCount());
    }

    public function testConcurrentRetryWhenOriginalTakesLastUnitReturnsOriginalReservation(): void
    {
        $productId = $this->product(stock: 1);

        // El stock alcanza para una sola: el reintento no llega al INSERT y debe encontrar la reserva original.
        $results = $this->reserveInParallel([['REQ-1', $productId, 1], ['REQ-1', $productId, 1]]);

        $statuses = array_column($results, 0);
        sort($statuses);
        self::assertSame([200, 201], $statuses);
        self::assertSame($results[0][1], $results[1][1]);
        self::assertSame(0, $this->stock($productId));
        self::assertSame(1, $this->reservationCount());
    }

    public function testCancelReturnsStockAndDeletesReservation(): void
    {
        $productId = $this->product(stock: 10);
        [, $created] = $this->reservations->create('REQ-1', $productId, 3);

        [$status, $body] = $this->reservations->cancel('REQ-1');

        self::assertSame(200, $status);
        self::assertSame(['reservation_id' => $created['reservation_id'], 'status' => 'cancelled', 'remaining_stock' => 10], $body);
        self::assertSame(10, $this->stock($productId));
        self::assertSame(0, $this->reservationCount());
    }

    public function testCancelUnknownReservationIsNotFound(): void
    {
        [$status, $body] = $this->reservations->cancel('REQ-1');

        self::assertSame(404, $status);
        self::assertSame('reservation_not_found', $body['error']);
    }

    public function testConcurrentCancelsReturnStockOnce(): void
    {
        $productId = $this->product(stock: 10);
        $this->reservations->create('REQ-1', $productId, 3);

        $results = $this->runInParallel('cancel.php', array_fill(0, 5, ['REQ-1']));

        $statuses = array_column($results, 0);
        sort($statuses);
        self::assertSame([200, 404, 404, 404, 404], $statuses);
        self::assertSame(10, $this->stock($productId));
        self::assertSame(0, $this->reservationCount());
    }

    /**
     * Lanza un proceso PHP por solicitud, cada uno con su propia conexión, y los hace reservar en el mismo instante.
     *
     * @param list<array{string, int, int}> $requests [request_id, product_id, quantity]
     * @return list<array{int, array<string, mixed>}>
     */
    private function reserveInParallel(array $requests): array
    {
        return $this->runInParallel(
            'reserve.php',
            array_map(fn ($request) => [$request[0], (string) $request[1], (string) $request[2]], $requests),
        );
    }

    /**
     * Ejecuta el script de tests/ una vez por lista de argumentos, en procesos simultáneos.
     *
     * @param list<list<string>> $argumentLists
     * @return list<array{int, array<string, mixed>}>
     */
    private function runInParallel(string $script, array $argumentLists): array
    {
        $startAt = (string) (microtime(true) + 0.5);
        $processes = [];
        foreach ($argumentLists as $arguments) {
            $command = [PHP_BINARY, __DIR__ . '/' . $script, ...$arguments, $startAt];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    private function product(int $stock): int
    {
        $this->pdo->prepare('INSERT INTO products (name, stock) VALUES (?, ?)')->execute(['Producto', $stock]);

        return (int) $this->pdo->lastInsertId();
    }

    private function stock(int $productId): int
    {
        $select = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $select->execute([$productId]);

        return $select->fetchColumn();
    }

    private function reservationCount(): int
    {
        return $this->pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();
    }
}
