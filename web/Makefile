# =====================================================================
#  Drupal 11 (php-fpm + nginx) — shortcut Docker Compose
#  Identitas, port & kredensial dibaca dari .env (lihat .env.example).
#  Jalankan `make help` untuk melihat semua perintah.
# =====================================================================
DC       = docker compose
S        = drupal
N        = nginx
ENV_FILE := .env

# --- konfigurasi dari .env (dengan fallback bila .env belum ada) ---
PROJECT   := $(shell sed -n 's/^COMPOSE_PROJECT_NAME=//p' $(ENV_FILE) 2>/dev/null | head -1)
PROJECT   := $(if $(strip $(PROJECT)),$(strip $(PROJECT)),drupal_web)
PHP_CT    := $(shell sed -n 's/^DRUPAL_CONTAINER=//p' $(ENV_FILE) 2>/dev/null | head -1)
PHP_CT    := $(if $(strip $(PHP_CT)),$(strip $(PHP_CT)),drupal_php)
NGINX_CT  := $(shell sed -n 's/^NGINX_CONTAINER=//p' $(ENV_FILE) 2>/dev/null | head -1)
NGINX_CT  := $(if $(strip $(NGINX_CT)),$(strip $(NGINX_CT)),drupal_nginx)
NETWORK   := $(shell sed -n 's/^NETWORK_NAME=//p' $(ENV_FILE) 2>/dev/null | head -1)
NETWORK   := $(if $(strip $(NETWORK)),$(strip $(NETWORK)),drupal-network)
SUBNET    := $(shell sed -n 's/^NETWORK_SUBNET=//p' $(ENV_FILE) 2>/dev/null | head -1)
SUBNET    := $(if $(strip $(SUBNET)),$(strip $(SUBNET)),172.22.0.0/24)
PORT      := $(shell sed -n 's/^NGINX_PORT=//p' $(ENV_FILE) 2>/dev/null | head -1)
PORT      := $(if $(strip $(PORT)),$(strip $(PORT)),8089)

.DEFAULT_GOAL := help

help: ## Tampilkan daftar perintah
	@echo "Drupal  : $(PHP_CT) (php-fpm) + $(NGINX_CT) (nginx)"
	@echo "network : $(NETWORK)   web -> http://<host>:$(PORT)"
	@echo ""
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

.env: ## Buat .env dari .env.example bila belum ada
	@test -f .env || cp .env.example .env

network: ## Buat network drupal bila belum ada
	@docker network inspect $(NETWORK) >/dev/null 2>&1 \
		|| docker network create --subnet $(SUBNET) $(NETWORK)
	@echo "network $(NETWORK) ($(SUBNET)) siap"

config: .env ## Tampilkan konfigurasi yang aktif
	$(DC) config

build: .env ## Build image PHP-FPM
	$(DC) build

up: .env network ## Nyalakan Drupal + nginx (detached)
	$(DC) up -d --build
	$(DC) ps
	@echo "Drupal -> http://<host>:$(PORT)   (container: $(PHP_CT), $(NGINX_CT))"

down: ## Matikan Drupal + nginx
	$(DC) down

restart: ## Restart container
	$(DC) restart

logs: ## Ikuti log php-fpm
	$(DC) logs -f --tail=100 $(S)

logs-nginx: ## Ikuti log nginx
	$(DC) logs -f --tail=100 $(N)

shell: ## Masuk ke shell container php-fpm
	$(DC) exec $(S) bash

composer: ## Composer di container, contoh: make composer CMD="require drush/drush"
	$(DC) exec --user 1000:1000 $(S) composer $(CMD)

drush: ## Drush di container, contoh: make drush CMD="status"
	$(DC) exec --user 1000:1000 $(S) vendor/bin/drush $(CMD)

settings: ## Buat settings.php dari .env (FORCE=1 untuk menimpa)
	$(DC) exec --user 1000:1000 -e FORCE=$(FORCE) $(S) sh scripts/gen-settings.sh web

theme: ## Buat theme kosongan: make theme NAME=blog_theme ["Blog Theme"]
	$(DC) exec --user 1000:1000 -e FORCE=$(FORCE) $(S) sh scripts/new-theme.sh $(NAME) '$(LABEL)'

theme-check: ## Validasi theme: make theme-check NAME=blank_theme
	$(DC) exec $(S) php scripts/check-theme.php $(NAME)

db-check: ## Uji koneksi PHP -> MariaDB (pakai kredensial .env)
	$(DC) exec $(S) php -r 'exit(@new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD")) ? 0 : 1);' \
		&& echo "koneksi database OK" || echo "koneksi database GAGAL"

perms: ## Perbaiki kepemilikan file agar bisa ditulis www-data (uid 1000)
	@chown -R 1000:1000 . 2>/dev/null || true
	@echo "kepemilikan file diset ke 1000:1000"

ps: ## Status container
	$(DC) ps

health: ## Cek status health
	@docker inspect -f '{{.Name}} -> {{.State.Status}} ({{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}})' \
		$(PHP_CT) $(NGINX_CT) 2>/dev/null || true

clean: ## Stop + hapus container & image lokal
	$(DC) down -v --rmi local

.PHONY: help .env network config build up down restart logs logs-nginx shell composer drush settings theme theme-check db-check perms ps health clean
