# Universal Support Chat (Telegram Tickets)

WordPress-плагин для онлайн-чата и тикет-системы с доставкой сообщений в Telegram.

## Что умеет

- Плавающий чат-виджет на сайте (гость и авторизованный пользователь).
- Тикетная модель диалога (история хранится в БД).
- Ответы операторов из Telegram прямо в нужный чат.
- Приглашение гостя на регистрацию из Telegram-команды.
- Внешняя интеграция: один бэкенд WordPress + много сайтов через JS-сниппет.
- Интерфейс на `ru`, `en`, `et` (в т.ч. ключ языка для embed-скрипта).
- Автоответ при отсутствии ответа оператора:
  - в рабочее время: через 2 минуты,
  - вне рабочего времени: сразу.

## Требования

- WordPress 6+
- PHP 8+
- Доступ к REST API (`/wp-json/...`)
- Telegram Bot Token и Chat ID

## Установка

1. Скопируйте папку плагина в:
   `wp-content/plugins/universal-support-chat`
2. Активируйте плагин в WordPress.
3. В админке откройте:
   `Support Chat Telegram`

## Базовая настройка (в админке)

Раздел `Настройки Telegram`:

- `Bot Token` — токен бота от BotFather.
- `Chat ID` — ID чата/группы, куда приходят обращения.
- `Регистрация клиентов` — разрешить/запретить регистрацию в виджете.

После сохранения используйте показанный `Webhook URL`.

## Настройка Telegram Webhook

Пример установки webhook через браузер/CLI:

```text
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://your-site.com/wp-json/support-chat/v1/telegram-webhook&secret_token=<WEBHOOK_SECRET>
```

Проверка статуса webhook:

```text
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo
```

`<WEBHOOK_SECRET>` берите из админки плагина (поле `Webhook Secret`).

## Как отвечать из Telegram

Плагин понимает форматы:

- `#123 Текст ответа`
- `/reply 123 Текст ответа`
- Reply на сообщение бота (по `reply_to_message`)

Команда приглашения на регистрацию:

- `/invite 123`

Проверка webhook-обработчика:

- `/ping`

## Внешние сайты (один backend -> много frontend)

В админке в блоке `Внешние сайты (JS интеграция)`:

1. Добавьте сайт (`Название`, `URL сайта`).
2. Скопируйте сгенерированный сниппет и вставьте на внешний сайт.

Пример сниппета:

```html
<script
  src="https://backend-site.com/wp-content/plugins/universal-support-chat/assets/support-chat-embed.js"
  data-support-chat-endpoint="https://backend-site.com/wp-json/support-chat/v1"
  data-support-chat-key="sc_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
  data-support-chat-lang="ru"
></script>
```

### Параметры embed-скрипта

- `data-support-chat-endpoint` — endpoint backend WordPress.
- `data-support-chat-key` — API ключ внешнего сайта (из админки).
- `data-support-chat-lang` — язык интерфейса: `ru`, `en`, `et`.

## Авторизация клиента

- Для внешних сайтов используется отдельная таблица клиентов плагина.
- Вход/регистрация происходят без WP-форм входа.
- После авторизации клиент видит свои чаты и может продолжать диалог.

## Рабочее время и автоответ

Рабочие часы (Europe/Tallinn):

- Пн–Пт, `09:00–18:00`

Логика:

- Если оператор не ответил в течение 2 минут — отправляется автоответ о занятости.
- Если сообщение пришло вне рабочего времени — автоответ отправляется сразу.

## Шорткод

Плагин также регистрирует шорткод:

```text
[support_chat]
```

Его можно использовать для отдельной страницы поддержки.

## Локализация

- Text domain: `universal-support-chat`
- Каталог переводов: `languages/`

Поддерживаются `ru`, `en_US`, `et` (PO/MO файлы в комплекте).

## Частые проблемы

1. Бот отправляет в Telegram, но не читает ответы:
- Проверьте, что webhook установлен именно на текущий URL backend.
- Проверьте `getWebhookInfo` и поле `last_error_message`.

2. Ошибка `origin_not_allowed` для внешнего сайта:
- Убедитесь, что в админке добавлен правильный `URL сайта` (домен должен совпадать).

3. Ответ из Telegram не попадает в нужный чат:
- Отвечайте форматом `#ID текст` или reply на исходное сообщение бота.

4. Не приходят уведомления:
- Проверьте `Bot Token`, `Chat ID`, и что бот добавлен в нужный чат.

## Где хранятся данные

Плагин создаёт таблицы (с префиксом WP):

- `support_chat_tickets`
- `support_chat_messages`
- `support_chat_telegram_links`
- `support_chat_sites`
- `support_chat_clients`

## Версия

Текущая версия: `2.0.0`
