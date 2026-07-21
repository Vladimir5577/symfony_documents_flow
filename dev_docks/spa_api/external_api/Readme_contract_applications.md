# SPA API — Заявки на договор

Список, карточка и обновление статуса заявок на заключение договора. Данные
приходят из внешнего сервиса, бэкенд проксирует их в JSON для SPA.

Базовый путь: `/spa/api/contract-applications`

--------------------------------------------------------------------------------

## Авторизация

Все запросы — с JWT в заголовке:

    Authorization: Bearer <token>

Токен получается через `POST /spa/api/login_check` (см.
`dev_docks/spa_api/user/Readme_spa_auth.txt`).

Модуль доступен только пользователям с ролью `ROLE_CONTRACT_APPLICATION`
(админы проходят автоматически).

    401  нет или невалидный токен
    403  нет роли ROLE_CONTRACT_APPLICATION

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

## 1. Список заявок

    GET /spa/api/contract-applications

Query-параметры (все опциональны):

    page          номер страницы, по умолчанию 1
    status        new | in_review | contract_sent | signed | rejected
    consumerType  legal | ip | person
    dateFrom      дата от, YYYY-MM-DD
    dateTo        дата до, YYYY-MM-DD
    search        строка поиска
    sort          поле сортировки, по умолчанию "id"
    order         asc | desc, по умолчанию "desc"

Пример запроса:

    fetch("/spa/api/contract-applications?status=in_review&page=1", {
      headers: { Authorization: `Bearer ${token}` }
    }).then(r => r.json());

Ответ:

    {
      "items": [
        {
          "id": 15,
          "publicId": "CT-2026-0015",
          "consumerType": "legal",
          "consumerTypeLabel": "Юридическое лицо",
          "consumerName": "ООО Ромашка",
          "organization": "ООО Ромашка",
          "status": "in_review",
          "statusLabel": "На проверке",
          "createdAt": "2026-07-11T09:30:00+03:00"
        }
      ],
      "pagination": { "page": 1, "limit": 20, "total": 1, "pages": 1 }
    }

Поля элемента списка:

    id                  int          внутренний id (для карточки/файлов)
    publicId            string       человекочитаемый номер заявки
    consumerType        string       код типа потребителя
    consumerTypeLabel   string       тип потребителя по-русски (готов к показу)
    consumerName        string       имя/наименование потребителя
    organization        string|null  организация
    status              string       код статуса
    statusLabel         string       статус по-русски (готов к показу)
    createdAt           string       дата создания, ISO-8601

## 2. Карточка заявки

    GET /spa/api/contract-applications/{id}

`{id}` — число (поле `id` из списка). Если нет — `404`.

Ответ:

    {
      "data": {
        "id": 15,
        "publicId": "CT-2026-0015",
        "consumerType": "legal",
        "consumerTypeLabel": "Юридическое лицо",
        "consumerName": "ООО Ромашка",
        "organization": "ООО Ромашка",
        "status": "in_review",
        "statusLabel": "На проверке",
        "createdAt": "2026-07-11T09:30:00+03:00",

        "primaryPhone": "+7 863 000-00-00",
        "primaryEmail": "info@romashka.ru",
        "adminComment": null,
        "updatedAt": "2026-07-11T09:30:00+03:00",

        "consumer":   { ... },
        "requisites": { ... },
        "signer":     { ... },
        "waste":      { ... },
        "site":       { ... },
        "containers": [ ... ],
        "extra":      { ... },

        "files": [
          {
            "id": 3,
            "originalName": "ustav.pdf",
            "mimeType": "application/pdf",
            "fileSize": 543210,
            "sizeLabel": "530.5 КБ",
            "url": "/spa/api/contract-applications/files/3"
          }
        ]
      }
    }

Доп. поля карточки (сверх списка):

    primaryPhone, primaryEmail   string|null  основные контакты
    adminComment                 string|null  комментарий сотрудника
    updatedAt                    string       дата обновления, ISO-8601
    consumer, requisites,        object|      детальные блоки заявки:
    signer, waste, site,         array|null   потребитель, реквизиты, подписант,
    containers, extra                         отходы, площадка, контейнеры, прочее.
                                              Приходят как есть из внешнего сервиса;
                                              структура зависит от типа заявки,
                                              любой блок может быть null.
    files                        array        вложения (см. п. 4)

Блоки `consumer/requisites/…` — произвольные объекты внешнего API. Отрисовывай
их по факту (проверяй на null), жёсткой схемы у них нет.

## 3. Изменить статус / комментарий

    PATCH /spa/api/contract-applications/{id}

Тело (JSON), оба поля опциональны:

    { "status": "signed", "adminComment": "Договор подписан" }

Пример:

    fetch(`/spa/api/contract-applications/${id}`, {
      method: "PATCH",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ status: "signed" })
    }).then(r => r.json());

В ответ — обновлённая карточка целиком (`{ "data": {...} }`).
`422` с текстом в `error`, если сохранить не удалось.

## 4. Скачать / открыть файл

    GET /spa/api/contract-applications/files/{id}

Используй готовый `url` из объекта файла. Отдаёт содержимое с правильным
`Content-Type`. `?download=1` — принудительное скачивание. `404`, если нет.

================================================================================

## Справочник значений

Статус (`status` / `statusLabel`):

    new             Новая
    in_review       На проверке
    contract_sent   Договор отправлен
    signed          Подписан
    rejected        Отклонена

Тип потребителя (`consumerType` / `consumerTypeLabel`):

    legal    Юридическое лицо
    ip       ИП
    person   Физическое лицо

Лейблы (`*Label`) уже переведены на русский на бэкенде — выводи их как есть.
