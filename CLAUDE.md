# CLAUDE.md — symfony_documents_flow

Symfony 8 монолит корпоративного портала (PHP ≥8.4, PostgreSQL 16.4, Doctrine ORM 3).
Выдаёт JWT для SPA (`analytics_platform`) и Go-сервисов. Все ответы SPA — `/spa/api/*`.

## Конвенции кода (обязательны)

- `declare(strict_types=1)`, `final` контроллеры/сервисы, constructor promotion `private readonly`.
- Роутинг — только PHP-атрибуты `#[Route]`; контроллеры SPA в `src/Controller/SpaApi/<Домен>/`.
- JSON строится вручную через `*ApiPresenter`/`*ResponseFormatter` (НЕ Symfony Serializer, НЕ `#[Groups]`).
- Формат списков: `{<имя_ресурса>: [...], pagination: {current_page, total_pages, total_items, items_per_page}}`
  (эталон — `PostResponseFormatter::formatPagination`). Клампы: `page ≥ 1`, `page_size` 1..100.
- Ошибки: `{error: <код>}` + HTTP-статус; коды — константы `src/Controller/SpaApi/SpaApiError.php`.
- Enum'ы — string-backed c RU-`getLabel()`; в БД — VARCHAR, НЕ нативные PG-enum. Новый кейс enum → обязательно дописать `getLabel()` (иначе `UnhandledMatchError`).
- Деньги/количества — `Types::DECIMAL` (PHP string), НИКОГДА float.
- Soft-delete — Gedmo `#[Gedmo\SoftDeleteable]` + `deleted_at`; timestamps — Gedmo Timestampable.
- Файлы — Vich, локальный приватный диск; эталон сущности файла — `src/Entity/Document/DocumentCommentFile.php` (storage_key + filename + content_type + size_bytes).
- ⚠ `src/Security/Voter/PermissionVoter.php` — МЁРТВЫЙ КОД (вызывает несуществующий `User::hasPermission()`). Не использовать и не «чинить» мимоходом. Авторизация: `#[IsGranted('ROLE_*')]` + доменный `*AccessService`.
- Роли: enum `src/Enum/User/UserRole.php` → `role_hierarchy` в `config/packages/security.yaml` → `php bin/console app:roles:sync`. Назначение людям — админка `/roles` (pivot `user_role`).
- Миграции: пишет и запускает ТОЛЬКО владелец. Агенты готовят DDL-спеку/справку, но не создают файлы в `migrations/` без явной просьбы.

## Модуль инвентаризации (Inventory)

- Домен: `src/{Entity,Enum,Repository}/Inventory/`, `src/Controller/SpaApi/Inventory/`, `src/Service/SpaApi/Inventory/`.
- Контракт API и матрица доступа: `dev_docks/spa_api/inventory/Readme_inventory.md`. **Любое изменение API — синхронное обновление этой доки в том же коммите.**
- Ledger-инварианты (нарушать нельзя):
  - `inventory_movement` — append-only: никаких UPDATE/DELETE; исправления — только сторно-документом.
  - `inventory_stock` меняется ТОЛЬКО проведением документов (UPSERT `ON CONFLICT` + retry на serialization failure); инвариант `stock = Σ movement` проверяется `app:inventory:verify-stock`.
  - После `posted` документ, строки и сканы неизменяемы.
  - Правило свёртки (единственное место — контракт): пользовательские экраны и сверка агрегируют `SUM … GROUP BY (nomenclature, holder)` без `managing_warehouse_id` и `room_id`.
- Секреты устройств: только через `CredentialCipher` (sodium secretbox, env `INVENTORY_CREDENTIALS_KEY`, формат `v{key_version}:…`). Секреты НЕ попадают в логи, `inventory_history.payload`, ответы без reveal, экспорты. Reveal — только `ROLE_INVENTORY_ADMIN`, с записью в `inventory_credential_view_log`, ответ с `Cache-Control: no-store`.

## Мульти-модельный протокол (обязателен, решение владельца)

Кроме Claude в проекте работают **Codex** (`codex exec --sandbox <read-only|workspace-write> -m gpt-5.6-sol -c model_reasoning_effort="xhigh"`) и **Kimi** (`~/.kimi-code/bin/kimi.exe -m kimi-code/k3 -p`).

1. **Состязательное ревью обязательно** для: схемы БД/миграций, логики остатков (`StockPostingService`, сторно, счётчики), безопасности (роли, скоупы, шифрование), импорта/сверки. Схема: Claude формулирует решение → Codex и Kimi независимо атакуют → минимум один раунд контраргументов → синтез.
2. **Совпавшие находки** двух независимых ревьюеров принимаются; **расхождения** — раскапываются до кода.
3. **Каждое фактическое утверждение делегата проверяется по репозиторию** — обе модели уверенно ссылаются на несуществующие файлы и методы.
4. **Большая кодовая работа параллелится по НЕПЕРЕСЕКАЮЩИМСЯ путям** (например: Codex — сущности, Kimi — фронт, Claude — сервисы ядра). Пересечение путей запрещено.
5. **Гейты (phpunit, lint:container, schema:validate, npm lint/build) и git — только рукой Claude.** Делегатам git запрещён.
6. Итог каждого дебата — 3–5 строк в `dev_docks/spa_api/inventory/decisions.md` (ADR-стиль, с пометкой «после дебатов с Codex/Kimi»).

## Проверка и запуск

- Тесты: `php vendor/bin/phpunit` (PHPUnit 12, test-БД с суффиксом `_test`).
- Контейнер: `php bin/console lint:container`; схема: `php bin/console doctrine:schema:validate`.
- Стек: `docker compose up -d` (php+nginx:8080+postgres+mercure+rabbitmq); фронт-дев: `analytics_platform` → `npm run dev` (:3001, rewrites на `NEXT_PUBLIC_BACKEND_URL`).
- После правки ролей: `php bin/console app:roles:sync`.
