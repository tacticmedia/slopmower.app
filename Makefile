# Per-checkout ports, for example `export HTTP_PORT=8101`, `export DATABASE_PUBLISHED_PORT=5501`, `export UI_PORT=4101`, and `export JOURNEY_PORT=4111` in a worktree.
# `export ACT_HTTP_PORT=...`, `ACT_DATABASE_PUBLISHED_PORT`, and `ACT_UI_PORT` override the ports of make act in .github/act.env.
-include .env.make.local

export JOURNEY_PORT ?= 4174

DOCKER_COMPOSE = docker compose
PHP            = $(DOCKER_COMPOSE) exec -T php
CONSOLE        = $(PHP) bin/console
PACKAGE_JSON   = $(wildcard package.json)

.DEFAULT_GOAL = help
.PHONY: help up down sh worker-restart console composer mate migration db-reset fixtures db-test test e2e stan cs cs-fix lint front ui journeys prod-check act check

help: ## List the targets
	@grep -hE '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "%-16s %s\n", $$1, $$2}'

up: ## Build the images and start php, worker, symbols, and database
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

db-reset: ## Drop, create, and migrate the dev database. Ask Jan first. Run make fixtures after it.
	$(DOCKER_COMPOSE) stop worker
	$(DOCKER_COMPOSE) exec -T database sh -c 'dropdb -U "$$POSTGRES_USER" --if-exists --force "$$POSTGRES_DB"'
	$(CONSOLE) doctrine:database:create
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
	$(DOCKER_COMPOSE) start worker

fixtures: ## Load the dev set: the local user, the token APP_DEV_API_TOKEN of .env.dev, and APP_DEV_REPOSITORY. Idempotent.
	$(CONSOLE) foundry:load-fixtures dev --append --no-transaction

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

front: ## TypeScript and Vitest on the host, with the packages of symbols
ifneq ($(PACKAGE_JSON),)
front: symbols/node_modules/.package-lock.json
	npm run typecheck && npm test
endif

# Installs again when the lock file is newer than the installed packages.
symbols/node_modules/.package-lock.json: symbols/package-lock.json
	npm ci --prefix symbols --ignore-scripts --no-audit --no-fund
	touch $@

ui: ## Playwright suite against the mocked API on the host, port UI_PORT
ifneq ($(PACKAGE_JSON),)
	npx playwright install chromium
	npm run test:ui
endif

journeys: ## Screenshots of the journeys against the mocked API on the host, port JOURNEY_PORT, for /_dev/screenshots. Not a gate.
ifneq ($(PACKAGE_JSON),)
	npx playwright install chromium
	npm run test:journeys
	@echo "Review the screenshots at http://localhost:$(or $(HTTP_PORT),8000)/_dev/screenshots"
endif

prod-check: ## Build the frankenphp_prod image, then lint its container and request /login from it
	bin/prod-check

act: ## .github/workflows/qa.yml with act, in var/act/workspace, on its own compose project and ports
	bin/act-run
	mkdir -p $(ACT_VERIFIED) && touch $(ACT_VERIFIED)/$(ACT_HASH)

# The files that define the workflow, the stack, or a gate.
ACT_INPUTS = $(wildcard .github/workflows/*) .actrc .github/act.env bin/act-run bin/prod-check .dockerignore \
	Makefile compose.yaml compose.override.yaml Dockerfile $(shell find docker -type f) \
	phpunit.dist.xml phpstan.dist.neon .php-cs-fixer.dist.php playwright.config.ts vite.config.ts $(wildcard tsconfig*.json) \
	package.json package-lock.json symbols/package.json symbols/package-lock.json composer.json composer.lock \
	.env.test .env.e2e

# In the git directory that every worktree shares, so a checkout whose ACT_INPUTS a green make act has seen skips act.
ACT_VERIFIED = $(shell git rev-parse --git-common-dir)/act-verified
ACT_HASH = $(shell for file in $(ACT_INPUTS); do echo "$$file"; cat "$$file"; done | git hash-object --stdin)

check: cs stan lint test front ui e2e ## Every quality gate, then make act when no green make act has seen these ACT_INPUTS
ifeq ($(CI)$(ACT),)
	@test -e $(ACT_VERIFIED)/$(ACT_HASH) || $(MAKE) act
endif
