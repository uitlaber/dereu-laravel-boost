# dereu/laravel-boost

Laravel Boost skill + AI guidelines для интеграции партнёрского Laravel-проекта с
[Dereu](https://docs.dereu.noderail.io) — WhatsApp CPaaS-хабом поверх Meta Cloud API.

Пакет не содержит кода — только `resources/boost/skills/dereu-integration/SKILL.md` и
`resources/boost/guidelines/core.blade.php`, которые [Laravel Boost](https://github.com/laravel/boost)
автоматически подхватывает у установленных composer-пакетов при `boost:install`/`boost:update`. После
установки AI-агент партнёра (Claude Code, Cursor, Copilot и т.п.) получает знание о том, как провижинить
компанию, подключать номера через Embedded Signup, принимать вебхуки и отправлять сообщения через Dereu.

## Установка (партнёром)

```bash
composer require dereu/laravel-boost --dev
php artisan boost:install
```

В интерактивном мастере `boost:install` выберите skill `dereu-integration` (или подтвердите установку всех
доступных skills от установленных пакетов) — Boost скопирует/подключит SKILL.md и guideline в
`.ai/`-конфигурацию используемого вами агента.

**Без пакета** (вручную): скопируйте
`vendor/dereu/laravel-boost/resources/boost/skills/dereu-integration/` в `.ai/skills/dereu-integration/`
своего проекта и выполните `php artisan boost:update`.

## Как мы публикуем пакет

**Вариант A — Packagist.** Репозиторий `packages/laravel-boost` сабмиттится на
[packagist.org](https://packagist.org) как отдельный пакет `dereu/laravel-boost` (через subtree-split или
отдельный git-репозиторий с зеркалом монорепо-каталога). После публикации партнёр ставит его обычным
`composer require`.

**Вариант B — приватный VCS** (пока пакет не на Packagist). Партнёр добавляет в свой `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/uitlaber/dereu" }
  ],
  "require-dev": {
    "dereu/laravel-boost": "dev-main"
  }
}
```

и указывает путь к пакету через `--strategy` или Composer path-репозиторий, если требуется установка из
подкаталога монорепо (`{"type": "path", "url": "packages/laravel-boost"}` для локальной разработки/тестов
внутри самого монорепо Dereu).

## Что внутри

```
resources/boost/skills/dereu-integration/SKILL.md   # контракт партнёрской интеграции: провижининг,
                                                      # Embedded Signup, вебхуки, отправка, OTP
resources/boost/guidelines/core.blade.php            # короткий upfront-guideline для AI-агента
```

Источник истины по API — `docs/partner-integration.md` и `services/api/public/openapi.yaml` в основном
репозитории Dereu; при изменении контракта API синхронизируйте `SKILL.md` вручную.
