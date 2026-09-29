#!/bin/sh
set -e

# The worker starts when php is healthy, so only php prepares the project.
if [ "$1" = 'frankenphp' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	php bin/console -V

	echo 'Waiting for database to be ready...'
	ATTEMPTS_LEFT_TO_REACH_DATABASE=60
	until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
		if [ $? -eq 255 ]; then
			# If the Doctrine command exits with 255, an unrecoverable error occurred
			ATTEMPTS_LEFT_TO_REACH_DATABASE=0
			break
		fi
		sleep 1
		ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
		echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
	done

	if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
		echo 'The database is not up or not reachable:'
		echo "$DATABASE_ERROR"
		exit 1
	fi
	echo 'The database is now ready and reachable'

	php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration

	php bin/console doctrine:database:create --if-not-exists --env=test
	php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration --env=test

	# Docker Desktop bind mounts on macOS do not support ACLs.
	if setfacl -m u:www-data:rwX var 2>/dev/null; then
		setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var
		setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
