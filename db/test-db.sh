#!/bin/sh
# Crea la base de test en el mismo MySQL, con el mismo usuario de la aplicación.
# Las tablas las recrea cada test ejecutando db/schema.sql.
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\`;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test\`.* TO '${MYSQL_USER}'@'%';
SQL
