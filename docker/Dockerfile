FROM composer:2 AS composer

FROM php:8.3-fpm-alpine

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Node 24 is copied in for the Vite build. Wayfinder shells out to PHP, so the
# frontend has to be built in this image rather than a Node-only stage.
COPY --from=node:24-alpine /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-alpine /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apk --no-cache add \
        nginx \
        supervisor \
        curl \
        gettext \
        bash \
        su-exec \
        postgresql-dev \
        postgresql-client \
        libpng-dev \
        zlib-dev \
        oniguruma-dev \
        libzip-dev \
        zip \
        unzip \
        autoconf \
        gcc \
        g++ \
        make \
        linux-headers \
        libtool \
    && addgroup nginx www-data \
    && docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install pdo pgsql pdo_pgsql gd bcmath zip exif pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-dev \
    && composer clear-cache

COPY package.json package-lock.json ./
RUN npm ci && npm cache clean --force

COPY . .

ARG VITE_APP_NAME=LaraOwl
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

RUN export APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && npm run build \
    && rm -rf node_modules \
    && mkdir -p storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker-app.conf
COPY docker/entrypoint /usr/local/bin/entrypoint
ENV PORT=8080
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p /etc/nginx/snippets /var/log/supervisor \
    && rm -f /etc/nginx/http.d/default.conf

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
