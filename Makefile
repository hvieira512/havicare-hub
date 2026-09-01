.PHONY: up down build rebuild logs shell simulate simulate-vivistar-tcp listen-vivistar-tcp hub hub-logs mqtt mqtt-logs smoke-hub analyse lint test-frontend test-unit test-integration test-scenarios test-all clean-test-artifacts ssl-setup ps dev-hub dev require-instance update restart status journal

# O diretório é a instância: o serviço sai dele, para não haver escolha que se possa fazer
# mal. `/opt/havicare-hub` é a produção e `/opt/havicare-hub-dev` a de desenvolvimento.
PROD_DIR ?= /opt/havicare-hub
DEV_DIR ?= /opt/havicare-hub-dev

ifeq ($(CURDIR),$(PROD_DIR))
  HUB_SERVICE = havicare-hub
else ifeq ($(CURDIR),$(DEV_DIR))
  HUB_SERVICE = havicare-hub-dev
endif

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

rebuild: down build up

logs:
	docker compose logs -f

shell:
	docker compose exec hub bash

simulate:
	docker compose exec hub php simulator/simulate.php $(ARGS)

simulate-vivistar-tcp:
	docker compose exec hub php simulator/simulate.php \
		--server tcp://127.0.0.1:9000 \
		--model $(or $(MODEL),VIVISTAR-CARE) \
		--imei $(or $(IMEI),861265061009822) \
		--command $(or $(COMMAND),AP49)

listen-vivistar-tcp:
	docker compose exec hub php simulator/simulate.php \
		--server tcp://127.0.0.1:9000 \
		--model $(or $(MODEL),VIVISTAR-CARE) \
		--imei $(or $(IMEI),861265061009822) \
		--listen

hub:
	docker compose up -d hub

hub-logs:
	docker compose logs -f hub

mqtt:
	docker compose up -d mosquitto

mqtt-logs:
	docker compose logs -f mosquitto

smoke-hub:
	tests/scenarios/scenario_hub_raw_mqtt_roundtrip.sh

test-unit:
	vendor/bin/phpunit --testsuite unit

test-integration:
	vendor/bin/phpunit --testsuite integration

analyse:
	composer analyse

lint:
	npm run lint

test-frontend:
	npm test

test-scenarios:
	tests/scenarios/run-all.sh

test-all: analyse lint test-frontend test-unit test-integration test-scenarios

clean-test-artifacts:
	tests/scenarios/cleanup-artifacts.sh

ssl-setup:
	mkdir -p config/ssl && openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
		-keyout config/ssl/privkey.pem \
		-out config/ssl/fullchain.pem \
		-subj "/C=PT/O=Havicare/CN=localhost"

ps:
	docker compose ps

dev-hub: hub

dev-dashboard:
	docker compose stop hub 2>/dev/null; \
	docker compose run --rm --service-ports --name havicare-hub-dev \
		-e WATCH_DIRS="/app/src/Dashboard /app/config/whitelist.json" \
		hub bin/dev.sh php bin/server-hub.php

dev: up

# Fora dos dois diretórios do servidor não há instância nenhuma, e parar é melhor do que
# adivinhar -- sem isto, um alvo destes numa cópia local reiniciava um serviço à sorte.
require-instance:
	@test -n "$(HUB_SERVICE)" || { echo "$(CURDIR) não é $(PROD_DIR) nem $(DEV_DIR): não há serviço para mexer"; exit 1; }

update: require-instance
	git pull --ff-only
	composer install --no-dev --optimize-autoloader
	php bin/migrate.php
	systemctl restart $(HUB_SERVICE)
	systemctl status $(HUB_SERVICE) --no-pager

restart: require-instance
	systemctl restart $(HUB_SERVICE)

status: require-instance
	systemctl status $(HUB_SERVICE) --no-pager

journal: require-instance
	journalctl -u $(HUB_SERVICE) -f
