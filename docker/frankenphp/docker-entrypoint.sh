#!/bin/sh
# Adapted from https://github.com/dunglas/symfony-docker. The dev and the prod image use it.
set -e

# The prod image sets APP_ENV. In dev, only the .env files set it.
if [ "${APP_ENV:-}" = 'prod' ]; then
	DEVELOPMENT=
else
	DEVELOPMENT=1
fi

# Only the php service installs, so the worker never races it on the bind mount. The md5 is written only after a
# successful install, so a failed install runs again at the next start.
if [ -n "$DEVELOPMENT" ] && [ "$1" = 'frankenphp' ]; then
	LOCK_MD5=$(md5sum composer.lock | cut -d ' ' -f 1)
	if [ "$LOCK_MD5" != "$(cat vendor/composer.lock.md5 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
		echo "$LOCK_MD5" > vendor/composer.lock.md5
	fi

	php bin/console -V
fi

if [ -n "${MIGRATE_DB:-}" ]; then
	echo 'Waiting for the database'
	ATTEMPTS_LEFT_TO_REACH_DATABASE=60
	until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
		if [ $? -eq 255 ]; then
			ATTEMPTS_LEFT_TO_REACH_DATABASE=0
			break
		fi
		sleep 1
		ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
	done

	if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
		echo 'The database is not reachable:'
		echo "$DATABASE_ERROR"
		exit 1
	fi

	php bin/console doctrine:migrations:migrate "$MIGRATE_DB" --no-interaction --all-or-nothing --allow-no-migration

	if [ -n "$DEVELOPMENT" ]; then
		for TEST_ENVIRONMENT in test e2e; do
			php bin/console doctrine:database:create --if-not-exists --env=$TEST_ENVIRONMENT
			php bin/console doctrine:migrations:migrate "$MIGRATE_DB" --no-interaction --all-or-nothing --allow-no-migration --env=$TEST_ENVIRONMENT
		done
	fi

	echo 'Migrations are done'

	if [ -z "${MIGRATE_DB_AND_CONTINUE:-}" ]; then
		exit 0
	fi
fi

if [ -n "$DEVELOPMENT" ] && [ "$1" = 'frankenphp' ]; then
	# Docker Desktop bind mounts on macOS do not support ACLs.
	if setfacl -m u:www-data:rwX var 2>/dev/null; then
		setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var
		setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var
	else
		echo 'Skipping setfacl: the file system of var/ does not support ACLs'
	fi

	echo 'PHP app ready'
fi

exec docker-php-entrypoint "$@"
