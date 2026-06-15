================================================================================
SPA API — Посты (posts)
================================================================================

Эндпоинты для SPA: лента постов с фильтром по типу, карточка поста, создание
поста (обложка + файлы), комментарии, отметка «ознакомлен», скачивание файлов.

Контроллеры: App\Controller\SpaApi\Post\*
  PostController

Сервисы (бизнес-логика и JSON): App\Service\SpaApi\Post\*
  PostResponseFormatter        — сериализация Entity → JSON (camelCase)
  PostCreateService            — POST /posts (валидация + создание + обложка + файлы)
  PostImagePreviewUrlGenerator — URL превью 400×400 для изображений (LiipImagine)

Сущности и репозитории (общие, не SpaApi): App\Entity\Post\*, App\Repository\Post\*
  Post, File, PostUserComment, PostUserStatus
  PostRepository, FileRepository, PostUserCommentRepository, PostUserStatusRepository

Базовый путь: /spa/api/posts

Авторизация:
  JWT в заголовке Authorization (как у всех /spa/api/*; firewall spa_api,
  stateless, jwt). На уровне access_control весь ^/spa/api закрыт ROLE_USER,
  поэтому аноним до контроллера не доходит (401).
  POST /posts дополнительно требует ROLE_MANAGER (#[IsGranted]).
  См. dev_docks/spa_api/user/Readme_spa_auth.txt.

Веб-аналог (server-side, не SpaApi): App\Controller\Post\PostController
  Тот же функционал на Twig/CSRF/flash. SpaApi-контур добавлен параллельно,
  веб-контур не затронут.


--------------------------------------------------------------------------------
ОБЩИЕ СОГЛАШЕНИЯ
--------------------------------------------------------------------------------

Пагинация (как documents/organizations/users):

  Query: page (>=1, по умолчанию 1), page_size (1..100, по умолчанию 10).

  Ответ pagination:
  {
    "current_page":   number,
    "total_pages":    number,
    "total_items":    number,
    "items_per_page": number
  }

Ошибки:

  Тело: { "error": "<код>" }
  Коды — константы App\Controller\SpaApi\SpaApiError.

  Посты:
    post_not_found
    post_title_required
    post_type_required
    post_invalid_type
    post_cover_invalid_image
    post_cover_too_large
    post_file_too_large
    post_file_upload_error
    post_comment_empty
    post_file_not_found
    post_file_not_found_on_disk
    access_denied
    invalid_json

Типы постов (enum App\Enum\Post\PostType):

  order        — Приказ
  news         — Новость
  instruction  — Инструкция
  regulation   — Положение
  disposition  — Распоряжение

Статус пользователя по посту (enum App\Enum\Post\PostUserStatusType):

  1 — Ознакомлен        (ACKNOWLEDGED)
  2 — Отклонено         (REJECTED)
  3 — Ознакомлюсь позже (LATER)

  В SpaApi сейчас выставляется только ACKNOWLEDGED (см. п.6).


--------------------------------------------------------------------------------
ОБЪЕКТЫ
--------------------------------------------------------------------------------

PostListItem:

  {
    "id": number,
    "title": string|null,
    "type": { "value": string, "label": string } | null,
    "content": string|null,
    "author": { "id": number, "name": string } | null,   // name = ФИО или логин
    "isActive": boolean,
    "isRequiredAcknowledgment": boolean,
    "coverImageUrl": string|null,        // оригинал: /uploads/posts/{id}/{name} или null
    "coverThumbnailUrl": string|null,    // превью 400×400 (LiipImagine) или null
    "createdAt": string|null,            // ISO 8601 ATOM
    "commentCount": number,
    "userStatus": { "value": number, "label": string } | null
  }

PostDetail = PostListItem + поле files:

  {
    ...PostListItem,
    "files": [ ...PostFile ]
  }

PostFile:

  {
    "id": number,
    "title": string|null,
    "extension": string|null,            // из имени файла на диске
    "downloadUrl": string|null,          // /spa/api/posts/files/{fileId}/download
    "previewUrl": string|null            // превью 400×400 только для картинок, иначе null
  }

PostComment:

  {
    "id": number,
    "content": string|null,
    "author": { "id": number, "name": string } | null,
    "createdAt": string|null,            // ISO 8601 ATOM
    "updatedAt": string|null
  }


--------------------------------------------------------------------------------
1. ЛЕНТА ПОСТОВ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/posts

Query:

  page, page_size
  type   — значение enum PostType (order|news|instruction|regulation|disposition)

Выборка: только активные неудалённые посты (isActive=true, deletedAt=null),
сортировка по createdAt DESC (PostRepository::findActivePaginated).
commentCount и userStatus подгружаются батчем по id постов страницы.

Ответ 200:

  {
    "items": [ ...PostListItem ],
    "pagination": { ... },
    "filters": {
      "typeChoices": [ { "value": string, "label": string } ]
    }
  }


--------------------------------------------------------------------------------
2. КАРТОЧКА ПОСТА
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/posts/{id}

  {id} — id поста.

Ответ 200: { ...PostDetail }   // включает files и userStatus текущего пользователя
Ответ 404: { "error": "post_not_found" }   // нет или soft-deleted


--------------------------------------------------------------------------------
3. СОЗДАНИЕ ПОСТА
--------------------------------------------------------------------------------

  Method : POST
  URL    : /spa/api/posts
  Content-Type: multipart/form-data
  Требует: ROLE_MANAGER

Поля формы:

  title                       string   — обязательно
  type                        string   — обязательно, значение PostType
  content                     string   — опционально
  is_active                   boolean  — чекбокс (1/true = активен)
  is_required_acknowledgment  boolean  — чекбокс (требуется ознакомление)
  cover_image                 file     — обложка, опционально
  files[]                     file[]   — вложения, опционально

Валидация (PostCreateService):

  • title пустой                          → post_title_required
  • type пустой                           → post_type_required
  • type не из enum                       → post_invalid_type
  • обложка не jpeg/png/webp              → post_cover_invalid_image
  • обложка > 5 МБ                         → post_cover_too_large
  • любой файл > 5 МБ                      → post_file_too_large
  • ошибка загрузки (UPLOAD_ERR != OK)    → post_file_upload_error
  • ошибка валидации сущности Post        → текст сообщения валидатора

  Сохранение транзакционно: сначала flush для получения id поста (нужен Vich
  directory namer), затем привязка обложки и файлов, затем общий commit.

Ответ 201: { ...PostDetail }
Ответ 400: { "error": "<код>" }
Ответ 403: ROLE_MANAGER не выполнен


--------------------------------------------------------------------------------
4. СПИСОК КОММЕНТАРИЕВ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/posts/{id}/comments

Query:

  offset — >=0, по умолчанию 0
  limit  — 1..100, по умолчанию 5

Сортировка: createdAt ASC (PostUserCommentRepository::findByPostPaginated).

Ответ 200:

  {
    "items": [ ...PostComment ],
    "hasMore": boolean,
    "nextOffset": number,
    "total": number
  }

Ответ 404: { "error": "post_not_found" }


--------------------------------------------------------------------------------
5. ДОБАВЛЕНИЕ КОММЕНТАРИЯ
--------------------------------------------------------------------------------

  Method : POST
  URL    : /spa/api/posts/{id}/comments
  Content-Type: application/json

Body:

  { "content": string }   — обязательно, непустой

Ответ 201: { ...PostComment }
Ответ 400: { "error": "post_comment_empty" } | { "error": "invalid_json" }
Ответ 404: { "error": "post_not_found" }


--------------------------------------------------------------------------------
6. ОТМЕТКА «ОЗНАКОМЛЕН»
--------------------------------------------------------------------------------

  Method : POST
  URL    : /spa/api/posts/{id}/acknowledge

  Тело не требуется. Создаёт или обновляет PostUserStatus текущего
  пользователя по посту: status=ACKNOWLEDGED, viewedAt=now.
  Уникальность пары (post, user) гарантирована на уровне БД.

Ответ 200:

  {
    "success": true,
    "userStatus": { "value": 1, "label": "Ознакомлен" }
  }

Ответ 404: { "error": "post_not_found" }


--------------------------------------------------------------------------------
7. СКАЧИВАНИЕ ФАЙЛА ПОСТА
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/posts/files/{fileId}/download

  {fileId} — id сущности File (не id поста).

Query:

  inline — boolean; true = Content-Disposition: inline (просмотр),
           иначе attachment (скачивание). По умолчанию attachment.

  Имя файла в ответе: {file.title}.{расширение} (или имя файла на диске).
  Файлы лежат в приватной директории (param private_upload_dir_posts) и
  отдаются только через этот эндпоинт (в отличие от обложки, см. ниже).

Ответ 200: бинарный поток (BinaryFileResponse)
Ответ 404: { "error": "post_file_not_found" }          // нет записи File
           { "error": "post_file_not_found_on_disk" }   // нет файла на диске


--------------------------------------------------------------------------------
ИЗОБРАЖЕНИЯ И ПРЕВЬЮ (ресайз)
--------------------------------------------------------------------------------

  Превью генерируются через LiipImagine, filter_set "post_image_preview"
  (thumbnail 400×400, mode inbound — вписывает с сохранением пропорций, quality 85;
  1:1 как kanban_attachment_preview). Loader post_uploads читает приватную
  директорию %private_upload_dir_posts%; кэш складывается в public/media/cache
  и отдаётся статикой по относительному URL (same-origin, без CORS).

  Обложка поста:
    coverImageUrl       — оригинал, прямой путь /uploads/posts/{id}/{name}
                          (для детального вида).
    coverThumbnailUrl   — превью 400×400 (для ленты/карточек).
    Фронт сам выбирает: список → thumbnail, карточка → оригинал.

  Вложения поста (PostFile.previewUrl):
    Вложения смешанные — и картинки, и документы (pdf/docx/xlsx/…).
    previewUrl заполняется ТОЛЬКО для картинок; «картинка» определяется по
    расширению имени файла (jpg|jpeg|png|webp), т.к. у сущности File нет поля
    contentType. Для не-картинок previewUrl = null — показывайте иконку типа и
    кнопку «скачать» (downloadUrl). Сам файл всегда доступен через downloadUrl
    в оригинале (см. п.7).

  Превью генерируется лениво при первом запросе URL (twig mode: lazy) и далее
  отдаётся из кэша.


--------------------------------------------------------------------------------
НЕ РЕАЛИЗОВАНО В SpaApi
--------------------------------------------------------------------------------

  • Обновление и удаление поста (PUT/DELETE).
  • Статусы REJECTED / LATER (доступен только ACKNOWLEDGED).
  • Уведомления о новых постах/комментариях.


--------------------------------------------------------------------------------
ПРИМЕРЫ
--------------------------------------------------------------------------------

curl — лента (фильтр по типу):

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/posts?page=1&page_size=10&type=news"

curl — карточка:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/posts/42"

curl — создать пост с обложкой и файлами (ROLE_MANAGER):

  curl -X POST -H "Authorization: Bearer <token>" \
       -F "title=Новый приказ" \
       -F "type=order" \
       -F "content=Текст поста" \
       -F "is_active=1" \
       -F "is_required_acknowledgment=1" \
       -F "cover_image=@cover.png" \
       -F "files[]=@doc1.pdf" \
       -F "files[]=@doc2.pdf" \
       "http://localhost:8080/spa/api/posts"

curl — добавить комментарий:

  curl -X POST -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"content":"Ознакомился"}' \
       "http://localhost:8080/spa/api/posts/42/comments"

curl — отметка «ознакомлен»:

  curl -X POST -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/posts/42/acknowledge"

curl — скачать файл:

  curl -L -H "Authorization: Bearer <token>" -OJ \
       "http://localhost:8080/spa/api/posts/files/7/download"

fetch — лента (через Next proxy):

  const q = new URLSearchParams({ page: "1", page_size: "10", type: "news" });
  const res = await fetch(`/spa/api/posts?${q}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
