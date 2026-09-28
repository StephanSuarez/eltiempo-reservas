<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Reservations
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{int, array<string, mixed>} [código HTTP, cuerpo de la respuesta] */
    public function create(string $requestId, int $productId, int $quantity): array
    {
        // Reintento: se responde con la reserva original sin tocar el stock.
        $existing = $this->find($requestId);
        if ($existing !== null) {
            return $this->replay($existing, $productId, $quantity);
        }

        $this->pdo->beginTransaction();
        try {
            // Valida y descuenta en una sola sentencia. InnoDB bloquea la fila hasta el commit,
            // así que una solicitud concurrente evalúa el WHERE contra el stock ya descontado.
            $update = $this->pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            $update->execute([$quantity, $productId, $quantity]);
            if ($update->rowCount() === 0) {
                $this->pdo->rollBack();

                // Un reintento concurrente del mismo request_id llega aquí cuando la solicitud original consumió
                // el stock. Si este UPDATE vio ese descuento, la reserva se confirmó en el mismo commit y ya es visible.
                $existing = $this->find($requestId);
                if ($existing !== null) {
                    return $this->replay($existing, $productId, $quantity);
                }

                return $this->productExists($productId)
                    ? [409, self::rejected('insufficient_stock', 'Stock insuficiente.')]
                    : [404, self::rejected('product_not_found', 'El producto no existe.')];
            }

            $select = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
            $select->execute([$productId]);
            $remainingStock = $select->fetchColumn();

            $insert = $this->pdo->prepare(
                'INSERT INTO reservations (request_id, product_id, quantity, remaining_stock) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$requestId, $productId, $quantity, $remainingStock]);
            $reservationId = (int) $this->pdo->lastInsertId();

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // 1062: otra solicitud con el mismo request_id confirmó primero. El UNIQUE lo impide
            // y el rollback deshace nuestro descuento; se responde con la reserva ganadora.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return $this->replay($this->find($requestId), $productId, $quantity);
            }
            throw $e;
        }

        return [201, ['reservation_id' => $reservationId, 'status' => 'confirmed', 'remaining_stock' => $remainingStock]];
    }

    private function find(string $requestId): ?array
    {
        $select = $this->pdo->prepare(
            'SELECT id, product_id, quantity, remaining_stock FROM reservations WHERE request_id = ?'
        );
        $select->execute([$requestId]);

        return $select->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Respuesta para un request_id ya reservado: la misma de la creación, o conflicto si cambió el payload. */
    private function replay(array $reservation, int $productId, int $quantity): array
    {
        if ($reservation['product_id'] !== $productId || $reservation['quantity'] !== $quantity) {
            return [409, self::rejected('request_id_conflict', 'El request_id ya se usó con otro product_id o quantity.')];
        }

        return [200, [
            'reservation_id' => $reservation['id'],
            'status' => 'confirmed',
            'remaining_stock' => $reservation['remaining_stock'],
        ]];
    }

    private function productExists(int $productId): bool
    {
        $select = $this->pdo->prepare('SELECT 1 FROM products WHERE id = ?');
        $select->execute([$productId]);

        return $select->fetchColumn() !== false;
    }

    private static function rejected(string $error, string $message): array
    {
        return ['status' => 'rejected', 'error' => $error, 'message' => $message];
    }
}
