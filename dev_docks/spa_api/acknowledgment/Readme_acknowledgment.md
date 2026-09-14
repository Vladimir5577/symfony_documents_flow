# SPA API — Документы к ознакомлению

Делопроизводство публикует документ и указывает, кто должен с ним ознакомиться:
либо все сотрудники, либо выбранные поимённо. Сотрудник открывает документ,
скачивает файлы и ставит отметку — «ознакомлен», «ознакомлен, не согласен» или
«ознакомлюсь позже», при желании с комментарием. Делопроизводство видит по
каждому документу, кто отметился, а кто молчит.

Базовый путь: `/spa/api/acknowledgment`

Код: `App\Controller\SpaApi\Acknowledgment`, `App\Entity\Acknowledgment`,
`App\Repository\Acknowledgment`, `App\Service\Acknowledgment`.

--------------------------------------------------------------------------------

## Аудитория «все» — живой список, а не список

Это главное, что нужно понять до всего остального.

    audience = SELECTED   Назначения хранятся: на каждого выбранного заводится
                          строка в ack_document_user при сохранении документа.

    audience = ALL        Не хранится ничего. «Все» вычисляется в момент запроса
                          как все пользователи с deleted_at IS NULL.

Отсюда три следствия:

- **Принятый завтра сотрудник получит документ сам**, без пересчёта и без
  фоновой задачи. Уволенный так же сам выпадет из знаменателя отчёта.
- **Знаменатель отчёта плавает.** «12 из 1767» завтра может стать «12 из 1770».
  Это цена живого списка, и она выбрана сознательно.
- **Строка `ack_document_user` для ALL появляется только когда человек нажал
  кнопку.** Поэтому «кто не ознакомился» считается через `LEFT JOIN` от таблицы
  пользователей, а не выборкой из отметок: в отметках лежат ровно те, кого в
  этом отчёте искать не надо.

--------------------------------------------------------------------------------

## Жизненный цикл документа

    Черновик       published_at IS NULL. Виден только делопроизводству.
                   Правится целиком: текст, категория, аудитория, файлы, состав.

    Опубликован    published_at заполнен. Появился у сотрудников, счётчик
                   неотмеченных вырос. Заморожены: файлы и состав аудитории.

    Архив          archived_at заполнен, «утратил силу». Пропал из списков
                   сотрудников, остался в реестре и в отчётах.

Почему файл после публикации не меняется: версий у документа нет, и подмена
файла означала бы, что люди «ознакомлены» не с тем, что лежит по ссылке. Нужна
новая редакция — заводится новый документ, старый уходит в архив.

Почему состав аудитории заморожен: люди уже отмечаются, и тихое расширение круга
сделало бы охват в отчёте несопоставимым с тем, что видели раньше.

Удалить можно только черновик. Опубликованный документ архивируется — иначе из
отчётов пропадали бы уже собранные ознакомления.

--------------------------------------------------------------------------------

## Статусы сотрудника

    ACKNOWLEDGED   «Ознакомлен»                 закрывает обязанность
    DISAGREED      «Ознакомлен, не согласен»    закрывает обязанность
    LATER          «Ознакомлюсь позже»          НЕ закрывает
    null           отметки нет                  НЕ закрывает

`DISAGREED` закрывает обязанность намеренно: человек документ прочитал, а
несогласие — это его позиция, а не отказ читать. В отчёте он идёт отдельной
колонкой, чтобы возражение было видно.

`LATER` ничего не закрывает — иначе кнопка была бы способом убрать документ из
своего списка. Документ остаётся в «моих» и в счётчике.

**Отметку назад не отыгрывают.** После `ACKNOWLEDGED` или `DISAGREED` любая
следующая отметка получает `409 ack_status_already_final`. Из `LATER` перейти в
любой статус можно. Истории смены статусов нет: у модуля нет юридической силы,
хранится только последнее состояние.

Комментарий обязателен для `DISAGREED` и необязателен для остальных.

