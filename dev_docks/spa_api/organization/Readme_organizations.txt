================================================================================
SPA API — Организации (список и карточка)
================================================================================

Эндпоинты для SPA: постраничный список организаций и просмотр одной
организации с иерархией дочерних подразделений и списком сотрудников.

Контроллер: App\Controller\SpaApi\Organization\OrganizationController
Базовый путь: /spa/api/organizations

Авторизация:
  JWT в заголовке Authorization (как у всех /spa/api/*).
  Требуется ROLE_USER. Отдельных IsGranted на контроллере нет.
  См. dev_docks/spa_api/Readme_spa_auth.txt.


--------------------------------------------------------------------------------
1. СПИСОК ОРГАНИЗАЦИЙ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/organizations

Query-параметры:

  page       (int, опционально, по умолчанию 1)
               Номер страницы. Значения < 1 приводятся к 1.

  page_size  (int, опционально, по умолчанию 10)
               Размер страницы. Ограничение: от 1 до 100 включительно.

  search     (string, опционально, по умолчанию "")
               Поиск без учёта регистра (LIKE %search%) по полям:
                 name, fullName, legalAddress, actualAddress, phone, email.

Поведение выборки (OrganizationRepository::findPaginated):

  • search пустой — в списке только корневые организации (parent IS NULL).
  • search непустой — ищутся все организации любого уровня иерархии,
    попавшие под условие поиска.
  • Сортировка: по id ASC.

Примеры URL:

  /spa/api/organizations
  /spa/api/organizations?page=2&page_size=20
  /spa/api/organizations?search=донснаб&page=1

curl:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/organizations?page=1&page_size=10"

fetch:

  const res = await fetch(
    "/spa/api/organizations?page=1&page_size=10&search=",
    { headers: { Authorization: `Bearer ${token}` } }
  );
  const data = await res.json();


--- Формат ответа (200) --------------------------------------------------------

{
  "organizations": [
    {
      "id":            number,
      "name":          string,
      "fullName":      string,
      "legalAddress":  string,   // при отсутствии в БД — "-"
      "actualAddress": string,   // при отсутствии в БД — "-"
      "phone":         string,   // при отсутствии в БД — "-"
      "email":         string    // при отсутствии в БД — "-"
    }
  ],
  "pagination": {
    "current_page":   number,   // текущая страница
    "total_pages":    number,   // всего страниц (минимум 1 даже при total_items=0)
    "total_items":    number,   // всего записей в выборке
    "items_per_page": number    // фактический limit (page_size после clamp)
  }
}


--------------------------------------------------------------------------------
2. КАРТОЧКА ОРГАНИЗАЦИИ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/organizations/{id}

Path-параметры:

  id  (int, обязательный)
        ID организации. Только цифры (\d+).

Загружаются дочерние организации до 3 уровней вглубь от запрошенной
(leftJoin co → co2 → co3). В JSON дерево childOrganizations сериализуется
рекурсивно на 3 уровня вложенности от корня ответа.

Пользователи: все User с organization = данная организация,
сортировка lastname, firstname ASC (UserRepository::findByOrganization).
Роли подгружаются, но в ответ не попадают — только ФИО, профессия, статус.

Примеры URL:

  /spa/api/organizations/1
  /spa/api/organizations/42

curl:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/organizations/1"


--- Формат ответа (200) --------------------------------------------------------

{
  "organization": {
    "id":                 number,
    "type":               string,    // см. OrganizationType ниже
    "typeLabel":          string,    // человекочитаемая подпись типа
    "name":               string,
    "fullName":           string,
    "description":        string | null,
    "legalAddress":       string | null,
    "actualAddress":      string | null,
    "phone":              string | null,
    "email":              string | null,
    "inn":                string | null,
    "kpp":                string | null,
    "ogrn":               string | null,
    "registrationDate":   string | null,   // "Y-m-d"
    "registrationOrgan":  string | null,
    "bankName":           string | null,
    "bik":                string | null,
    "bankAccount":        string | null,
    "taxType":            string | null,   // см. TaxType ниже
    "taxTypeLabel":       string | null,
    "createdAt":          string | null,   // ISO 8601 (ATOM)
    "updatedAt":          string | null,   // ISO 8601 (ATOM)
    "parent":             object | null,   // { id, name, fullName } или null
    "childOrganizations": [
      {
        "id":                 number,
        "name":               string,
        "fullName":           string,
        "childOrganizations": [
          {
            "id":                 number,
            "name":               string,
            "fullName":           string,
            "childOrganizations": [
              { "id", "name", "fullName" }   // без вложенных childOrganizations
            ]
          }
        ]
      }
    ]
  },
  "users": [
    {
      "id":          number,
      "fullName":    string,   // "Фамилия Имя Отчество", лишние пробелы убраны
      "profession":  string | null,   // из Worker, если есть
      "status":      string | null,   // WorkerStatus.value
      "statusLabel": string | null    // WorkerStatus.getLabel()
    }
  ]
}

Отличие от списка: в карточке null-поля отдаются как null (не "-").


--- Ошибки ---------------------------------------------------------------------

  404  { "error": "Организация не найдена" }
       Организация с указанным id отсутствует или soft-deleted (не попала в выборку).

  401  Нет или невалидный JWT (стандартная обработка firewall).


--------------------------------------------------------------------------------
3. СПРАВОЧНИКИ ЗНАЧЕНИЙ В ОТВЕТЕ
--------------------------------------------------------------------------------

OrganizationType (поле organization.type / typeLabel):

  organization  →  Организация     (сущность Organization, корневая)
  filial        →  Филиал          (сущность Filial)
  department    →  Департамент     (сущность Department)

Определение типа в контроллере: instanceof Filial → filial,
instanceof Department → department, иначе organization.

TaxType (поле organization.taxType / taxTypeLabel), если задан:

  osno                 →  ОСНО
  usn_income           →  УСН (доходы)
  usn_income_expense   →  УСН (доходы минус расходы)
  psn                  →  ПСН (патент)
  eshn                 →  ЕСХН
  none                 →  —

WorkerStatus (поля users[].status / statusLabel), если у пользователя есть Worker:

  AT_WORK, ANNUAL_LEAVE, UNPAID_LEAVE, MATERNITY_LEAVE, PARENTAL_LEAVE,
  ON_BUSINESS_TRIP, SICK_LEAVE, REMOTE, DAY_OFF, ON_DUTY, UNAVAILABLE,
  CONTRACT_SUSPENDED, UNEXCUSED_ABSENCE, EDUCATIONAL_PAID_LEAVE

Подписи — см. App\Enum\WorkerStatus::getLabel().


--------------------------------------------------------------------------------
4. СВЯЗАННЫЙ КОД
--------------------------------------------------------------------------------

  src/Controller/SpaApi/Organization/OrganizationController.php
  src/Repository/Organization/OrganizationRepository.php   — findPaginated
  src/Repository/User/UserRepository.php                   — findByOrganization
  src/Entity/Organization/AbstractOrganization.php
  src/Entity/Organization/Organization.php | Filial.php | Department.php
  src/Enum/OrganizationType.php
  src/Enum/TaxType.php
  src/Enum/WorkerStatus.php

Для SSR и CRUD организаций (не SPA) используется отдельный контроллер:
  src/Controller/Organization/OrganizationController.php

В SPA API на момент документации только чтение (GET list, GET view).
Создание, редактирование и удаление через /spa/api/organizations не реализованы.
