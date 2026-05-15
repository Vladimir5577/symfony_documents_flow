================================================================================
SPA API — Citizen Appeals Analytics (Dashboard)
================================================================================

Эндпоинт для получения сырых недельных значений по дашборду
"Обращение граждан": по каждой неделе и каждому из 7 городов
отдаётся пара чисел calls/appeals.

Никакой агрегации, KPI или conversion на стороне сервера — всё
считает фронт.

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

Авторизация:
  JWT в заголовке Authorization (как у всех /spa/api/*).
  См. dev_docks/spa_api/Readme_spa_auth.txt.

Примеры URL:

  /spa/api/analytics/citizen-appeals/dashboard/data
  /spa/api/analytics/citizen-appeals/dashboard/data?org_id=20

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/citizen-appeals/dashboard/data?org_id=20"

fetch:

  const res = await fetch(
    "/spa/api/analytics/citizen-appeals/dashboard/data?org_id=20",
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

Endpoint читает напрямую из analytics_report_values, JOIN с analytics_reports,
analytics_periods, analytics_board_version_metrics, analytics_metrics.
Учитываются ТОЛЬКО утверждённые отчёты (status = 'approved').

Никаких пересчётов, агрегатов, среднего, накопительных значений —
просто то, что вручную забили в отчёт. Если за неделю отчёта нет —
этой недели не будет и в ответе.

Источник в БД:
  analytics_boards         id=3, name="Обращение граждан", period_type=weekly
  analytics_metrics        id 28..41 (см. выше)
  analytics_report_values  фактические значения отчётов

--------------------------------------------------------------------------------
3. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

Корневой объект:

{
  "cities": [                    // справочник городов, порядок зафиксирован
    { "key": string, "name": string },
    ...
  ],
  "weeks": [                     // массив недель, отсортирован по возрастанию
    {
      "year":      number,       // ISO-год
      "week":      number,       // ISO-неделя 1..53
      "startDate": string,       // YYYY-MM-DD, понедельник
      "endDate":   string,       // YYYY-MM-DD, воскресенье
      "label":     string,       // "DD.MM–DD.MM" — готовый ярлык для UI
      "cities": {
        "<cityKey>": { "calls": number, "appeals": number },
        ...                      // все 7 городов в каждой неделе
      }
    },
    ...
  ]
}

Порядок городов в cities[] и ключи в weeks[].cities одинаковы:
gorlovka, donetsk, enakievo, makeevka, shakhtersk, mariupol, yasinovataya.

В каждой неделе всегда присутствуют все 7 городов. Если по конкретному
городу в отчёте не было звонков или обращений (или метрика была пустой) —
там стоит 0 (а не null/пропуск).

--------------------------------------------------------------------------------
4. ПРИМЕР ОТВЕТА
--------------------------------------------------------------------------------

GET /spa/api/analytics/citizen-appeals/dashboard/data?org_id=20

{
  "cities": [
    { "key": "gorlovka",     "name": "Горловка г.о." },
    { "key": "donetsk",      "name": "Донецк г.о." },
    { "key": "enakievo",     "name": "Енакиево г.о." },
    { "key": "makeevka",     "name": "Макеевка г.о." },
    { "key": "shakhtersk",   "name": "Шахтёрск г.о." },
    { "key": "mariupol",     "name": "Мариуполь г.о." },
    { "key": "yasinovataya", "name": "Ясиноватая" }
  ],
  "weeks": [
    {
      "year": 2026,
      "week": 18,
      "startDate": "2026-04-27",
      "endDate":   "2026-05-03",
      "label":     "27.04–03.05",
      "cities": {
        "gorlovka":     { "calls": 43,  "appeals": 20 },
        "donetsk":      { "calls": 244, "appeals": 174 },
        "enakievo":     { "calls": 41,  "appeals": 4 },
        "makeevka":     { "calls": 127, "appeals": 30 },
        "shakhtersk":   { "calls": 9,   "appeals": 3 },
        "mariupol":     { "calls": 39,  "appeals": 22 },
        "yasinovataya": { "calls": 0,   "appeals": 124 }
      }
    },
    {
      "year": 2026,
      "week": 19,
      "startDate": "2026-05-04",
      "endDate":   "2026-05-10",
      "label":     "04.05–10.05",
      "cities": {
        "gorlovka":     { "calls": 60,  "appeals": 35 },
        "donetsk":      { "calls": 262, "appeals": 305 },
        "enakievo":     { "calls": 2,   "appeals": 1 },
        "makeevka":     { "calls": 219, "appeals": 31 },
        "shakhtersk":   { "calls": 13,  "appeals": 2 },
        "mariupol":     { "calls": 52,  "appeals": 18 },
        "yasinovataya": { "calls": 0,   "appeals": 159 }
      }
    }
  ]
}

--------------------------------------------------------------------------------
5. ПРИМЕР ПУСТОГО ОТВЕТА
--------------------------------------------------------------------------------

Если по запрошенным организациям нет утверждённых отчётов —
HTTP 200, weeks пустой, cities всегда заполнен:

{
  "cities": [
    { "key": "gorlovka",     "name": "Горловка г.о." },
    { "key": "donetsk",      "name": "Донецк г.о." },
    { "key": "enakievo",     "name": "Енакиево г.о." },
    { "key": "makeevka",     "name": "Макеевка г.о." },
    { "key": "shakhtersk",   "name": "Шахтёрск г.о." },
    { "key": "mariupol",     "name": "Мариуполь г.о." },
    { "key": "yasinovataya", "name": "Ясиноватая" }
  ],
  "weeks": []
}

--------------------------------------------------------------------------------
6. КРАЕВЫЕ СЛУЧАИ
--------------------------------------------------------------------------------

- В выборку попадают только отчёты со статусом 'approved'. Черновики
  (draft) и поданные на проверку (submitted) не учитываются.

- Если отчёт за неделю существует, но какая-то метрика пустая —
  соответствующее число будет 0 (не null). Город всё равно
  присутствует.

- Если за неделю вообще нет отчёта — этой недели не будет в weeks[].

- Города и их порядок зашиты в сервис (CITY_LABELS). Добавление
  нового города — это правка кода сервиса + создание двух новых
  метрик calls_<city>/appeals_<city> и привязка их к версии доски.

- value хранится в БД как numeric(20,4), endpoint округляет до
  ближайшего целого. Если нужны дробные — изменить приведение
  в groupByWeek().

--------------------------------------------------------------------------------
7. СВЯЗАННЫЕ ФАЙЛЫ
--------------------------------------------------------------------------------

  Контроллер : src/Controller/SpaApi/Analytics/CitizenAppealsAnalyticsController.php
  Сервис     : src/Service/Analytics/CitizenAppealsDashboardDataService.php
  Auth       : dev_docks/spa_api/Readme_spa_auth.txt
