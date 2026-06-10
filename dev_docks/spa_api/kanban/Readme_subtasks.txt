================================================================================
SPA API — Kanban: подзадачи карточки (чек-лист)
================================================================================

Контроллер: App\Controller\SpaApi\Kanban\SubtaskController
Базовый путь: /spa/api/cards/{cardId}/subtasks

Авторизация: JWT, ROLE_USER (заголовок Authorization: Bearer <token>).

cardId — числовой идентификатор KanbanCard.

Подзадачи формируют чек-лист задачи (вкладка «Подзадачи» в карточке).
Текущий список подзадач также приходит в GET /spa/api/cards/{id} (поле
subtasks) — отдельный GET ниже нужен в основном для обновления списка.

Поля подзадачи (единый формат во всех ответах):

  id          — int, идентификатор подзадачи
  title       — string, текст подзадачи
  status      — string, "to_do" | "done" (значение KanbanSubtaskStatus)
  isCompleted — bool, true если status = "done"
  position    — float, порядковая позиция (по возрастанию)
  userId      — int|null, идентификатор исполнителя подзадачи
  userName    — string|null, "Фамилия Имя" исполнителя (null, если не назначен)

  Примечание: status и isCompleted — две проекции одного поля. Менять можно
  любым из них (см. PATCH). При status="done" isCompleted=true, иначе false.

Роли (KanbanService::requireRole на доске карточки):

  • Чтение (GET)            — минимум KANBAN_VIEWER
  • Создание (POST)         — минимум KANBAN_EDITOR
  • Изменение (PATCH)       — минимум KANBAN_EDITOR
  • Удаление (DELETE)       — минимум KANBAN_EDITOR


--------------------------------------------------------------------------------
СПИСОК ПОДЗАДАЧ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/cards/{cardId}/subtasks

  Сортировка — по position по возрастанию.
  Права: KANBAN_VIEWER.

curl:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/cards/42/subtasks"

--- Ответ (200) ----------------------------------------------------------------

[
  {
    "id": 5,
    "title": "Подготовить макет",
    "status": "done",
    "isCompleted": true,
    "position": 1,
    "userId": 1,
    "userName": "Иванов Иван"
  },
  {
    "id": 6,
    "title": "Согласовать с заказчиком",
    "status": "to_do",
    "isCompleted": false,
    "position": 2,
    "userId": null,
    "userName": null
  }
]

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "card_not_found" }
  403  AccessDeniedException (нет роли KANBAN_VIEWER на доске)


--------------------------------------------------------------------------------
СОЗДАНИЕ ПОДЗАДАЧИ
--------------------------------------------------------------------------------

  Method : POST
  URL    : /spa/api/cards/{cardId}/subtasks

  Content-Type: application/json

  Body:
  {
    "title": "Текст подзадачи"
  }

  title — обязателен, непустая строка (обрезается trim).

  Новая подзадача создаётся со статусом "to_do" и позицией в конце списка
  (max(position) + 1). Исполнитель не назначается (назначается через PATCH).

  Права: KANBAN_EDITOR.

curl:

  curl -X POST -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"title":"Текст подзадачи"}' \
       "http://localhost:8080/spa/api/cards/42/subtasks"

--- Ответ (201) ----------------------------------------------------------------

{
  "id": 7,
  "title": "Текст подзадачи",
  "status": "to_do",
  "isCompleted": false,
  "position": 3,
  "userId": null,
  "userName": null
}

--- Ошибки ---------------------------------------------------------------------

  400  { "error": "subtask_title_required" }
  404  { "error": "card_not_found" }
  403  AccessDeniedException (нет роли KANBAN_EDITOR на доске)


--------------------------------------------------------------------------------
ИЗМЕНЕНИЕ ПОДЗАДАЧИ
--------------------------------------------------------------------------------

  Method : PATCH
  URL    : /spa/api/cards/{cardId}/subtasks/{id}

  Content-Type: application/json

  Все поля тела опциональны — передаются только изменяемые.

  Body (любая комбинация полей):
  {
    "title": "Новый текст",
    "status": "done",
    "isCompleted": true,
    "user_id": 1
  }

  title       — строка; применяется, только если непустая после trim.
  status      — "to_do" | "done"; иные значения игнорируются.
  isCompleted — bool; true => status="done", false => status="to_do".
                (status и isCompleted — два способа задать одно поле; если
                переданы оба, последним применяется isCompleted.)
  user_id     — int  => назначить исполнителя;
                null => снять исполнителя.

  Назначение исполнителя (user_id != null):
    • Если пользователь ещё не состоит в проекте доски — он автоматически
      добавляется в проект с ролью KANBAN_VIEWER.
    • Побочные эффекты (если назначенный — не сам инициатор запроса):
        - notifyNewKanbanProjectUser — если пользователь был добавлен в проект;
        - notifyKanbanTaskAssigned (isSubtask=true) — уведомление о назначении
          на подзадачу; в тексте: "<подзадача> (задача: <карточка>)".

  Права: KANBAN_EDITOR.

curl (отметить выполненной):

  curl -X PATCH -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"isCompleted":true}' \
       "http://localhost:8080/spa/api/cards/42/subtasks/7"

curl (назначить исполнителя):

  curl -X PATCH -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"user_id":1}' \
       "http://localhost:8080/spa/api/cards/42/subtasks/7"

--- Ответ (200) ----------------------------------------------------------------

{
  "id": 7,
  "title": "Текст подзадачи",
  "status": "done",
  "isCompleted": true,
  "position": 3,
  "userId": 1,
  "userName": "Иванов Иван"
}

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "card_not_found" }
  404  { "error": "subtask_not_found" }   (нет такой подзадачи у карточки)
  404  { "error": "user_not_found" }      (user_id указывает на несуществующего)
  403  AccessDeniedException (нет роли KANBAN_EDITOR на доске)


--------------------------------------------------------------------------------
УДАЛЕНИЕ ПОДЗАДАЧИ
--------------------------------------------------------------------------------

  Method : DELETE
  URL    : /spa/api/cards/{cardId}/subtasks/{id}

  Права: KANBAN_EDITOR.

curl:

  curl -X DELETE -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/cards/42/subtasks/7"

--- Ответ (204) ----------------------------------------------------------------

  Тело пустое (No Content).

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "card_not_found" }
  404  { "error": "subtask_not_found" }
  403  AccessDeniedException (нет роли KANBAN_EDITOR на доске)


--------------------------------------------------------------------------------
ПРИМЕЧАНИЯ
--------------------------------------------------------------------------------

  • Изменение порядка подзадач (reorder) отдельным эндпоинтом пока не
    реализовано: position задаётся только при создании. При необходимости
    drag&drop добавляется отдельный метод.

  • Коды ошибок — машинные строки из App\Controller\SpaApi\SpaApiError
    (subtask_title_required, subtask_not_found, card_not_found,
    user_not_found). Человекочитаемый текст подставляет фронтенд.

  • Аналог в legacy-API (сессионная авторизация, не для SPA):
    /api/kanban/cards/{cardId}/checklist — App\Controller\Kanban\Api\
    KanbanChecklistApiController. Отличие SPA-версии: при user_id с
    несуществующим пользователем возвращается 404 user_not_found (в legacy
    такой user_id молча игнорировался).
