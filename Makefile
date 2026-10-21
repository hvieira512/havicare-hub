.PHONY: simulate-vivistar-tcp listen-vivistar-tcp require-instance update restart status journal

# O diretório é a instância: o serviço sai dele, para não haver escolha que se possa fazer
# mal. `/opt/havicare-hub` é a produção e `/opt/havicare-hub-dev` a de desenvolvimento.
PROD_DIR ?= /opt/havicare-hub
DEV_DIR ?= /opt/havicare-hub-dev

ifeq ($(CURDIR),$(PROD_DIR))
  HUB_SERVICE = havicare-hub
else ifeq ($(CURDIR),$(DEV_DIR))
  HUB_SERVICE = havicare-hub-dev
endif

simulate-vivistar-tcp:
	docker compose exec hub php simulator/simulate.php \
		--server tcp://127.0.0.1:9000 \
		--imei $(or $(IMEI),861265061009822) \
		--command $(or $(COMMAND),AP49)

listen-vivistar-tcp:
	docker compose exec hub php simulator/simulate.php \
		--server tcp://127.0.0.1:9000 \
		--imei $(or $(IMEI),861265061009822) \
		--listen

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
