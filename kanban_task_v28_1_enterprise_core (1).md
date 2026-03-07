# ONE-FILE ЗАДАНИЕ ДЛЯ CODING-АГЕНТА (v28.1 THE ENTERPRISE CORE+ — Fix envsubst command, Proper code fences, Proxy headers, Explicit ID types, Explicit CASCADE)
## Канбан-доска (микросервис) для корпоративного портала (Bootstrap 5.2 + Mazer)

> Цель: сделать **канбан-доску** (UX близок к YouGile). Нет отдельного чата как сервиса, но есть **комментарии (чат) внутри карточки** в правом Drawer (Offcanvas).  
> Встраивается в портал предприятия как **микросервис**, поднимается **в Docker Compose** с PostgreSQL.  

---

## 0) Протокол выполнения
**Если есть доступ к ФС (Cursor/Windsurf/IDE):** Создавай/изменяй файлы **напрямую**. В чат выдавай только отчёт.  
**Если нет доступа к ФС:** Выдавай проект по этапам (БД/Инфра, Backend, Frontend).  
- **Никаких `// ...` / `TODO`**. Выдавай полные листинги.
- В `docs/ASSUMPTIONS.md` зафиксируй: **"Конкурентный DnD и редактирование используют Optimistic Locking (`prev_updated_at`), если он передан. Иначе применяется стратегия Last-write-wins".**

---

## 0.1 Типы идентификаторов (КРИТИЧНО — чтобы seed и SSO совпали)
- `User.id`: **TEXT** (из `X-Portal-UserId`, допускается `"1"`).
- `org_id`: **TEXT** (из `X-Portal-OrgId`, допускается `"1"`).
- Все доменные сущности **кроме User**: **UUID** (`Board/Column/Card/Label/Attachment/ChecklistItem/CardComment.id`).

---

## 1) Жёсткие ограничения, Nginx и Безопасность
- Базовый путь UI: **`/apps/kanban`**. Backend не публикуется наружу, только через edge.
- Все API-роуты строго под `/api/...`.
- Swagger: `app = FastAPI(root_path="/apps/kanban", servers=[{"url": "/apps/kanban"}], docs_url="/api/docs", openapi_url="/api/openapi.json", redoc_url=None)`.

### 1.1 Edge Nginx (КРИТИЧНО: Кастомный envsubst)
Стандартный механизм `NGINX_ENVSUBST_FILTER` может не сработать. В `docker-compose.yml` используем явный вызов `envsubst`:

```yaml
kanban-edge:
  image: nginx:alpine
  environment:
    - INTERNAL_PROXY_SECRET=${INTERNAL_PROXY_SECRET}
    - MAX_UPLOAD_MB=${MAX_UPLOAD_MB:-25}
  volumes:
    - ./edge-nginx/default.conf.template:/etc/nginx/templates/default.conf.template:ro
  command: >
    /bin/sh -c "envsubst '$${INTERNAL_PROXY_SECRET} $${MAX_UPLOAD_MB}'
    < /etc/nginx/templates/default.conf.template
    > /etc/nginx/conf.d/default.conf
    && exec nginx -g 'daemon off;'"
  ports:
    - "8080:80"
```

Шаблон: `./edge-nginx/default.conf.template`

```nginx
server {
    listen 80;
    client_max_body_size ${MAX_UPLOAD_MB}m;

    location = /apps/kanban { return 301 /apps/kanban/; }

    location ^~ /apps/kanban/ {
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_pass http://kanban-web:80/;
    }

    location ^~ /apps/kanban/api/ {
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP $remote_addr;

        proxy_set_header X-Portal-UserId $http_x_portal_userid;
        proxy_set_header X-Portal-OrgId  $http_x_portal_orgid;
        proxy_set_header X-Portal-Roles  $http_x_portal_roles;

        proxy_set_header X-Internal-Secret ${INTERNAL_PROXY_SECRET};
        proxy_pass http://kanban-api:8080/api/;
    }
}
```

### 1.2 CORS Конфигурация
`CORS_ORIGINS` из ENV (список через запятую). Если есть `*`, то `allow_credentials=False`.  
Разрешить заголовки: `Content-Type`, `Authorization`, `Accept`, `X-Request-Id`, `X-Portal-UserId`, `X-Portal-OrgId`, `X-Portal-Roles`, `X-Internal-Secret`.

