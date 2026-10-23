#!/usr/bin/env bash
# Cópia de segurança do Clinic: banco de dados + pasta das fotos e documentos
# (que já estão criptografados com a APP_KEY). Gera uma pasta
# clinic-AAAAMMDD-HHMMSS com database.sql.gz, files.tar.gz e SHA256SUMS.
#
# A cópia só é útil junto com APP_KEY e BLIND_INDEX_KEY, guardadas fora do
# servidor. Com BACKUP_PASSPHRASE, os dois arquivos saem criptografados (.enc).
set -euo pipefail
umask 077

: "${DB_HOST:?defina DB_HOST}" "${DB_DATABASE:?defina DB_DATABASE}" "${DB_USERNAME:?defina DB_USERNAME}" "${DB_PASSWORD:?defina DB_PASSWORD}"

backup_dir=${BACKUP_DIR:-/backups}
files_dir=${FILES_DIR:-/patient-files}
retention_days=${BACKUP_RETENTION_DAYS:-14}

# Sem senha, o dump sai legível (nome, telefone, endereço, auditoria). Só com
# BACKUP_ALLOW_PLAINTEXT=1, para quem cifra o destino por outro meio.
if [[ -z "${BACKUP_PASSPHRASE:-}" && "${BACKUP_ALLOW_PLAINTEXT:-0}" != "1" ]]; then
    echo "Defina BACKUP_PASSPHRASE: o backup não é gravado sem criptografia." >&2
    exit 1
fi

if [[ ! -d "$files_dir" ]]; then
    echo "Pasta das fotos não encontrada: $files_dir" >&2
    exit 1
fi

stamp=$(date +%Y%m%d-%H%M%S)
target="$backup_dir/clinic-$stamp"
work="$target.partial"
mkdir -p "$work"
# Cópia interrompida (erro, parada do container) não fica pela metade no disco.
trap 'rm -rf "$work"' ERR EXIT INT TERM

# Com senha, cada arquivo é cifrado no caminho, sem passar em claro pelo disco.
seal() {
    if [[ -n "${BACKUP_PASSPHRASE:-}" ]]; then
        openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -out "$1.enc" -pass env:BACKUP_PASSPHRASE
    else
        cat > "$1"
    fi
}

# --single-transaction: cópia consistente sem travar o sistema (InnoDB).
# A senha vai pelo ambiente, nunca pela linha de comando (visível no ps).
MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="$DB_USERNAME" \
    --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" | gzip -9 | seal "$work/database.sql.gz"

tar -C "$files_dir" -czf - . | seal "$work/files.tar.gz"

(cd "$work" && sha256sum -- * > SHA256SUMS)
mv "$work" "$target"
trap - ERR EXIT INT TERM

# Retenção: apaga as cópias mais antigas e sobras de cópias interrompidas.
find "$backup_dir" -mindepth 1 -maxdepth 1 -type d -name 'clinic-*' ! -name '*.partial' -mtime +"$retention_days" -exec rm -rf {} +
find "$backup_dir" -mindepth 1 -maxdepth 1 -type d -name 'clinic-*.partial' -mmin +1440 -exec rm -rf {} +

echo "Backup concluído: $target"
