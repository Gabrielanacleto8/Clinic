#!/usr/bin/env bash
# Restaura uma cópia feita por backup.sh: confere as somas, decifra (se for o
# caso), importa o banco e devolve os arquivos à pasta das fotos.
#
# Uso: restore.sh /backups/clinic-AAAAMMDD-HHMMSS --sim
# O banco DB_DATABASE é sobrescrito. Use primeiro num banco vazio de teste
# para provar que a cópia abre (ver docs/deploy.md, "Teste de restauração").
set -euo pipefail
umask 077

source_dir=${1:?informe a pasta da cópia}
if [[ "${2:-}" != "--sim" ]]; then
    echo "Isto sobrescreve o banco ${DB_DATABASE:-?} e a pasta ${FILES_DIR:-/patient-files}. Repita com --sim para confirmar." >&2
    exit 1
fi

: "${DB_HOST:?defina DB_HOST}" "${DB_DATABASE:?defina DB_DATABASE}" "${DB_USERNAME:?defina DB_USERNAME}" "${DB_PASSWORD:?defina DB_PASSWORD}"
files_dir=${FILES_DIR:-/patient-files}

(cd "$source_dir" && sha256sum --check --quiet SHA256SUMS)

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

for name in database.sql.gz files.tar.gz; do
    if [[ -f "$source_dir/$name.enc" ]]; then
        : "${BACKUP_PASSPHRASE:?esta cópia é criptografada: defina BACKUP_PASSPHRASE}"
        openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$source_dir/$name.enc" -out "$work/$name" -pass env:BACKUP_PASSPHRASE
    else
        cp "$source_dir/$name" "$work/$name"
    fi
done

gunzip -c "$work/database.sql.gz" | MYSQL_PWD="$DB_PASSWORD" mysql \
    --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="$DB_USERNAME" \
    --default-character-set=utf8mb4 "$DB_DATABASE"

mkdir -p "$files_dir"
tar -C "$files_dir" -xzf "$work/files.tar.gz"

echo "Restauração concluída a partir de $source_dir"
