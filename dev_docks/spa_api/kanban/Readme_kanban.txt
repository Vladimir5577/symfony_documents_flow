================================================================================
SPA API — Kanban: комментарии карточки (чат задачи)
================================================================================

Контроллер: App\Controller\SpaApi\Kanban\CommentController
Базовый путь: /spa/api/cards/{cardId}/comments

Авторизация: JWT, ROLE_USER (заголовок Authorization: Bearer <token>).

cardId — числовой идентификатор KanbanCard.

Комментарии формируют ленту «Чата» в карточке задачи. Текущий список
комментариев также приходит в GET /spa/api/cards/{id} (поле comments) —
отдельный GET ниже нужен в основном для поллинга новых сообщений.

Поля комментария (единый формат во всех ответах):

  id          — int, идентификатор комментария
  body        — string, текст
  authorName  — string, "Фамилия Имя" автора
  authorId    — int, идентификатор автора
  createdAt   — string|null, ISO-8601 (формат "c")
  updatedAt   — string|null, ISO-8601; равен createdAt, если не редактировался

Роли (KanbanService::requireRole на доске карточки):

  • Чтение (GET)            — минимум KANBAN_VIEWER
  • Создание (POST)         — минимум KANBAN_EDITOR
  • Изменение/удаление      — минимум KANBAN_EDITOR И только автор комментария


--------------------------------------------------------------------------------
СПИСОК КОММЕНТАРИЕВ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/cards/{cardId}/comments

  Сортировка — по createdAt по возрастанию (старые сверху).
  Права: KANBAN_VIEWER.

curl:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/cards/42/comments"

--- Ответ (200) ----------------------------------------------------------------

[
  {
    "id": 7,
    "body": "Первый комментарий",
    "authorName": "Иванов Иван",
    "authorId": 1,
    "createdAt": "2026-06-04T10:00:00+00:00",
    "updatedAt": "2026-06-04T10:00:00+00:00"
  }
]

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "card_not_found" }
  403  AccessDeniedException (нет роли KANBAN_VIEWER на доске)


--------------------------------------------------------------------------------
СОЗДАНИЕ КОММЕНТАРИЯ
--------------------------------------------------------------------------------

  Method : POST
  URL    : /spa/api/cards/{cardId}/comments

  Content-Type: application/json

  Body:
  {
    "body": "Текст сообщения"
  }

  body — обязателен, непустая строка (обрезается trim).

  Права: KANBAN_EDITOR.

  Побочный эффект: уведомление notifyTaskCommentAdded получателям —
  админам проекта, исполнителям карточки и исполнителям её подзадач,
  кроме самого автора комментария.

curl:

  curl -X POST -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"body":"Текст сообщения"}' \
       "http://localhost:8080/spa/api/cards/42/comments"

--- Ответ (201) ----------------------------------------------------------------

{
  "id": 8,
  "body": "Текст сообщения",
  "authorName": "Иванов Иван",
  "authorId": 1,
  "createdAt": "2026-06-04T11:00:00+00:00",
  "updatedAt": "2026-06-04T11:00:00+00:00"
}

--- Ошибки ---------------------------------------------------------------------

  400  { "error": "comment_body_required" }
  404  { "error": "card_not_found" }
  403  AccessDeniedException (нет роли KANBAN_EDITOR на доске)


--------------------------------------------------------------------------------
РЕДАКТИРОВАНИЕ КОММЕНТАРИЯ
--------------------------------------------------------------------------------

  Method : PUT
  URL    : /spa/api/cards/{cardId}/comments/{commentId}

  Content-Type: application/json

  Body:
  {
    "body": "Новый текст"
  }

  body — обязателен, непустая строка (обрезается trim).

  Права: KANBAN_EDITOR И только автор комментария.

curl:

  curl -X PUT -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"body":"Новый текст"}' \
       "http://localhost:8080/spa/api/cards/42/comments/8"

--- Ответ (200) ----------------------------------------------------------------

{
  "id": 8,
  "body": "Новый текст",
  "authorName": "Иванов Иван",
  "authorId": 1,
  "createdAt": "2026-06-04T11:00:00+00:00",
  "updatedAt": "2026-06-04T11:30:00+00:00"
}

--- Ошибки ---------------------------------------------------------------------

  400  { "error": "comment_body_required" }
  403  { "error": "comment_author_only" }   (не автор комментария)
  404  { "error": "card_not_found" }
  404  { "error": "comment_not_found" }      (нет такого комментария у карточки)
  403  AccessDeniedException (нет роли KANBAN_EDITOR на доске)


--------------------------------------------------------------------------------
УДАЛЕНИЕ КОММЕНТАРИЯ
--------------------------------------------------------------------------------

  Method : DELETE
  URL    : /spa/api/cards/{cardId}/comments/{commentId}

  Права: KANBAN_EDITOR И только автор комментария.

curl:

  curl -X DELETE -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/cards/42/comments/8"

--- Ответ (204) ----------------------------------------------------------------

  Тело пустое (No Content).

--- Ошибки ---------------------------------------------------------------------

  403  { "error": "comment_author_only" }   (не автор комментария)
  404  { "error": "card_not_found" }
  404  { "error": "comment_not_found" }
  403  AccessDeniedException (нет роли KANBAN_EDITOR на доске)


--------------------------------------------------------------------------------
ПРИМЕЧАНИЯ
--------------------------------------------------------------------------------

  • Вложения чата идут отдельным эндпоинтом attachments с context="chat":
    POST /spa/api/cards/{cardId}/attachments (поле context в multipart).
    Лента чата на фронте объединяет comments и attachments(context="chat")
    и сортирует по createdAt. См. AttachmentController.

  • Коды ошибок — машинные строки из App\Controller\SpaApi\SpaApiError
    (comment_body_required, comment_not_found, comment_author_only,
    card_not_found). Человекочитаемый текст подставляет фронтенд.

  • Аналог в legacy-API (сессионная авторизация, не для SPA):
    /api/kanban/cards/{cardId}/comments — App\Controller\Kanban\Api\
    KanbanCommentApiController.
