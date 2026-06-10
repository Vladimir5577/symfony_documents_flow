================================================================================
SPA API — Citizen Appeals Analytics
================================================================================

Эндпоинт для получения отчётов «Обращение граждан» в виде дерева метрик
по неделям. Никакой агрегации/расчётов на лету — отдаются ровно те значения,
которые оператор ввёл в подтверждённых отчётах, в иерархии, заданной составом
версии доски (`analytics_board_version_metrics.parent_id`).

Контракт и формат — общий с финансовой аналитикой
(см. Readme_financial_analytics.txt); отличается только категория метрик
(`citizen_appeal`) и состав дерева.

--------------------------------------------------------------------------------
1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/citizen-appeals/reports

Query-параметры:

  org_id  (int, опционально, по умолчанию 0)
            ID родительской организации.
            0 = все видимые родительские организации (объединение их
                самих и всех дочерних);
            N = конкретная организация N + все её дочерние.

  from    (string YYYY-MM-DD, опционально)
            Нижняя граница периода: возвращаются только недели,
            у которых analytics_periods.start_date >= from.
            Невалидное значение (не подходящее под YYYY-MM-DD)
            игнорируется без ошибки.

  to      (string YYYY-MM-DD, опционально)
            Верхняя граница периода: analytics_periods.end_date <= to.
            Невалидное значение игнорируется.

  limit   (int, опционально, по умолчанию 12, максимум 100)
            Сколько недель вернуть. limit <= 0 → 12. limit > 100 → 100.
            Пагинация — по неделям, а не по строкам метрик.

  offset  (int, опционально, по умолчанию 0)
            Сдвиг по неделям. offset < 0 → 0.

