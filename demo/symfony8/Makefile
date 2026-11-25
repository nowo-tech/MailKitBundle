# Makefile for Mail Kit Bundle Demo - Symfony 8
# FrankenPHP + Caddyfile. Run from this directory: make up, make install, etc.

COMPOSE_FILE := docker-compose.yml
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down build install shell logs setup db-schema seed key cache-clear update-bundle ensure-up test test-e2e demo-screenshots
.PHONY: test-harness outlook-check-scratch outlook-up outlook-login outlook-register outlook-sync outlook-list
.PHONY: outlook-send outlook-double-send outlook-status outlook-down

help:
	@echo "Mail Kit Bundle Demo - Symfony 8"
	@echo ""
	@echo "  make up           Start containers (FrankenPHP on port \$${PORT:-8085})"
	@echo "  make down         Stop containers"
	@echo "  make build        Build image (no cache)"
	@echo "  make install      Install dependencies in container"
	@echo "  make setup        install + schema + encrypt key"
	@echo "  make shell        Open shell in PHP container"
	@echo "  make logs         Show container logs"
	@echo "  make db-schema    Update schema"
	@echo "  make seed         Seed demo variables (idempotent)"
	@echo "  make key          Generate Halite encryption secret key"
	@echo "  make cache-clear  Clear Symfony cache"
	@echo "  make update-bundle Update bundle from path repo (with its dependencies)"
	@echo "  make test-e2e     Playwright happy path (official Playwright image)"
	@echo "  make demo-screenshots Refresh docs/images/demo/*.webp"
	@echo "  make test-harness Unit tests of the Outlook manual test harness"
	@echo "  make outlook-up | outlook-login | outlook-register ADDRESS=... | outlook-sync | outlook-list"
	@echo "  make outlook-send ARGS=... | outlook-double-send | outlook-status | outlook-down"
	@echo "                   Outlook manual test harness (README); needs MAILKIT_OUTLOOK_SCRATCH_DIR"
	@echo ""

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@echo "Waiting…"
	$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@# The entrypoint runs the first composer install and the schema, then starts FrankenPHP: wait for the server
	@# instead of racing that install on the shared vendor/ (up to 15 minutes on a cold cache).
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8085; \
	i=0; while [ $$i -lt 300 ] && [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$$PORT 2>/dev/null)" = "000" ]; do i=$$((i+1)); sleep 3; done
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction || true
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8085; \
	code=000; \
	i=0; \
	while [ $$i -lt 90 ]; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT 2>/dev/null || echo "000"); \
		if [ "$$code" = "200" ] || [ "$$code" = "302" ]; then break; fi; \
		i=$$((i+1)); sleep 2; \
	done; \
	if [ "$$code" != "200" ] && [ "$$code" != "302" ]; then \
		echo "Demo not ready (HTTP $$code)"; \
		$(COMPOSE) logs --tail=80 db $(SERVICE_PHP) || true; \
		exit 1; \
	fi; \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (demo $(COMPOSE))..."; \
		$(COMPOSE) up -d; \
		sleep 5; \
		$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction; \
	fi

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction

setup: install key db-schema seed
	@echo "Demo ready. App: http://localhost:$${PORT:-8085}  Mailer settings: http://localhost:$${PORT:-8085}/admin/settings/sections/mailer  Log: /admin/mail/log  Mailpit: http://localhost:8087"

key: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:encrypt:generate-secret-key || true

db-schema: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists --no-interaction || true
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:schema:update --force --no-interaction

seed: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console app:demo:seed --no-interaction

shell: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) sh

logs:
	$(COMPOSE) logs -f

cache-clear: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true

update-bundle: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer update nowo-tech/mail-kit-bundle -w --no-interaction

# REQ-MAKE-003: lint the wiring and templates; the only PHPUnit suite covers the demo-only Outlook test harness.
test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:container
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:twig templates
	$(COMPOSE) exec -T $(SERVICE_PHP) php vendor/bin/phpunit

