================================================================================
SPA API — Citizen Appeals Analytics (Dashboard)
================================================================================

Эндпоинт для получения данных дашборда "Обращение граждан":
KPI по звонкам/обращениям, ряды по периодам, таблица сравнения
по городам (городским округам).

В отличие от HR-аналитики, разрез в таблице сравнения здесь —
**по городам внутри одной организации**, а не по организациям.
Город определяется по суффиксу business_key (calls_gorlovka,
appeals_donetsk и т.д.).

--------------------------------------------------------------------------------
1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/citizen-appeals/dashboard/data

Query-параметры:

  org_id  (int, опционально, по умолчанию 0)
            ID родительской организации.
            0 = все видимые родительские организации (объединение их
                самих и всех дочерних);
            N = конкретная организация N + все её дочерние.

  scale   (string, опционально, по умолчанию "week")
            Гранулярность временной оси. Допустимые значения:
              "week"  — понедельная (ISO-неделя) агрегация (по умолчанию,
                        т.к. period_type доски = weekly);
              "month" — помесячная агрегация.
            Любое другое значение приводится к "week" без ошибки.

Авторизация:
  JWT в заголовке Authorization (как у всех /spa/api/*).
  См. dev_docks/spa_api/Readme_spa_auth.txt.

Примеры URL:

  /spa/api/analytics/citizen-appeals/dashboard/data?org_id=0&scale=week
  /spa/api/analytics/citizen-appeals/dashboard/data?org_id=20&scale=month

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/citizen-appeals/dashboard/data?org_id=20&scale=week"

fetch:

  const res = await fetch(
    "/spa/api/analytics/citizen-appeals/dashboard/data?org_id=20&scale=week",
    {
      headers: { Authorization: `Bearer ${token}` },
      credentials: "include"
    }
  );
  const data = await res.json();

--------------------------------------------------------------------------------
2. ИСТОЧНИК ДАННЫХ
--------------------------------------------------------------------------------

Используются 14 бизнес-ключей из analytics_metrics — пары (calls_*, appeals_*)
по 7 городам:

  calls_gorlovka       / appeals_gorlovka       Горловка г.о.
  calls_donetsk        / appeals_donetsk        Донецк г.о.
  calls_enakievo       / appeals_enakievo       Енакиево г.о.
  calls_makeevka       / appeals_makeevka       Макеевка г.о.
  calls_shakhtersk     / appeals_shakhtersk     Шахтёрск г.о.
  calls_mariupol       / appeals_mariupol       Мариуполь г.о.
  calls_yasinovataya   / appeals_yasinovataya   Ясиноватая

Все метрики имеют:
  type             = number
  unit             = кол-во
  aggregation_type = sum
  is_active        = true

Понедельные строки analytics_aggregated_data сворачиваются в выбранный
масштаб (week/month) на лету. Правила свёртки:

  Все метрики — потоковые (SUM):
    calls_*   суммируются по периодам и по городам;
    appeals_* суммируются по периодам и по городам.

В hr-аналитике есть остаточные метрики (AVG) — здесь их нет, поэтому
KPI считаются как обычные суммы.

Источник данных в БД:
  analytics_boards         id=3, name="Обращение граждан", period_type=weekly
  analytics_metrics        id 28..41 (см. выше)
  analytics_aggregated_data — пересчитывается из analytics_report_values
                              сервисом RecalculateAggregatesService.

--------------------------------------------------------------------------------
3. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

Корневой объект:

{
  "scale":   "week" | "month",   // эхо запрошенного scale (после нормализации)
  "labels":  string[],           // подписи периодов оси X
  "appeals": { ... },            // KPI + ряды
  "compare": { ... }             // таблица сравнения по городам
}

Длина каждого массива в appeals.series совпадает с длиной labels: i-й
элемент ряда — значение за i-й период. Пропущенные периоды заполняются
нулями.

Подписи периодов (labels):

  scale = "week":
    Диапазон дат недели "DD.MM–DD.MM": "27.04–03.05"
    При нескольких годах в выборке: "27.04–03.05'26"

  scale = "month":
    "Янв", "Фев", "Мар", ... "Дек"
    При нескольких годах в выборке: "Апр '26"

--- appeals -------------------------------------------------------------------

{
  "kpis": {
    "totalCalls":    number,   // итого звонков за весь диапазон labels (целое)
    "totalAppeals":  number,   // итого обращений за весь диапазон labels (целое)
    "conversionPct": number    // totalAppeals / totalCalls * 100, до 0.1
  },
  "series": {
    "calls":    number[],      // звонки по периодам (целые), сумма по всем городам
    "appeals":  number[]       // обращения по периодам (целые), сумма по всем городам
  }
}

KPI и series — суммы по всем 7 городам в рамках выбранных организаций.
Для разбивки по городам — использовать compare.rows.

--- compare ------------------------------------------------------------------

Таблица сравнения 7 городов за один конкретный период.

{
  "scale":          "week" | "month",
  "selectedYear":   number,    // год показанного периода
  "selectedPeriod": number,    // ISO-неделя 1..53 или месяц 1..12

  "availablePeriods": [        // отсортирован от свежего к старому
    {
      "year":   number,
      "period": number,        // неделя или месяц
      "label":  string         // "11.05–17.05'26" или "Май 2026"
    }
  ],

  "rows": [                    // первая строка — всегда "Итого",
                               // далее 7 городов в фиксированном порядке:
                               // Горловка, Донецк, Енакиево, Макеевка,
                               // Шахтёрск, Мариуполь, Ясиноватая
    {
      "name":          string,   // название города или "Итого"
      "isTotal":       boolean,  // true только у строки "Итого"
      "calls":         number,   // целое
      "appeals":       number,   // целое
      "conversionPct": number    // appeals / calls * 100, до 0.1
    }
  ]
}

selectedYear / selectedPeriod равны первому элементу availablePeriods
(самый свежий доступный период). Если данных нет вообще —
selectedYear = 0, selectedPeriod = 0, availablePeriods = [], rows = [].

Особенности строки «Итого»:
  - calls, appeals — простые суммы по всем 7 городам;
  - conversionPct пересчитан от суммарного appeals и суммарного calls,
    а не усреднён по городам.

Порядок строк-городов в rows всегда фиксирован (как объявлен в
CitizenAppealsDashboardDataService::CITY_LABELS), даже если у какого-то
города нет данных — он всё равно присутствует с calls=0, appeals=0,
conversionPct=0.

--------------------------------------------------------------------------------
4. ПРИМЕР ОТВЕТА (укороченный, scale = week)
--------------------------------------------------------------------------------

GET /spa/api/analytics/citizen-appeals/dashboard/data?org_id=20&scale=week

{
  "scale": "week",
  "labels": ["27.04–03.05", "04.05–10.05", "11.05–17.05"],
  "appeals": {
    "kpis": {
      "totalCalls":    2222,
      "totalAppeals":  1856,
      "conversionPct": 83.5
    },
    "series": {
      "calls":   [503, 608, 1111],
      "appeals": [377, 551, 928]
    }
  },
  "compare": {
    "scale": "week",
    "selectedYear":   2026,
    "selectedPeriod": 20,
    "availablePeriods": [
      { "year": 2026, "period": 20, "label": "11.05–17.05'26" },
      { "year": 2026, "period": 19, "label": "04.05–10.05'26" },
      { "year": 2026, "period": 18, "label": "27.04–03.05'26" }
    ],
    "rows": [
      { "name": "Итого",         "isTotal": true,  "calls": 1111, "appeals": 928, "conversionPct": 83.5 },
      { "name": "Горловка г.о.", "isTotal": false, "calls": 103,  "appeals": 55,  "conversionPct": 53.4 },
      { "name": "Донецк г.о.",   "isTotal": false, "calls": 506,  "appeals": 479, "conversionPct": 94.7 },
      { "name": "Енакиево г.о.", "isTotal": false, "calls": 43,   "appeals": 5,   "conversionPct": 11.6 },
      { "name": "Макеевка г.о.", "isTotal": false, "calls": 346,  "appeals": 61,  "conversionPct": 17.6 },
      { "name": "Шахтёрск г.о.", "isTotal": false, "calls": 22,   "appeals": 5,   "conversionPct": 22.7 },
      { "name": "Мариуполь г.о.","isTotal": false, "calls": 91,   "appeals": 40,  "conversionPct": 44.0 },
      { "name": "Ясиноватая",    "isTotal": false, "calls": 0,    "appeals": 283, "conversionPct": 0.0 }
    ]
  }
}

--------------------------------------------------------------------------------
5. ПРИМЕР ПУСТОГО ОТВЕТА
--------------------------------------------------------------------------------

Если по запрошенным организациям нет данных — HTTP 200, но все ряды
пустые, KPI = 0:

{
  "scale": "week",
  "labels": [],
  "appeals": {
    "kpis": {
      "totalCalls":    0,
      "totalAppeals":  0,
      "conversionPct": 0
    },
    "series": {
      "calls":   [],
      "appeals": []
    }
  },
  "compare": {
    "scale": "week",
    "selectedYear":   0,
    "selectedPeriod": 0,
    "availablePeriods": [],
    "rows": []
  }
}

--------------------------------------------------------------------------------
6. КРАЕВЫЕ СЛУЧАИ
--------------------------------------------------------------------------------

- scale с любым значением, кроме "week" / "month", приводится к "week".
  Поле "scale" в ответе содержит уже нормализованное значение.

- org_id, по которому нет ни одной строки данных, возвращает пустой
  ответ (см. раздел 5). Ошибки нет.

- compare всегда возвращает 8 строк (1 «Итого» + 7 городов) при наличии
  данных, даже если по конкретному городу нет ни звонков, ни обращений
  в выбранном периоде — он будет с нулями.

- Длина массивов в appeals.series равна длине labels. Если для какого-то
  периода нет данных — на этой позиции стоит 0 (а не пропуск/null).

- conversionPct: если calls == 0, возвращается 0.0 (без деления),
  даже если appeals > 0 (как у Ясиноватой в примере выше — звонки
  не приходят, но обращения регистрируются по другим каналам).

- Поскольку period_type доски = weekly, при scale="month" данные одной
  ISO-недели попадают в месяц по start_date этой недели (понедельник).
  Неделя, пересекающая границу месяца, целиком относится к месяцу
  своего понедельника.

- Метрики и города зашиты в сервис (CITY_LABELS, PREFIX_CALLS,
  PREFIX_APPEALS). Добавление нового города — это правка кода
  сервиса плюс создание двух новых метрик и привязка их к версии доски.

--------------------------------------------------------------------------------
7. СВЯЗАННЫЕ ФАЙЛЫ
--------------------------------------------------------------------------------

  Контроллер : src/Controller/SpaApi/Analytics/CitizenAppealsAnalyticsController.php
  Сервис     : src/Service/Analytics/CitizenAppealsDashboardDataService.php
  Репозиторий: src/Repository/Analytics/AnalyticsAggregatedDataRepository.php
  Auth       : dev_docks/spa_api/Readme_spa_auth.txt
  Похожий доку: dev_docks/spa_api/Readme_hr_analytics.txt
