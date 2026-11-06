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

# ХРАНИЛИЩЕ ФАЙЛОВ

Свой бакет MinIO, как у канбана, аватаров, инвентаризации, закупок и ознакомления:

    MINIO_FILE_GROUP_BUCKET=file-groups

Контракт ключа объекта (целевой, как у закупок):

    {id группы}/{32 hex}.{расширение}

Исходное имя файла в ключе не светим. Порядок при загрузке после перехода на
MinIO: **сначала объект, потом строка в БД** — при обратном сбое осталась бы
строка в пустоту; так худшее — осиротевший объект.

## Сейчас (этапы 0–2 + 4; проверено на стенде 2026-11-06)

| Что | Где |
|-----|-----|
| Метаданные | Postgres (`file_group_*`); `stored_name` = ключ объекта (VARCHAR 255) |
| Новые байты | MinIO bucket `file-groups`, ключ `{groupId}/{hex}.{ext}` |
| Старые байты | dual-read: сначала MinIO по ключу, иначе диск `…/file_groups/{groupId}/{storedName}` |
| OnlyOffice save | `putObject` тем же ключом; если файл был только на диске — уезжает в бакет |
| Удаление | `deleteObject` + unlink диска (best-effort) |

Смоук на стенде: открытие группы, загрузка / скачивание / удаление — OK.
Превью через imgproxy ещё нет. CLI-миграции старых файлов ещё нет — dual-read держит
их живыми.

## План перехода на MinIO

Ветка: `feature/file-groups-minio`.

1. **Инфра (готово)** — бакет, env, DI, хелперы ключа.
2. **Storage API (готово, проверено)** — `putObject` / dual-read / `deleteObject`; OnlyOffice `replace`.
3. **Миграция** — CLI: диск → бакет, идемпотентно; потом выключить fallback.
4. **OnlyOffice (готово вместе с п.2)** — callback save → тот же ключ в MinIO.
5. **Превью (опционально)** — imgproxy URL для картинок в detail API.
6. **Чистка** — убрать dual-read и локальные `uploads/file_groups/*`.

Новый сервис и правки стека `file_storage` (контейнеры) не нужны — MinIO/imgproxy
уже в `project-net`. Образец кода: `PurchaseFileStorageService`, `AckFileStorageService`.

Создание бакета:

    docker run --rm --network project-net --entrypoint sh minio/mc:latest \
      -c "mc alias set local http://minio:9000 minioadmin minioadmin \
          && mc mb -p local/file-groups && mc ls local"

(подставить свои логин/пароль из окружения MinIO)

--------------------------------------------------------------------------------

# РАЗВЁРТЫВАНИЕ

1. Миграции таблиц `file_group*`.
2. `MINIO_FILE_GROUP_BUCKET=file-groups` в окружении Symfony (см. `.env.example`).
3. Бакет `file-groups` в MinIO (команда выше).
4. Пока dual-read жив: каталог `PRIVATE_UPLOAD_DIR/file_groups` нужен для старых
   файлов (чтение). Новые загрузки пишут только в MinIO.

Realtime каталога нет: новый файл у соседа — после «Обновить» или перезахода.
Уведомления о новых файлах в колокольчик — отдельная задача (общий
`NotificationPublisher`, как у закупок).

--------------------------------------------------------------------------------

# ЧЕГО В МОДУЛЕ НЕТ

Перечислено, чтобы не искать.

    CLI миграции диск→MinIO     этап 3 ветки feature/file-groups-minio
    Превью imgproxy             этап 5
    Уведомления о новых файлах  отдельный план
    Realtime дерева             нет; кнопка «Обновить» на фронте
    Presigned URL наружу        скачивание только через `/content` с JWT
