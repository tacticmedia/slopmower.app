# Per-checkout ports, for example `export HTTP_PORT=8101`, `export DATABASE_PORT=5501`, and `export UI_PORT=4101` in a worktree.
-include .env.make.local

DOCKER_COMPOSE = docker compose
PHP            = $(DOCKER_COMPOSE) exec -T php
CONSOLE        = $(PHP) bin/console
PACKAGE_JSON   = $(wildcard package.json)

.DEFAULT_GOAL = help
.PHONY: help up down sh worker-restart console composer mate migration db-reset db-test test e2e stan cs cs-fix lint front ui check

help: ## List the targets
	@grep -hE '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "%-16s %s\n", $$1, $$2}'

up: ## Build the images and start php, worker, and database
	$(DOCKER_COMPOSE) up -d --wait --build

down: ## Stop the stack, keep the volumes
	$(DOCKER_COMPOSE) down

sh: ## Shell in the php container
	$(DOCKER_COMPOSE) exec php bash

worker-restart: ## Stop the Messenger worker; compose starts it again with the new code
	$(CONSOLE) messenger:stop-workers

console: ## bin/console, arguments in c="..."
	$(CONSOLE) $(c)

composer: ## composer, arguments in c="..."
	$(PHP) composer $(c)

mate: ## ai-mate, arguments in c="..."
	$(PHP) vendor/bin/mate $(c)

migration: ## Generate a migration from the mapping
	$(CONSOLE) make:migration --no-interaction

db-reset: ## Drop, create, and migrate the dev database. Ask Jan first.
	$(DOCKER_COMPOSE) stop worker
	$(DOCKER_COMPOSE) exec -T database sh -c 'dropdb -U "$$POSTGRES_USER" --if-exists --force "$$POSTGRES_DB"'
	$(CONSOLE) doctrine:database:create
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
	$(DOCKER_COMPOSE) start worker

db-test: ## Create app_test and app_e2e when they are missing, then migrate them
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration --env=test
	$(CONSOLE) doctrine:database:create --if-not-exists --env=e2e
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration --env=e2e

test: ## Unit and functional suites
ifneq ($(PACKAGE_JSON),)
	test -f public/build-e2e/entrypoints.json || npm run build:e2e
endif
	$(PHP) bin/phpunit --testsuite unit,functional

e2e: ## Full-stack suite: Playwright in the php container against the e2e site on port 8081
ifneq ($(PACKAGE_JSON),)
	npm run build:e2e
endif
	$(PHP) sh -c 'npm --prefix vendor/playwright-php/playwright/bin install --save-exact --no-audit --no-fund "playwright@$$PLAYWRIGHT_VERSION"'
	$(PHP) vendor/bin/playwright-install chromium
	$(PHP) bin/phpunit --testsuite e2e

stan: ## PHPStan level 8 on the test container, so the dev worker keeps its cache
	$(CONSOLE) cache:warmup --env=test
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs: ## php-cs-fixer, dry run
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## php-cs-fixer
	$(PHP) vendor/bin/php-cs-fixer fix

lint: ## Container, YAML, Twig, and schema checks
	$(CONSOLE) lint:container
	$(CONSOLE) lint:yaml config
	$(CONSOLE) lint:twig templates
	$(CONSOLE) doctrine:schema:validate --env=test

front: ## TypeScript and Vitest on the host
ifneq ($(PACKAGE_JSON),)
	npm run typecheck && npm test
endif

ui: ## Playwright suite against the mocked API on the host, port UI_PORT
ifneq ($(PACKAGE_JSON),)
	npx playwright install chromium
	npm run test:ui
endif

check: cs stan lint test front ui e2e ## Every quality gate
