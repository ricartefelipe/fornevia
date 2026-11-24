#!/bin/sh
# Testes de navegador (Dusk) no ambiente Docker local.
#
#   docker compose --profile browser up -d selenium
#   sh scripts/browser-tests.sh [argumentos do pest]
#
# Sobe o servidor embutido do PHP num contêiner da aplicação, sobre o banco
# fornevia_browser, e roda tests/Browser com o Chrome do Selenium.
set -eu

SERVER_ENV="APP_ENV=local APP_DEBUG=false APP_URL=http://fornevia-dusk:8000 DB_DATABASE=fornevia_browser \
SESSION_DRIVER=file SESSION_SECURE_COOKIE=false CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
PAYMENTS_GATEWAY=simulator PASSWORD_BREACH_CHECK=false BCRYPT_ROUNDS=4 REDIS_PREFIX=fornevia-browser- LOG_STACK=single"

docker compose run --rm -T --name fornevia-dusk \
    -e APP_URL=http://fornevia-dusk:8000 -e DUSK_DRIVER_URL=http://selenium:4444/wd/hub -e FORNEVIA_TEST_FRESH \
    app sh -c "(cd public && env ${SERVER_ENV} php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > ../storage/logs/browser-server.log 2>&1) &
        sleep 1 && vendor/bin/pest -c phpunit.browser.xml $*"
