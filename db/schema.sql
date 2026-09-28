-- Estructura de la base. Lo usa MySQL al inicializarla y los tests para recrear la base de test.
CREATE TABLE products (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(255) NOT NULL,
    stock INT NOT NULL,
    CONSTRAINT chk_products_stock CHECK (stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reservations (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- Collation binaria sin PAD: 'REQ-1', 'req-1' y 'REQ-1 ' son request_id distintos.
    request_id      VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    quantity        INT NOT NULL,
    -- Stock que quedó al confirmar; permite que un reintento devuelva la misma respuesta.
    remaining_stock INT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_reservations_request_id UNIQUE (request_id),
    CONSTRAINT fk_reservations_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_reservations_quantity CHECK (quantity > 0),
    CONSTRAINT chk_reservations_remaining_stock CHECK (remaining_stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
