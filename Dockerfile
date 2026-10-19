#syntax=docker/dockerfile:1

# Adapted from https://github.com/dunglas/symfony-docker.

FROM dunglas/frankenphp:1.12.7-php8.5-trixie AS frankenphp_upstream

FROM node:24-trixie-slim AS node_upstream

FROM node_upstream AS symbols

WORKDIR /srv/symbols

COPY --link symbols/package.json symbols/package-lock.json ./

# The install scripts of the grammars compile Node bindings, which the service does not load.
RUN npm ci --ignore-scripts --no-audit --no-fund && rm -rf /root/.npm

COPY --link symbols/src src
COPY --link symbols/queries queries

USER node

ENV SYMBOLS_HOST=0.0.0.0

EXPOSE 3000

CMD ["node", "src/server.ts"]

FROM node_upstream AS assets

WORKDIR /app

COPY --link package.json package-lock.json ./
COPY --link bin/patch-react-router.mjs bin/

RUN npm ci --no-audit --no-fund && rm -rf /root/.npm

COPY --link vite.config.ts tsconfig*.json ./
COPY --link assets assets

RUN npm run build

FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		acl \
		git \
		unzip
	install-php-extensions \
		@composer \
		intl \
		opcache \
		pcntl \
		pdo_pgsql \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY --link docker/frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

FROM frankenphp_base AS frankenphp_dev

ENV XDEBUG_MODE=off

# The playwright npm version of the system libraries below. `make e2e` pins the playwright-php server to it.
ENV PLAYWRIGHT_VERSION=1.63.0

# On the playwright_browsers volume. vendor/bin/playwright-install fills it.
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

COPY --link --from=node_upstream /usr/local/bin/node /usr/local/bin/node
COPY --link --from=node_upstream /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm

RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions \
		gd \
		xdebug
	ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
	ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
	npx --yes "playwright@${PLAYWRIGHT_VERSION}" install-deps chromium
	rm -rf /var/lib/apt/lists/* /root/.npm
EOF

COPY --link docker/frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

FROM frankenphp_base AS frankenphp_prod_builder

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link docker/frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

COPY --link composer.json composer.lock symfony.lock ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link --exclude=assets --exclude=docker --exclude=symbols --exclude=package*.json --exclude=tsconfig*.json --exclude=vite.config.ts . ./
COPY --link --from=assets /app/public/build public/build
COPY --link --from=assets /app/public/entrypoints.json /app/public/manifest.json public/

RUN <<-EOF
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	mkdir -p var/sessions var/mcp-sessions
EOF

FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod

RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
	mkdir -p /srv/slopmower/tenants
	chown -R www-data:www-data /config/caddy /data/caddy /srv/slopmower
EOF

COPY --link docker/frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/
COPY --link docker/frankenphp/Caddyfile.prod /etc/frankenphp/Caddyfile

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
COPY --link --chown=www-data:www-data --from=frankenphp_prod_builder /app/var /app/var

USER www-data