--------------------------------------------------------------------------------

## Дедлайн и просрочка

Дедлайн необязателен (`deadline: null` — документ без срока). Нигде не хранится
признак просрочки, он считается на лету:

    просрочен = deadline < сегодня
                И дата создания учётки сотрудника <= deadline

Второе условие — про живой список: сотрудника, чья учётка заведена уже после
дедлайна, документ ждать не мог, и вешать на него просрочку в первый рабочий
день неправильно. Дата учётки здесь — приближение даты приёма, точной у нас нет.

Никаких напоминаний, писем и фоновых задач по дедлайну нет. Просрочка — это
только флаг `isOverdue` в ответе.

--------------------------------------------------------------------------------

## Авторизация

Тот же stateless-фаервол `spa_api`, что у остальных модулей: JWT в заголовке.

    Authorization: Bearer <token>

Токен для проверок руками выдаётся консольной командой (печатает в **stderr**):

    JWT=$(docker compose exec -T php php bin/console lexik:jwt:generate-token admin 2>&1 \
          | grep -o 'eyJ[A-Za-z0-9_.-]*' | tail -1)
    curl -s -H "Authorization: Bearer $JWT" http://localhost:8080/spa/api/acknowledgment/pending-count

--------------------------------------------------------------------------------

## Модель доступа

Роль одна: `ROLE_DOC_OFFICE` («Роль делопроизводства»). Наследует её только
`ROLE_ADMIN` — аналитику она намеренно не выдана: читать отчёты и издавать
приказы это разное.

    Действие                                      DOC_OFFICE   Сотрудник

    Реестр, создание, публикация, архив, отчёт        да          нет (403)
    Категории: читать                                 да           да
    Категории: заводить, править, удалять             да          нет (403)
    Карточка документа                            любого      только своего
    Скачивание файла                              любого      только своего
    Отметка об ознакомлении                    только если назначен

«Свой документ» = опубликованный, не архивный, и либо `audience = ALL`, либо
сотрудник есть в списке назначенных. Посторонний получает `403` и на карточке,
и на файле, и на попытке отметиться — прямая ссылка на файл ничего не открывает.

Делопроизводство видит любой документ, включая черновики и архив, но **в его
собственном счётчике неотмеченных чужие документы не появляются**: обязанность
и доступ — разные вещи.

--------------------------------------------------------------------------------

## Формат ответов

Успех — объект или `{"items": [...], "total": N}`. Ошибка — `{"error": "код"}`
с соответствующим HTTP-статусом. Коды перечислены в `SpaApiError`.

    ack_category_has_documents            409  в категории есть документы
    ack_category_name_required            400  пустое имя категории
    ack_category_name_taken               409  такая категория уже есть
    ack_category_not_found                404
    ack_document_already_published        409  действие только для черновика
    ack_document_audience_invalid         400  audience не ALL и не SELECTED
    ack_document_deadline_invalid         400  дедлайн не в формате Y-m-d
    ack_document_not_active               409  черновик или архив
    ack_document_not_found                404
    ack_document_no_files                 409  публикация без единого файла
    ack_document_no_recipients            409  SELECTED без назначенных
    ack_document_title_required           400
    ack_file_not_found                    404  нет строки или объекта в MinIO
    ack_file_too_large                    400  больше 25 МБ
    ack_file_type_not_allowed             400  тип вне белого списка
    ack_status_already_final              409  отметка уже стоит
    ack_status_comment_required           400  DISAGREED без комментария
    ack_status_invalid                    400  статус вне перечисления
    access_denied                         403
    invalid_json                          400

Исключение — `403` от атрибута `IsGranted` на контроллере делопроизводства: он
отдаёт HTML-страницу Symfony, а не JSON. Так же ведут себя инвентаризация, посты
и аналитика, ориентироваться нужно на код ответа.

Кириллица в JSON приходит `\u`-экранированной (`При...`). В
примерах ниже она раскрыта для читаемости.

