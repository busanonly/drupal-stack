# =====================================================================
#  Drupal + MariaDB (Docker) — drupal/
#  Orkestrasi 2 stack terpisah yang berbagi network `drupal-network`:
#     database/  -> MariaDB 10.11 (+ .env)
#     web/       -> Drupal 11 php-fpm + nginx (+ .env, composer project)
#  Jalankan `make help` untuk daftar perintah.
# =====================================================================
NETWORK_NAME := $(shell sed -n 's/^NETWORK_NAME=//p' database/.env 2>/dev/null | head -1)
NETWORK_NAME := $(if $(strip $(NETWORK_NAME)),$(strip $(NETWORK_NAME)),drupal-network)
WEB_PORT     := $(shell sed -n 's/^NGINX_PORT=//p' web/.env 2>/dev/null | head -1)
WEB_PORT     := $(if $(strip $(WEB_PORT)),$(strip $(WEB_PORT)),8089)

.DEFAULT_GOAL := help

help: ## Tampilkan daftar perintah
	@echo "network : $(NETWORK_NAME)    web : http://<host>:$(WEB_PORT)"
	@echo ""
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

network: ## Buat network drupal (172.22.0.0/24) bila belum ada
	@docker network inspect $(NETWORK_NAME) >/dev/null 2>&1 \
		|| docker network create --subnet 172.22.0.0/24 $(NETWORK_NAME)
	@docker network inspect $(NETWORK_NAME) --format 'network {{.Name}} subnet={{range .IPAM.Config}}{{.Subnet}}{{end}} siap'

env: ## Buat .env di database/ & web/ dari .env.example bila belum ada
	@test -f database/.env || cp database/.env.example database/.env
	@test -f web/.env || cp web/.env.example web/.env
	@echo "file .env siap"

up: network env ## Nyalakan database lalu web (detached)
	$(MAKE) -C database up
	$(MAKE) -C web up
	@echo "Drupal -> http://<host>:$(WEB_PORT)"

down: ## Matikan web lalu database
	-$(MAKE) -C web down
	-$(MAKE) -C database down

restart: ## Restart kedua stack
	$(MAKE) -C database restart
	$(MAKE) -C web restart

logs: ## Ikuti log database + web (Ctrl-C untuk keluar)
	$(MAKE) -C database logs

logs-web: ## Ikuti log nginx Drupal
	$(MAKE) -C web logs-nginx

ps: ## Status semua container (database + web)
	@$(MAKE) -C database ps
	@$(MAKE) -C web ps

health: ## Cek health semua container
	@$(MAKE) -C database health
	@$(MAKE) -C web health

db-check: ## Uji koneksi PHP Drupal -> MariaDB
	@$(MAKE) -C web db-check

shell-db: ## Shell container MariaDB
	$(MAKE) -C database shell

shell-web: ## Shell container php-fpm
	$(MAKE) -C web shell

dump: ## Backup database ke database/backup/*.sql
	$(MAKE) -C database dump

clean: ## Stop + hapus semua container & image lokal (data DB dihapus!)
	-$(MAKE) -C web clean
	-$(MAKE) -C database clean

.PHONY: help network env up down restart logs logs-web ps health db-check shell-db shell-web dump clean
