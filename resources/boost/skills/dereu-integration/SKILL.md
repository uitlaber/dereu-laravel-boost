---
name: dereu-integration
description: Интеграция с Dereu — WhatsApp CPaaS-хабом поверх Meta Cloud API. Используй эту скилл, когда нужно провижинить компанию/тенант в Dereu, подключить WhatsApp-номер клиента через Embedded Signup (Model B), принять и обработать вебхук-форвард от Dereu (входящие сообщения, статусы доставки), отправить исходящее сообщение/медиа/шаблон/OTP, или депровижинить компанию. Триггеры — "Dereu", "подключить WhatsApp", "WABA", "Embedded Signup", "platform key", "webhook от Dereu", "dereu_company_id".
---

# Интеграция с Dereu

Dereu — официальный Meta Tech Provider: сам создаёт и хранит WABA клиентов, принимает единый вебхук от
Meta и форвардит его партнёру. Партнёрский проект (этот) не работает с Meta Graph API напрямую — вся
коммуникация идёт через REST API Dereu.

Базовый URL: `https://api.dereu.noderail.io/api/v1`
Полная спецификация (OpenAPI): `https://docs.dereu.noderail.io`

## Модель данных

```
Партнёр (этот проект)
  └─ Компания (Dereu company, 1:1 с номером WhatsApp), external_id = ваш internal org id
       └─ Номер (phone_number_id)
```

Строго: 1 организация партнёра = 1 company в Dereu = 1 номер WhatsApp.

## Ключи — не путать

| Ключ | Формат | Где взять | Для чего |
|---|---|---|---|
| Platform key | `Bearer plat_<prefix>.<secret>` | ЛК партнёра в Dereu, раздел «Настройки партнёра» (self-service выпуск/перевыпуск) | Провижининг/депровижининг компаний, онбординг WABA — не привязан к компании |
| API-ключ компании | `Bearer dereu_...` | Возвращается автоматически в ответе `POST /platform/companies` при первом создании | `/messages/send`, `/otp/*`, `/media` — привязан к конкретной компании |

Оба ключа показываются **только один раз** — сохраняйте сразу в секрет-хранилище (env `DEREU_PLATFORM_KEY`
для platform key). Компанийный `api_key`, если потерян, повторно не выдаётся через provisioning-эндпоинт —
пересоздайте через `/api-keys` в ЛК владельца компании.

При выпуске/перевыпуске platform key в ЛК настраивается **общий webhook URL и secret на весь проект** —
все компании, провижиненные под этим ключом, автоматически используют этот форвард.

## 1. Провижининг компании

```
POST /platform/companies
Authorization: Bearer {DEREU_PLATFORM_KEY}
Content-Type: application/json

{
  "external_id": "org_123",
  "name": "ООО Ромашка"
}
```

Идемпотентно по `external_id`.

- **201** (первое создание): `{ "dereu_company_id": "co_abc123", "api_key": "dereu_xxx", "phone_number_id_registered": false }`
  — сохраните `dereu_company_id` и `api_key` немедленно, `api_key` больше не будет показан.
- **200** (повтор с тем же `external_id`): `{ "dereu_company_id": "co_abc123", "already_provisioned": true }`
  — `api_key` не возвращается.
- `409` — `phone_number_id` уже занят другой компанией. `422` — валидация.

## 2. Подключение номера (Embedded Signup, Model B)

Embedded Signup-попап Meta запускается **на вашем фронтенде** с `app_id`/`config_id`, которые выдаёт Dereu
вместе с platform key. По завершении попап отдаёт вам `code`, `waba_id`, `phone_number_id` — перешлите их в:

```
POST /platform/companies/{external_id}/waba
Authorization: Bearer {DEREU_PLATFORM_KEY}
Content-Type: application/json

{
  "code": "AQD...",
  "waba_id": "9876543210",
  "phone_number_id": "1234567890",
  "account_mode": "business_only"
}
```