--------------------------------------------------------------------------------

# ЭНДПОИНТЫ СОТРУДНИКА

## 1. Счётчик неотмеченных

    GET /spa/api/acknowledgment/pending-count

Лёгкий запрос под бейдж в шапке: его зовут с любой страницы портала. Считает
двумя подзапросами (адресные + «для всех»), тяжёлых объединений не делает.

Запрос:

    curl -H "Authorization: Bearer $JWT" \
      http://localhost:8080/spa/api/acknowledgment/pending-count

Ответ `200`:

    { "count": 3 }

В счётчик попадают документы со статусом `null` и `LATER`. Архивные и черновики
не попадают никогда.

--------------------------------------------------------------------------------

## 2. Мои документы

    GET /spa/api/acknowledgment/documents
    GET /spa/api/acknowledgment/documents?status=history&page=1

Один маршрут с фильтром, а не два: форма ответа одинаковая, а объёмы разные —
неотмеченных единицы и они нужны целиком, закрытых за год сотни и им нужна
страница.

    status    без параметра или что угодно кроме `history` — неотмеченные.
              `history` — закрытые: ACKNOWLEDGED и DISAGREED, включая архивные
              (человек их читал, из истории они не исчезают)
    page      только для `history`, по 20 на страницу

Сортировка неотмеченных: сначала документы с дедлайном (ближайший выше), затем
без дедлайна — свежеопубликованные выше. История сортируется по дате отметки,
новые выше.

Ответ `200` (неотмеченные, `total` = длина списка, страниц нет):

    {
      "items": [
        {
          "id": 1,
          "title": "Инструкция по охране труда",
          "description": "Редакция от 01.09.2026",
          "category": { "id": 1, "name": "Инструкции", "sort": 10 },
          "audience": "ALL",
          "deadline": "2026-09-30",
          "isOverdue": false,
          "publishedAt": "2026-09-02T14:48:01",
          "archivedAt": null,
          "files": [
            { "id": 1, "name": "Приказ №12.pdf", "createdAt": "2026-09-02T14:48:00" }
          ],
          "myStatus": null,
          "myStatusLabel": null,
          "myComment": null,
          "myActedAt": null
        }
      ],
      "total": 1
    }

Ответ `200` (`status=history`, добавляются `page` и `limit`):

    {
      "items": [
        {
          "id": 4,
          "title": "Положение о пропускном режиме",
          "myStatus": "DISAGREED",
          "myStatusLabel": "Ознакомлен, не согласен",
          "myComment": "Не согласен с пунктом 3",
          "myActedAt": "2026-09-02T14:49:36"
        }
      ],
      "total": 12,
      "page": 1,
      "limit": 20
    }

(поля документа те же, здесь сокращены)

--------------------------------------------------------------------------------

## 3. Карточка документа

    GET /spa/api/acknowledgment/documents/{id}

Запрос:

    curl -H "Authorization: Bearer $JWT" \
      http://localhost:8080/spa/api/acknowledgment/documents/1

Ответ `200` — один объект той же формы, что элемент списка из п. 2.

Ошибки:

    404 ack_document_not_found   документа нет или он удалён
    403 access_denied            документ не адресован вам (и вы не DOC_OFFICE)

--------------------------------------------------------------------------------

## 4. Отметка об ознакомлении

    POST /spa/api/acknowledgment/documents/{id}/status

Одна ручка на все три статуса: различаются они значением, а не логикой.

Тело:

    {
      "status": "ACKNOWLEDGED",     // ACKNOWLEDGED | DISAGREED | LATER
      "comment": "Прочитал"          // обязателен только для DISAGREED
    }

Запрос:

    curl -X POST -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
      -d '{"status":"DISAGREED","comment":"Не согласен с пунктом 3"}' \
      http://localhost:8080/spa/api/acknowledgment/documents/2/status

