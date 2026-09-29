DOCKER_COMPOSE = docker compose
PHP = $(DOCKER_COMPOSE) exec -T php
CONSOLE = $(PHP) bin/console

.DEFAULT_GOAL := help
.PHONY: help up down sh worker-restart console composer migration db-reset db-test test e2e stan cs cs-fix lint front check

help: ## List the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "%-16s %s\n", $$1, $$2}'

up: ## Start php, worker, and database
	$(DOCKER_COMPOSE) up -d --wait

down: ## Stop the services, keep the volumes
	$(DOCKER_COMPOSE) down

sh: ## Shell in the php container
	$(DOCKER_COMPOSE) exec php sh

worker-restart: ## Restart the Messenger worker after a PHP change
	$(CONSOLE) messenger:stop-workers

console: ## bin/console, arguments in c="..."
	$(CONSOLE) $(c)

composer: ## composer, arguments in c="..."
	$(PHP) composer $(c)

migration: ## Generate a migration from the mapping
	$(CONSOLE) make:migration --no-interaction

db-reset: ## Drop, create, and migrate the dev database. Ask Jan first.
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

db-test: ## Create app_test if it is missing, then migrate it
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

test: ## Unit and functional suites
	$(PHP) bin/phpunit --testsuite unit,functional

e2e: ## E2E suite, after the E2E front-end build when package.json exists
	@if [ -f package.json ]; then npm run build:e2e; fi
	$(PHP) bin/phpunit --testsuite e2e

stan: ## PHPStan
	$(CONSOLE) cache:warmup --env=dev
	$(PHP) vendor/bin/phpstan analyse --no-progress

cs: ## php-cs-fixer dry run
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## php-cs-fixer
	$(PHP) vendor/bin/php-cs-fixer fix

lint: ## Container, YAML, Twig, and Doctrine mapping
	$(CONSOLE) lint:container
	$(CONSOLE) lint:yaml config
	$(CONSOLE) lint:twig templates
	$(CONSOLE) doctrine:schema:validate --env=test

front: ## Type check and Vitest when package.json exists
	@if [ -f package.json ]; then npm run typecheck && npm test; fi

check: cs stan lint test front e2e ## Every quality gate
