# Notification Service

Микросервис массовой рассылки SMS и Email-уведомлений с приоритизацией, идемпотентностью и отслеживанием статусов доставки.

## Стек

- **PHP 8.5 / Laravel 13**
- **PostgreSQL** — персистентное хранение
- **RabbitMQ** — очередь с приоритетами и retry через DLX
- **Redis** — кэш, блокировки, дедупликация provider_id → delivery_id
- **Docker Compose** — локальный запуск всего окружения

## Быстрый старт

```bash
make dev-init          # первый запуск: .env, build, migrate
make dev-migrate-refresh-seed  # пересоздать БД с тестовыми пользователями
```

Приложение: http://localhost:3000  
RabbitMQ Management: http://localhost:15672 (guest/guest)

## API

### POST `/api/notification`

Массовая отправка уведомлений.

**Headers:** `Idempotency-Key: <uuid>`

```json
{
  "channel": "sms",
  "text": "Your verification code is 1234",
  "user_ids": [1, 2, 3],
  "priority": 10
}
```

- `channel`: `sms` | `email`
- `priority`: 1–10 (10 — наивысший, обгоняет маркетинговые рассылки в RabbitMQ priority queue)

### GET `/api/user/{user}/notifications`

История и текущий статус всех уведомлений подписчика.

### POST `/api/webhooks/gateway/callback`

Callback от провайдера (mock gateway).

```json
{
  "provider_id": "sms_abc123",
  "status": "delivered"
}
```

`status`: `delivered` | `failed`

## Статусы доставки

| Статус | Описание |
|--------|----------|
| `processing` | Принято, ожидает отправки |
| `sent` | Передано шлюзу |
| `delivered` | Подтверждено провайдером |
| `dropped` | Ошибка доставки / превышен лимит retry |

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
- Идемпотентность на уровне `idempotency_key` + unique `(notification_id, user_id)`
- Retry до 3 раз при временных сбоях шлюза (TTL queue + DLX)
- Redis lock на обработку delivery (защита от duplicate processing)
- При повторном запросе с тем же Idempotency-Key — переотправка зависших `processing` deliveries

## Структура проекта

```
app/
├── Console/Commands/ConsumeNotificationDeliveries.php
├── Enums/
├── Http/Controllers/Api/
├── Services/Notification/
│   ├── NotificationService.php      # оркестрация
│   ├── GatewayResolver.php          # выбор SMS/Email gateway
│   ├── Gateways/                    # mock-провайдеры
│   └── Messaging/                   # RabbitMQ publisher & connection
config/rabbitmq.php
```

## Разработка

```bash
make dev-test           # PHPUnit
make dev-pint           # форматирование
make dev-phpstan        # статический анализ (level 8)
make dev-workers-status # статус consumer-воркеров
make dev-shell          # bash в php-контейнере
```

## Mock-провайдеры

- Блокированные получатели: `+79990000000` (SMS), `test@mail.com` (Email) → `dropped`
- ~10% запросов → временная ошибка → retry
- Успешная отправка → `provider_id` вида `sms_*` / `email_*`
