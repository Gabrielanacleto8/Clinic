#!/bin/sh
# Cria o banco usado pela suíte de testes, isolado do banco de desenvolvimento.
set -eu

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_testing\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_testing\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
