#!/bin/sh
# Antes de abrir o sistema: confere a configuração de produção e gera os
# caches de configuração, rotas, views e eventos com as variáveis deste
# container. Migrations NÃO rodam aqui: são um passo manual, depois do backup
# (ver docs/deploy.md).
set -e

php artisan clinic:check
php artisan optimize --quiet

exec "$@"
