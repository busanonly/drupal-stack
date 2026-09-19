# =====================================================================
#  Drupal + MariaDB (Docker) — drupal/
#  Orkestrasi 2 stack terpisah yang berbagi network `drupal-network`:
#     database/  -> MariaDB 10.11 (+ .env)
#     web/       -> Drupal 11 php-fpm + nginx (+ .env, composer project)
#  Jalankan `make help` untuk daftar perintah.
# =====================================================================
# Nilai dari .env dibaca ulang tiap kali dipakai (recursive `=`) supaya
# perubahan .env — mis. hasil `make init` di run yang sama — langsung terpakai.
env_get = $(shell sed -n 's/^$(1)=//p' $(2) 2>/dev/null | head -1)

NETWORK_NAME_INFO = $(if $(strip $(call env_get,NETWORK_NAME,database/.env)),$(strip $(call env_get,NETWORK_NAME,database/.env)),drupal-network)
SUBNET_INFO       = $(if $(strip $(call env_get,NETWORK_SUBNET,database/.env)),$(strip $(call env_get,NETWORK_SUBNET,database/.env)),172.22.0.0/24)
WEB_PORT_INFO     = $(if $(strip $(call env_get,NGINX_PORT,web/.env)),$(strip $(call env_get,NGINX_PORT,web/.env)),8089)

.DEFAULT_GOAL := help

help: ## Tampilkan daftar perintah
	@echo "network : $(NETWORK_NAME_INFO)    web : http://<host>:$(WEB_PORT_INFO)"
	@echo ""
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

network: ## Buat network drupal (subnet dari .env) bila belum ada
	@docker network inspect $(NETWORK_NAME_INFO) >/dev/null 2>&1 \
		|| docker network create --subnet $(SUBNET_INFO) $(NETWORK_NAME_INFO)
	@docker network inspect $(NETWORK_NAME_INFO) --format 'network {{.Name}} subnet={{range .IPAM.Config}}{{.Subnet}}{{end}} siap'

env: ## Buat .env di database/ & web/ dari .env.example bila belum ada
	@test -f database/.env || cp database/.env.example database/.env
	@test -f web/.env || cp web/.env.example web/.env
	@echo "file .env siap (nilai masih contoh — sebaiknya pakai 'make init NAME=...')"

init: ## Siapkan project baru dari template: make init NAME=proyek2 [WEB_PORT=8090 DB_PORT=3307]
	@sh scripts/init-project.sh '$(NAME)' $(if $(WEB_PORT),--web-port $(WEB_PORT),) $(if $(DB_PORT),--db-port $(DB_PORT),) $(if $(SUBNET_ARG),--subnet $(SUBNET_ARG),) $(if $(FORCE),--force,)

deps: ## Isi dependency untuk clone/project baru: composer install + settings.php
	-@$(MAKE) -C web perms
	$(MAKE) -C web composer CMD="install --no-interaction"
	$(MAKE) -C web settings

new: init up deps ## Bootstrap project baru sekali jalan: make new NAME=proyek2 [WEB_PORT=8090 DB_PORT=3307]
	@echo ""
	@echo "Project '$(NAME)' siap. Installer Drupal: http://<IP-server>:$$(sed -n 's/^NGINX_PORT=//p' web/.env | head -1)/core/install.php"

up: env ## Nyalakan database lalu web (network dibuat oleh masing-masing stack)
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

.PHONY: help network env init deps new up down restart logs logs-web ps health db-check shell-db shell-web dump clean
