================================================================================
SPA API — Citizen Appeals Analytics (Dashboard)
================================================================================

Эндпоинт для получения данных дашборда "Обращение граждан":
KPI по звонкам/обращениям и ряды по периодам.

Метрики устроены так: по каждому из 7 городов (городских округов)
заведена пара business_key — calls_<city> и appeals_<city>. В ответе
endpoint все 7 городов сворачиваются в общие суммы по периодам.
Если нужна разбивка по городам — её можно собрать на фронте, дёргая
эндпоинт по разным org_id или используя другой источник.

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
  "appeals": { ... }             // KPI + ряды
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

--------------------------------------------------------------------------------
4. ПРИМЕР ОТВЕТА (scale = week)
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
  }
}

--------------------------------------------------------------------------------
6. КРАЕВЫЕ СЛУЧАИ
--------------------------------------------------------------------------------

- scale с любым значением, кроме "week" / "month", приводится к "week".
  Поле "scale" в ответе содержит уже нормализованное значение.

- org_id, по которому нет ни одной строки данных, возвращает пустой
  ответ (см. раздел 5). Ошибки нет.

- Длина массивов в appeals.series равна длине labels. Если для какого-то
  периода нет данных — на этой позиции стоит 0 (а не пропуск/null).

- conversionPct: если totalCalls == 0, возвращается 0.0 (без деления),
  даже если totalAppeals > 0.

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
