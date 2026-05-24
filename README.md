# Notification Service

Микросервис массовой рассылки SMS и Email-уведомлений с приоритизацией, идемпотентностью и отслеживанием статусов
доставки.

## Стек

- **PHP 8.5 / Laravel 13**
- **PostgreSQL** — персистентное хранение
- **RabbitMQ** — очередь с приоритетами и retry через DLX
- **Redis** — кэш, блокировки, дедупликация `provider_id` → `delivery_id`
- **Docker Compose** — локальный запуск всего окружения

## Запуск

### Требования

- Docker 24+
- Docker Compose v2+

### Пошаговая инструкция

1. **Клонировать репозиторий и перейти в директорию:**

   ```bash
   git clone <repo-url>
   cd notification-service
   ```

2. **Запустить все сервисы:**

   ```bash
   make dev-init
   ```

   Команда выполнит:
    - копирование `.env.example` → `.env` (если файл отсутствует)
    - сборку Docker-образов
    - запуск всех сервисов через `docker compose up -d --build`

3. **Пересоздать базу данных с тестовыми данными:**

   ```bash
   make dev-migrate-refresh-seed
   ```

### Доступные сервисы

| Сервис              | Адрес                                  |
|---------------------|----------------------------------------|
| Приложение          | http://localhost:3000                  |
| RabbitMQ Management | http://localhost:15672 (guest / guest) |
| PostgreSQL          | localhost:5432                         |
| Redis               | localhost:6379                         |

### Управление

```bash
make dev-stop          # Остановить контейнеры (сохранить данные)
make dev-restart       # Перезапустить контейнеры
make dev-down          # Остановить и удалить контейнеры и тома
make clean             # Удалить всё (контейнеры, тома, образы)
```

## API

### POST `/api/notification`

Массовая отправка уведомлений.

**Headers:**

| Header            | Required | Description                              |
|-------------------|----------|------------------------------------------|
| `Idempotency-Key` | Да       | UUID для защиты от дублирования запросов |
| `Accept`          | Да       | `application/json`                       |

**Body:**

```json
{
    "channel": "sms",
    "text": "Your verification code is 1234",
    "user_ids": [
        1,
        2,
        3
    ],
    "priority": 10
}
```

| Параметр   | Тип      | Описание                                         |
|------------|----------|--------------------------------------------------|
| `channel`  | `string` | `sms` или `email`                                |
| `text`     | `string` | Текст сообщения                                  |
| `user_ids` | `int[]`  | Массив идентификаторов получателей               |
| `priority` | `int`    | Приоритет 1–10 (10 — наивысший). По умолчанию: 1 |

**Ответ:** массив объектов модели `NotificationDelivery` через ресурс `NotificationDeliveryResource`.

---

### GET `/api/user/{user}/notifications`

История и текущий статус всех уведомлений подписчика.

**Headers:**

| Header   | Required | Description        |
|----------|----------|--------------------|
| `Accept` | Да       | `application/json` |

**Путь:**

| Параметр | Тип   | Описание                   |
|----------|-------|----------------------------|
| `user`   | `int` | Идентификатор пользователя |

**Ответ:** массив объектов модели `NotificationDelivery` через ресурс `NotificationDeliveryResource`.

---

### POST `/api/webhooks/gateway/callback`

Callback от провайдера (mock gateway) для обновления статуса доставки.

**Headers:**

| Header   | Required | Description        |
|----------|----------|--------------------|
| `Accept` | Да       | `application/json` |

**Body:**

```json
{
    "provider_id": "sms_abc123",
    "status": "delivered"
}
```

| Параметр      | Тип      | Описание                                             |
|---------------|----------|------------------------------------------------------|
| `provider_id` | `string` | Идентификатор, возвращённый провайдером при отправке |
| `status`      | `string` | `delivered` или `failed`                             |

**Ответ:** `{"status": "delivered"}` или `{"status": "dropped"}`

---

## Статусы доставки

| Статус       | Описание                                     |
|--------------|----------------------------------------------|
| `processing` | Принято, ожидает отправки в очереди RabbitMQ |
| `sent`       | Передано шлюзу/провайдеру                    |
| `delivered`  | Подтверждено провайдером                     |
| `dropped`    | Ошибка доставки / превышен лимит retry       |

## Архитектура

```
Client → API → NotificationService → PostgreSQL
                    ↓
              RabbitMQ (priority queue)
                    ↓
         ConsumeNotificationDeliveries (workers)
                    ↓
              Mock SMS/Email Gateway
                    ↓
         Webhook callback → финальный статус
```

**Надёжность:**

- At-least-once доставка через persistent RabbitMQ messages
- Exactly-once на уровне бизнес-логики через `idempotency_key` + unique `(notification_id, user_id)`
- Retry до 3 раз при временных сбоях шлюза (TTL queue + DLX)
- Redis lock на обработку delivery (защита от duplicate processing)
- При повторном запросе с тем же `Idempotency-Key` — переотправка зависших `processing` deliveries

## Тестирование

```bash
make dev-test           # PHPUnit (все тесты)
make dev-test-coverage  # С покрытием (Xdebug)
```

Тесты покрывают:

- **Feature:** полный цикл отправки, получение истории, webhook-колбэки
- **Unit:** сервисы, контроллеры, команды, gateways, RabbitMQ publisher

## Mock-провайдеры

- Блокированные получатели: `+79990000000` (SMS), `test@mail.com` (Email) → `dropped`
- ~10% запросов → временная ошибка → retry
- Успешная отправка → `provider_id` вида `sms_*` / `email_*`

## Postman-коллекция

Для тестирования API через Postman используйте коллекцию `api_collection.json` в корне репозитория.

Импорт: **Postman → Import → File → api_collection.json**

Коллекция содержит 3 запроса:

1. **Batch send notification to users** — массовая отправка
2. **Get user notifications** — получение истории уведомлений
3. **Provider delivery verification (webhook)** — callback провайдера