- Ровно одно из полей `code` / `system_user_token` обязательно (`system_user_token` — путь миграции уже
  существующего токена без нового прохода Embedded Signup).
- `account_mode`: `business_only` (по умолчанию, полная регистрация) или `coexistence` (номер продолжает
  работать в WhatsApp Business App на телефоне клиента параллельно с Cloud API). Также принимает Meta-native
  значения `cloud_api`/`smb_coexistence` как есть — приводить вручную не нужно.
- **201**: `{ "dereu_company_id": "...", "phone_number_id": "...", "status": "connected", "catalogs": { "owned": [...], "waba": [...] } }`
  — каталоги Meta Commerce приходят сразу в этом ответе при подключении через `code`, отдельный
  `GET .../catalogs` обычно не нужен. Для coexistence/SMB-номеров `waba` в каталогах будет пустым
  (ограничение Meta) — используйте `owned`.
- `409` — `external_id` чужой или `phone_number_id` занят другой компанией (не ретраить тем же запросом).
- `422` "код невалиден/использован" — `code` одноразовый, нужен свежий проход Embedded Signup, не повтор
  того же `code`.
- `422` "PIN Mismatch" (`#133005`) — возникает, только если у номера в Meta Business Manager включена
  двухшаговая проверка с PIN, которого Dereu не знает (задан вручную или номер регистрировался вне Dereu).
  Dereu использует детерминированный PIN и переиспользует его при повторном подключении того же номера,
  поэтому обычный реконнект того же номера через Embedded Signup эту ошибку НЕ вызывает. Решение: снять или
  переустановить 2FA-PIN в Meta Business Manager, затем переподключить номер (другой номер не нужен).
- `502` — временный сбой Meta, ретраить со свежим `code`.

## 2b. Hosted Embedded Signup (подключение с сайта партнёра, без своего ES)

Альтернатива §2. ES-попап Meta крутится на **нашем** домене `connect.dereu.*` (он один раз в вайтлисте
Meta App) — домен партнёра вайтлистить не надо, FB SDK к себе тащить не надо. Партнёр встраивает подписанную
кнопку; клиент проходит ES у нас и возвращается на `return_url` партнёра. Полный гайд —
`docs/partner-integration.md` §11, готовый хелпер — `examples/DereuConnect.php` (рядом с этой скилл).

Оператор выдаёт партнёру `connect_signing_secret` (`consec_...`) + `allowed_return_origins`. Схема подписи —
симметричный HMAC-SHA256 над base64url-строкой (НЕ JWT): подписывается строка `d`, а не её JSON.

**1. Партнёр строит подписанный payload** и встраивает виджет (или ручной `window.open`):

```php
function b64url(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }

$payload = ['external_id' => 'org_123', 'return_url' => 'https://app.partner.kz/done',
            'nonce' => $nonce = bin2hex(random_bytes(16)), 'exp' => time() + 600];   // nonce сохранить
$d   = b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$sig = b64url(hash_hmac('sha256', $d, $connectSigningSecret, true));   // подписываем строку $d
$p   = $keyPrefix;   // напр. plat_ab12cd
```

```html
<script src="https://connect.dereu.chat/widget.js"
        data-connect-url="https://connect.dereu.chat/connect"
        data-payload="{d}" data-prefix="{p}" data-sig="{sig}"
        data-label="Подключить WhatsApp"></script>
```

**2. Верификация OUT-редиректа** `return_url?result=<b64>&sig=<hmac>` (`result` =
`{dereu_company_id, phone_number_id, waba_id, status, nonce}`):

```php
$expected = b64url(hash_hmac('sha256', $_GET['result'], $connectSigningSecret, true));
if (! hash_equals($expected, $_GET['sig'])) { abort(400); }              // подпись
$data = json_decode(base64_decode(strtr($_GET['result'], '-_', '+/')), true);
if (! consumeNonce($data['nonce'])) { abort(409); }                      // one-time, ваш стор
```

