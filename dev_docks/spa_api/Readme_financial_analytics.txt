================================================================================
SPA API — Financial Analytics (Dashboard)
================================================================================

Эндпоинт для получения данных аналитического дашборда: блоки
"Финансы", "ТКО", "Кадры" и таблица сравнения по организациям.

--------------------------------------------------------------------------------
1. ЗАПРОС
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/analytics/dashboard/data

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

Примеры URL:

  /spa/api/analytics/dashboard/data?org_id=0&scale=week
  /spa/api/analytics/dashboard/data?org_id=12&scale=month

curl:

  curl -H "Authorization: Bearer <token>" \
       "https://example.local/spa/api/analytics/dashboard/data?org_id=0&scale=week"

fetch:

  const res = await fetch(
    "/spa/api/analytics/dashboard/data?org_id=0&scale=week",
    { credentials: "include" }
  );
  const data = await res.json();

--------------------------------------------------------------------------------
2. ФОРМАТ ОТВЕТА
--------------------------------------------------------------------------------

HTTP 200, application/json.

Корневой объект:

{
  "scale":   "week" | "month",   // эхо запрошенного scale (после нормализации)
  "labels":  string[],           // подписи периодов оси X
  "finance": { ... },            // финансовый блок
  "tko":     { ... },            // блок ТКО / топливо
  "hr":      { ... },            // кадровый блок
  "compare": { ... }             // таблица сравнения по организациям
}

Длина каждого массива-серии (например finance.cashInflow,
tko.tkoExport, hr.hired) совпадает с длиной labels: i-й элемент
серии — это значение за i-й период из labels. Пропущенные периоды
заполнены нулями.

Подписи периодов (labels):

  scale = "month":
    "Янв", "Фев", "Мар", ... "Дек"
    При наличии нескольких лет в выборке добавляется суффикс года:
    "Янв '26"

  scale = "week":
    Диапазон дат недели в формате DD.MM–DD.MM:
    "07.04–13.04"
    При нескольких годах добавляется суффикс года:
    "07.04–13.04'26"

--- finance --------------------------------------------------------------------

Все значения в млн руб (поделены на 1 000 000, округление до 0.1),
кроме breakdown'ов, которые отдаются в исходных рублях.

{
  "cashInflow":  number[],   // приход денежных средств за период (млн)
  "cashOutflow": number[],   // расход денежных средств за период (млн)

  "trends": {                // динамика остатков по периодам (рубли)
    "receivableTotal": number[],   // дебиторка
    "payableTotal":    number[],   // кредиторка
    "balanceTotal":    number[]    // остаток на счетах
  },

  "kpis": {                  // снапшот по последнему периоду серии (рубли)
    "receivableTotal":     number,
    "payableTotal":        number,
    "balanceTotal":        number,
    "debtorCreditorRatio": number,   // receivableTotal / payableTotal
    "balanceCoveragePct":  number,   // balanceTotal / payableTotal * 100
    "netPosition":         number    // balanceTotal − payableTotal
  },

  "receivablesBreakdown": {  // последнее значение серии (рубли)
    "populationTkoExport": number,   // население — вывоз ТКО
    "legalEntitiesTko":    number    // юр. лица — ТКО
  },
  "receivablesBreakdownSeries": {    // ряды по периодам
    "populationTkoExport": number[],
    "legalEntitiesTko":    number[]
  },

  "payablesBreakdown": {     // последнее значение серии (рубли)
    "contractorsTkoExport":  number,
    "landfillsMaintenance":  number,
    "fuel":                  number,
    "otherGoodsServices":    number
  },
  "payablesBreakdownSeries": {
    "contractorsTkoExport":  number[],
    "landfillsMaintenance":  number[],
    "fuel":                  number[],
    "otherGoodsServices":    number[]
  },

  "balancesBreakdown": {     // последнее значение серии (рубли)
    "mainAccount":               number,   // основной счёт
    "yasinovatayaUnit":          number,   // Ясиноватовское подразделение
    "cardAccount":               number,   // карточный счёт
    "headOpenedForBranches":     number,   // голова — счета, открытые филиалам
    "landfills":                 number,   // полигоны
    "roadService":               number,   // ДРСУ
    "branchOpenedAccountsTotal": number    // итого по счетам филиалов
  },

  "branchesBreakdown": [     // остаток по филиалам, последнее значение
    { "name": "Донецкий филиал",      "value": number },
    { "name": "Мариупольский филиал", "value": number },
    { "name": "Макеевский филиал",    "value": number },
    { "name": "Шахтерский филиал",    "value": number },
    { "name": "Горловский филиал",    "value": number },
    { "name": "Енакиевский филиал",   "value": number },
    { "name": "Амвросиевский филиал", "value": number }
  ],
  "branchesBreakdownSeries": [    // ряды по филиалам
    { "name": "Донецкий филиал",      "values": number[] },
    { "name": "Мариупольский филиал", "values": number[] },
    { "name": "Макеевский филиал",    "values": number[] },
    { "name": "Шахтерский филиал",    "values": number[] },
    { "name": "Горловский филиал",    "values": number[] },
    { "name": "Енакиевский филиал",   "values": number[] },
    { "name": "Амвросиевский филиал", "values": number[] }
  ]
}

