================================================================================
SPA API — Financial Analytics
================================================================================

Эндпоинт для получения финансовых отчётов в виде дерева метрик по неделям.
Никакой агрегации/расчётов на лету — отдаются ровно те значения, которые
бухгалтер ввёл в подтверждённых отчётах, в иерархии, заданной составом
версии доски (`analytics_board_version_metrics.parent_id`).

--------------------------------------------------------------------------------
1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/finance/reports

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

Примеры URL:

  // последние 12 недель организации 20
  /spa/api/analytics/finance/reports?org_id=20

  // следующая страница (предыдущие 12 недель)
  /spa/api/analytics/finance/reports?org_id=20&offset=12

  // апрель–май 2026, все недели в диапазоне
  /spa/api/analytics/finance/reports?org_id=20&from=2026-04-01&to=2026-05-31&limit=100

  // только одна последняя неделя
  /spa/api/analytics/finance/reports?org_id=20&limit=1

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/finance/reports?org_id=20&from=2026-04-01"

fetch:

  const params = new URLSearchParams({
    org_id: '20',
    from:   '2026-04-01',
    to:     '2026-05-31',
    limit:  '12',
    offset: '0',
  });
  const res = await fetch(
    `/spa/api/analytics/finance/reports?${params}`,
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

Имя поля `weeks` отражает текущую конфигурацию финансовой доски (weekly):
startDate — понедельник, endDate — воскресенье ISO-недели. Если в будущем
финансовая доска будет переведена на daily/monthly, формат ответа не
меняется: одна запись = один analytics_periods, поля те же.

Недели в ответе отсортированы по startDate по возрастанию (от старой к
свежей). При этом пагинация (limit/offset) работает «от свежих к старым»:
limit=12, offset=0 — последние 12 недель; offset=12 — следующая страница
с предыдущими 12 неделями. Внутри одной страницы порядок всегда ASC.
В выборку попадают только отчёты со статусом `confirmed`.

Узел дерева MetricNode:

{
  "metric_key":  string,         // business_key метрики (finance_debit, ...)
  "name":        string,         // отображаемое имя метрики
  "unit":        string|null,    // единица измерения (например "руб.")
  "valueNumber": number|null,    // числовое значение; null = не заполнено
  "valueJSON":   any|null,       // структурированные пояснения; null если пусто
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
- `valueJSON` декодируется из jsonb в JSON-значение (обычно массив-дерево
  пояснений). Если пояснений нет — `null`.
- Категория метрик жёстко зафиксирована в эндпоинте как `finance`.

--------------------------------------------------------------------------------
3. ПРИМЕР ОТВЕТА (сокращённый)
--------------------------------------------------------------------------------

GET /spa/api/analytics/finance/reports?org_id=20

{
  "weeks": [
    {
      "startDate": "2026-04-20",
      "endDate":   "2026-04-26",
      "reports": [
        {
          "metric_key": "finance_debit",
          "name":       "Дебиторская задолженность",
          "unit":       "руб.",
          "valueNumber": 472148964.79,
          "valueJSON":   null,
          "children": [
            {
              "metric_key": "finance_debit_budget_organization",
              "name":       "Бюджетные организации заказчики услуг",
              "unit":       "руб.",
              "valueNumber": 9665613.81,
              "valueJSON":   null,
              "children":    []
            },
            {
              "metric_key": "finance_debit_people",
              "name":       "Население",
              "unit":       "руб.",
              "valueNumber": 360565441.81,
              "valueJSON":   null,
              "children":    []
            },
            {
              "metric_key": "finance_debit_other_buyers",
              "name":       "Прочие покупатели",
              "unit":       "руб.",
              "valueNumber": null,
              "valueJSON":   null,
              "children":    []
            }
            // ↑ пример метрики без заполненного значения: узел присутствует
            //   в дереве, но valueNumber = null. Фронт показывает прочерк.
            // ... finance_debit_individual, finance_debit_legal,
            //     finance_debit_advances_paid
          ]
        },
        {
          "metric_key": "finance_credit",
          "name":       "Кредиторская задолженность",
          "unit":       "руб.",
          "valueNumber": 296139799.60,
          "valueJSON":   null,
          "children": [
            {
              "metric_key": "finance_credit_advances_received",
              "name":       "Авансы, внесенные покупателями",
              "unit":       "руб.",
              "valueNumber": 66279652.99,
              "valueJSON":   null,
              "children":    []
            },
            {
              "metric_key": "finance_credit_suppliers",
              "name":       "Задолженность перед поставщиками",
              "unit":       "руб.",
              "valueNumber": 213254268.95,
              "valueJSON":   null,
              "children":    []
            }
          ]
        },
        {
          "metric_key": "finance_cash",
          "name":       "Остатки на расчётных счетах",
          "unit":       "руб.",
          "valueNumber": 64831462.26,
          "valueJSON":   null,
          "children": [
            {
              "metric_key": "finance_cash_main",
              "name":       "на основном счёте",
              "unit":       "руб.",
              "valueNumber": 1939610.31,
              "valueJSON":   null,
              "children":    []
            },
            // ... finance_cash_yasinovataya_mo, finance_cash_card,
            //     finance_cash_branches_tko, finance_cash_landfills,
            //     finance_cash_road_service
            {
              "metric_key": "finance_cash_branches",
              "name":       "на счетах, открытых филиалами самостоятельно",
              "unit":       "руб.",
              "valueNumber": 42395042.13,
              "valueJSON":   null,
              "children": [
                {
                  "metric_key": "finance_cash_branches_donetsk",
                  "name":       "Донецкий филиал",
                  "unit":       "руб.",
                  "valueNumber": 3149372.80,
                  "valueJSON":   null,
                  "children":    []
                }
                // ... mariupol, makeevka, shakhtersk, gorlovka,
                //     enakievo, amvrosievka
              ]
            }
          ]
        }
      ]
    },
    {
      "startDate": "2026-04-27",
      "endDate":   "2026-05-03",
      "reports":   [ /* такая же структура с другими значениями */ ]
    }
    // ... остальные недели
  ]
}

--------------------------------------------------------------------------------
4. ПРИМЕР ПУСТОГО ОТВЕТА
--------------------------------------------------------------------------------

Если по запрошенным организациям нет ни одного `confirmed`-отчёта —
HTTP 200 с пустым массивом недель:

{
  "weeks": []
}

--------------------------------------------------------------------------------
5. КРАЕВЫЕ СЛУЧАИ
--------------------------------------------------------------------------------

- В выборке только подтверждённые отчёты (`status = 'confirmed'`).
  Черновики (`draft`) **не отдаются** — они не должны влиять на аналитику.

- Метрики, не заполненные в отчёте, всё равно присутствуют в дереве
  с `valueNumber: null`. Фронт сам решает: показывать прочерк, скрывать
  узел или выделять как «незаполнено».

- `valueJSON` обычно null для финансовых метрик; используется только если
  бухгалтер добавил пояснения в форме (структурированный комментарий).

- Иерархия живёт только в визуальном представлении формы и таблиц.
  Никаких автосумм `parent = Σ children` нет: родительские значения
  бухгалтер вводит вручную, и они могут расходиться с суммой детей
  (например, из-за округлений или потому что детализация неполная).

- Сортировка узлов на одном уровне — строго по `position`. Изменение
  состава версии доски (`/analytics/admin/board/...`) меняет порядок
  отдачи без переразвёртывания клиента.

- Эндпоинт возвращает **только** финансовые метрики (category = 'finance').
  Для других категорий (hr, tko, ...) предусмотрены отдельные эндпоинты.

- При указании одновременно from/to и limit/offset фильтр диапазона
  применяется первым, затем уже работает пагинация по отфильтрованным
  неделям.

- Невалидные значения query-параметров (например, from=invalid) не
  возвращают ошибку — они просто игнорируются, чтобы клиент мог
  безопасно строить URL.

--------------------------------------------------------------------------------
6. СПИСОК ОТЧЁТОВ (без метрик)
--------------------------------------------------------------------------------

Плоский список подтверждённых финансовых отчётов — для экрана-индекса
на фронте. Дерева метрик в ответе нет; за детализацией клиент обращается
к основному эндпоинту /spa/api/analytics/finance/reports выше.

Доска определяется по analytics_boards.category = 'finance' (одна доска
на категорию). В выборку попадают только отчёты со status = 'confirmed'.

  Method : GET
  URL    : /spa/api/analytics/finance/reports/list

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

  /spa/api/analytics/finance/reports/list?org_id=20&page=1&per_page=20
  /spa/api/analytics/finance/reports/list?from=2026-04-01&to=2026-05-31&per_page=50

Формат ответа:

{
  "items": [
    {
      "id":             123,
      "boardId":        2,
      "boardVersionId": 5,
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
7. СВЯЗАННЫЕ ФАЙЛЫ
--------------------------------------------------------------------------------

  Контроллер : src/Controller/SpaApi/Analytics/FinancialAnalyticsController.php
               методы financeReports() и reportsList()
  Сервис     : src/Service/Analytics/FinanceReportTreeService.php
               методы buildWeeks() и getAllReports()
  Репозитории: src/Repository/Analytics/AnalyticsReportValueRepository.php
               (findReportsWithMetricTree — общий с HR и обращениями граждан,
                используется для дерева метрик)
               src/Repository/Analytics/AnalyticsReportRepository.php
               (findConfirmedListByCategory — плоский список для /list)
  HR         : dev_docks/spa_api/Readme_hr_analytics.txt
  Обращения  : dev_docks/spa_api/Readme_citizen_appeal.txt
  Auth       : dev_docks/spa_api/Readme_spa_auth.txt