Ответ `200` — карточка документа с проставленной отметкой:

    {
      "id": 2,
      "title": "Адресный документ",
      "myStatus": "DISAGREED",
      "myStatusLabel": "Ознакомлен, не согласен",
      "myComment": "Не согласен с пунктом 3",
      "myActedAt": "2026-09-02T14:49:36"
    }

Ошибки:

    404 ack_document_not_found
    409 ack_document_not_active         черновик или архив
    403 access_denied                   документ вам не адресован
    400 invalid_json
    400 ack_status_invalid              статус не из перечисления
    400 ack_status_comment_required     DISAGREED без комментария
    409 ack_status_already_final        отметка уже стоит и отыграть её нельзя

Две вкладки, нажавшие кнопку одновременно, получат `409` на второй — уникальный
ключ `(document_id, user_id)` ловится и превращается в тот же код.

--------------------------------------------------------------------------------

## 5. Скачивание файла

    GET /spa/api/acknowledgment/documents/{id}/files/{fileId}/download

Файл отдаётся потоком из MinIO через контроллер, а не подписанной ссылкой:
права проверяются на каждом скачивании, и пересланный адрес чужому ничего не
открывает.

    inline    1 — Content-Disposition: inline (открыть в браузере),
              без параметра — attachment (скачать)

Запрос:

    curl -H "Authorization: Bearer $JWT" -OJ \
      http://localhost:8080/spa/api/acknowledgment/documents/1/files/1/download

Ответ `200` — тело файла, заголовки:

    Content-Type: application/pdf
    Content-Length: 284134
    Content-Disposition: attachment; filename="_______ N12.pdf"; filename*=utf-8''%D0%9F%D1%80%D0%B8%D0%BA%D0%B0%D0%B7%20%E2%84%9612.pdf
    Cache-Control: max-age=0, private

Кириллическое имя уезжает в `filename*`, ASCII-заглушка — в `filename`.

Ошибки:

    404 ack_document_not_found
    403 access_denied
    404 ack_file_not_found       нет строки, либо объект пропал из бакета

--------------------------------------------------------------------------------

# ЭНДПОИНТЫ ДЕЛОПРОИЗВОДСТВА

Все требуют `ROLE_DOC_OFFICE` (или `ROLE_ADMIN` по иерархии). Базовый путь
`/spa/api/acknowledgment/admin/documents`.

## 6. Реестр документов

    GET /spa/api/acknowledgment/admin/documents

Единственное место, где видны черновики и архив.

    categoryId        фильтр по категории
    includeArchived   1 — показать и архивные, по умолчанию скрыты
    page              по 20 на страницу

Запрос:

    curl -H "Authorization: Bearer $JWT" \
      "http://localhost:8080/spa/api/acknowledgment/admin/documents?includeArchived=1&page=1"

Ответ `200`:

    {
      "items": [
        {
          "id": 1,
          "title": "Инструкция по охране труда",
          "category": { "id": 1, "name": "Инструкции", "sort": 10 },
          "audience": "ALL",
          "deadline": "2026-09-30",
          "isDraft": false,
          "publishedAt": "2026-09-02T14:48:01",
          "archivedAt": null,
          "filesCount": 1,
          "stats": {
            "total": 1767,
            "done": 1,
            "acknowledged": 1,
            "disagreed": 0,
            "later": 0,
            "pending": 1766
          }
        }
      ],
      "total": 2,
      "page": 1,
      "limit": 20
    }

`stats.total` — знаменатель: для `ALL` это все активные пользователи на текущий
момент, для `SELECTED` — число назначенных. `done` = `acknowledged + disagreed`.
Счётчики по всей странице берутся одним группирующим запросом, не по документу.

Ошибки:

    404 ack_category_not_found   в categoryId передана несуществующая категория

--------------------------------------------------------------------------------

## 7. Создание черновика

    POST /spa/api/acknowledgment/admin/documents

