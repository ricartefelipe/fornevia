#!/bin/sh
# Testes de navegador (Dusk) no ambiente Docker local.
#
#   docker compose --profile browser up -d selenium
#   sh scripts/browser-tests.sh [argumentos do pest]
#
# Sobe o servidor embutido do PHP num contêiner da aplicação, sobre o banco
# fornevia_browser e com a mesma chave do processo de teste
# (phpunit.browser.xml), e roda tests/Browser com o Chrome do Selenium.
set -eu

APP_KEY_BROWSER=$(sed -n 's/.*name="APP_KEY" value="\([^"]*\)".*/\1/p' phpunit.browser.xml)

docker compose run --rm -T --name fornevia-dusk \
    -e APP_URL=http://fornevia-dusk:8000 -e DUSK_DRIVER_URL=http://selenium:4444/wd/hub -e FORNEVIA_TEST_FRESH \
    -e APP_KEY_BROWSER="${APP_KEY_BROWSER}" \
    app sh -c '
        (cd public && env APP_ENV=local APP_DEBUG=false APP_KEY="$APP_KEY_BROWSER" APP_URL=http://fornevia-dusk:8000 \
            DB_DATABASE=fornevia_browser SESSION_DRIVER=file SESSION_SECURE_COOKIE=false CACHE_STORE=array \
            QUEUE_CONNECTION=sync MAIL_MAILER=array PAYMENTS_GATEWAY=simulator PASSWORD_BREACH_CHECK=false \
            BCRYPT_ROUNDS=4 REDIS_PREFIX=fornevia-browser- LOG_STACK=single \
            php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > ../storage/logs/browser-server.log 2>&1) &
        for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8000/health/live && break; sleep 1; done
        curl -sf -o /dev/null http://127.0.0.1:8000/health/live || { echo "Servidor da aplicação não respondeu."; cat storage/logs/browser-server.log; exit 1; }
        vendor/bin/pest -c phpunit.browser.xml "$@"
    ' sh "$@"
