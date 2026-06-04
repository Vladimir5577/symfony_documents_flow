================================================================================
SPA API — Analytics TKO (аналитика полигонов ТКО)
================================================================================

Вывод суточной отчётности по полигонам ТКО для SPA. Только чтение — заполнение
и сохранение остаются на серверной (Twig) странице /analytics/tko.

Данные хранятся в «длинном» формате: одна строка таблицы analytics_tko =
один полигон за одну дату (см. App\Entity\Analytics\TKO\AnalyticsTKO).

Два эндпоинта:

  1. /spa/api/analytics/tko          — недельная сетка по дням (Пн–Вс)
  2. /spa/api/analytics/tko/summary  — availableWeeks (все недели) + weeks (агрегаты, limit/offset)

П.1 — сетка по дням одной недели; п.2 — календарь для выбора периода и
понедельные суммы/счётчики с разбивкой по полигонам (series).

Авторизация: JWT в заголовке Authorization (как у всех /spa/api/* маршрутов).
См. dev_docks/spa_api/Readme_spa_auth.txt.
Доступ: требуется роль ROLE_MANAGER (и выше по иерархии).

--------------------------------------------------------------------------------
СПРАВОЧНИК МЕТРИК (общий для обоих эндпоинтов)
--------------------------------------------------------------------------------

Поле metrics — массив описаний строк таблицы, в фиксированном порядке:

  key                       label                   type   aggregate*
  ------------------------- ----------------------- ------ -----------
  garbage_trucks_volume     Мусоровозы              num    sum
  garbage_trucks_weight     Вес ТКО мусоровозы      num    sum
  containers_volume         Контейнеры              num    sum
  scrap_trucks_volume       Ломовозы                num    sum
  containers_scrap_weight   Вес ТКО конт., ломов    num    sum
  vegetation_volume         Растительные            num    sum
  construction_volume       Строительные            num    sum
  terminal_volume           Терминал                num    sum
  bulldozer_work            Работа бульдозера       text   days_count
  equipment_work            Работа техники          text   days_count

  type      : num  — числовая метрика; text — текстовая отметка.
  aggregate : только в /summary. sum — сумма за период; days_count — число
              дней, в которых метрика была заполнена (см. ниже).

Значения всегда отдаются строками. Для num пустое значение = "" (нет данных).

================================================================================
1. НЕДЕЛЬНАЯ СЕТКА ПО ДНЯМ
================================================================================

--------------------------------------------------------------------------------
1.1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/tko

Query-параметры:

  polygon_id  (int, опционально)
                ID полигона. Если не передан / не найден / неактивен —
                берётся первый активный полигон из списка (как на Twig-странице).

  week        (string YYYY-MM-DD, опционально)
                Любая дата нужной недели. Внутри приводится к понедельнику
                этой недели (ISO). Невалидное/пустое значение → текущая неделя.

Примеры URL:

  // конкретный полигон, неделя по дате 13.05 (вернётся неделя 11.05–17.05)
  /spa/api/analytics/tko?polygon_id=11&week=2026-05-13

  // первый активный полигон, текущая неделя
  /spa/api/analytics/tko

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/tko?polygon_id=11&week=2026-05-13"

fetch:

  const params = new URLSearchParams({ polygon_id: '11', week: '2026-05-13' });
  const res = await fetch(`/spa/api/analytics/tko?${params}`,
    { headers: { Authorization: "Bearer " + jwt } });
  const data = await res.json();

--------------------------------------------------------------------------------
1.2. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

Корневой объект:

{
  "polygons":          [{ "id": int, "name": string }, ...],  // все активные
  "selectedPolygonId": int|null,    // фактически выбранный полигон
  "week":              "YYYY-MM-DD", // понедельник выбранной недели
  "weekLabel":         "dd.mm — dd.mm",
  "prevWeek":          "YYYY-MM-DD", // понедельник предыдущей недели
  "nextWeek":          "YYYY-MM-DD", // понедельник следующей недели
  "metrics":           Metric[],     // см. «Справочник метрик»
  "days":              Day[]         // всегда 7 элементов, Пн → Вс
}

Day:

{
  "date":   "YYYY-MM-DD",
  "dow":    "Пн" | ... | "Вс",
  "short":  "dd.mm",
  "values": { "<metric.key>": string }  // значение метрики за этот день
}

Правила:
- days всегда содержит ровно 7 дней недели, даже если данных за день нет
  (тогда все values пустые "").
- num-значения нормализованы: лишние нули и точка обрезаются ("168", "1.15").
- text-значения отдаются как есть ("работал", "Д-12", "").

Пример (полигоны и дни усечены — «…»):

{
  "polygons": [ { "id": 20, "name": "Волновахский полигон ТКО" }, "…" ],
  "selectedPolygonId": 11,
  "week": "2026-05-11",
  "weekLabel": "11.05 — 17.05",
  "prevWeek": "2026-05-04",
  "nextWeek": "2026-05-18",
  "metrics": [
    { "key": "garbage_trucks_volume", "label": "Мусоровозы", "type": "num" },
    { "key": "bulldozer_work", "label": "Работа бульдозера", "type": "text" },
    "…"
  ],
  "days": [
    {
      "date": "2026-05-11", "dow": "Пн", "short": "11.05",
      "values": {
        "garbage_trucks_volume": "1583.6",
        "garbage_trucks_weight": "",
        "containers_volume": "168",
        "scrap_trucks_volume": "",
        "containers_scrap_weight": "",
        "vegetation_volume": "",
        "construction_volume": "1.15",
        "terminal_volume": "1196.35",
        "bulldozer_work": "",
        "equipment_work": "работал"
      }
    },
    "…"
  ]
}

Как рисовать таблицу: строки = metrics, колонки = days (7 шт.),
ячейка = days[i].values[metric.key]. Навигация по неделям — ссылки на тот же
эндпоинт с week = prevWeek / nextWeek.

================================================================================
2. СВОДКА ПО НЕДЕЛЯМ (availableWeeks + weeks)
================================================================================

availableWeeks — полный список недель по данным в analytics_tko (для выбора
периода на фронте). weeks — срез limit/offset с агрегатами за каждую неделю.

--------------------------------------------------------------------------------
2.1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/tko/summary

Query-параметры:

  limit        (int, по умолчанию 12, макс. 100) — размер среза weeks.
  offset       (int, по умолчанию 0) — пагинация weeks «от свежих к старым».

Примеры URL:

  /spa/api/analytics/tko/summary
  /spa/api/analytics/tko/summary?limit=12&offset=0
  /spa/api/analytics/tko/summary?limit=12&offset=12

--------------------------------------------------------------------------------
2.2. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

{
  "availableWeeks": [
    { "startDate": "2026-04-27", "endDate": "2026-05-03" },
    { "startDate": "2026-05-04", "endDate": "2026-05-10" }
  ],
  "weeks": [
    {
      "startDate": "2026-04-27",
      "endDate": "2026-05-03",
      "series": [
        {
          "metric_key": "terminal_volume",
          "name": "Терминал",
          "unit": "м³",
          "valueNumber": 11911.45,
          "children": [
            {
              "metric_key": "terminal_volume_11",
              "name": "Волновахский полигон ТКО",
              "unit": "м³",
              "valueNumber": 5000.0
            },
            {
              "metric_key": "terminal_volume_20",
              "name": "…",
              "unit": "м³",
              "valueNumber": 6911.45
            }
          ]
        }
      ]
    }
  ]
}

availableWeeks — всегда полный список по всей таблице analytics_tko, без limit/offset.

weeks — только страница limit/offset; внутри страницы startDate ASC.

Узел series (на каждую метрику из METRICS контроллера):

  metric_key   — ключ метрики (terminal_volume, …)
  name         — отображаемое имя (родитель — name метрики, ребёнок — имя полигона)
  unit         — м³, т или дн. (у детей — как у родителя)
  valueNumber  — сумма по всем полигонам (num) или сумма дней с отметкой (text);
                 null если за неделю нет данных ни по одному полигону
  children     — разбивка по активным полигонам:
    metric_key   — {metric_key}_{polygon_id}, например terminal_volume_11
    name         — polygon.name
    unit         — как у родителя
    valueNumber  — сумма/счётчик только по этому полигону; null если нет данных

Агрегация в БД: num → SUM, text → COUNT непустых дней.

Правила availableWeeks:
- MIN(report_date) → понедельник недели, MAX → воскресенье; все недели подряд.
- Нет записей → availableWeeks: [], weeks: [].

Правила weeks:
- Пагинация как у finance: limit=12, offset=0 — последние 12 недель из
  availableWeeks.

================================================================================
3. ОШИБКИ
================================================================================

  401 — нет/невалидный JWT (общая обработка /spa/api/*, см. Readme_spa_auth.txt).
  403 — у пользователя нет роли ROLE_MANAGER.

Прикладных 4xx/5xx нет: невалидные limit/offset подставляются дефолтами;
в п.1 невалидный week — текущая неделя.

================================================================================
4. РЕАЛИЗАЦИЯ (для бэкенда)
================================================================================

  Контроллер : src/Controller/SpaApi/Analytics/TkoAnalyticsController.php
                 ::index()   — /spa/api/analytics/tko
                 ::summary() — /spa/api/analytics/tko/summary
                 репозиторий (SQL): findAvailableWeeks(), aggregateWeeklyByPolygon()
                 сервис (сборка): buildWeeksSummary(), buildSeries(), paginateWeeks()
  Репозиторий: src/Repository/Analytics/TKO/AnalyticsTKORepository.php
                 ::findByPolygonAndDateRange() — данные недели по дням (п.1)
  Сущность   : src/Entity/Analytics/TKO/AnalyticsTKO.php

Заполнение/сохранение из SPA пока не реализовано (только просмотр).
