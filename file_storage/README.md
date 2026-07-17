# file_storage — файловое хранилище проекта

Связанный стек: **MinIO** (S3-хранилище) + **imgproxy** (ресайз картинок на лету)
+ **nginx-кэш** перед imgproxy.

> Папка спроектирована **автономной** — рассчитана на переезд в отдельный
> репозиторий (вместе с Go-сервисом канбана или как общий infra-репозиторий).
> Поэтому у неё свои `.env`, `.gitignore`, `README.md` и `docker-compose.yml`.

---

## Состав

| Сервис | Образ | Роль | Доступ |
|---|---|---|---|
| `minio` | minio/minio:RELEASE.2025-09-07T16-13-09Z | S3-хранилище файлов | API `:9000` внутр., Console `:9001` localhost |
| `imgproxy` | darthsim/imgproxy:v4.0.8 | ресайз картинок, источник — MinIO (S3) | `:8080` внутренний |
| `imgproxy-cache` | nginx (build, 1.27.0-alpine-slim) | кэш перед imgproxy | `:8082` публичный |

Поток запросов:
```
браузер ──:8082──> nginx (кэш) ──> imgproxy ──> MinIO
браузер ────────>  Go/Symfony ───────────────> MinIO   (скачивание файлов)
```

### Зачем nginx-кэш
imgproxy ресайзит **на лету** и сам ничего не хранит. Без кэша каждый показ
картинки = новый ресайз. nginx хранит готовые превью на диске и отдаёт повторно
(заменяет дисковый кэш LiipImagine).

---

## Что нужно для старта (по шагам)

### 1. Переменные окружения
```sh
cp .env.example .env
```
Заполнить в `.env` минимум `MINIO_ROOT_PASSWORD`. Остальное имеет дефолты.

### 2. Общая сеть
Все сервисы — в общей **external**-сети (по умолчанию `project-net`, имя в
переменной `SHARED_NETWORK`). Сеть создаётся СНАРУЖИ:

- **в материнском проекте** — её создаёт основной `docker-compose.yml`
  (поднимать ядро ДО этого стека либо вместе через `-f`);
- **в отдельном репозитории** (после переезда) — создать вручную один раз:
  ```sh
  docker network create project-net
  ```

### 3. Запуск
**Автономно (из этой папки):**
```sh
docker compose up -d
```
**В материнском проекте (из корня, вместе с ядром):**
```sh
docker compose -f docker-compose.yml -f file_storage/docker-compose.yml up -d
```

### 4. Создать bucket (ОБЯЗАТЕЛЬНЫЙ разовый шаг)
MinIO стартует пустым. Bucket для канбан-вложений нужно создать один раз —
он сохранится в `minio_data` и переживёт перезапуски.

**Вариант А — через веб-консоль:**
1. Открыть http://localhost:9001
2. Войти (`MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` из `.env`)
3. Buckets → Create Bucket → имя из `MINIO_KANBAN_BUCKET` (по умолчанию `kanban`)

**Вариант Б — одной командой через `mc` в контейнере:**
```sh
docker run --rm --network project-net \
  minio/mc:RELEASE.2025-09-07T16-13-09Z sh -c \
  "mc alias set local http://minio:9000 minioadmin minioadmin && \
   mc mb -p local/kanban"
```
(подставить свои логин/пароль/имя bucket из `.env`)

> Bucket создаётся вручную намеренно — авто-init-контейнер убран, чтобы не
> плодить разовый `Exited`-контейнер при каждом `up`.

### 5. Проверка
```sh
docker compose ps                       # сервисы Up
curl -I http://localhost:8082/healthz   # nginx-кэш отвечает
```

---

## Данные (volumes)

Лежат на два уровня выше (`./../../`), рядом с корнем материнского проекта:
- `minio_data/` — файлы MinIO
- `imgproxy_cache/` — кэш превью nginx

> **При переезде** в отдельный репозиторий (папка станет корнем) эти пути надо
> поправить: `./../../` → `./`.

---

## Прод-чеклист

- [ ] Задать `MINIO_ROOT_PASSWORD` (не дефолтный `minioadmin`).
- [ ] Включить подпись imgproxy: задать `IMGPROXY_KEY` / `IMGPROXY_SALT`
      (`openssl rand -hex 32` на каждый), убрать `IMGPROXY_ALLOW_UNSAFE_URL=1`.
- [ ] Подпись imgproxy-URL генерит тот, кто отдаёт `<img src>` (Go / Symfony).
- [ ] Ограничить доступ к MinIO Console (`:9001`).
- [ ] MinIO S3 API (`:9000`) наружу не публиковать — файлы отдаёт Go проксированием.

---

## Переменные окружения

См. `.env.example` — там все ключи с комментариями. Кратко:

| Переменная | Назначение | Дефолт |
|---|---|---|
| `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` | креды MinIO | minioadmin |
| `MINIO_KANBAN_BUCKET` | имя bucket для вложений | kanban |
| `MINIO_CONSOLE_PORT` | порт веб-консоли (localhost) | 9001 |
| `IMGPROXY_KEY` / `IMGPROXY_SALT` | подпись URL (прод) | пусто |
| `IMGPROXY_ALLOW_UNSAFE_URL` | 1 = без подписи (только локалка) | 1 |
| `IMGPROXY_CACHE_PORT` | публичный порт nginx-кэша | 8082 |
| `SHARED_NETWORK` | имя общей docker-сети | project-net |