**3. Забрать `api_key`** (наружу в OUT не отдаётся) — S2S своим `plat_`-ключом:

```
POST /platform/companies/{external_id}/api-key/reissue
Authorization: Bearer {DEREU_PLATFORM_KEY}
```

- **200**: `{ "api_key": "dereu_..." }` — показывается один раз. `409` — компания чужого партнёра.

Требования: `return_url` origin ∈ `allowed_return_origins` (строгий scheme+host+port, иначе
`422 return_url_not_allowed`); `exp` 5–10 мин (`422 expired`); `nonce` one-time (`409 nonce_used`).

## 3. Приём входящих (webhook-форвард)

Один webhook URL/secret на весь проект (настраивается при выпуске platform key), не per-компания.

**Обязательно проверяйте подпись до парсинга JSON:**
заголовок `X-Dereu-Signature: sha256=<hex>`, где `hex = HMAC-SHA256(webhook_secret, raw_body)`.

```php
$signature = $request->header('X-Dereu-Signature');
$expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), config('services.dereu.webhook_secret'));
if (! hash_equals($expected, (string) $signature)) {
    abort(401);
}
```

Тело события — `type`/`payload` являются pass-through сырого объекта Meta:

```json
{
  "event": "message_received",
  "event_id": "01J8Z...",
  "company_id": "co_abc123",
  "phone_number_id": "1234567890",
  "from": "77011234567",
  "wamid": "wamid.HBg...",
  "type": "text",
  "payload": { "body": "Привет" },
  "timestamp": 1718000000
}
```

- Резолвьте вашего тенанта по `company_id` вебхука — это **внутренний `dereu_company_id`** Dereu (из ответа
  провижининга), а НЕ ваш `external_id`. Сохраняйте `dereu_company_id` при провижининге и матчите по нему
  (см. gotcha `dereu_company_id` ≠ `external_id`).
- Дедуп: входящие сообщения — по `wamid` (одно сообщение может дать несколько статусных событий);
  доставки статусов — по `event_id`.
- `event`: `message_received`, `message_sent`, `message_delivered`, `message_read`, `message_failed`,
  `template_status_update`.
- `type` известные значения: `text`, `image`, `video`, `audio`, `document`, `sticker`, `location`,
  `interactive`, `button`, `order`, `contacts`, `reaction`, `system`, `unsupported` — список не закрыт,
  не падайте на неизвестном `type`.
- `template_status_update` — статус шаблона в Meta изменился (модерация). У этого события нет `from`/
  `wamid`/`type`, форма другая:

  ```json
  {
    "event": "template_status_update",
    "event_id": "01J8Z...",
    "company_id": "co_abc123",
    "waba_id": "9876543210",
    "payload": {
      "name": "order_shipped",
      "language": "ru",
      "status": "approved",
      "reason": null
    }
  }
  ```

  `payload.status`: `pending` / `approved` / `rejected`. `payload.reason` заполнен только при `rejected`
  (текст причины от Meta), иначе `null`.
- Отвечайте `2xx` быстро (обработку — в очередь). Ретраи с backoff, при исчерпании — dead-letter у Dereu.

## 4. Отправка сообщений

```
POST /messages/send
Authorization: Bearer {company api_key}
Content-Type: application/json

{
  "phone_number_id": "1234567890",
  "to": "+77010000000",
  "type": "text",
  "payload": { "body": "Привет" }
}
```

`type`: `text`, `template`, `otp`, `image`, `video`, `document`, `audio`, `interactive`, `location`, `contacts`.
Для медиа-типов — ровно одно из `payload.id` (media_id из `POST /media`) или `payload.link` (публичный URL,
Meta скачает сама — предпочтительный вариант, если файл уже доступен по HTTPS).

