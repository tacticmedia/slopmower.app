#!/bin/sh
# Adapted from https://github.com/dunglas/symfony-docker. Local development only.
set -e

# Only the php service prepares the project. The worker starts after php is healthy.
if [ "$1" = 'frankenphp' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	php bin/console -V

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

	php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
	php bin/console doctrine:database:create --if-not-exists --env=test
	php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration --env=test

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