Авторизация: JWT в заголовке Authorization (как у всех /spa/api/* маршрутов).
См. dev_docks/spa_api/Readme_spa_auth.txt.

Примеры URL:

  // последние 12 недель организации 20
  /spa/api/analytics/citizen-appeals/reports?org_id=20

  // следующая страница (предыдущие 12 недель)
  /spa/api/analytics/citizen-appeals/reports?org_id=20&offset=12

  // апрель–май 2026, все недели в диапазоне
  /spa/api/analytics/citizen-appeals/reports?org_id=20&from=2026-04-01&to=2026-05-31&limit=100

  // только одна последняя неделя
  /spa/api/analytics/citizen-appeals/reports?org_id=20&limit=1

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/citizen-appeals/reports?org_id=20&from=2026-04-01"

fetch:

  const params = new URLSearchParams({
    org_id: '20',
    from:   '2026-04-01',
    to:     '2026-05-31',
    limit:  '12',
    offset: '0',
  });
  const res = await fetch(
    `/spa/api/analytics/citizen-appeals/reports?${params}`,
    { headers: { Authorization: "Bearer " + jwt } }
  );
  const data = await res.json();

--------------------------------------------------------------------------------
2. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

Корневой объект:

{
  "weeks": [
    {
      "startDate": "YYYY-MM-DD",   // analytics_periods.start_date
      "endDate":   "YYYY-MM-DD",   // analytics_periods.end_date
      "reports":   MetricNode[]    // дерево метрик за этот период
    },
    ...
  ]
}

Имя поля `weeks` отражает текущую конфигурацию доски «Обращение граждан»
(weekly): startDate — понедельник, endDate — воскресенье ISO-недели.
Если в будущем доска будет переведена на daily/monthly, формат ответа не
меняется: одна запись = один analytics_periods, поля те же.

Недели в ответе отсортированы по startDate по возрастанию (от старой к
свежей). Пагинация (limit/offset) работает «от свежих к старым»: limit=12,
offset=0 — последние 12 недель; offset=12 — следующая страница с предыдущими
12 неделями. Внутри одной страницы порядок всегда ASC.
В выборку попадают только отчёты со статусом `confirmed`.

Узел дерева MetricNode:

{
  "metric_key":  string,         // business_key метрики (citizen_appeal_*)
  "name":        string,         // отображаемое имя метрики
  "unit":        string|null,    // единица измерения (обычно "кол-во")
  "valueNumber": number|null,    // числовое значение; null = не заполнено
  "valueJSON":   any|null,       // расшифровка по подкатегориям; null если пусто
  "children":    MetricNode[]    // подметрики; [] если нет детей
}

Правила формирования:

- В каждой неделе возвращается **полный** набор метрик из версии доски,
  привязанной к отчёту, — независимо от того, заполнено значение или нет.
  Метрики без заполненного значения отдают `valueNumber: null` и
  `valueJSON: null`. Это позволяет фронту нарисовать полный каркас формы
  и проставить прочерк там, где значения нет.
- Порядок узлов на одном уровне — по `analytics_board_version_metrics.position`
  по возрастанию.
- Иерархия строится по `parent_id`: корнями являются метрики с `parent_id = NULL`.
- `valueJSON` декодируется из jsonb в JSON-значение — массив объектов вида
  `[{ "key": string, "value": string, "children": [...] }]`. Используется для
  расшифровки общего числа обращений по подкатегориям (например, «Перерасчет»,
  «По коррект. пропис.»). Если пояснений нет — `null`.
- Категория метрик жёстко зафиксирована в эндпоинте как `citizen_appeal`.

--------------------------------------------------------------------------------
3. СТРУКТУРА ДЕРЕВА
--------------------------------------------------------------------------------

Текущая версия доски «Обращение граждан» содержит 16 метрик
(`analytics_metrics.category = 'citizen_appeal'`) в двух корневых узлах:

  Обращения — Итого           (citizen_appeal_public_request_total)
    ├─ Горловка г.о.          (citizen_appeal_public_request_gorlovka)
    ├─ Донецк г.о.            (citizen_appeal_public_request_donetsk)
    ├─ Енакиево г.о.          (citizen_appeal_public_request_enakievo)
    ├─ Макеевка г.о.          (citizen_appeal_public_request_makeevka)
    ├─ Шахтёрск г.о.          (citizen_appeal_public_request_shakhtersk)
    ├─ Мариуполь г.о.         (citizen_appeal_public_request_mariupol)
    └─ Ясиноватая             (citizen_appeal_public_request_yasinovataya)

  Звонки — Итого              (citizen_appeal_calls_total)
    ├─ Горловка г.о.          (citizen_appeal_calls_gorlovka)
    ├─ Донецк г.о.            (citizen_appeal_calls_donetsk)
    ├─ Енакиево г.о.          (citizen_appeal_calls_enakievo)
    ├─ Макеевка г.о.          (citizen_appeal_calls_makeevka)
    ├─ Шахтёрск г.о.          (citizen_appeal_calls_shakhtersk)
    ├─ Мариуполь г.о.         (citizen_appeal_calls_mariupol)
    └─ Ясиноватая             (citizen_appeal_calls_yasinovataya)

Все метрики имеют:
  type             = number
  unit             = кол-во
  aggregation_type = sum
  is_active        = true

В справочнике `analytics_metrics` дополнительно есть Амвросиевка
(citizen_appeal_public_request_amvrosievka, citizen_appeal_calls_amvrosievka) —
к текущей версии доски она не привязана и в ответе не появится. Состав
дерева определяется составом версии доски, к которой привязан отчёт, поэтому
любые правки в админке (`/analytics/admin/board/...`) сразу отражаются на
ответе без перевыкатки клиента.

--------------------------------------------------------------------------------
4. ПРИМЕР ОТВЕТА (сокращённый)
--------------------------------------------------------------------------------

GET /spa/api/analytics/citizen-appeals/reports?org_id=20

{
  "weeks": [
    {
      "startDate": "2026-05-04",
      "endDate":   "2026-05-10",
      "reports": [
        {
          "metric_key": "citizen_appeal_public_request_total",
          "name":       "Обращения — Итого",
          "unit":       "кол-во",
          "valueNumber": 551,
          "valueJSON":   null,
          "children": [
            {
              "metric_key": "citizen_appeal_public_request_gorlovka",
              "name":       "Обращения — Горловка г.о.",
              "unit":       "кол-во",
              "valueNumber": 35,
              "valueJSON":   null,
              "children":    []
            },
            {
              "metric_key": "citizen_appeal_public_request_donetsk",
              "name":       "Обращения — Донецк г.о.",
              "unit":       "кол-во",
              "valueNumber": 305,
              "valueJSON": [
                { "key": "Перерасчет", "value": "125", "children": [] }
              ],
              "children":    []
            },
            // ... enakievo, makeevka, shakhtersk, mariupol
            {
              "metric_key": "citizen_appeal_public_request_yasinovataya",
              "name":       "Обращения — Ясиноватая",
              "unit":       "кол-во",
              "valueNumber": 159,
              "valueJSON": [
                { "key": "По коррект. пропис.", "value": "151", "children": [] }
              ],
              "children":    []
            }
          ]
        },
        {
          "metric_key": "citizen_appeal_calls_total",
          "name":       "Звонки — Итого",
          "unit":       "кол-во",
          "valueNumber": 778,
          "valueJSON":   null,
          "children": [
            {
              "metric_key": "citizen_appeal_calls_gorlovka",
              "name":       "Звонки — Горловка г.о.",
              "unit":       "кол-во",
              "valueNumber": 60,
              "valueJSON":   null,
              "children":    []
            }
            // ... donetsk, enakievo, makeevka, shakhtersk, mariupol, yasinovataya
          ]
        }
      ]
    },
    {
      "startDate": "2026-05-11",
      "endDate":   "2026-05-17",
      "reports":   [ /* такая же структура с другими значениями */ ]
    }
    // ... остальные недели
  ]
}

--------------------------------------------------------------------------------
5. ПРИМЕР ПУСТОГО ОТВЕТА
--------------------------------------------------------------------------------

Если по запрошенным организациям нет ни одного `confirmed`-отчёта —
HTTP 200 с пустым массивом недель:

