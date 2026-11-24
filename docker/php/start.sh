#!/bin/sh
# Inicialização dos processos da imagem de produção.
# Gera os caches de configuração, rotas, eventos e views a partir do ambiente
# do container. Migrations NÃO são executadas aqui: rodam em um processo
# separado (serviço "migrate" ou job de deploy) com o papel de manutenção.
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY ausente: defina uma chave estável por ambiente." >&2
    exit 1
fi

# storage/framework pode ser um tmpfs vazio (sistema de arquivos somente
# leitura, compose.prod.yaml): recria a estrutura esperada pelo Laravel.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

php artisan optimize --no-interaction >/dev/null

exec "$@"
