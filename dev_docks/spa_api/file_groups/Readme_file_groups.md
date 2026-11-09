# Общие файлы (file groups)

Базовый путь: `/spa/api/file-groups`

Группы файлов с участниками, деревом папок и office-редактированием в OnlyOffice.
Фронт: `analytics_platform` → `src/features/FileGroups/`, маршруты `/file-groups`.

--------------------------------------------------------------------------------

# РОЛИ

| Кто | Может |
|-----|--------|
| Владелец | всё: участники, папки, удаление, writers, загрузка |
| Участник | читать / скачивать |
| Uploader (участник с флагом) | ещё и загружать |
| Автор файла / writer | править office-файл в OnlyOffice |

Владелец включает участнику `canUpload`. У файла, загруженного участником, автор
сам становится writer. Владелец может раздать права записи на office-файл другим
участникам (`PUT .../files/{id}/writers`).

--------------------------------------------------------------------------------

# API (кратко)

| Метод | Путь | Кто |
|-------|------|-----|
| GET/POST | `/spa/api/file-groups` | список / создать группу |
| GET/PATCH/DELETE | `/spa/api/file-groups/{id}` | детали / переименовать / удалить |
| POST/DELETE/PATCH | `.../members`, `.../members/{userId}` | участники и флаг загрузки |
| POST/PATCH/DELETE | `.../folders`, `.../folders/{id}` | папки |
| POST/DELETE/PATCH | `.../files`, `.../files/{id}`, `.../files/{id}/folder` | файлы |
| GET | `.../files/{id}/content` | скачивание / inline для картинок (JWT) |
| PUT | `.../files/{id}/writers` | права записи на office-файл |
| * | `/spa/api/file-groups/.../editor*` | сессия OnlyOffice |

Лимит файла: **10 МБ** (`FileGroupStorage::MAX_BYTES`).

--------------------------------------------------------------------------------

# REALTIME

Топик Mercure: `/file-groups/{id}`.

| Событие | Когда | Что делает фронт |
|---------|--------|------------------|
| `catalog_changed` | папки/файлы/участники/writers | refetch detail (кнопка «Обновить» тоже) |
| `group_deleted` | удаление группы | уводит со страницы группы |

Колокольчик: только `file_uploaded` → участники + владелец (актор исключён), ссылка `/file-groups/{id}`.

Пустой `NEXT_PUBLIC_MERCURE_URL` на фронте выключает live-каталог (как у канбана).

--------------------------------------------------------------------------------

# ХРАНИЛИЩЕ ФАЙЛОВ

Свой бакет MinIO, как у канбана, аватаров, инвентаризации, закупок и ознакомления:

    MINIO_FILE_GROUP_BUCKET=file-groups

Контракт ключа объекта (новые загрузки):

    {id группы}/{32 hex}.{расширение}

Исходное имя файла в ключе не светим. Порядок при загрузке: **сначала объект,
потом строка в БД**.

| Что | Где |
|-----|-----|
| Метаданные | Postgres (`file_group_*`); `stored_name` = ключ объекта (VARCHAR 255) |
| Байты | MinIO bucket `file-groups` |
| OnlyOffice save | `putObject` тем же ключом |
| Превью картинок | `previewUrl` в detail → `/media/preview/…/s3://file-groups/{key}` |
| Удаление | `deleteObject` (best-effort) |

Старые файлы с диска: один раз `php bin/console app:file-groups:migrate-to-minio`
(`--dry-run`, потом без флагов, потом `--delete-disk`). CLI остаётся для других
стендов; runtime dual-read выключен.

Создание бакета:

    docker run --rm --network project-net --entrypoint sh minio/mc:latest \
      -c "mc alias set local http://minio:9000 minioadmin minioadmin \
          && mc mb -p local/file-groups && mc ls local"

(подставить свои логин/пароль из окружения MinIO)

--------------------------------------------------------------------------------

# РАЗВЁРТЫВАНИЕ

1. Миграции таблиц `file_group*` + `Version20261106120000` (длина `stored_name`).
2. `MINIO_FILE_GROUP_BUCKET=file-groups` в окружении Symfony (см. `.env.example`).
3. Бакет `file-groups` в MinIO (команда выше).
4. На стендах со старым диском: `app:file-groups:migrate-to-minio`, затем
   `--delete-disk`.

Realtime каталога нет: новый файл у соседа — после «Обновить» или перезахода.
Уведомления о новых файлах в колокольчик — отдельная задача (общий
`NotificationPublisher`, как у закупок).
