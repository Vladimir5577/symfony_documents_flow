# SPA API — Вакансии

Список, карточка, создание, редактирование и публикация вакансий. Данные
приходят из внешнего сервиса, бэкенд проксирует их в JSON для SPA.

Базовый путь: `/spa/api/vacancies`

--------------------------------------------------------------------------------

## Авторизация

Все запросы — с JWT в заголовке:

    Authorization: Bearer <token>

Токен получается через `POST /spa/api/login_check` (см.
`dev_docks/spa_api/user/Readme_spa_auth.txt`).

Модуль доступен только пользователям с ролью `ROLE_HR`
(админы проходят автоматически).

    401  нет или невалидный токен
    403  нет роли ROLE_HR

## Формат ответов

    Список:            { "items": [...], "pagination": { page, limit, total, pages } }
    Один объект:       { "data": {...} }
    После POST/PATCH:  { "data": {...} }   — созданный/обновлённый объект целиком
    Ошибка:            { "error": "текст на русском для показа пользователю" }

`limit` в списке всегда 20. В списках объект приходит в кратком виде,
в карточке (`/{id}`) и после мутаций — в полном.

Коды ошибок: `400` — тело не JSON / нет обязательного поля, `404` — не найдено,
`422` — ошибка валидации, `502` — внешний сервис недоступен.

ВАЖНО про справочные поля (город, тип занятости, график, опыт):
  - в ОТВЕТАХ они приходят объектом `{ "value": "...", "label": "..." }`;
  - в ТЕЛЕ создания/редактирования их надо слать ПЛОСКОЙ строкой-значением
    (только `value`).

================================================================================

## 1. Список вакансий

    GET /spa/api/vacancies

Query-параметры (все опциональны):

    page            номер страницы, по умолчанию 1
    isPublished     "1" | "0" — только опубликованные / только черновики
    city            значение города (value)
    employmentType  значение типа занятости (value)
    schedule        значение графика (value)
    experience      значение опыта (value)
    search          строка поиска
    sort            поле сортировки, по умолчанию "sortOrder"
    order           asc | desc, по умолчанию "asc"

Пример запроса:

    fetch("/spa/api/vacancies?isPublished=1&page=1", {
      headers: { Authorization: `Bearer ${token}` }
    }).then(r => r.json());

Ответ:

    {
      "items": [
        {
          "id": 8,
          "slug": "menedzher-po-prodazham",
          "title": "Менеджер по продажам",
          "salary": "от 60 000 ₽",
          "city": { "value": "rostov", "label": "Ростов-на-Дону" },
          "isPublished": true,
          "sortOrder": 10,
          "createdAt": "2026-07-01T10:00:00+03:00"
        }
      ],
      "pagination": { "page": 1, "limit": 20, "total": 1, "pages": 1 }
    }

Поля элемента списка:

    id           int          внутренний id (для карточки/редактирования)
    slug         string       ЧПУ-идентификатор вакансии
    title        string       заголовок
    salary       string|null  зарплата (произвольный текст)
    city         object       { value, label } — город
    isPublished  bool         опубликована ли
    sortOrder    int          порядок сортировки
    createdAt    string       дата создания, ISO-8601

## 2. Карточка вакансии

    GET /spa/api/vacancies/{id}

`{id}` — число (поле `id` из списка). Если нет — `404`.

Ответ:

    {
      "data": {
        "id": 8,
        "slug": "menedzher-po-prodazham",
        "title": "Менеджер по продажам",
        "salary": "от 60 000 ₽",
        "city": { "value": "rostov", "label": "Ростов-на-Дону" },
        "isPublished": true,
        "sortOrder": 10,
        "createdAt": "2026-07-01T10:00:00+03:00",

        "employmentType": { "value": "full", "label": "Полная занятость" },
        "schedule":       { "value": "5_2",  "label": "5/2" },
        "experience":     { "value": "1_3",  "label": "1–3 года" },
        "shortDescription": "Коротко о вакансии",
        "bodyBlocks": [
          { "type": "list", "items": ["Обязанность 1", "Обязанность 2"] }
        ],
        "contactEmail": "hr@example.ru",
        "contactPhone": "+7 900 000-00-00",
        "updatedAt": "2026-07-02T12:00:00+03:00"
      }
    }

Доп. поля карточки (сверх списка):

    employmentType    object       { value, label } — тип занятости
    schedule          object       { value, label } — график
    experience        object       { value, label } — требуемый опыт
    shortDescription  string|null  короткое описание
    bodyBlocks        array        блоки описания [ { type, items: [...] } ]
    contactEmail      string|null  контактный email
    contactPhone      string|null  контактный телефон
    updatedAt         string       дата обновления, ISO-8601

## 3. Создать вакансию

    POST /spa/api/vacancies

Тело (JSON). Справочные поля (`city`, `employmentType`, `schedule`,
`experience`) — ПЛОСКИМИ строками-значениями, НЕ объектом:

    {
      "title":            "Менеджер по продажам",
      "city":             "rostov",
      "employmentType":   "full",
      "schedule":         "5_2",
      "experience":       "1_3",
      "salary":           "от 60 000 ₽",
      "shortDescription": "Коротко о вакансии",
      "contactPhone":     "+7 900 000-00-00",
      "contactEmail":     "hr@example.ru",
      "sortOrder":        0,
      "isPublished":      false,
      "bodyBlocks":       [ { "type": "list", "items": ["..."] } ]
    }

Обязательные: `title`, `city`, `employmentType`, `schedule`, `experience`.
Остальные — опциональны (`salary`, `shortDescription`, `contactPhone`,
`contactEmail` можно слать `null`; `sortOrder` — целое, по умолчанию 0;
`isPublished` — булево; `bodyBlocks` — массив, можно пустой `[]`).

Пример:

    fetch("/spa/api/vacancies", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify(payload)
    }).then(r => r.json());

Успех — `201 Created`, `{ "data": {...} }` (созданная вакансия в полном виде).
Ошибка валидации — `422`, текст в `error` (одной строкой, готов к показу).

Допустимые значения городов/типов/графиков/опыта задаёт внешний сервис. Их
список берётся из существующей Twig-формы создания вакансии; если нужен эндпоинт
справочников — уточни у бэкенда.

## 4. Редактировать вакансию

    PATCH /spa/api/vacancies/{id}

Тело — те же поля, что при создании (можно частично, только изменяемые).
Справочные поля — так же плоскими значениями.

Ответ — обновлённая вакансия (`{ "data": {...} }`).
`404`, если не найдено; `422` при ошибке валидации.

## 5. Опубликовать / снять с публикации

    PATCH /spa/api/vacancies/{id}/publish

Узкий эндпоинт только для переключения флага публикации.

Тело — обязательно поле `isPublished`:

    { "isPublished": true }

Без него — `400`. Ответ — обновлённая вакансия (`{ "data": {...} }`).

Пример:

    fetch(`/spa/api/vacancies/${id}/publish`, {
      method: "PATCH",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ isPublished: true })
    }).then(r => r.json());
