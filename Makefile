.PHONY: up down build rebuild logs shell simulate simulate-vivistar-tcp listen-vivistar-tcp hub hub-logs mqtt mqtt-logs smoke-hub test-unit test-scenarios test-all ssl-setup ps dev-hub dev prod-update prod-restart prod-status prod-logs

HEALTH_HUB_SERVICE ?= health-hub

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
		--imei $(or $(IMEI),865028000000308) \
		--command $(or $(COMMAND),AP49)

listen-vivistar-tcp:
	docker compose exec hub php simulator/simulate.php \
		--server tcp://127.0.0.1:9000 \
		--model $(or $(MODEL),VIVISTAR-CARE) \
		--imei $(or $(IMEI),865028000000308) \
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

test-scenarios:
	tests/scenarios/run-all.sh

test-all: test-unit test-scenarios

ssl-setup:
	mkdir -p config/ssl && openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
		-keyout config/ssl/privkey.pem \
		-out config/ssl/fullchain.pem \
		-subj "/C=PT/O=Hitecosystem/CN=localhost"

ps:
	docker compose ps

dev-hub:
	docker compose stop hub 2>/dev/null; \
	docker compose run --rm --service-ports --name hitecosystem-devices-hub-dev hub bin/dev.sh php bin/server-hub.php

dev: dev-hub

prod-update:
	git pull --ff-only
	composer install --no-dev --optimize-autoloader
	systemctl restart $(HEALTH_HUB_SERVICE)
	systemctl status $(HEALTH_HUB_SERVICE) --no-pager

prod-restart:
	systemctl restart $(HEALTH_HUB_SERVICE)

prod-status:
	systemctl status $(HEALTH_HUB_SERVICE) --no-pager

prod-logs:
	journalctl -u $(HEALTH_HUB_SERVICE) -f
