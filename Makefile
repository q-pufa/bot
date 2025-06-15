# Задаємо ім'я контейнера для зручності
COMPOSE = docker compose

# Мета для запуску контейнерів в фоновому режимі
up:
	$(COMPOSE) up -d

build:
	$(COMPOSE) build

# Мета для зупинки контейнерів
stop:
	$(COMPOSE) stop

# Мета для перезапуску контейнерів
restart:
	$(COMPOSE) stop
	$(COMPOSE) up -d

# Мета для перегляду логів контейнерів
logs:
	$(COMPOSE) logs -f

# Мета для перевірки статусу контейнерів
ps:
	$(COMPOSE) ps

bash:
	$(COMPOSE) exec -u 1000 apache bash