---

## 2) Авторизация, Strict RBAC и Формат Ролей
SSO_MODE=header (`X-Portal-UserId`, `X-Portal-OrgId`, `X-Portal-Roles`).

### 2.1 Парсинг ролей (КРИТИЧНО)
Формат `X-Portal-Roles` — **CSV-строка** (например, `"admin, editor"`).  
Парсить строго так: `roles = [r.strip().lower() for r in roles_str.split(",") if r.strip()]`.

### 2.2 Security и Upsert
Backend проверяет: `X-Internal-Secret == INTERNAL_PROXY_SECRET`. Иначе 403.  
User — read-only cache. UPSERT при каждом запросе (по умолчанию `name="Пользователь {user_id}"`).

### 2.3 Матрица RBAC
- **Admin** (`"admin" in roles`): полный доступ к `org_id`. Разрешено удаление досок.
- **Editor** (в `BoardMember`): создание/редактирование задач, чек-листов, комментов. Запрет на удаление доски/колонок.
- **Viewer** (в `BoardMember`): только чтение GET.

---

## 3) Схема БД (ER-модель, Лимиты, Enums и CASCADE)
Времена: `timestamptz`.

КРИТИЧНО: каскадное удаление (**ON DELETE CASCADE**) должно быть явно прописано на уровне БД для всех дочерних элементов карточки и доски.

- `User (id TEXT PK, name, avatar_url)`
- `Board (id UUID PK, org_id TEXT, title max 200)`
- `BoardMember (board_id UUID FK ON DELETE CASCADE, user_id TEXT FK, role)`  
- `Column (id UUID PK, board_id UUID FK ON DELETE CASCADE, title, header_color, position float)`
  - `header_color`: строго валидировать по Enum Bootstrap: `bg-primary`, `bg-warning`, `bg-success`, `bg-danger`, `bg-info`, `bg-dark`.
- `Card (id UUID PK, column_id UUID FK ON DELETE CASCADE, title, description max 50_000, position float, due_at, priority int, is_archived bool, created_at, updated_at)`
  - `priority`: строгий диапазон: `1` (Low), `2` (Medium), `3` (High).
- `ChecklistItem (id UUID PK, card_id UUID FK ON DELETE CASCADE, title, is_completed bool, position float)`
- `Attachment (id UUID PK, card_id UUID FK ON DELETE CASCADE, filename, storage_key, content_type, size_bytes)`
- `Label (id UUID PK, board_id UUID FK ON DELETE CASCADE, name, color)`  
  - `color` — Bootstrap Enum (как для колонок).
  - Создание меток разрешено прямо из модалки карточки.
- `CardComment (id UUID PK, card_id UUID FK ON DELETE CASCADE, user_id TEXT FK, body max 10_000, created_at)`

Индекс:
```sql
CREATE INDEX idx_comments_card_created ON card_comments (card_id, created_at);
```

---

## 4) API Контракты: Locking, Rate Limiting и Delete Policy
### 4.1 Карточки: Позиции, Move & Опциональный Lock
Создание (POST): при создании новой карточки присваивать `position = max_position_in_column + 1.0` (для первой: `1.0`).

Move (POST `/api/cards/{id}/move`):
- Если передан `prev_updated_at`: UPDATE с проверкой `WHERE id=:id AND updated_at=:prev_updated_at`. Если 0 строк → **409 Conflict**.
- Если `prev_updated_at` НЕТ: стратегия **Last-write-wins**.

Ребаланс: если при вычислении новой позиции `delta < 1e-5`, выполнить ребаланс колонки (`1.0, 2.0, 3.0...`) в той же транзакции.

### 4.2 Rate Limiting (Только WRITE-операции)
MVP: in-memory token bucket.

PROD ключ лимита: `user_id + ip`. 30 req/min.

КРИТИЧНО: лимит применяется ТОЛЬКО к `POST`, `PATCH`, `DELETE` (для комментариев и move). `GET` (поллинг) НЕ лимитируется этим правилом.  
В `ENV=dev` rate limiter отключен.

