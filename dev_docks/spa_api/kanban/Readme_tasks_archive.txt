================================================================================
SPA API — Kanban: архив задач (карточек)
================================================================================

Контроллеры:
  • App\Controller\SpaApi\Kanban\BoardController — список архива доски
  • App\Controller\SpaApi\Kanban\CardController  — архивация/восстановление

Авторизация: JWT, ROLE_USER (заголовок Authorization: Bearer <token>).

Архивация — это «мягкое» скрытие карточки с доски: карточка не удаляется,
а помечается флагом is_archived = true. Архивные карточки не попадают в
GET /spa/api/projects/{id}/boards/{boardId} (формирование колонок их
пропускает), но остаются доступны через эндпоинт списка архива ниже и могут
быть восстановлены обратно на доску.

При архивации/восстановлении в БД фиксируется:

  is_archived   — bool, текущее состояние (true = в архиве)
  archived_at   — дата/время последней архивации (DATETIME_IMMUTABLE)
  archived_by   — пользователь, выполнивший архивацию (User, nullable)

Каждая операция пишется в журнал активности карточки
(KanbanCardActivityLogger::logArchived):
  • архивация    — тип "archived"
  • восстановление — тип "restored"
(см. Readme_log_activities.txt).

Роли (KanbanService::requireRole на доске карточки):

  • Просмотр архива (GET)               — минимум KANBAN_VIEWER
  • Архивация / восстановление (PATCH)  — минимум KANBAN_ADMIN

  Внимание: смотреть архив может любой участник доски (VIEWER), а
  архивировать и восстанавливать карточки — только администратор доски
  (KANBAN_ADMIN).


--------------------------------------------------------------------------------
СПИСОК АРХИВНЫХ КАРТОЧЕК ДОСКИ (с фильтрами и пагинацией)
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/projects/{id}/boards/{boardId}/archive

  id       — идентификатор KanbanProject (проекта)
  boardId  — идентификатор KanbanBoard (доски) внутри этого проекта

  Возвращает архивные карточки доски постранично, отсортированные по дате
  архивации (archived_at) по убыванию, затем по id по убыванию.
  Права: KANBAN_VIEWER.

  Эндпоинт полностью повторяет фильтрацию и пагинацию SSR-страницы архива
  (app_kanban_board_archive): оба используют один метод репозитория
  KanbanCardRepository::findArchivedByBoardPaginated.

  Query-параметры (все опциональны):

    page         — int, номер страницы (по умолчанию 1, минимум 1)
    title        — поиск по названию карточки, частичное совпадение (LIKE %...%)
    description  — поиск по описанию карточки, частичное совпадение (LIKE %...%)
    dateFrom     — нижняя граница archived_at, формат YYYY-MM-DD (включительно,
                   с 00:00:00 этого дня)
    dateTo       — верхняя граница archived_at, формат YYYY-MM-DD (включительно,
                   до 23:59:59 этого дня)

    Размер страницы фиксирован: 10 карточек.
    Пустые параметры фильтра игнорируются. Некорректные даты (не парсятся как
    YYYY-MM-DD) игнорируются — соответствующая граница не применяется.

  Поля карточки в ответе:

    id           — int, идентификатор карточки
    title        — string, название
    description  — string|null, описание
    columnTitle  — string, название колонки, в которой карточка была/находится
    borderColor  — string|null, цвет левой границы карточки ("primary",
                   "success", "warning", "danger", "info", "dark" или null)
    archivedAt   — string|null, дата/время архивации (ISO 8601 / ATOM)
    archivedBy   — object|null, кто архивировал:
                     { "id": int, "name": "Фамилия Имя", "avatarUrl": string|null }
                   null, если пользователь был удалён (archived_by = NULL).

  Поля пагинации (pagination):

    currentPage  — int, текущая страница
    totalPages   — int, всего страниц
    total        — int, всего архивных карточек, попавших под фильтр
    limit        — int, размер страницы (всегда 10)

  archivedCount  — int, общее число архивных карточек доски БЕЗ учёта фильтров
                   (для бейджа-счётчика на кнопке «Архив»).