--- tko ------------------------------------------------------------------------

{
  "tkoExport":       number[],   // вывоз ТКО за период, целые
  "fuelConsumption": number[]    // расход топлива за период
}

--- hr -------------------------------------------------------------------------

{
  "hired":        number[],   // принято за период, целые
  "terminated":   number[],   // уволено за период, целые
  "staffPlanned": number,     // плановая числ-ть, последнее ненулевое значение
  "staffActual":  number,     // фактическая числ-ть, последнее ненулевое
  "vacancies":    number      // max(0, staffPlanned − staffActual)
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
      "label":  string         // "Янв 2026" или "07.04–13.04"
    }
  ],

  "rows": [                    // первая строка — всегда "Итого"
    {
      "name":            string,    // название организации или "Итого"
      "isTotal":         boolean,   // true только у строки "Итого"
      "cashInflow":      number,    // млн, 0.1
      "cashOutflow":     number,    // млн, 0.1
      "tkoExport":       number,    // целое
      "fuelConsumption": number,    // млн, 0.1
      "staffPlanned":    number,    // целое
      "staffActual":     number,    // целое
      "hired":           number,    // целое
      "terminated":      number     // целое
    }
  ]
}

selectedYear / selectedPeriod равны первому элементу availablePeriods
(самый свежий доступный период). Если данных нет вообще —
selectedYear = 0, selectedPeriod = 0, availablePeriods = [], rows = [].

--------------------------------------------------------------------------------
3. ПРИМЕР ОТВЕТА (укороченный)
--------------------------------------------------------------------------------

GET /spa/api/analytics/dashboard/data?org_id=0&scale=week

{
  "scale": "week",
  "labels": ["24.03–30.03", "31.03–06.04", "07.04–13.04"],
  "finance": {
    "cashInflow":  [12.4, 9.8, 15.1],
    "cashOutflow": [10.1, 11.3, 9.7],
    "trends": {
      "receivableTotal": [4500000, 4620000, 4710000],
      "payableTotal":    [3800000, 3950000, 4000000],
      "balanceTotal":    [1200000, 1150000, 1320000]
    },
    "kpis": {
      "receivableTotal":     4710000,
      "payableTotal":        4000000,
      "balanceTotal":        1320000,
      "debtorCreditorRatio": 1.18,
      "balanceCoveragePct":  33,
      "netPosition":         -2680000
    },
    "receivablesBreakdown": {
      "populationTkoExport": 2100000,
      "legalEntitiesTko":    2610000
    },
    "receivablesBreakdownSeries": {
      "populationTkoExport": [2000000, 2050000, 2100000],
      "legalEntitiesTko":    [2500000, 2570000, 2610000]
    },
    "payablesBreakdown": {
      "contractorsTkoExport": 1500000,
      "landfillsMaintenance":  900000,
      "fuel":                  700000,
      "otherGoodsServices":    900000
    },
    "payablesBreakdownSeries": {
      "contractorsTkoExport": [1400000, 1450000, 1500000],
      "landfillsMaintenance": [ 850000,  880000,  900000],
      "fuel":                 [ 650000,  680000,  700000],
      "otherGoodsServices":   [ 900000,  940000,  900000]
    },
    "balancesBreakdown": {
      "mainAccount":               800000,
      "yasinovatayaUnit":           50000,
      "cardAccount":                70000,
      "headOpenedForBranches":     200000,
      "landfills":                 100000,
      "roadService":                40000,
      "branchOpenedAccountsTotal": 60000
    },
    "branchesBreakdown": [
      { "name": "Донецкий филиал",      "value": 30000 },
      { "name": "Мариупольский филиал", "value": 12000 },
      { "name": "Макеевский филиал",    "value":  8000 },
      { "name": "Шахтерский филиал",    "value":  4000 },
      { "name": "Горловский филиал",    "value":  3000 },
      { "name": "Енакиевский филиал",   "value":  2000 },
      { "name": "Амвросиевский филиал", "value":  1000 }
    ],
    "branchesBreakdownSeries": [
      { "name": "Донецкий филиал",      "values": [28000, 29000, 30000] },
      { "name": "Мариупольский филиал", "values": [11000, 11500, 12000] }
    ]
  },
  "tko": {
    "tkoExport":       [2100, 2350, 2500],
    "fuelConsumption": [180000, 195000, 210000]
  },
  "hr": {
    "hired":        [3, 5, 2],
    "terminated":   [1, 2, 4],
    "staffPlanned": 420,
    "staffActual":  398,
    "vacancies":    22
  },
  "compare": {
    "scale": "week",
    "selectedYear":   2026,
    "selectedPeriod": 15,
    "availablePeriods": [
      { "year": 2026, "period": 15, "label": "07.04–13.04" },
      { "year": 2026, "period": 14, "label": "31.03–06.04" }
    ],
    "rows": [
      { "name": "Итого",          "isTotal": true,  "cashInflow": 15.1, "cashOutflow": 9.7, "tkoExport": 2500, "fuelConsumption": 0.21, "staffPlanned": 420, "staffActual": 398, "hired": 2, "terminated": 4 },
      { "name": "ООО Дон-Строй",  "isTotal": false, "cashInflow":  9.0, "cashOutflow": 6.2, "tkoExport": 1500, "fuelConsumption": 0.13, "staffPlanned": 250, "staffActual": 240, "hired": 1, "terminated": 2 },
      { "name": "ООО Маш-Сервис", "isTotal": false, "cashInflow":  6.1, "cashOutflow": 3.5, "tkoExport": 1000, "fuelConsumption": 0.08, "staffPlanned": 170, "staffActual": 158, "hired": 1, "terminated": 2 }
    ]
  }
}