Тело:

    {
      "categoryId": 1,               // обязательно
      "title": "Инструкция по охране труда",   // обязательно
      "description": "Редакция от 01.09.2026", // опционально
      "audience": "ALL",             // ALL (по умолчанию) | SELECTED
      "deadline": "2026-09-30",      // опционально, формат Y-m-d, null — без срока
      "userIds": [2, 17, 34]         // только для SELECTED
    }

Запрос:

    curl -X POST -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
      -d '{"categoryId":1,"title":"Адресный документ","audience":"SELECTED","userIds":[2]}' \
      http://localhost:8080/spa/api/acknowledgment/admin/documents

Ответ `201`:

    {
      "id": 2,
      "title": "Адресный документ",
      "description": null,
      "category": { "id": 1, "name": "Инструкции", "sort": 10 },
      "audience": "SELECTED",
      "deadline": null,
      "isDraft": true,
      "publishedAt": null,
      "archivedAt": null,
      "filesCount": 0,
      "files": [],
      "stats": { "total": 1, "done": 0, "acknowledged": 0, "disagreed": 0, "later": 0, "pending": 1 }
    }

Документ создаётся пустым: файлы прикладываются отдельным запросом (п. 10),
публикация — ещё одним (п. 12). Так и задумано: пока файла нет, публиковать
нечего, и промежуточный черновик никому не виден.

`userIds` при `audience = ALL` игнорируется и уже заведённые назначения
удаляются — «все» назначений не хранит.

Ошибки:

    400 invalid_json
    400 ack_document_title_required
    404 ack_category_not_found            категории нет или categoryId не передан
    400 ack_document_audience_invalid
    400 ack_document_deadline_invalid
    400 ack_document_no_recipients        userIds пришёл не массивом

--------------------------------------------------------------------------------

## 8. Правка

    PATCH /spa/api/acknowledgment/admin/documents/{id}

Тело — те же поля, что при создании; передавать нужно только изменяемые.

Запрос:

    curl -X PATCH -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
      -d '{"title":"Инструкция по охране труда (ред. 2)","deadline":null}' \
      http://localhost:8080/spa/api/acknowledgment/admin/documents/1

Ответ `200` — та же форма, что в п. 7.

У опубликованного документа правятся только `title`, `description`, `deadline`
и `categoryId`. Попытка прислать `audience` или `userIds` даёт:

    409 ack_document_already_published

Остальные ошибки — как в п. 7.

--------------------------------------------------------------------------------

## 9. Загрузка файла

    POST /spa/api/acknowledgment/admin/documents/{id}/files

`multipart/form-data`, поле `file`. Только для черновика.

Ограничения: 25 МБ, тип определяется по содержимому, а не по заголовку запроса.
Белый список: `pdf`, `doc`, `docx`, `xls`, `xlsx`, `jpeg`, `png`. SVG запрещён —
он исполняемый, а отдаём мы файлы тем же людям, что и загружают.

Запрос:

    curl -X POST -H "Authorization: Bearer $JWT" \
      -F "file=@Приказ №12.pdf" \
      http://localhost:8080/spa/api/acknowledgment/admin/documents/1/files

Ответ `201`:

    { "id": 1, "name": "Приказ №12.pdf", "createdAt": "2026-09-02T14:48:00" }

В базе хранится ключ объекта и исходное имя; размер и mime не дублируются —
их отдаёт сам MinIO при скачивании. Имя объекта в бакете случайное: у
делопроизводства половина файлов называется «Приказ.pdf».

Ошибки:

    404 ack_document_not_found
    409 ack_document_already_published    файлы после публикации не меняются
    400 file_not_provided                 нет поля file
    400 ack_file_too_large
    400 ack_file_type_not_allowed

--------------------------------------------------------------------------------

## 10. Удаление файла

    DELETE /spa/api/acknowledgment/admin/documents/{id}/files/{fileId}

Только для черновика. Удаляет и строку, и объект в бакете.

Запрос:

    curl -X DELETE -H "Authorization: Bearer $JWT" \
      http://localhost:8080/spa/api/acknowledgment/admin/documents/1/files/1