{
  "weeks": []
}

--------------------------------------------------------------------------------
6. КРАЕВЫЕ СЛУЧАИ
--------------------------------------------------------------------------------

- В выборке только подтверждённые отчёты (`status = 'confirmed'`).
  Черновики (`draft`) **не отдаются** — они не должны влиять на аналитику.

- Метрики, не заполненные в отчёте, всё равно присутствуют в дереве
  с `valueNumber: null`. Фронт сам решает: показывать прочерк, скрывать
  узел или выделять как «незаполнено».

- `valueJSON` — массив объектов с расшифровкой общего числа по
  подкатегориям (например, сколько из всех обращений Донецка — «Перерасчет»).
  Не используется для всех метрик; для большинства строк остаётся `null`.

- Иерархия живёт только в визуальном представлении формы и таблиц.
  Никаких автосумм `parent = Σ children` нет: значения «Итого» оператор
  вводит вручную, и они могут расходиться с суммой детей.

- Сортировка узлов на одном уровне — строго по `position`. Изменение
  состава версии доски (`/analytics/admin/board/...`) меняет порядок
  отдачи без переразвёртывания клиента.

- Состав городов и сам факт наличия раздела (например, наличие
  Амвросиевки) определяется составом версии доски, привязанной к отчёту,
  а не справочником `analytics_metrics`. Метрика в справочнике без
  привязки к версии в ответе не появится.

- Эндпоинт возвращает **только** метрики обращений граждан
  (category = 'citizen_appeal'). Для других категорий (finance, hr, tko, ...)
  предусмотрены отдельные эндпоинты.

- При указании одновременно from/to и limit/offset фильтр диапазона
  применяется первым, затем уже работает пагинация по отфильтрованным
  неделям.

- Невалидные значения query-параметров (например, from=invalid) не
  возвращают ошибку — они просто игнорируются, чтобы клиент мог
  безопасно строить URL.

--------------------------------------------------------------------------------
7. СПИСОК ОТЧЁТОВ (без метрик)
--------------------------------------------------------------------------------

Плоский список подтверждённых отчётов «Обращение граждан» — для экрана-индекса
на фронте. Дерева метрик в ответе нет; за детализацией клиент обращается
к основному эндпоинту /spa/api/analytics/citizen-appeals/reports выше.

Доска определяется по analytics_boards.category = 'citizen_appeal' (одна
доска на категорию). В выборку попадают только отчёты со status = 'confirmed'.

  Method : GET
  URL    : /spa/api/analytics/citizen-appeals/reports/list

Query-параметры:

  org_id    (int, опц., default 0)        — та же логика, что в /reports.
  from      (YYYY-MM-DD, опц.)            — analytics_periods.start_date >= from.
  to        (YYYY-MM-DD, опц.)            — analytics_periods.end_date <= to.
  page      (int, опц., default 1)        — номер страницы, начиная с 1.
                                            page < 1 → 1.
  per_page  (int, опц., default 20, max 100)
                                          — кол-во отчётов на страницу.
                                            per_page < 1 → 20, > 100 → 100.

Невалидные значения дат и пагинации игнорируются/нормализуются без ошибки.

Пример URL:

  /spa/api/analytics/citizen-appeals/reports/list?org_id=20&page=1&per_page=20
  /spa/api/analytics/citizen-appeals/reports/list?from=2026-04-01&to=2026-05-31&per_page=50

Формат ответа:

{
  "items": [
    {
      "id":             123,
      "boardId":        3,
      "boardVersionId": 6,
      "organization":   { "id": 20, "name": "Донецкий филиал" },
      "period":         { "startDate": "2026-05-18", "endDate": "2026-05-24" },
      "status":         "confirmed",
      "createdAt":      "2026-05-25 10:11:12",
      "updatedAt":      "2026-05-25 10:11:12"
    }
  ],
  "page":    1,
  "perPage": 20,
  "total":   137
}

Сортировка: period.start_date DESC, id DESC (свежие сверху).
Пустая выборка — HTTP 200 с `items: []` и `total: 0`.

--------------------------------------------------------------------------------
8. СВЯЗАННЫЕ ФАЙЛЫ
--------------------------------------------------------------------------------

  Контроллер : src/Controller/SpaApi/Analytics/CitizenAppealsAnalyticsController.php
               методы reports() и reportsList()
  Сервис     : src/Service/Analytics/CitizenAppealsReportTreeService.php
               методы buildWeeks() и getAllReports()
  Репозитории: src/Repository/Analytics/AnalyticsReportValueRepository.php
               (findReportsWithMetricTree — общий с финансовой аналитикой и HR,
                используется для дерева метрик)
               src/Repository/Analytics/AnalyticsReportRepository.php
               (findConfirmedListByCategory — плоский список для /list)
  Финансы    : dev_docks/spa_api/Readme_financial_analytics.txt
  HR         : dev_docks/spa_api/Readme_hr_analytics.txt
  Auth       : dev_docks/spa_api/Readme_spa_auth.txt
