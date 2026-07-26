# SPA API — Отклики на вакансии

Список, карточка и обновление статуса откликов кандидатов на вакансии. Данные
приходят из внешнего сервиса, бэкенд проксирует их в JSON для SPA.

Базовый путь: `/spa/api/vacancy-applications`

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

    Список:         { "items": [...], "pagination": { page, limit, total, pages } }
    Один объект:    { "data": {...} }
    После PATCH:    { "data": {...} }   — обновлённый объект целиком
    Ошибка:         { "error": "текст на русском для показа пользователю" }

`limit` в списке всегда 20. В списках объект приходит в кратком виде,
в карточке (`/{id}`) и после обновления — в полном (с контактами и резюме).

Коды ошибок: `400` — тело не JSON, `404` — не найдено, `422` — ошибка
сохранения, `502` — внешний сервис недоступен.

================================================================================

## 1. Список откликов

    GET /spa/api/vacancy-applications

Query-параметры (все опциональны):

    page       номер страницы, по умолчанию 1
    status     new | viewed | invited | rejected | archived
    vacancyId  id вакансии — отклики только по ней
    search     строка поиска
    dateFrom   дата от, YYYY-MM-DD
    dateTo     дата до, YYYY-MM-DD
    sort       поле сортировки, по умолчанию "createdAt"
    order      asc | desc, по умолчанию "desc"

Пример запроса:

    fetch("/spa/api/vacancy-applications?vacancyId=8&status=new", {
      headers: { Authorization: `Bearer ${token}` }
    }).then(r => r.json());

Ответ:

    {
      "items": [
        {
          "id": 55,
          "vacancyId": 8,
          "vacancyTitleSnapshot": "Менеджер по продажам",
          "fio": "Петров Пётр",
          "status": "new",
          "statusLabel": "Новый",
          "createdAt": "2026-07-12T16:20:00+03:00"
        }
      ],
      "pagination": { "page": 1, "limit": 20, "total": 1, "pages": 1 }
    }

Поля элемента списка:

    id                     int     внутренний id (для карточки/резюме)
    vacancyId              int     id вакансии, на которую откликнулись
    vacancyTitleSnapshot   string  название вакансии на момент отклика
    fio                    string  ФИО кандидата
    status                 string  код статуса
    statusLabel            string  статус по-русски (готов к показу)
    createdAt              string  дата отклика, ISO-8601

`vacancyTitleSnapshot` — «снимок» названия на момент отклика: даже если вакансию
потом переименуют/удалят, в отклике останется исходное название.

## 2. Карточка отклика

    GET /spa/api/vacancy-applications/{id}

`{id}` — число (поле `id` из списка). Если нет — `404`.

Ответ:

    {
      "data": {
        "id": 55,
        "vacancyId": 8,
        "vacancyTitleSnapshot": "Менеджер по продажам",
        "fio": "Петров Пётр",
        "status": "new",
        "statusLabel": "Новый",
        "createdAt": "2026-07-12T16:20:00+03:00",

        "vacancySlug": "menedzher-po-prodazham",
        "phone": "+7 900 111-22-33",
        "email": "petrov@example.ru",
        "coverLetter": "Здравствуйте, хочу у вас работать…",
        "adminComment": null,
        "updatedAt": "2026-07-12T16:20:00+03:00",
        "resume": {
          "originalName": "cv.pdf",
          "mimeType": "application/pdf",
          "size": 234100,
          "sizeLabel": "228.6 КБ",
          "url": "/spa/api/vacancy-applications/55/resume"
        }
      }
    }

Доп. поля карточки (сверх списка):

    vacancySlug     string       ЧПУ вакансии
    phone, email    string       контакты кандидата
    coverLetter     string|null  сопроводительное письмо
    adminComment    string|null  комментарий сотрудника
    updatedAt       string       дата обновления, ISO-8601
    resume          object|null  файл резюме (см. п. 4); может отсутствовать

Резюме может быть `null` — кандидат мог откликнуться без файла.

## 3. Изменить статус / комментарий

    PATCH /spa/api/vacancy-applications/{id}

Тело (JSON), оба поля опциональны:

    { "status": "invited", "adminComment": "Приглашён на собеседование" }

Пример:

    fetch(`/spa/api/vacancy-applications/${id}`, {
      method: "PATCH",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ status: "invited" })
    }).then(r => r.json());

В ответ — обновлённая карточка целиком (`{ "data": {...} }`).
`422` с текстом в `error`, если сохранить не удалось.

## 4. Скачать / открыть резюме

    GET /spa/api/vacancy-applications/{id}/resume

ВНИМАНИЕ: `{id}` здесь — id ОТКЛИКА (не id файла). Проще всего брать готовый
`url` из объекта `resume`. Отдаёт файл резюме с правильным `Content-Type`.

`?download=1` — принудительное скачивание:

    <a href={`${application.resume.url}?download=1`}>Скачать резюме</a>

`404`, если резюме отсутствует.

================================================================================

## Справочник значений

Статус (`status` / `statusLabel`):

    new        Новый
    viewed     Просмотрен
    invited    Приглашён
    rejected   Отказ
    archived   Архив

Лейблы (`statusLabel`) уже переведены на русский на бэкенде — выводи их как есть.
