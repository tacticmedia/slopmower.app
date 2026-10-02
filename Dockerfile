#syntax=docker/dockerfile:1

# Adapted from https://github.com/dunglas/symfony-docker. Local development only.

FROM dunglas/frankenphp:1.12.7-php8.5-trixie AS frankenphp_upstream

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

ENV PANTHER_NO_SANDBOX=1
ENV PANTHER_CHROME_ARGUMENTS=--disable-dev-shm-usage
ENV PANTHER_CHROME_BINARY=/usr/bin/chromium

# hadolint ignore=DL3008
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	apt-get update
	apt-get install -y --no-install-recommends \
		chromium \
		chromium-driver
	rm -rf /var/lib/apt/lists/*
EOF

COPY --link docker/frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/
