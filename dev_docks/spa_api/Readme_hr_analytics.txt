================================================================================
SPA API — HR Analytics (Dashboard)
================================================================================

Эндпоинт для получения данных HR-дашборда (вкладка "Отдел кадров"):
KPI по численности и движению персонала, ряды по периодам, таблица
сравнения по организациям.

--------------------------------------------------------------------------------
1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/hr/dashboard/data

Query-параметры:

  org_id  (int, опционально, по умолчанию 0)
            ID родительской организации.
            0 = все видимые родительские организации (объединение их
                самих и всех дочерних);
            N = конкретная организация N + все её дочерние.

  scale   (string, опционально, по умолчанию "month")
            Гранулярность временной оси. Допустимые значения:
              "month" — помесячная агрегация;
              "week"  — понедельная (ISO-неделя) агрегация.
            Любое другое значение приводится к "month" без ошибки.

Авторизация:
  JWT в заголовке Authorization (как у всех /spa/api/*).
  См. dev_docks/spa_api/Readme_spa_auth.txt.

Примеры URL:

  /spa/api/analytics/hr/dashboard/data?org_id=0&scale=week
  /spa/api/analytics/hr/dashboard/data?org_id=20&scale=month

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/hr/dashboard/data?org_id=0&scale=week"

fetch:

  const res = await fetch(
    "/spa/api/analytics/hr/dashboard/data?org_id=0&scale=week",
    {
      headers: { Authorization: `Bearer ${token}` },
      credentials: "include"
    }
  );
  const data = await res.json();

--------------------------------------------------------------------------------
2. ИСТОЧНИК ДАННЫХ
--------------------------------------------------------------------------------

Используются 4 бизнес-ключа из analytics_metrics:

  actual_number_of_employees   Общее количество работников          чел.
  hired_employees              Принято сотрудников                  чел.
  fired_employees              Уволено сотрудников                  чел.
  staff_fill_rate              Штат укомплектован на, %             %

Понедельные строки analytics_aggregated_data сворачиваются в выбранный
масштаб (month/week) на лету. Правила свёртки:

  Потоковые (SUM-keys) — суммируются по периодам:
    hired_employees, fired_employees

  Остаточные (AVG-keys) — усредняются по периодам:
    actual_number_of_employees, staff_fill_rate

KPI «headcount» и «fillRatePct» дополнительно берутся как последнее
ненулевое значение соответствующего ряда (snapshot последнего периода).

--------------------------------------------------------------------------------
3. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

Корневой объект:

{
  "scale":   "week" | "month",   // эхо запрошенного scale (после нормализации)
  "labels":  string[],           // подписи периодов оси X
  "hr":      { ... },            // KPI + ряды
  "compare": { ... }             // таблица сравнения по организациям
}

Длина каждого массива в hr.series совпадает с длиной labels: i-й элемент
ряда — значение за i-й период. Пропущенные периоды заполняются нулями.

Подписи периодов (labels):

  scale = "month":
    "Янв", "Фев", "Мар", ... "Дек"
    При нескольких годах в выборке: "Янв '26"

  scale = "week":
    Диапазон дат недели "DD.MM–DD.MM": "07.04–13.04"
    При нескольких годах: "07.04–13.04'26"

--- hr -------------------------------------------------------------------------

{
  "kpis": {
    "headcount":    number,   // фактическая числ-ть, последнее ненулевое
    "fillRatePct":  number,   // укомплектованность %, последнее ненулевое, до 0.1
    "hired":        number,   // принято — сумма по всем точкам labels
    "fired":        number,   // уволено — сумма по всем точкам labels
    "netChange":    number,   // hired − fired
    "turnoverPct":  number    // fired / headcount * 100, до 0.1
  },
  "series": {
    "headcount":    number[],  // фактическая числ-ть по периодам (целые)
    "hired":        number[],  // принято за период (целые)
    "fired":        number[],  // уволено за период (целые)
    "fillRatePct":  number[]   // укомплектованность % по периодам, до 0.1
  }
}

--- compare --------------------------------------------------------------------

Таблица сравнения родительских организаций за один конкретный период.
Не зависит от параметра org_id (всегда показывает все видимые
родительские организации).

{
  "scale":          "week" | "month",
  "selectedYear":   number,    // год показанного периода
  "selectedPeriod": number,    // месяц 1..12 ИЛИ ISO-неделя 1..53

  "availablePeriods": [        // отсортирован от свежего к старому
    {
      "year":   number,
      "period": number,        // месяц или неделя
      "label":  string         // "Май 2026" или "11.05–17.05"
    }
  ],

  "rows": [                    // первая строка — всегда "Итого"
    {
      "name":         string,    // название организации или "Итого"
      "isTotal":      boolean,   // true только у строки "Итого"
      "headcount":    number,    // целое
      "fillRate":     number,    // %, до 0.1
      "hired":        number,    // целое
      "fired":        number,    // целое
      "netChange":    number,    // hired − fired
      "turnoverPct":  number     // %, до 0.1
    }
  ]
}

selectedYear / selectedPeriod равны первому элементу availablePeriods
(самый свежий доступный период). Если данных нет вообще —
selectedYear = 0, selectedPeriod = 0, availablePeriods = [], rows = [].

Особенности строки «Итого»:
  - headcount, hired, fired — суммы по орг-ям;
  - fillRate — среднее по орг-ям (сумма процентов бессмысленна);
  - turnoverPct пересчитан от суммарного fired и суммарной headcount.

--------------------------------------------------------------------------------
4. ПРИМЕР ОТВЕТА (укороченный, scale = week)
--------------------------------------------------------------------------------

GET /spa/api/analytics/hr/dashboard/data?org_id=0&scale=week

{
  "scale": "week",
  "labels": ["27.04–03.05", "04.05–10.05", "11.05–17.05"],
  "hr": {
    "kpis": {
      "headcount":   1838,
      "fillRatePct": 41.0,
      "hired":       19,
      "fired":       10,
      "netChange":   9,
      "turnoverPct": 0.5
    },
    "series": {
      "headcount":   [1820, 1832, 1838],
      "hired":       [0, 0, 19],
      "fired":       [0, 0, 10],
      "fillRatePct": [40.5, 40.7, 41.0]
    }
  },
  "compare": {
    "scale": "week",
    "selectedYear":   2026,
    "selectedPeriod": 20,
    "availablePeriods": [
      { "year": 2026, "period": 20, "label": "11.05–17.05" }
    ],
    "rows": [
      { "name": "Итого",                     "isTotal": true,  "headcount": 1838, "fillRate": 41.0, "hired": 19, "fired": 10, "netChange": 9, "turnoverPct": 0.5 },
      { "name": "ГУП ДНР \"ДОНСНАБКОМПЛЕКТ\"", "isTotal": false, "headcount": 1838, "fillRate": 41.0, "hired": 19, "fired": 10, "netChange": 9, "turnoverPct": 0.5 }
    ]
  }
}

--------------------------------------------------------------------------------
5. ПРИМЕР ПУСТОГО ОТВЕТА
--------------------------------------------------------------------------------

Если по запрошенным организациям нет данных — HTTP 200, но все ряды
пустые, KPI = 0:

{
  "scale": "month",
  "labels": [],
  "hr": {
    "kpis": {
      "headcount":   0,
      "fillRatePct": 0,
      "hired":       0,
      "fired":       0,
      "netChange":   0,
      "turnoverPct": 0
    },
    "series": {
      "headcount":   [],
      "hired":       [],
      "fired":       [],
      "fillRatePct": []
    }
  },
  "compare": {
    "scale": "month",
    "selectedYear":   0,
    "selectedPeriod": 0,
    "availablePeriods": [],
    "rows": []
  }
}

--------------------------------------------------------------------------------
6. КРАЕВЫЕ СЛУЧАИ
--------------------------------------------------------------------------------

- scale с любым значением, кроме "month" / "week", приводится к "month".
  Поле "scale" в ответе содержит уже нормализованное значение.

- org_id, по которому нет ни одной строки данных, возвращает пустой
  ответ (см. раздел 5). Ошибки нет.

- compare всегда строится по всем видимым родительским организациям
  (analytics_organizations.is_visible = true), независимо от org_id
  из запроса.

- Длина массивов в hr.series равна длине labels. Если для какого-то
  периода по метрике нет данных — на этой позиции стоит 0
  (а не пропуск/null).

- KPI headcount и fillRatePct берутся как последнее ненулевое значение
  ряда. Если ряд целиком пустой/нулевой — KPI = 0.

- turnoverPct: если headcount == 0, возвращается 0 (без деления).

- В compare-строке "Итого" fillRate усредняется по орг-ям, а не
  суммируется. headcount/hired/fired/netChange суммируются.

--------------------------------------------------------------------------------
7. СВЯЗАННЫЕ ФАЙЛЫ
--------------------------------------------------------------------------------

  Контроллер : src/Controller/SpaApi/Analytics/HrAnalyticsController.php
  Сервис     : src/Service/Analytics/HrDashboardDataService.php
  Репозиторий: src/Repository/Analytics/AnalyticsAggregatedDataRepository.php
  Auth       : dev_docks/spa_api/Readme_spa_auth.txt