Ответ `200`:

    { "success": true }

Ошибки:

    404 ack_document_not_found
    409 ack_document_already_published
    404 ack_file_not_found

--------------------------------------------------------------------------------

## 11. Публикация

    POST /spa/api/acknowledgment/admin/documents/{id}/publish

Момент, с которого документ появляется у сотрудников и растёт их счётчик.

Запрос:

    curl -X POST -H "Authorization: Bearer $JWT" \
      http://localhost:8080/spa/api/acknowledgment/admin/documents/1/publish

Ответ `200` — карточка с заполненным `publishedAt` и `isDraft: false`.

Ошибки:

    404 ack_document_not_found
    409 ack_document_already_published
    409 ack_document_no_files          ознакомление без документа — это кнопка
    409 ack_document_no_recipients     SELECTED, а назначенных ноль

Уведомлений при публикации не рассылается — ни в колокольчик, ни на почту.
Обязанность доносит счётчик неотмеченных: он висит, пока человек не нажал
кнопку, тогда как уведомление гаснет от прочтения, а обязанность оставляет.

--------------------------------------------------------------------------------

## 12. Архивирование

    POST /spa/api/acknowledgment/admin/documents/{id}/archive

«Документ утратил силу»: исчезает из списков и счётчиков сотрудников, остаётся
в реестре и в отчётах. Разархивирования нет.

Запрос:

    curl -X POST -H "Authorization: Bearer $JWT" \
      http://localhost:8080/spa/api/acknowledgment/admin/documents/1/archive

Ответ `200` — карточка с заполненным `archivedAt`.

Ошибки:

    404 ack_document_not_found
    409 ack_document_not_active     черновик или уже в архиве

--------------------------------------------------------------------------------

## 13. Удаление

    DELETE /spa/api/acknowledgment/admin/documents/{id}

Только черновик. Опубликованный документ архивируется, а не удаляется, иначе из
отчётов пропали бы уже собранные ознакомления.

Удаление мягкое (`deleted_at`), объекты в бакете при этом остаются: они никому
не мешают, а вот строка, ссылающаяся в пустоту, ломала бы скачивание молча.

Запрос:

    curl -X DELETE -H "Authorization: Bearer $JWT" \
      http://localhost:8080/spa/api/acknowledgment/admin/documents/3

Ответ `200`:

    { "success": true }

Ошибки:

    404 ack_document_not_found
    409 ack_document_already_published

--------------------------------------------------------------------------------

## 14. Отчёт по документу

    GET /spa/api/acknowledgment/admin/documents/{id}/report

Кто ознакомился, кто возразил, кто молчит.

    filter    ACKNOWLEDGED | DISAGREED | LATER | pending
              `pending` — отметки нет вовсе. Без параметра — вся аудитория
    page      по 50 на страницу

Запрос:

    curl -H "Authorization: Bearer $JWT" \
      "http://localhost:8080/spa/api/acknowledgment/admin/documents/1/report?filter=pending&page=1"

Ответ `200`:

    {
      "document": {
        "id": 1,
        "title": "Инструкция по охране труда",
        "category": { "id": 1, "name": "Инструкции", "sort": 10 },
        "audience": "ALL",
        "deadline": "2026-09-30",
        "isDraft": false,
        "publishedAt": "2026-09-02T14:48:01",
        "archivedAt": null,
        "filesCount": 1,
        "stats": {
          "total": 1767, "done": 1, "acknowledged": 1,
          "disagreed": 0, "later": 0, "pending": 1766
        }
      },
      "items": [
        {
          "userId": 2,
          "name": "Камойленко Анатолий Петрович",
          "status": null,
          "statusLabel": null,
          "comment": null,
          "actedAt": null
        },
        {
          "userId": 17,
          "name": "Есков Сергей Владимирович",
          "status": "DISAGREED",
          "statusLabel": "Ознакомлен, не согласен",
          "comment": "Не согласен с пунктом 3",
          "actedAt": "2026-09-02T14:49:36"
        }
      ],
      "total": 1766,
      "page": 1,
      "limit": 50
    }

