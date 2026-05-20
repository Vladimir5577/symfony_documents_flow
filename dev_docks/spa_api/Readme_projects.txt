================================================================================
SPA API — Kanban-проекты
================================================================================

Контроллер: App\Controller\SpaApi\Project\ProjectController
Базовый путь: /spa/api/projects

Список проектов текущего пользователя — в GET /spa/api/me (поле projects).
См. dev_docks/spa_api/Readme_spa_auth.txt.

Авторизация: JWT, ROLE_USER.


--------------------------------------------------------------------------------
ПРОСМОТР ПРОЕКТА
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/projects/{id}

  id — числовой идентификатор KanbanProject.

Права доступа (как /kanban_project/{id}, ProjectKanbanController::viewProject):

  • Есть доски: нужна роль минимум KANBAN_VIEWER на первой доске проекта.
    Если роль не KANBAN_ADMIN — 403 и entryBoardId (редирект на доску в SPA).
  • Нет досок: доступ у владельца (owner) или участника (KanbanProjectUser).

curl:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/projects/3"


--- Формат ответа (200) --------------------------------------------------------

{
  "id": 3,
  "name": "Мой проект",
  "description": "Описание",
  "createdAt": "2026-03-01T10:00:00+00:00",
  "updatedAt": "2026-03-07T12:00:00+00:00",
  "owner": {
    "id": 1,
    "login": "admin",
    "lastname": "Иванов",
    "firstname": "Иван",
    "patronymic": "Иванович"
  },
  "isOwner": true,
  "isProjectAdmin": true,
  "memberRole": "KANBAN_ADMIN",
  "boards": [
    {
      "id": 12,
      "title": "Главная доска",
      "updatedAt": "2026-03-07T11:00:00+00:00",
      "href": "/projects/3/boards/12"
    }
  ],
  "members": [
    {
      "userId": 1,
      "login": "admin",
      "lastname": "Иванов",
      "firstname": "Иван",
      "patronymic": "Иванович",
      "profession": "Инженер",
      "role": "KANBAN_ADMIN",
      "roleLabel": "Администратор канбан",
      "isOwner": true
    }
  ]
}


--------------------------------------------------------------------------------
СОЗДАНИЕ ДОСКИ В ПРОЕКТЕ
--------------------------------------------------------------------------------

  Method : POST
  URL    : /spa/api/projects/{id}/boards

  Content-Type: application/json

  Body:
  {
    "title": "Новая доска",
    "columns": ["Backlog", "To Do", "In Progress", "Done"]
  }

  title   — обязательно.
  columns — опционально, массив названий колонок.
            Если не передан или пуст — Backlog, To Do, In Progress, Done.

Права: JWT (ROLE_USER). Проверка ролей Kanban на создание доски пока отключена.

curl:

  curl -X POST -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"title":"Новая доска"}' \
       "http://localhost:8080/spa/api/projects/3/boards"

--- Ответ (201) ----------------------------------------------------------------

{
  "id": 15,
  "title": "Новая доска",
  "updatedAt": "2026-03-19T12:00:00+00:00",
  "href": "/projects/3/boards/15"
}


--- Ошибки ---------------------------------------------------------------------

  400  { "error": "Название доски обязательно" }
  400  { "error": "Некорректный JSON" }

  404  { "error": "Проект не найден" }


--------------------------------------------------------------------------------
ОБНОВЛЕНИЕ ДОСКИ
--------------------------------------------------------------------------------

  Method : PATCH
  URL    : /spa/api/projects/{id}/boards/{boardId}

  Content-Type: application/json

  Body:
  {
    "title": "Новое название"
  }

  title — обязательно, непустая строка, максимум 200 символов.

Права: JWT (ROLE_USER). Проверка ролей Kanban пока отключена.

curl:

  curl -X PATCH -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"title":"Переименованная доска"}' \
       "http://localhost:8080/spa/api/projects/3/boards/15"

--- Ответ (200) ----------------------------------------------------------------

{
  "id": 15,
  "title": "Переименованная доска",
  "updatedAt": "2026-03-19T14:00:00+00:00",
  "href": "/projects/3/boards/15"
}

--- Ошибки ---------------------------------------------------------------------

  400  { "error": "Некорректный JSON" }
  400  { "error": "Название доски обязательно" }
  400  { "error": "Название доски слишком длинное (максимум 200 символов)" }

  404  { "error": "Проект не найден" }
  404  { "error": "Доска не найдена" }


--------------------------------------------------------------------------------
УДАЛЕНИЕ ДОСКИ
--------------------------------------------------------------------------------

  Method : DELETE
  URL    : /spa/api/projects/{id}/boards/{boardId}

  id      — KanbanProject.
  boardId — KanbanBoard в этом проекте.

Права: JWT (ROLE_USER). Проверка ролей Kanban пока отключена.

curl:

  curl -X DELETE -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/projects/3/boards/15"

--- Ответ (200) ----------------------------------------------------------------

{
  "success": true,
  "nextBoardId": 12
}

  nextBoardId — id другой доски проекта для переключения вкладки;
                null, если досок не осталось.

--- Ошибки ---------------------------------------------------------------------

  404  { "error": "Проект не найден" }
  404  { "error": "Доска не найдена" }


  403  (участник не админ, есть доска)
       {
         "error": "Нет доступа к странице проекта",
         "entryBoardId": 12
       }
