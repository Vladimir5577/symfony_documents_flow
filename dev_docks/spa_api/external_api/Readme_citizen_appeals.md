# SPA API — Обращения граждан

Список, карточка и обновление статуса обращений граждан. Данные приходят из
внешнего сервиса, бэкенд проксирует их в JSON для SPA.

Базовый путь: `/spa/api/citizen-appeals`

--------------------------------------------------------------------------------

## Авторизация

Все запросы — с JWT в заголовке:

    Authorization: Bearer <token>

Токен получается через `POST /spa/api/login_check` (см.
`dev_docks/spa_api/user/Readme_spa_auth.txt`).

Модуль доступен только пользователям с ролью `ROLE_CITIZEN_APPEAL`
(админы проходят автоматически).

    401  нет или невалидный токен
    403  нет роли ROLE_CITIZEN_APPEAL

## Формат ответов

    Список:         { "items": [...], "pagination": { page, limit, total, pages } }
    Один объект:    { "data": {...} }
    После PATCH:    { "data": {...} }   — обновлённый объект целиком
    Ошибка:         { "error": "текст на русском для показа пользователю" }

`limit` в списке всегда 20. В списках объект приходит в кратком виде,
в карточке (`/{id}`) и после обновления — в полном (с доп. полями и файлами).

Коды ошибок: `400` — тело не JSON, `404` — не найдено, `422` — ошибка
сохранения, `502` — внешний сервис недоступен.

================================================================================

## 1. Список обращений

    GET /spa/api/citizen-appeals

Query-параметры (все опциональны):

    page        номер страницы, по умолчанию 1
    status      new | in_progress | done
    city        город (строка)
    appealType  тип обращения (значения — в справочнике внизу)
    dateFrom    дата от, YYYY-MM-DD
    dateTo      дата до, YYYY-MM-DD
    search      строка поиска
    sort        поле сортировки, по умолчанию "id"
    order       asc | desc, по умолчанию "desc"

Пример запроса:

    fetch("/spa/api/citizen-appeals?status=new&page=1", {
      headers: { Authorization: `Bearer ${token}` }
    }).then(r => r.json());

Ответ:

    {
      "items": [
        {
          "id": 42,
          "publicId": "CA-2026-0042",
          "fio": "Иванов Иван Иванович",
          "appealType": "recalculation",
          "appealTypeLabel": "Перерасчёт",
          "city": "Ростов-на-Дону",
          "status": "new",
          "statusLabel": "Новое",
          "createdAt": "2026-07-10T14:05:00+03:00"
        }
      ],
      "pagination": { "page": 1, "limit": 20, "total": 3, "pages": 1 }
    }

Поля элемента списка:

    id                int     внутренний id (для ссылок на карточку/файлы)
    publicId          string  человекочитаемый номер обращения
    fio               string  ФИО заявителя
    appealType        string  код типа обращения
    appealTypeLabel   string  тип обращения по-русски (готов к показу)
    city              string  город
    status            string  код статуса
    statusLabel       string  статус по-русски (готов к показу)
    createdAt         string  дата создания, ISO-8601

## 2. Карточка обращения

    GET /spa/api/citizen-appeals/{id}

`{id}` — число (поле `id` из списка). Если нет — `404`.

Ответ:

    {
      "data": {
        "id": 42,
        "publicId": "CA-2026-0042",
        "fio": "Иванов Иван Иванович",
        "appealType": "recalculation",
        "appealTypeLabel": "Перерасчёт",
        "city": "Ростов-на-Дону",
        "status": "new",
        "statusLabel": "Новое",
        "createdAt": "2026-07-10T14:05:00+03:00",

        "phone": "+7 900 000-00-00",
        "email": "ivanov@example.ru",
        "address": "ул. Ленина, 1",
        "message": "Текст обращения…",
        "replyTo": "email",
        "adminComment": null,
        "updatedAt": "2026-07-10T14:05:00+03:00",
        "files": [
          {
            "id": 7,
            "originalName": "scan.pdf",
            "mimeType": "application/pdf",
            "fileSize": 128456,
            "sizeLabel": "125.4 КБ",
            "url": "/spa/api/citizen-appeals/files/7"
          }
        ]
      }
    }

Доп. поля карточки (сверх списка):

    phone, email    string|null  контакты заявителя
    address         string       адрес
    message         string|null  текст обращения
    replyTo         string       способ ответа
    adminComment    string|null  комментарий сотрудника
    updatedAt       string       дата обновления, ISO-8601
    files           array        вложения (см. п. 4)

## 3. Изменить статус / комментарий

    PATCH /spa/api/citizen-appeals/{id}

Тело (JSON), оба поля опциональны — можно слать только то, что меняешь:

    { "status": "in_progress", "adminComment": "Взято в работу" }

Пример:

    fetch(`/spa/api/citizen-appeals/${id}`, {
      method: "PATCH",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ status: "done", adminComment: "Обработано" })
    }).then(r => r.json());

В ответ — обновлённая карточка целиком (`{ "data": {...} }`).
`422` с текстом в `error`, если сохранить не удалось.

## 4. Скачать / открыть файл

    GET /spa/api/citizen-appeals/files/{id}

`{id}` — поле `id` из объекта файла (не из `url`; хотя `url` уже содержит
готовый путь — используй его напрямую). Отдаёт содержимое файла с правильным
`Content-Type`.

Добавь `?download=1`, чтобы браузер скачал файл, а не открыл во вкладке:

    <a href={`${file.url}?download=1`}>Скачать</a>

`404`, если файл не найден.

================================================================================

## Справочник значений

Статус (`status` / `statusLabel`):

    new           Новое
    in_progress   В работе
    done          Обработано

Тип обращения (`appealType` / `appealTypeLabel`):

    individual_contract          Договор (физ. лицо)
    legal_contract               Договор (юр. лицо)
    receipt_data_correction      Корректировка квитанции
    recalculation                Перерасчёт
    waste_pickup_schedule        График вывоза мусора
    illegal_dump                 Несанкционированная свалка
    bulky_waste                  КГО
    container_site_improvement   Контейнерная площадка
    other                        Другое

Лейблы (`*Label`) уже переведены на русский на бэкенде — выводи их как есть,
свой словарь на фронте держать не нужно.