Список идёт от пользователей, а не от отметок: при `audience = ALL` строки
отметки у большинства просто нет. Отдаются скаляры, а не сущности — у `User`
обратная сторона `OneToOne` на `Worker`, и каждая гидрация человека стоила бы
отдельного `SELECT` (полсотни лишних запросов на страницу отчёта).

Должность в отчёте не выводится по той же причине. Понадобится — тянуть её
надо отдельным запросом по списку id, а не через `getWorker()` в цикле.

Ошибки:

    404 ack_document_not_found

--------------------------------------------------------------------------------

# КАТЕГОРИИ

Категория у документа обязательна: поиска в модуле нет, и категории — это
единственная навигация по разделу. Поле `sort` задаёт порядок в списке, по `id`
он был бы порядком заведения.

## 15. Список

    GET /spa/api/acknowledgment/categories

Доступен любому авторизованному: список нужен фильтру на экране «мои документы».

Ответ `200` (отсортирован по `sort`, затем по имени):

    {
      "items": [
        { "id": 1, "name": "Инструкции", "sort": 10 },
        { "id": 2, "name": "Приказы",    "sort": 20 }
      ]
    }

## 16. Создание

    POST /spa/api/acknowledgment/categories

Требует `ROLE_DOC_OFFICE`. Тело:

    { "name": "Приказы", "sort": 20 }

Запрос:

    curl -X POST -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
      -d '{"name":"Приказы","sort":20}' \
      http://localhost:8080/spa/api/acknowledgment/categories

Ответ `201`:

    { "id": 2, "name": "Приказы", "sort": 20 }

Ошибки:

    403 access_denied
    400 invalid_json
    400 ack_category_name_required
    409 ack_category_name_taken

## 17. Изменение

    PUT /spa/api/acknowledgment/categories/{id}

Тело: `name` обязателен, `sort` опционален.

    curl -X PUT -H "Authorization: Bearer $JWT" -H "Content-Type: application/json" \
      -d '{"name":"Приказы и распоряжения","sort":15}' \
      http://localhost:8080/spa/api/acknowledgment/categories/2

Ответ `200`:

    { "id": 2, "name": "Приказы и распоряжения", "sort": 15 }

Ошибки: те же, что в п. 16, плюс `404 ack_category_not_found`.

## 18. Удаление

    DELETE /spa/api/acknowledgment/categories/{id}

Мягкое. Категорию с документами удалить нельзя: связь стоит на `RESTRICT`, и без
проверки запрос падал бы на уровне базы.

    curl -X DELETE -H "Authorization: Bearer $JWT" \
      http://localhost:8080/spa/api/acknowledgment/categories/2

Ответ `200`:

    { "success": true }

Ошибки:

    403 access_denied
    404 ack_category_not_found
    409 ack_category_has_documents

--------------------------------------------------------------------------------

# СХЕМА БАЗЫ

    ack_category         id, name (уникально), sort, deleted_at

    ack_document         id, category_id → ack_category (RESTRICT),
                         author_id → user (SET NULL), title, description,
                         audience ('ALL' | 'SELECTED'), deadline (date),
                         published_at, archived_at, created_at, updated_at,
                         deleted_at
                         индекс (deleted_at, archived_at, published_at)

    ack_document_file    id, document_id → ack_document (CASCADE),
                         storage_key, original_name, created_at

    ack_document_user    id, document_id → ack_document (CASCADE),
                         user_id → user (CASCADE),
                         status (null | ACKNOWLEDGED | DISAGREED | LATER),
                         comment, acted_at, created_at
                         уникально (document_id, user_id)
                         индексы (document_id, status), (user_id, status)

Мягкое удаление у `ack_category` и `ack_document` — через фильтр `softdeleteable`,
он включён глобально, поэтому условий на `deleted_at` в репозиториях нет.