# REQ-DEMO-013: Playwright e2e and README screenshots (official Playwright image, nothing to install on the host).
# The image tag always follows the exact @playwright/test version pinned in e2e/package.json (browsers ship with the image).
PLAYWRIGHT_VERSION := $(shell sed -n 's/.*"@playwright\/test": "\([0-9.]*\)".*/\1/p' e2e/package.json)
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v$(PLAYWRIGHT_VERSION)-jammy

test-e2e: ensure-up seed
	@mkdir -p e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8085}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium'

demo-screenshots: ensure-up seed
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8085}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"

# The demo-only Outlook manual test harness (src/Outlook) unit tests.
test-harness: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php vendor/bin/phpunit

# ---------------------------------------------------------------------------------------------------------------------
# DEMO ONLY: Outlook manual test harness (README "Outlook manual test harness"). The token file lives in
# MAILKIT_OUTLOOK_SCRATCH_DIR, an absolute directory OUTSIDE every repository, mounted at /outlook-scratch.
# ---------------------------------------------------------------------------------------------------------------------
OUTLOOK_ID      ?= outlook-test
FOLDERS         ?= inbox
OUTLOOK_COMPOSE := $(COMPOSE) -f docker-compose.outlook.yml
OUTLOOK_EXEC    := $(COMPOSE) exec -T $(SERVICE_PHP) php bin/console

outlook-check-scratch:
	@d="$${MAILKIT_OUTLOOK_SCRATCH_DIR:-}"; \
	if [ -z "$$d" ]; then echo "Set MAILKIT_OUTLOOK_SCRATCH_DIR to an absolute directory outside any repository (e.g. $$HOME/.mailkit-outlook-scratch)."; exit 1; fi; \
	case "$$d" in /*) ;; *) echo "MAILKIT_OUTLOOK_SCRATCH_DIR must be absolute."; exit 1;; esac; \
	if [ ! -d "$$d" ]; then echo "$$d does not exist (create it with mkdir -m 700 first)."; exit 1; fi; \
	if git -C "$$d" rev-parse --is-inside-work-tree >/dev/null 2>&1; then echo "Refused: $$d is inside a git work tree. Use a scratch directory outside any repository."; exit 1; fi; \
	if [ -z "$${MAILKIT_OUTLOOK_CLIENT_ID:-}" ]; then echo "Note: MAILKIT_OUTLOOK_CLIENT_ID is empty (outlook-login and the token refresh need it)."; fi

outlook-up: outlook-check-scratch
	$(OUTLOOK_COMPOSE) up -d
	@i=0; while [ $$i -lt 300 ] && ! $(COMPOSE) exec -T $(SERVICE_PHP) test -f vendor/autoload_runtime.php 2>/dev/null; do i=$$((i+1)); sleep 3; done
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists --no-interaction
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:schema:update --force --no-interaction
	@echo "Outlook harness ready: the token file is /outlook-scratch/$${MAILKIT_OUTLOOK_TOKEN_NAME:-token.json} in the container."

outlook-login:
	$(OUTLOOK_EXEC) app:outlook:login

outlook-register:
	@[ -n "$(ADDRESS)" ] || { echo "Usage: make outlook-register ADDRESS=you@example.com [FOLDERS='inbox sentitems'] [OUTLOOK_ID=outlook-test]"; exit 1; }
	$(OUTLOOK_EXEC) app:outlook:register --id=$(OUTLOOK_ID) --address=$(ADDRESS) $(foreach f,$(FOLDERS),--folder=$(f)) $(ARGS)

outlook-sync:
	$(OUTLOOK_EXEC) app:outlook:sync --id=$(OUTLOOK_ID) $(ARGS)

outlook-list:
	$(OUTLOOK_EXEC) app:outlook:list --id=$(OUTLOOK_ID) $(ARGS)

outlook-send:
	$(OUTLOOK_EXEC) app:outlook:send --id=$(OUTLOOK_ID) $(ARGS)

outlook-double-send:
	$(OUTLOOK_EXEC) app:outlook:double-send --id=$(OUTLOOK_ID) $(ARGS)

outlook-status:
	$(OUTLOOK_EXEC) nowo:mail-kit:mailbox:status $(OUTLOOK_ID)

outlook-down:
	$(COMPOSE) down
