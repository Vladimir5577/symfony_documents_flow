================================================================================
SPA API — Analytics TKO (аналитика полигонов ТКО)
================================================================================

Вывод суточной отчётности по полигонам ТКО для SPA. Только чтение — заполнение
и сохранение остаются на серверной (Twig) странице /analytics/tko.

Данные хранятся в «длинном» формате: одна строка таблицы analytics_tko =
один полигон за одну дату (см. App\Entity\Analytics\TKO\AnalyticsTKO).

Два эндпоинта:

  1. /spa/api/analytics/tko          — недельная сетка по дням (Пн–Вс)
  2. /spa/api/analytics/tko/summary  — суммы с группировкой по неделям/месяцам

Оба отдают один и тот же справочник метрик (metrics) и список полигонов
(polygons), различаются только тем, как разложены значения (по дням vs по
бакетам-периодам).

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
2. СУММАРНАЯ АНАЛИТИКА (НЕДЕЛИ / МЕСЯЦЫ)
================================================================================

Суммы по одному полигону за период с группировкой по неделям или месяцам.
Агрегация выполняется на стороне БД (PostgreSQL date_trunc) — лёгкий запрос.

--------------------------------------------------------------------------------
2.1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/tko/summary

Query-параметры:

  polygon_id   (int, опционально)
                 ID полигона. Поведение по умолчанию — как в п.1.1
                 (первый активный, если не передан/не найден).

  granularity  (string, опционально, по умолчанию "week")
                 "week"  — группировка по неделям (бакет = ISO-неделя, Пн);
                 "month" — группировка по месяцам (бакет = 1-е число месяца).
                 Любое иное значение трактуется как "week".

  from         (string YYYY-MM-DD, опционально)
  to           (string YYYY-MM-DD, опционально)
                 Границы периода. По умолчанию — текущий месяц
                 (с 1-го по последнее число).
                 Невалидное значение игнорируется (берётся дефолт).
                 Если to < from, to подтягивается к from.

                 Диапазон расширяется до целых периодов:
                 - week:  from → понедельник своей недели,
                          to   → воскресенье своей недели;
                 - month: from → 1-е число своего месяца,
                          to   → последнее число своего месяца.
                 Поэтому фактические from/to в ответе могут отличаться от
                 переданных (см. поля from/to в ответе).

  limit        (int, опционально, по умолчанию 10, максимум 10)
                 Сколько периодов (недель или месяцев — см. granularity)
                 вернуть в buckets. limit <= 0 → 10. limit > 10 → 10.

  offset       (int, опционально, по умолчанию 0)
                 Сдвиг по периодам. offset < 0 → 0.
                 Пагинация «от свежих к старым»: limit=10, offset=0 — последние
                 10 периодов в диапазоне [from, to]; offset=10 — следующие 10
                 более старых. Внутри ответа buckets всегда по key ASC.

                 Сначала применяется фильтр from/to (полный набор периодов в
                 диапазоне), затем limit/offset по этому набору.

Примеры URL:

  // понедельные суммы за май 2026 (последние 10 недель диапазона)
  /spa/api/analytics/tko/summary?polygon_id=11&granularity=week&from=2026-05-01&to=2026-05-31

  // следующая страница недель (более старые)
  /spa/api/analytics/tko/summary?polygon_id=11&granularity=week&from=2026-01-01&to=2026-05-31&offset=10

  // максимум периодов за один запрос
  /spa/api/analytics/tko/summary?polygon_id=11&from=2026-04-01&to=2026-05-31&limit=10

  // помесячные суммы за апрель–май 2026
  /spa/api/analytics/tko/summary?polygon_id=11&granularity=month&from=2026-04-01&to=2026-05-31

  // недельные суммы за текущий месяц (дефолт)
  /spa/api/analytics/tko/summary?polygon_id=11

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/tko/summary?polygon_id=11&granularity=month&from=2026-04-01&to=2026-05-31"

fetch:

  const params = new URLSearchParams({
    polygon_id:  '11',
    granularity: 'week',
    from:        '2026-05-01',
    to:          '2026-05-31',
    limit:       '10',
    offset:      '0',
  });
  const res = await fetch(`/spa/api/analytics/tko/summary?${params}`,
    { headers: { Authorization: "Bearer " + jwt } });
  const data = await res.json();

--------------------------------------------------------------------------------
2.2. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

Корневой объект:

{
  "polygons":          [{ "id": int, "name": string }, ...],  // все активные
  "selectedPolygonId": int|null,
  "granularity":       "week" | "month",
  "from":              "YYYY-MM-DD",  // фактическое начало (после расширения)
  "to":                "YYYY-MM-DD",  // фактический конец
  "limit":             number,        // фактический limit после clamp
  "offset":            number,
  "total":             number,        // всего периодов в [from, to] до среза
  "metrics":           Metric[],      // с полем aggregate (sum|days_count)
  "buckets":           Bucket[]       // срез периодов (limit/offset), по key ASC
}

