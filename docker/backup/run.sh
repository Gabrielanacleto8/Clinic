#!/usr/bin/env bash
# Serviço "backup": roda backup.sh todo dia na hora BACKUP_HOUR (horário de TZ).
set -uo pipefail

hour=${BACKUP_HOUR:-2}

while true; do
    now=$(date +%s)
    next=$(date -d "today ${hour}:00" +%s)
    if (( next <= now )); then
        next=$(date -d "tomorrow ${hour}:00" +%s)
    fi
    echo "Próximo backup: $(date -d "@$next" '+%d/%m/%Y %H:%M')"
    sleep $(( next - now ))

    if ! /opt/clinic-backup/backup.sh; then
        echo "ERRO: o backup falhou. Confira o log deste serviço." >&2
    fi
done