curl:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/projects/3/boards/7/archive?page=1&title=договор&dateFrom=2026-06-01&dateTo=2026-06-19"

--- Ответ (200) ----------------------------------------------------------------

{
  "cards": [
    {
      "id": 42,
      "title": "Договор аренды",
      "description": "Согласовать условия с юристом",
      "columnTitle": "В работе",
      "borderColor": "primary",
      "archivedAt": "2026-06-10T14:30:00+00:00",
      "archivedBy": {
        "id": 5,
        "name": "Иванов Иван",
        "avatarUrl": "/media/cache/avatar_thumbnail/users/5.png"
      }
    }
  ],
  "pagination": {
    "currentPage": 1,
    "totalPages": 3,
    "total": 25,
    "limit": 10
  },
  "archivedCount": 25
}

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "project_not_found" }
  404  { "error": "board_not_found" }   (доска не найдена либо не принадлежит
                                         указанному проекту)
  403  AccessDeniedException (нет роли KANBAN_VIEWER на доске)


--------------------------------------------------------------------------------
АРХИВИРОВАТЬ / ВОССТАНОВИТЬ КАРТОЧКУ (переключатель)
--------------------------------------------------------------------------------

  Method : PATCH
  URL    : /spa/api/cards/{id}/archive

  id — идентификатор KanbanCard.

  Один эндпоинт-переключатель (toggle) на оба действия — отдельного метода
  для восстановления нет:
    • активная карточка  -> архивируется   (isArchived: true)
    • архивная карточка  -> восстанавливается (isArchived: false)

  Тело запроса не требуется. Новое состояние вычисляется как инверсия
  текущего: setIsArchived(!isArchived()).

  Побочные эффекты:
    • пишется запись в журнал активности ("archived" или "restored");
    • при восстановлении карточка снова появляется в колонке доски на своей
      прежней позиции (position не сбрасывается).

  Права: KANBAN_ADMIN.

curl:

  curl -X PATCH -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/cards/42/archive"

--- Ответ (200) ----------------------------------------------------------------

{
  "id": 42,
  "isArchived": true
}

  isArchived в ответе — итоговое состояние карточки после переключения
  (true = только что заархивирована, false = только что восстановлена).

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "card_not_found" }
  403  AccessDeniedException (нет роли KANBAN_ADMIN на доске)


--------------------------------------------------------------------------------
ПРИМЕЧАНИЯ
--------------------------------------------------------------------------------

  • Один PATCH-переключатель — намеренно: клиент не передаёт желаемое
    состояние, оно всегда инвертируется. Чтобы узнать текущее состояние до
    переключения, используйте поле isArchived в GET /spa/api/cards/{id} или
    факт присутствия карточки в списке архива.

  • Архивные карточки исключаются из выдачи доски
    (GET /spa/api/projects/{id}/boards/{boardId}) на этапе формирования
    колонок (BoardController::formatColumn пропускает isArchived = true).

  • archivedCount в ответе списка архива считается БЕЗ фильтров — это полный
    счётчик для индикатора на кнопке, тогда как pagination.total учитывает
    применённые фильтры.

  • Коды ошибок — машинные строки из App\Controller\SpaApi\SpaApiError
    (project_not_found, board_not_found, card_not_found). Человекочитаемый
    текст подставляет фронтенд.

  • Аналог в SSR (сессионная авторизация, не для SPA):
    GET /kanban/board/{id}/archive — App\Controller\Kanban\ProjectKanban
    Controller::boardArchive (рендерит страницу kanban_board_archive.html.twig
    с тем же набором фильтров). Архивация в legacy-API:
    PATCH /api/kanban/cards/{id}/archive —
    App\Controller\Kanban\Api\KanbanCardApiController.