`marketing: true` требует предварительного `POST /optin`, иначе `403 opt_in_required`.
`context: { message_id: "<wamid>" }` — reply-threading.

Ответ **202**: `{ "id": "<uuid>", "status": "queued" }` — статус доставки прилетит асинхронно вебхуком.

Ошибки: `401` неверный ключ; `403` компания приостановлена / opt-in отсутствует; `422` валидация;
`429` throttle `60/мин` на компанию.

**OTP:** `POST /otp/send { phone_number_id, to }` → `202`, код живёт 5 минут;
`POST /otp/verify { to, code }` → `200 {status: verified}` / `422` неверный код / `429`.

**Opt-in:** `POST /optin { phone, type: marketing|transactional, source, consent_text }` → `201`.

## 5. Медиа

- `POST /media` (company api_key, multipart) — загрузка файла без публичного URL → `{ media_id, mime_type }`,
  использовать в `payload.id`.
- `GET /media/{media_id}` (company api_key) — скачать байты входящего медиа-сообщения.

## 6. Депровижининг

```
DELETE /platform/companies/{external_id}?purge=true
Authorization: Bearer {DEREU_PLATFORM_KEY}
```

Идемпотентно: несуществующий `external_id` → `404`, уже деактивированная → `410`. Без `purge=true`
входящие хранятся ещё 30 дней. Ответ **200**: `{ "dereu_company_id": "...", "deactivated": true, "purged": false }`.

## 7. Управление шаблонами

S2S-эндпоинты под platform key — партнёр управляет шаблонами компании без захода в ЛК. Изоляция та же,
что у остальных `/platform/*`: чужой или несуществующий `external_id` → `409`.

**Список:**

```
GET /platform/companies/{external_id}/templates
Authorization: Bearer {DEREU_PLATFORM_KEY}
```

**200**: `{ "data": [{ "id": 1, "name": "order_shipped", "language": "ru", "category": "utility",
"status": "approved", "components": [...] }] }` — `components` в форме Meta (см. ниже).

**Создание:**

```
POST /platform/companies/{external_id}/templates
Authorization: Bearer {DEREU_PLATFORM_KEY}
Content-Type: application/json

{
  "phone_number_id": "1234567890",
  "name": "order_shipped",
  "language": "ru",
  "category": "utility",
  "body": "Ваш заказ {{1}} отправлен, ожидайте {{2}} дней.",
  "components": [
    { "type": "HEADER", "format": "TEXT", "text": "Статус заказа" },
    { "type": "FOOTER", "text": "Спасибо, что выбрали нас" },
    { "type": "BUTTONS", "buttons": [
      { "type": "QUICK_REPLY", "text": "Уточнить" },
      { "type": "URL", "text": "Отследить", "url": "https://x.kz/track/{{1}}", "example": ["abc123"] }
    ] }
  ],
  "example": { "body_text": [["12345", "3"]] }
}
```

- `name` — только `[a-z0-9_]`. `category`: `authentication` / `utility` / `marketing`.
- `components` — опционален, элементы: `HEADER` (`format`: `TEXT`/`IMAGE`/`VIDEO`/`DOCUMENT`/`LOCATION`,
  для `TEXT` — `text`), `FOOTER` (`text`), `BUTTONS` (`buttons[]` с `type`: `QUICK_REPLY`/`URL`/
  `PHONE_NUMBER`; `URL` требует `url`, `PHONE_NUMBER` требует `phone_number`).
- **Правило про `example`**: для каждого плейсхолдера `{{n}}` в `body`/`HEADER.text`/URL-кнопки нужен
  пример с ровно тем же числом элементов, иначе Meta режет шаблон на модерации и API отвечает `422`:
  - `body` с `{{n}}` → `example.body_text` — массив строк-наборов, каждый набор длиной `n`.
  - `HEADER` (`format: TEXT`) с `{{n}}` → `example.header_text`, длина `n`.
  - медиа-`HEADER` (`IMAGE`/`VIDEO`/`DOCUMENT`) → `example.header_handle` обязателен (непустой).
  - URL-кнопка с `{{n}}` в `url` → `buttons[i].example`, длина `n`.
