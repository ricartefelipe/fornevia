# syntax=docker/dockerfile:1.12
#
# Imagens do Fornevia. Estágios:
#   base    - runtime PHP-FPM com extensões necessárias
#   dev     - ferramentas de desenvolvimento (composer, pcov, cliente postgres)
#   vendor  - dependências de produção do Composer
#   assets  - build dos assets com Vite
#   prod    - imagem final da aplicação (php-fpm, horizon, scheduler, relay)
#   web     - nginx com os arquivos públicos

ARG PHP_IMAGE=php:8.5.12-fpm-alpine3.24
ARG NODE_IMAGE=node:26.13.1-alpine3.24
ARG COMPOSER_IMAGE=composer:2.11.2
ARG EXT_INSTALLER_IMAGE=mlocati/php-extension-installer:2.13.2
ARG NGINX_IMAGE=nginxinc/nginx-unprivileged:1.30.6-alpine3.24

FROM ${COMPOSER_IMAGE} AS composer-bin
FROM ${EXT_INSTALLER_IMAGE} AS ext-installer

FROM ${PHP_IMAGE} AS base
COPY --from=ext-installer /usr/bin/install-php-extensions /usr/local/bin/
# A imagem base pode estar atrás das correções do Alpine: aplica as
# atualizações de segurança disponíveis antes de instalar as extensões.
RUN apk upgrade --no-cache \
    && install-php-extensions pdo_pgsql pgsql intl bcmath zip pcntl redis-6.3.0 \
    && apk add --no-cache fcgi tini
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
WORKDIR /var/www/html
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm"]

FROM base AS dev
ARG UID=1000
ARG GID=1000
RUN install-php-extensions pcov \
    && apk add --no-cache git unzip postgresql18-client bash \
    && cp /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini \
    && addgroup -g ${GID} app \
    && adduser -D -u ${UID} -G app -h /home/app app
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
ENV COMPOSER_HOME=/home/app/.composer
USER app

FROM base AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && rm -rf tests docker .github node_modules storage/logs/*.log

FROM ${NODE_IMAGE} AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY --from=vendor /var/www/html/vendor/livewire ./vendor/livewire
RUN npm run build

FROM base AS prod
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY --chmod=0755 docker/php/start.sh /usr/local/bin/fornevia-start
USER www-data
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
CMD ["fornevia-start", "php-fpm"]

FROM ${NGINX_IMAGE} AS web
# A imagem base pode estar atrás das correções do Alpine: aplica as
# atualizações de segurança disponíveis e volta ao usuário sem privilégios.
USER root
RUN apk upgrade --no-cache
USER 101
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=prod /var/www/html/public /var/www/html/public
ENV FPM_HOST=app FPM_PORT=9000
EXPOSE 8080