--------------------------------------------------------------------------------
4. ПРИМЕР ПУСТОГО ОТВЕТА
--------------------------------------------------------------------------------

Если по запрошенным организациям нет данных — HTTP 200, но все ряды
пустые, KPI = 0:

{
  "scale": "month",
  "labels": [],
  "finance": {
    "cashInflow":  [],
    "cashOutflow": [],
    "trends":   { "receivableTotal": [], "payableTotal": [], "balanceTotal": [] },
    "kpis":     {
      "receivableTotal": 0, "payableTotal": 0, "balanceTotal": 0,
      "debtorCreditorRatio": 0, "balanceCoveragePct": 0, "netPosition": 0
    },
    "receivablesBreakdown":       { "populationTkoExport": 0, "legalEntitiesTko": 0 },
    "receivablesBreakdownSeries": { "populationTkoExport": [], "legalEntitiesTko": [] },
    "payablesBreakdown": {
      "contractorsTkoExport": 0, "landfillsMaintenance": 0,
      "fuel": 0, "otherGoodsServices": 0
    },
    "payablesBreakdownSeries": {
      "contractorsTkoExport": [], "landfillsMaintenance": [],
      "fuel": [], "otherGoodsServices": []
    },
    "balancesBreakdown": {
      "mainAccount": 0, "yasinovatayaUnit": 0, "cardAccount": 0,
      "headOpenedForBranches": 0, "landfills": 0,
      "roadService": 0, "branchOpenedAccountsTotal": 0
    },
    "branchesBreakdown":       [],
    "branchesBreakdownSeries": []
  },
  "tko": { "tkoExport": [], "fuelConsumption": [] },
  "hr":  { "hired": [], "terminated": [], "staffPlanned": 0, "staffActual": 0, "vacancies": 0 },
  "compare": {
    "scale": "month",
    "selectedYear": 0, "selectedPeriod": 0,
    "availablePeriods": [], "rows": []
  }
}

--------------------------------------------------------------------------------
5. КРАЕВЫЕ СЛУЧАИ
--------------------------------------------------------------------------------

- scale с любым значением, кроме "month" / "week", приводится к "month".
  Поле "scale" в ответе содержит уже нормализованное значение.

- org_id, по которому нет ни одной строки данных, возвращает пустой
  ответ (см. раздел 4). Ошибки нет.

- compare всегда строится по всем видимым родительским организациям,
  независимо от org_id из запроса.

- Длина массивов-серий равна длине labels. Если для какого-то периода
  по метрике нет данных — на этой позиции стоит 0 (а не пропуск/null).