### 4.3 Политика удаления (Hard Delete досок)
Удаление доски (DELETE `/api/boards/{id}`): разрешено только Admin. Выполняет HARD DELETE каскадно (доска → колонки → карточки → вложения/комментарии). Восстановление невозможно.

Удаление колонки: ЗАПРЕЩЕНО, если в ней есть любые карточки (даже архивные). Возвращать **409 Conflict**.

---

## 5) Uploads: Security & Volume Permissions
### 5.1 Volume Permissions & Non-Root (КРИТИЧНО)
Никаких chmod 777!

В Dockerfile бэкенда создать пользователя:
```dockerfile
RUN addgroup -S appgroup && adduser -S appuser -G appgroup
```

В `backend/entrypoint.sh` до запуска uvicorn:
```bash
mkdir -p /data/uploads
chown -R appuser:appgroup /data/uploads
chmod 755 /data/uploads
# Запуск приложения от имени appuser (через su-exec/gosu)
```

### 5.2 Upload Whitelist & Rejection
Разрешенные расширения: `.pdf`, `.png`, `.jpg`, `.jpeg`, `.webp`, `.docx`, `.xlsx`.

КРИТИЧНО: любые другие файлы СТРОГО отклонять (415 Unsupported Media Type).

Безопасное имя: `safe_name = Path(filename).name`, `ext = os.path.splitext(safe_name)[1].lower()`.

Игнорировать `file.content_type`.  
PDF проверять по `%PDF`.  
DOCX/XLSX проверять как ZIP-архив (проверка сигнатуры `PK\x03\x04` + наличие `[Content_Types].xml` и `word/` или `xl/` внутри архива).  

---

## 6) Фронтенд: UI Drawer, Embed Mode и Polling
### 6.1 UI Карточки (Tabs в Drawer)
Правый Drawer (Offcanvas) использует Tabs (Вкладки):

- **Чат** (активна по умолчанию): список `CardComment` + поле ввода внизу.
- Описание: Markdown карточки, выбор колонки, приоритета.
- Подзадачи: управление `ChecklistItem`.
- Инфо / Лог: метки, дедлайны, вложения.

### 6.2 Polling Комментариев и Оптимистичный UI Rollback
Поллинг каждые 5 сек. Мерджить список по `comment.id`.

Оптимистичный UI: при отправке коммента присваивать временный `temp_${Date.now()}`.

Rollback: если POST вернул 4xx/5xx — удалить `temp_id` из списка и показать Toast с текстом ошибки.

### 6.3 Embed Mode CSS
В embed-режиме предполагается, что портал уже загрузил Bootstrap 5.2.  
Если `VITE_KANBAN_EMBED_MODE === 'true'`, не импортировать глобальный `bootstrap.min.css`.

---

## 7) Инфраструктура и Async Alembic
В `alembic/env.py` обязательно `asyncio.run(...)` и `await conn.run_sync(...)`.

`backend/entrypoint.sh` (chmod +x): `pg_isready -> alembic upgrade head -> python -m app.seed -> exec uvicorn`.

---

## 8) Seed-Данные (Только ENV=dev)
Сидирование (`python -m app.seed`) должно быть идемпотентным и создавать:

- Пользователя: `id="1"`, `name="Агафонов Василий"`.
- Доску `org_id="1"`: "Донснабкомплект — Договоры". Привязать user "1" как Editor в `BoardMember`.
- Колонки (валидный `header_color`): "Новые" (`bg-primary`), "В работе" (`bg-warning`), "Готово" (`bg-success`).
- Карточки: 3 тестовые задачи, приоритеты 1/2/3. У одной задачи — чек-лист из 2 пунктов (1 выполнен). `position`: 1.0, 2.0, 3.0.

---

## 9) Acceptance Criteria
- GET `/apps/kanban` редиректит на `/apps/kanban/` и не отдает 404.
- Удаление доски работает (каскадное удаление), а удаление непустой колонки дает 409.
- Новые карточки добавляются в конец списка (позиция вычисляется сервером корректно).
- Загрузка `.sh`, `.exe` или `.svg` отклоняется сервером (415).
- Ошибка сети при отправке комментария удаляет его из UI и показывает Toast.
- Вкладка "Чат" открывается первой при клике на карточку.
- Поллинг GET-запросов не блокируется Rate Limiter-ом.

**Конец задания**
