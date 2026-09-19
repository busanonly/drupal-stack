# =====================================================================
#  MariaDB untuk Drupal — shortcut Docker Compose
#  Identitas & port dibaca dari .env (lihat .env.example).
#  Jalankan `make help` untuk melihat semua perintah.
# =====================================================================
DC        = docker compose
ENV_FILE  := .env

# --- konfigurasi dari .env (dengan fallback bila .env belum ada) ---
PROJECT  := $(shell sed -n 's/^COMPOSE_PROJECT_NAME=//p' $(ENV_FILE) 2>/dev/null | head -1)
PROJECT  := $(if $(strip $(PROJECT)),$(strip $(PROJECT)),drupal_database)
CONTAINER:= $(shell sed -n 's/^CONTAINER_NAME=//p' $(ENV_FILE) 2>/dev/null | head -1)
CONTAINER:= $(if $(strip $(CONTAINER)),$(strip $(CONTAINER)),mariadb_drupal)
NETWORK  := $(shell sed -n 's/^NETWORK_NAME=//p' $(ENV_FILE) 2>/dev/null | head -1)
NETWORK  := $(if $(strip $(NETWORK)),$(strip $(NETWORK)),drupal-network)
DB_NAME  := $(shell sed -n 's/^MARIADB_DATABASE=//p' $(ENV_FILE) 2>/dev/null | head -1)
DB_NAME  := $(if $(strip $(DB_NAME)),$(strip $(DB_NAME)),drupal)
DB_USER  := $(shell sed -n 's/^MARIADB_USER=//p' $(ENV_FILE) 2>/dev/null | head -1)
DB_USER  := $(if $(strip $(DB_USER)),$(strip $(DB_USER)),drupal)
DB_PORT  := $(shell sed -n 's/^MARIADB_PORT=//p' $(ENV_FILE) 2>/dev/null | head -1)
DB_PORT  := $(if $(strip $(DB_PORT)),$(strip $(DB_PORT)),3306)
DB_PASSWORD := $(shell sed -n 's/^MARIADB_PASSWORD=//p' $(ENV_FILE) 2>/dev/null | head -1)
ROOT_PASSWORD := $(shell sed -n 's/^MARIADB_ROOT_PASSWORD=//p' $(ENV_FILE) 2>/dev/null | head -1)

.DEFAULT_GOAL := help

help: ## Tampilkan daftar perintah
	@echo "MariaDB : container $(CONTAINER)  (db:$(DB_NAME) user:$(DB_USER))"
	@echo "network : $(NETWORK)   port host :$(DB_PORT) -> container 3306"
	@echo ""
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

.env: ## Buat .env dari .env.example bila belum ada
	@test -f .env || cp .env.example .env

network: ## Buat network drupal bila belum ada
	@docker network inspect $(NETWORK) >/dev/null 2>&1 \
		|| docker network create --subnet 172.22.0.0/24 $(NETWORK)
	@echo "network $(NETWORK) siap"

config: .env ## Tampilkan konfigurasi yang aktif
	$(DC) config

up: .env network ## Nyalakan MariaDB (detached)
	$(DC) up -d
	$(DC) ps
	@echo "MariaDB -> $(CONTAINER):3306  (host :$(DB_PORT))"

down: ## Matikan MariaDB (data di ./data tetap aman)
	$(DC) down

restart: ## Restart MariaDB
	$(DC) restart

logs: ## Ikuti log MariaDB
	$(DC) logs -f --tail=100 mariadb

shell: ## Masuk ke shell container
	$(DC) exec mariadb bash

mysql: ## Masuk sebagai root (password dari .env)
	$(DC) exec mariadb mariadb -uroot -p$(ROOT_PASSWORD)

dbshell: ## Masuk sebagai user aplikasi (DB dari .env)
	$(DC) exec mariadb mariadb -u$(DB_USER) -p$(DB_PASSWORD) $(DB_NAME)

ping: ## Cek koneksi database
	$(DC) exec mariadb mariadb-admin ping -u$(DB_USER) -p$(DB_PASSWORD)

dump: ## Backup ke ./backup/<db>-<tanggal>.sql
	@mkdir -p backup
	$(DC) exec -T mariadb mariadb-dump -u$(DB_USER) -p$(DB_PASSWORD) \
		--single-transaction --routines --triggers $(DB_NAME) \
		> backup/$(DB_NAME)-$$(date +%F-%H%M).sql
	@ls -lh backup | tail -1

restore: ## Restore: make restore FILE=backup/nama.sql
	@test -n "$(FILE)" || { echo "Pakai: make restore FILE=backup/nama.sql"; exit 1; }
	$(DC) exec -T mariadb mariadb -u$(DB_USER) -p$(DB_PASSWORD) $(DB_NAME) < $(FILE)
	@echo "restore selesai: $(FILE)"

perms: ## Perbaiki kepemilikan data MariaDB (uid 999 = user mysql di image)
	@chown -R 999:999 data 2>/dev/null || true
	@echo "kepemilikan ./data diset ke 999:999 (user mysql di container)"

ps: ## Status container
	$(DC) ps

health: ## Cek status health
	@docker inspect -f '{{.Name}} -> {{.State.Status}} ({{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}})' $(CONTAINER) 2>/dev/null || true

clean: ## Stop + hapus container DAN data database (hati-hati!)
	$(DC) down -v
	rm -rf data/*
	@echo "Data database dihapus. Jalankan 'make up' untuk mulai dari nol."

.PHONY: help .env network config up down restart logs shell mysql dbshell ping dump restore perms ps health clean
