================================================================================
SPA API — Пользователи (список)
================================================================================

Эндпоинт для SPA: постраничный список пользователей с поиском и фильтрами
по организации и статусу сотрудника.

Контроллер: App\Controller\SpaApi\User\UserController
Базовый путь: /spa/api/users

Авторизация:
  JWT в заголовке Authorization (как у всех /spa/api/*).
  Требуется ROLE_USER.
  См. dev_docks/spa_api/Readme_spa_auth.txt.


--------------------------------------------------------------------------------
1. СПИСОК ПОЛЬЗОВАТЕЛЕЙ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/users

Query-параметры:

  page             (int, опционально, по умолчанию 1)
                     Номер страницы. Значения < 1 приводятся к 1.

  page_size        (int, опционально, по умолчанию 10)
                     Размер страницы. Ограничение: от 1 до 100 включительно.

  search           (string, опционально, по умолчанию "")
                     Поиск без учёта регистра (LIKE %search%) по полям:
                       lastname, firstname, patronymic, login, phone.

  organization_id  (int, опционально)
                     Фильтр по ID организации и всем её дочерним (дерево до 5 уровней,
                     OrganizationRepository::findOrganizationWithChildrenIds).
                     Пользователи с organization IN (выбранная + потомки).
                     Не передан или 0 — все организации.
                     Несуществующий id — пустой список (200).

  status           (string, опционально)
                     Фильтр по статусу Worker (WorkerStatus.value).
                     Не передан или пустая строка — все статусы.
                     Неизвестное значение игнорируется (фильтр не применяется).

Поведение выборки (UserRepository::findPaginated):

  • Сортировка: lastname ASC, firstname ASC.
  • Подгружаются worker и organization (без N+1 в списке).
  • Soft-deleted пользователи не попадают в выборку (Gedmo SoftDeleteable).

Примеры URL:

  /spa/api/users
  /spa/api/users?page=2&page_size=20
  /spa/api/users?search=иванов&organization_id=5&status=AT_WORK

curl:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/users?page=1&page_size=10"

fetch:

  const params = new URLSearchParams({
    page: "1",
    page_size: "10",
    search: "",
    organization_id: "5",
    status: "AT_WORK",
  });
  const res = await fetch(`/spa/api/users?${params}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const data = await res.json();


--- Формат ответа (200) --------------------------------------------------------

{
  "users": [
    {
      "id":           number,
      "lastname":     string,   // при отсутствии — "-"
      "firstname":    string,
      "patronymic":   string,
      "login":        string,
      "phone":        string,
      "profession":   string,   // из Worker; при отсутствии — "-"
      "status":       string | null,   // WorkerStatus.value
      "statusLabel":  string,   // подпись статуса; при отсутствии Worker — "-"
      "organization": {
        "id":       number,
        "name":     string,
        "fullName": string
      } | null,
      "lastSeenAt": string | null   // ISO 8601, дата последнего входа / активности
    }
  ],
  "pagination": {
    "current_page":   number,
    "total_pages":    number,
    "total_items":    number,
    "items_per_page": number
  },
  "filters": {
    "statusChoices": {
      "<WorkerStatus.value>": "<подпись>"
    }
  }
}

Поле filters.statusChoices — варианты для выпадающего списка фильтра статуса
(аналог status_choices в SSR-шаблоне all_users.html.twig).

Дерево организаций для фильтра по организации — отдельный эндпоинт
GET /spa/api/organizations (см. Readme_organizations.txt).


--- Ошибки ---------------------------------------------------------------------

  401  Нет или невалидный JWT.


--------------------------------------------------------------------------------
2. СПИСОК РОЛЕЙ ПОЛЬЗОВАТЕЛЕЙ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/users/roles

Справочник ролей для форм создания/редактирования пользователя.
ROLE_ADMIN в список не входит (как в SSR: RoleRepository::findAllExceptAdmin).
Сортировка: sortOrder ASC, id ASC.

Пример:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/users/roles"

--- Формат ответа (200) --------------------------------------------------------

{
  "roles": [
    {
      "id":        number,
      "name":      string,   // Symfony role, напр. "ROLE_USER"
      "label":     string,   // подпись из БД или UserRole::getLabel()
      "sortOrder": number
    }
  ]
}


--------------------------------------------------------------------------------
3. СПРАВОЧНИК WorkerStatus (status / statusLabel)
--------------------------------------------------------------------------------

  AT_WORK                  Работа
  ANNUAL_LEAVE             Отпуск основной
  UNPAID_LEAVE             Отпуск неоплачиваемый по разрешению работодателя
  MATERNITY_LEAVE          Отпуск по беременности и родам
  PARENTAL_LEAVE           Отпуск по уходу за ребенком
  ON_BUSINESS_TRIP         В командировке
  SICK_LEAVE               Болезнь
  REMOTE                   Удалённая работа
  DAY_OFF                  Выходной / отгул
  ON_DUTY                  Дежурство
  UNAVAILABLE              Не на связи
  CONTRACT_SUSPENDED       Трудовой договор приостановлен
  UNEXCUSED_ABSENCE        Прогул
  EDUCATIONAL_PAID_LEAVE   Отпуск учебный оплачиваемый

Полный список value → label в filters.statusChoices ответа API.


--------------------------------------------------------------------------------
4. СВЯЗАННЫЙ КОД
--------------------------------------------------------------------------------

  src/Controller/SpaApi/User/UserController.php
  src/Repository/User/UserRepository.php   — findPaginated (SSR)
  src/Repository/User/RoleRepository.php   — findAllExceptAdmin
  src/Enum/WorkerStatus.php
  src/Enum/UserRole.php

SSR-аналоги (не SPA):

  GET /users              — страница списка (Twig)
  GET /users/search       — AJAX-обновление таблицы (JSON, limit=10 фикс.)

В SPA API на момент документации только чтение списка (GET).
Карточка пользователя (/spa/api/users/{id}) не реализована.
