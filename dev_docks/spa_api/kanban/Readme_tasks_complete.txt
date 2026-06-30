================================================================================
SPA API — Kanban: отметка «Задача выполнена»
================================================================================

Контроллер: App\Controller\SpaApi\Kanban\CardController
Базовый путь: /spa/api/cards/{id}/complete

Авторизация: JWT, ROLE_USER (заголовок Authorization: Bearer <token>).

id — числовой идентификатор KanbanCard.

Карточка может быть отмечена как выполненная. Статус определяется наличием
поля completed_at: если NOT NULL — задача выполнена, если NULL — не выполнена.
Отдельного булевого поля isCompleted нет.

Поля в БД (таблица kanban_card):

  completed_at     — DATETIME_IMMUTABLE, nullable. Когда задача выполнена.
  completed_by_id  — INT (FK → user.id), nullable, ON DELETE SET NULL. Кто выполнил.


--------------------------------------------------------------------------------
TOGGLE ВЫПОЛНЕНИЯ
--------------------------------------------------------------------------------

  Method : PATCH
  URL    : /spa/api/cards/{id}/complete

  Content-Type: не требуется (тело пустое).

  Логика: toggle — если задача не выполнена → ставит completed_at (текущее
  время) и completed_by (текущий пользователь). Если уже выполнена — обнуляет
  оба поля.

  Права: KANBAN_EDITOR.

  Побочные эффекты:
    • Запись в историю активности (type = «completed» или «reopened»).
    • Realtime (Mercure): публикуется card_updated с patch { completedAt }.

curl:

  curl -X PATCH -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/cards/42/complete"

--- Ответ (200) — задача отмечена выполненной -----------------------------------

{
  "id": 42,
  "completedAt": "2026-06-30T13:30:00+03:00",
  "completedBy": {
    "id": 5,
    "firstname": "Иван",
    "lastname": "Иванов"
  }
}

--- Ответ (200) — отметка снята ------------------------------------------------

{
  "id": 42,
  "completedAt": null,
  "completedBy": null
}

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "card_not_found" }
  403  AccessDeniedException (нет роли KANBAN_EDITOR на доске)


--------------------------------------------------------------------------------
ПОЛЯ В ОТВЕТАХ ДРУГИХ ENDPOINT'ОВ
--------------------------------------------------------------------------------

1) GET /spa/api/cards/{id} — детали карточки

   В корне ответа добавлены два поля:

     "completedAt":  "2026-06-30T13:30:00+03:00",   // string|null, ISO-8601
     "completedBy":  {                                // object|null
       "id": 5,
       "firstname": "Иван",
       "lastname": "Иванов"
     }

2) GET /spa/api/projects/{id}/boards/{boardId} — доска (список карточек)

   В каждой карточке внутри колонки:

     "completedAt":  "2026-06-30T13:30:00+03:00"     // string|null, ISO-8601

   completedBy на доске не передаётся — он нужен только в деталях карточки.

3) POST /spa/api/cards — создание карточки

   В realtime-событии card_created передаётся completedAt: null.


--------------------------------------------------------------------------------
REALTIME (MERCURE)
--------------------------------------------------------------------------------

При toggle выполнения публикуется событие card_updated с patch:

  { "id": 42, "completedAt": "2026-06-30T13:30:00+03:00" }

При снятии отметки:

  { "id": 42, "completedAt": null }


--------------------------------------------------------------------------------
ИСТОРИЯ АКТИВНОСТИ
--------------------------------------------------------------------------------

При toggle создаётся запись в kanban_card_activity:

  Задача выполнена      type = "completed"    old_value = null   new_value = null
  Задача снова открыта  type = "reopened"     old_value = null   new_value = null

Иконки:
  completed  → bi-check-circle
  reopened   → bi-arrow-counterclockwise

Метки:
  completed  → «Задача выполнена»
  reopened   → «Задача снова открыта»


--------------------------------------------------------------------------------
ИСПОЛЬЗОВАНИЕ НА ФРОНТЕ
--------------------------------------------------------------------------------

  // Проверка статуса
  const isCompleted = card.completedAt !== null;

  // Toggle
  const res = await fetch(`/spa/api/cards/${cardId}/complete`, {
    method: 'PATCH',
    headers: { Authorization: `Bearer ${token}` },
  });
  const data = await res.json();


--------------------------------------------------------------------------------
ПРИМЕЧАНИЯ
--------------------------------------------------------------------------------

  • Коды ошибок — машинные строки из App\Controller\SpaApi\SpaApiError
    (card_not_found). Человекочитаемый текст подставляет фронтенд.

  • Паттерн аналогичен архивации (PATCH /spa/api/cards/{id}/archive),
    но archive использует булево поле isArchived, а complete определяет
    статус по наличию completedAt.