- **201**: `{ "name": "order_shipped", "language": "ru", "status": "pending" }`. `422` — валидация или
  отказ Meta (`TemplateRejected`).

**Удаление:**

```
DELETE /platform/companies/{external_id}/templates/{id}
Authorization: Bearer {DEREU_PLATFORM_KEY}
```

**200**: `{ "status": "deleted" }`. `404` — шаблон не найден. `422` — Meta отказала в удалении.

**Синк из Meta** (подтянуть шаблоны, созданные/изменённые напрямую в Meta Business Manager, минуя Dereu):

```
POST /platform/companies/{external_id}/templates/sync
Authorization: Bearer {DEREU_PLATFORM_KEY}
```

**200**: `{ "synced": 3 }` — число синхронизированных шаблонов. `422` — ошибка синка.

Статус модерации приходит асинхронно вебхуком — см. `template_status_update` в §3.

## Требования к webhook-приёмнику

Реальные грабли из онбординга партнёра — с ними форвард «не заводится», хотя провижининг прошёл.

- **Точный путь.** URL, который вы регистрируете (ЛК «Webhooks» или `--webhook-url` при выпуске platform
  key), должен ТОЧНО совпадать с вашим роутом, включая префикс. Частая ошибка: зарегистрировали
  `/webhooks/dereu`, а Laravel-роут реально `/api/webhooks/dereu` (глобальный префикс `/api`) → Dereu
  получает **404**, событие уходит в dead-letter. Проверьте curl'ом снаружи, что POST на точный URL
  возвращает НЕ 404.
- **Стабильный публичный HTTPS + 2xx.** Приёмник должен быть публично доступен по HTTPS и на валидно
  подписанный POST возвращать **2xx**. Любой не-2xx (401 неверная подпись, 404 путь, 5xx падение) → Dereu
  ретраит, затем **dead-letter**.
- **Молчаливость провалов.** Провалы доставки НЕ видны в UI партнёра автоматически. Если «входящие не
  приходят», проверьте по порядку: (а) ваш endpoint отвечает 2xx на подписанный POST; (б) путь точный;
  (в) подпись сверяется по сырым байтам тем же `whsec_`.
- **Эфемерные туннели.** ngrok-free и подобные меняют URL при рестарте → форвард начинает ловить ошибки и
  копить dead-letter. Для боевой интеграции — стабильный постоянный URL; при смене туннеля не забудьте
  обновить webhook URL в Dereu.
- **Диагностика по HTTP-коду** (что видит Dereu на вашем endpoint): `404` = неверный путь;
  `401/403` = подпись/секрет не сошлись (проверьте raw-body HMAC и что `whsec_` тот же); `5xx/timeout` = ваш
  обработчик падает; `2xx` = ок.

## Частые ошибки (gotchas)

- Не путайте platform key (провижининг) и company api_key (отправка) — разные заголовки, разная область
  действия.
- `code` из Embedded Signup одноразовый — при `422 "код невалиден"` не ретраить тем же значением, нужен
  новый проход попапа.
- Для coexistence-номеров `PIN` не создаётся и `/register` не вызывается — не ожидайте PIN-флоу в вашем UI
  для таких номеров.
- Проверка `X-Dereu-Signature` — обязательна, до `json_decode`. Сырое тело, не re-encoded JSON.
- `dereu_company_id` ≠ `external_id`: `external_id` — ваш внутренний id, используется в путях
  `/platform/companies/{external_id}/...`; `dereu_company_id` — id Dereu, приходит в ответах и в
  `company_id` вебхука.

Полный контракт с примерами всех эндпоинтов (шаблоны, каталоги, business profile): `https://docs.dereu.noderail.io`.