Doctrine не генерирует `CHECK`, их дописывают в миграцию руками:

    ALTER TABLE ack_document ADD CONSTRAINT chk_ack_document_audience
        CHECK (audience IN ('ALL', 'SELECTED'));
    ALTER TABLE ack_document_user ADD CONSTRAINT chk_ack_document_user_status
        CHECK (status IS NULL OR status IN ('ACKNOWLEDGED', 'DISAGREED', 'LATER'));
    ALTER TABLE ack_document_user ADD CONSTRAINT chk_ack_document_user_disagree_comment
        CHECK (status <> 'DISAGREED' OR comment IS NOT NULL);

Приложение эти правила и так проверяет; констрейнты нужны на случай правок мимо
него — руками в базе, импортом, будущей командой.

Одноколоночные индексы под внешние ключи (`IDX_…` на `document_id` и `user_id`)
дублируют составные и удалять их не нужно: Doctrine генерирует их из `JoinColumn`
и следующий `diff` вернёт обратно.

--------------------------------------------------------------------------------

# ХРАНИЛИЩЕ ФАЙЛОВ

Свой бакет MinIO, как у канбана, аватаров, инвентаризации и закупок:

    MINIO_ACKNOWLEDGMENT_BUCKET=acknowledgment

Ключ объекта: `{id документа}/{32 hex}.{расширение}`. Порядок операций при
загрузке — сначала объект, потом строка в базе: при обратном сбой оставил бы
строку, ссылающуюся в пустоту, а так худшее — осиротевший объект.

Создание бакета:

    docker run -it --rm --network=project-net --entrypoint sh minio/mc \
      -c "mc alias set myminio http://minio:9000 minioadmin minioadmin \
          && mc mb --ignore-existing myminio/acknowledgment && mc ls myminio"

--------------------------------------------------------------------------------

# РАЗВЁРТЫВАНИЕ

1. Миграция (таблицы `ack_*`) плюс три `CHECK` руками.
2. Строка `ROLE_DOC_OFFICE` в таблице `role` и выдача роли делопроизводству.
3. `MINIO_ACKNOWLEDGMENT_BUCKET` в окружении и сам бакет в MinIO.

Внешних сервисов модуль не требует: ни очередей, ни сервиса уведомлений, ни
Mercure.

--------------------------------------------------------------------------------

# ЧЕГО В МОДУЛЕ НЕТ

Перечислено, чтобы не искать: это решения, а не недоделки.

    Уведомления            вместо них счётчик неотмеченных
    Версии документа       переиздание — это новый документ, старый в архив
    Отделы как адресат     только «все» либо поимённо
    Напоминания о дедлайне ни писем, ни фоновых задач
    Электронная подпись    юридической силы у отметки нет
    История смены статусов хранится последнее состояние
    Поиск по документам    навигация через категории
    Выгрузка отчёта в файл таблица на экране плюс печать браузером

--------------------------------------------------------------------------------

# ПРОВЕРКА

Тест `tests/Repository/Acknowledgment/AckQueryCompilesTest.php` — 9 проверок,
живой базы не требует: компилирует запросы модуля в SQL и отдельно стережёт две
вещи, ломающиеся молча.

Первая — условие в `LEFT JOIN` должно оставаться в `ON`. В `WHERE` он схлопнется
в `INNER`, и документы «для всех» пропадут у тех, кто их ещё не открывал, то
есть ровно у тех, кому они нужны.

Вторая — пустой статус обязан считаться «не ознакомлен» наравне с отсутствием
строки: строка со `status = NULL` остаётся, если черновик заводили адресным, а
опубликовали на всех.

    docker compose exec php php -d memory_limit=512M \
      vendor/bin/phpunit tests/Repository/Acknowledgment/AckQueryCompilesTest.php

Весь phpunit прогонять нельзя: `LoginControllerTest` падает независимо от этого
модуля.