Bucket:

{
  "key":    "YYYY-MM-DD",   // начало периода (Пн для week, 1-е число для month)
  "label":  string,         // week: "dd.mm — dd.mm"; month: "mm.YYYY"
  "start":  "YYYY-MM-DD",
  "end":    "YYYY-MM-DD",
  "values": { "<metric.key>": string }
}

Правила агрегации значений:
- num (aggregate=sum): сумма значений метрики за период. Если данных нет —
  пустая строка "".
- text (aggregate=days_count): количество дней периода, в которых метрика
  была заполнена (непустой текст). Всегда число строкой; нет данных = "0".

Правила формирования buckets:
- В диапазоне [from, to] считается полный набор периодов (поле total), затем
  в buckets попадает срез limit/offset (от свежих к старым), включая периоды
  без данных (у них num = "", days_count = "0").
- Периоды в buckets отсортированы по key по возрастанию.
- offset >= total → buckets: [] при total в ответе сохраняется.
- Для week бакеты — ISO-недели (Пн–Вс); крайние недели могут захватывать дни
  соседнего месяца (это нормально, недели не «режутся» по границе месяца).

Пример — granularity=week (полигоны и бакеты усечены):

{
  "polygons": [ { "id": 20, "name": "Волновахский полигон ТКО" }, "…" ],
  "selectedPolygonId": 11,
  "granularity": "week",
  "from": "2026-04-27",
  "to": "2026-05-31",
  "limit": 10,
  "offset": 0,
  "total": 5,
  "metrics": [
    { "key": "garbage_trucks_volume", "label": "Мусоровозы", "type": "num", "aggregate": "sum" },
    { "key": "equipment_work", "label": "Работа техники", "type": "text", "aggregate": "days_count" },
    "…"
  ],
  "buckets": [
    {
      "key": "2026-04-27", "label": "27.04 — 03.05",
      "start": "2026-04-27", "end": "2026-05-03",
      "values": {
        "garbage_trucks_volume": "3458.6",
        "garbage_trucks_weight": "",
        "containers_volume": "368",
        "scrap_trucks_volume": "0",
        "containers_scrap_weight": "",
        "vegetation_volume": "0",
        "construction_volume": "31.45",
        "terminal_volume": "11911.45",
        "bulldozer_work": "0",
        "equipment_work": "1"
      }
    },
    "…"
  ]
}

Пример — granularity=month (период без данных + период с данными):

{
  "selectedPolygonId": 11,
  "granularity": "month",
  "from": "2026-04-01",
  "to": "2026-05-31",
  "buckets": [
    {
      "key": "2026-04-01", "label": "04.2026",
      "start": "2026-04-01", "end": "2026-04-30",
      "values": {
        "garbage_trucks_volume": "",
        "construction_volume": "",
        "terminal_volume": "",
        "bulldozer_work": "0",
        "equipment_work": "0"
      }
    },
    {
      "key": "2026-05-01", "label": "05.2026",
      "start": "2026-05-01", "end": "2026-05-31",
      "values": {
        "garbage_trucks_volume": "32786.9",
        "containers_volume": "3760",
        "scrap_trucks_volume": "1568",
        "vegetation_volume": "381.15",
        "construction_volume": "214.6",
        "terminal_volume": "156825.41",
        "bulldozer_work": "0",
        "equipment_work": "10"
      }
    }
  ]
}

Как рисовать таблицу: строки = metrics, колонки = buckets,
ячейка = buckets[i].values[metric.key]. Для метрик с aggregate=days_count
значение — число дней (можно подписывать «дн.»), а не сумма.

================================================================================
3. ОШИБКИ
================================================================================

  401 — нет/невалидный JWT (общая обработка /spa/api/*, см. Readme_spa_auth.txt).
  403 — у пользователя нет роли ROLE_MANAGER.

Прикладных 4xx/5xx нет: невалидные query-параметры (week/from/to/granularity)
не приводят к ошибке, а заменяются значениями по умолчанию.

================================================================================
4. РЕАЛИЗАЦИЯ (для бэкенда)
================================================================================

  Контроллер : src/Controller/SpaApi/Analytics/TkoAnalyticsController.php
                 ::index()   — /spa/api/analytics/tko
                 ::summary() — /spa/api/analytics/tko/summary
  Репозиторий: src/Repository/Analytics/TKO/AnalyticsTKORepository.php
                 ::findByPolygonAndDateRange() — данные недели по дням
                 ::aggregateByPolygon()        — суммы по неделям/месяцам (DBAL)
  Сущность   : src/Entity/Analytics/TKO/AnalyticsTKO.php

Заполнение/сохранение из SPA пока не реализовано (только просмотр).
