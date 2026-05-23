DC_DEV = docker compose

.PHONY: help dev-init dev-run dev-stop dev-restart dev-down dev-test dev-test-coverage dev-pint dev-phpstan dev-shell dev-workers-status dev-workers-reload dev-workers-log clean

.DEFAULT_GOAL := help

SERVICE_NAME=nginx
CONTAINER_PORT=80

define print_link
	@echo "✅ Проект запущен!"
	@PORT=$$(docker compose port $(SERVICE_NAME) $(CONTAINER_PORT) | sed 's/.*://'); \
	echo "🔗 Ссылка: http://localhost:$$PORT"
endef

dev-init:
	[ -f .env ] || cp .env.example .env
	$(DC_DEV) up -d --build
	$(call print_link)

dev-run:
	$(DC_DEV) up -d
	$(call print_link)

dev-stop:
	$(DC_DEV) stop
	@echo "Проект остановлен."

dev-restart:
	$(DC_DEV) restart
	$(call print_link)

dev-down:
	$(DC_DEV) down

dev-test:
	$(DC_DEV) exec php php artisan test

dev-test-coverage:
	$(DC_DEV) exec -e XDEBUG_MODE=coverage php php artisan test --coverage

dev-pint:
	$(DC_DEV) exec php ./vendor/bin/pint

dev-phpstan:
	$(DC_DEV) exec php ./vendor/bin/phpstan

dev-migrate-refresh-seed:
	$(DC_DEV) exec php php artisan migrate:refresh --seed

dev-shell:
	$(DC_DEV) exec php bash

dev-workers-status:
	$(DC_DEV) exec php-worker supervisorctl status

dev-workers-reload:
	$(DC_DEV) exec php-worker supervisorctl reload

dev-workers-log:
	$(DC_DEV) logs -f php-worker

clean:
	docker image prune
	docker compose down -v
	docker builder prune

help:
	@echo ""
	@echo "Использование: make [команда]"
	@echo ""
	@echo "┌─────────────────────────────────────────────────────────────────────┐"
	@echo "│                              🚀  Запуск                             │"
	@echo "├───────────────────────────────┬─────────────────────────────────────┤"
	@echo "│ make dev-init                 │ Первый запуск (копирует .env)       │"
	@echo "│ make dev-run                  │ Запуск уже собранных контейнеров    │"
	@echo "│ make dev-stop                 │ Остановить (контейнеры сохраняются) │"
	@echo "│ make dev-restart              │ Перезапустить контейнеры            │"
	@echo "│ make dev-down                 │ Остановить и удалить контейнеры     │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│                              🛠️  Инструменты                        │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│ make dev-pint                 │ Форматирование кода (Pint)          │"
	@echo "│ make dev-phpstan              │ Статический анализ (PHPStan)        │"
	@echo "│ make dev-shell                │ Открыть bash в php контейнере       │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│                              🗄️  База данных                        │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│ make dev-migrate-refresh-seed │ Пересоздать БД и сиды               │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│                              📦  Очереди и воркеры                  │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│ make dev-workers-status       │ Статус фоновых процессов воркеров   │"
	@echo "│ make dev-workers-reload       │ Перезагрузить воркеров              │"
	@echo "│ make dev-workers-log          │ Показать лог воркеров               │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│                              🧪  Тестирование                       │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│ make dev-test                 │ Запуск тестов PHPUnit               │"
	@echo "│ make dev-test-coverage        │ Запуск тестов с покрытием (Xdebug)  │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│                              🧹  Очистка                            │"
	@echo "├───────────────────────────────┼─────────────────────────────────────┤"
	@echo "│ make clean                    │ Удалить контейнеры, тома, образы    │"
	@echo "└───────────────────────────────┴─────────────────────────────────────┘"
	@echo ""
