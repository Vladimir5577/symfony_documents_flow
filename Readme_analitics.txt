    DB structures
    =============

1. users - already exist

2. organization - already exist

3. analytics_metrics
    id	PK	Уникальный идентификатор метрики
    business_key	string	Стабильный бизнес-ключ (fuel_consumption, spare_parts_cost). Не изменяется после создания
    name	string	Название показателя (Расход топлива, Прибыль…)
    type	string	Тип значения: number, currency, distance, liters, count и т.д.
    unit	string	Единица измерения (л, км, руб., шт.)
    aggregation_type	enum	Правило агрегации: sum | avg | min | max | last
    input_type	string	(опционально) Тип поля для ввода: text, number, select, checkbox
    is_active	boolean	Включена ли метрика в использование
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения
    UNIQUE(business_key)		Один бизнес-смысл = один стабильный ключ

4. analytics_boards
    id	PK	Уникальный идентификатор доски
    name	string	Название доски (Аналитика механиков, Финансовая аналитика)
    description	text	Дополнительное описание доски
    period_type	enum	Частота заполнения отчётов: daily | weekly | monthly (default: weekly). Определяет, за какой промежуток создаётся отчёт
    active_version_id	FK → analytics_board_versions.id NULL	Текущая рабочая версия доски; новые отчёты создаются по ней
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения

5. analytics_board_versions
    id	PK	Уникальный идентификатор версии
    board_id	FK → analytics_boards.id	Доска
    version_number	int	Номер версии (1,2,3...)
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения состава версии
    UNIQUE(board_id, version_number)		Уникальность номера версии внутри доски
    Примечание: у версии больше нет статуса draft/published/archived. Активность определяется только ссылкой `analytics_boards.active_version_id`.

6. analytics_board_version_metrics
    id	PK	Уникальный идентификатор
    board_version_id	FK → analytics_board_versions.id	Версия доски
    metric_id	FK → analytics_metrics.id	Метрика
    position	int	Порядок метрики внутри версии
    is_required	boolean	Обязательна ли метрика
    UNIQUE(board_version_id, metric_id)		Одна метрика один раз в версии

7. analytics_organization_boards
    id	PK	Уникальный идентификатор
    organization_id	FK → organization.id	Организация
    board_id	FK → analytics_boards.id	Доска
    is_required	boolean	Обязательная для заполнения доска или нет
    UNIQUE(organization_id, board_id)		Одна доска не назначается организации дважды

8. analytics_periods
    id	PK	Уникальный идентификатор периода
    type	enum	Тип периода: daily | weekly | monthly
    start_date	date	Канонический день периода: для daily — сама дата, для weekly — понедельник ISO-недели, для monthly — 1-е число месяца
    end_date	date	Конец периода: для daily = start_date, для weekly = воскресенье, для monthly = последний день месяца
    UNIQUE(type, start_date)		Один период каждого типа на свой канонический день

    Фабрики создания:
    - `AnalyticsPeriod::forIsoWeek(isoYear, isoWeek)` — weekly (внутри: setISODate → понедельник)
    - `AnalyticsPeriod::forDate(date)` — daily
    - `AnalyticsPeriod::forMonth(year, month)` — monthly

    Семантика ISO 8601 (только в логике, не в схеме):
    - ISO-год/неделя при необходимости вычисляются из `start_date` через `format('o')` и `format('W')`.
    - Подпись `2026-W21` собирается на лету в `getDisplayLabel()`.
    - Поиск weekly-периода: `findOneBy(['type' => Weekly, 'startDate' => $monday])`.

    Правило назначения периода:
    - Период отчёта определяется автоматически по `analytics_boards.period_type` и текущей дате (timezone `Europe/Moscow`).
    - daily → период за сегодня; weekly → текущая ISO-неделя; monthly → текущий месяц.
    - Если запись уже есть — используется она. Если нет — создаётся автоматически.

    Закрытие периода:
    - Явного закрытия периодов нет (поле `is_closed` удалено, админка периодов отсутствует).
    - Иммутабельность обеспечивается на уровне отчёта: после `draft -> confirmed` редактирование значений запрещено.

9. analytics_reports
    id	PK	Уникальный идентификатор отчёта
    organization_id	FK → organization.id	Организация, которая заполняет отчёт
    board_id	FK → analytics_boards.id	Доска (денормализация для уникальности и запросов)
    board_version_id	FK → analytics_board_versions.id	Версия доски, по которой заполнен отчёт
    period_id	FK → analytics_periods.id	Период отчёта
    created_by	FK → "user".id	Пользователь, который создал отчёт
    status	enum	draft / confirmed
    comment	text	Комментарий к отчёту (опционально)
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения
    UNIQUE(organization_id, board_id, period_id)		Один отчёт на организацию/доску/период (независимо от версии)
    Примечание: `board_id` дублирует `analytics_board_versions.board_id` выбранной версии. При сохранении отчёта сервис обязан выставить `board_id` в ту же доску, что и у `board_version_id` (консистентность на уровне приложения; при желании можно добавить CHECK в БД).
    Правило назначения периода (актуально):
    - Период отчёта определяется автоматически как текущая ISO-неделя по timezone `Europe/Moscow`.
    - Если запись в `analytics_periods` для `(iso_year, iso_week)` уже есть — используется она.
    - Если записи нет — период создаётся автоматически и сразу используется отчётом.

--------------------------
Проверка полноты отчёта (required-метрики)
1) Перед переходом `draft -> confirmed` сервис обязан проверить, что все `is_required = true` из `analytics_board_version_metrics` имеют значение в `analytics_report_values`.
2) Если хотя бы одна обязательная метрика не заполнена, подтверждение отклоняется с ошибкой валидации.
3) Полнота не хранится в БД — вычисляется по требованию (при подтверждении и в выборках для UI).

10. analytics_report_values
    id	PK	Уникальный идентификатор
    report_id	FK → analytics_reports.id	Отчёт, к которому относится значение
    board_version_metric_id	FK → analytics_board_version_metrics.id	Метрика из конкретной версии доски
    value_number decimal NULL
    value_text string NULL
    value_bool boolean NULL
    value_json	JSONB NULL	Структурированное значение (select / multi-select / произвольный JSON); в MVP может быть пустым
    created_by	FK → "user".id	Кто внес данные
    created_at	timestamp	Технический timestamp создания записи
    updated_at	timestamp	Дата последнего изменения значения
    UNIQUE(report_id, board_version_metric_id)		Одна метрика версии один раз в отчёте
    CHECK (
      (value_number IS NOT NULL)::int +
      (value_text IS NOT NULL)::int +
      (value_bool IS NOT NULL)::int +
      (value_json IS NOT NULL)::int = 1
    )		Ровно одно из полей значения заполнено (скаляр ИЛИ JSON)

    Про value_json (future-proof):
    - Примеры: одиночный select `{"code":"fuel_a"}`; multi-select `["a","b"]`; при необходимости снимок подписей рядом с кодами.
    - Агрегации sum/avg/min/max и предрасчёт в `analytics_aggregated_data` по умолчанию только для числовых метрик; для JSON — отображение, отдельные отчёты или правила на уровне сервиса.
    - Индексация по содержимому JSON (GIN и т.д.) — по мере появления запросов.

--------------------------
Защита истории отчётов от изменений справочника метрик
1) Snapshot-полей (`metric_name_snapshot`, `metric_unit_snapshot`, `metric_type_snapshot`) в `analytics_report_values` и `analytics_aggregated_data` больше нет — подписи и единицы берутся напрямую из `analytics_metrics` через JOIN по `metric_id` (для агрегатов — напрямую, для значений отчётов — через `board_version_metric → metric`).
2) Историческая корректность обеспечивается жёсткой иммутабельностью ключевых полей метрики на уровне сервиса `UpdateMetricService`:
   - `business_key` — неизменяем всегда (якорь бизнес-смысла для графиков);
   - `type` и `unit` — заблокированы после появления первой записи в `analytics_report_values` для этой метрики, иначе график исторических значений может смешать литры с километрами;
   - `name` — можно менять свободно, это просто подпись; при показе исторического отчёта она возьмётся в текущей редакции.
3) Удаление метрики из `analytics_metrics` запрещено через `ON DELETE RESTRICT` на всех ссылающихся FK; деактивация — только `is_active = false`.
4) Если меняется именно бизнес-смысл метрики (а не подпись/уточнение представления) — создаётся новая запись в `analytics_metrics` с новым `business_key`. Старые отчёты остаются на исходной метрике.

11. analytics_aggregated_data
    - id
    - metric_id	FK → analytics_metrics.id	Ссылка на метрику (целостность, защита от удаления строки метрики при наличии агрегатов)
    - period_id	FK → analytics_periods.id	Ссылка на период
    - organization_id	FK → organization.id	Ссылка на организацию
    - business_key	string	Денормализация `analytics_metrics.business_key` для фильтров графиков без JOIN; при записи должна совпадать с метрикой
    - aggregation_type	string	Правило агрегации: sum | avg | min | max | last
    - value	DECIMAL(20,4)	Агрегированное значение
    - source_count	int	Количество raw-записей, участвовавших в расчёте
    - calculated_at	timestamp	Время последнего пересчёта
    - UNIQUE(metric_id, period_id, organization_id)		Один агрегат на метрику / период / организацию
    Примечание: агрегаты привязаны к метрике, периоду и организации. При подтверждении отчёта значения агрегируются по правилу `aggregation_type` метрики (sum/avg/min/max/last). Подписи/единицы при отображении графиков берутся из `analytics_metrics` через JOIN по `metric_id`.

--------------------------
Консистентность агрегатов (выбранный подход для MVP)
Выбран Вариант A (простой и достаточный для MVP+):
1) Пересчёт агрегатов выполняется при переходе отчёта в `confirmed`.
2) Пересчёт агрегатов выполняется при любом изменении уже confirmed-отчёта (если такой сценарий разрешён).
3) Пересчёт делается адресно по связке `metric_id + period_id + organization_id` (канонический ключ строки; `business_key` синхронизировать с метрикой), без полного rebuild.
4) До первого пересчёта графики могут читать данные напрямую из `analytics_report_values` (fallback), если запись в агрегатах отсутствует.
5) Если в проекте будет разрешено массовое ретро-редактирование, следующий шаг — перейти на Вариант B (`source_updated_at`) или materialized view/джобу.
6) `RecalculateAggregatesService` выполняется в транзакции.
7) При upsert агрегата используется блокировка строки по ключу (`metric_id`, `period_id`, `organization_id`) для защиты от гонок.

--------------------------
Защита от "битых" агрегатов
1) При пересчёте агрегата сохраняется `source_count` (сколько исходных значений попало в расчёт).
2) При повторном пересчёте сравниваются:
   - новый `aggregated_value_number`,
   - новый `source_count`,
   - предыдущие значения в `analytics_aggregated_data`.
3) Если `source_count` изменился, а агрегат не обновился, запись считается неактуальной и пересчитывается принудительно.
4) Для мониторинга можно добавить периодическую сверку: пересчитать sample-подмножество и сравнить с сохранёнными агрегатами.

--------------------------
Критичные индексы (MVP)
1) `analytics_report_values`
   - INDEX(report_id)
   - INDEX(board_version_metric_id)
   - INDEX(report_id, board_version_metric_id)
2) `analytics_reports`
   - INDEX(period_id)
   - INDEX(organization_id)
   - INDEX(organization_id, period_id) — отчёты организации за период
   - INDEX(board_id)
   - INDEX(status)
3) `analytics_aggregated_data`
   - UNIQUE(metric_id, period_id, organization_id) покрывает точечные выборки по тройке
   - INDEX(period_id, organization_id)
   - INDEX(business_key, organization_id) — фильтры графиков по стабильному ключу без JOIN
   - INDEX(metric_id, organization_id) — ряды по метрике в разрезе организации
4) `analytics_periods`
   - UNIQUE(iso_year, iso_week) уже даёт индекс для выборки по неделе
   - INDEX(start_date) — сортировки и диапазоны по датам
Примечание: без этих индексов выборки для графиков и сводной аналитики будут деградировать на росте данных.

--------------------------
ENUM/CHECK ограничения (обязательно в БД)
1) `analytics_reports.status`:
   - CHECK/ENUM: `draft | confirmed`.
2) `analytics_metrics.aggregation_type`:
   - CHECK/ENUM: `sum | avg | min | max | last`.
3) `analytics_metrics.type`:
   - рекомендуется CHECK/ENUM (например: `number | currency | distance | liters | count | text | bool`) и единый справочник допустимых типов.
4) Для `analytics_report_values` — CHECK: ровно одно из `value_number`, `value_text`, `value_bool`, `value_json` заполнено.

--------------------------
FK стратегии (ON DELETE / ON UPDATE)
Базовое правило: для исторических сущностей использовать `ON DELETE RESTRICT`, чтобы не ломать историю отчётов.

Рекомендованные стратегии:
1) `analytics_board_versions.board_id -> analytics_boards.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
2) `analytics_board_version_metrics.board_version_id -> analytics_board_versions.id`
   - ON DELETE CASCADE, ON UPDATE CASCADE
3) `analytics_board_version_metrics.metric_id -> analytics_metrics.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
4) `analytics_organization_boards.organization_id -> organization.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
5) `analytics_organization_boards.board_id -> analytics_boards.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
6) `analytics_reports.organization_id -> organization.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
7) `analytics_reports.board_id -> analytics_boards.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
8) `analytics_reports.board_version_id -> analytics_board_versions.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
9) `analytics_reports.period_id -> analytics_periods.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
10) `analytics_reports.created_by -> "user".id`
    - ON DELETE RESTRICT, ON UPDATE CASCADE
11) `analytics_report_values.report_id -> analytics_reports.id`
    - ON DELETE CASCADE, ON UPDATE CASCADE
12) `analytics_report_values.board_version_metric_id -> analytics_board_version_metrics.id`
    - ON DELETE RESTRICT, ON UPDATE CASCADE
13) `analytics_report_values.created_by -> "user".id`
    - ON DELETE RESTRICT, ON UPDATE CASCADE
14) `analytics_aggregated_data.metric_id -> analytics_metrics.id`
    - ON DELETE RESTRICT, ON UPDATE CASCADE

--------------------------
    Версионирование.

Инструкция по изменению метрик (через версию доски)
1) Активная версия доски определяется полем `analytics_boards.active_version_id`.
2) Если нужно добавить/удалить/изменить метрику:
   - создать новую версию доски из текущей активной версии (копия состава метрик),
   - внести изменения в новой неактивной версии,
   - сделать новую версию активной: обновить `analytics_boards.active_version_id` на id новой версии.
3) Все новые отчёты создаются только по текущей активной версии.
4) Старые отчёты не изменяются и остаются привязаны к своей версии (analytics_reports.board_version_id).
5) Пример:
   - v1: топливо, запчасти; `analytics_boards.active_version_id = v1`
   - через месяц добавили "простой" -> создаём v2, редактируем состав, делаем v2 активной
   - отчёты до изменения остаются на v1, новые отчёты идут на v2.

--------------------------
Стабильность бизнес-смысла метрики
1) `analytics_metrics.business_key` — неизменяемый идентификатор бизнес-смысла.
2) По `business_key` метрика узнаётся в длинной истории (через разные версии досок).
3) `name/unit/type` можно изменять, но это должно отражать только уточнение представления.
4) Если меняется именно бизнес-смысл (не просто подпись/единица), создаётся новая запись в `analytics_metrics` с новым `business_key`.
5) В графиках длинного периода объединение метрик делается по `business_key`, а не только по названию/версии.

--------------------------
Правила агрегации метрик
1) Для каждой метрики задаётся `analytics_metrics.aggregation_type`:
   - `sum` / `avg` / `min` / `max` / `last`.
2) Агрегация в отчётах/графиках выбирается по настройке метрики, а не хардкодится в коде.
3) Примеры:
   - расход топлива -> `sum`
   - прибыль -> `sum`
   - количество сотрудников -> `last`
   - средняя скорость -> `avg`
4) Для `aggregation_type = last`:
   - в `analytics_report_values` действует `UNIQUE(report_id, board_version_metric_id)`, значит в одном периоде есть ровно одно значение метрики у организации — выбирать «последнее» внутри периода не из чего;
   - для серий по нескольким периодам берётся значение из отчёта в периоде с самой поздней `analytics_periods.end_date`.
5) Семантика timestamp-полей:
   - бизнес-время значения задаётся периодом отчёта (`analytics_reports.period_id` → `analytics_periods.start_date`/`end_date`);
   - `created_at` / `updated_at` = технические timestamps хранения/редактирования записи.

--------------------------
Правила MVP
1) В диаграммы и сводные отчёты попадают только confirmed-отчёты.
2) Новый отчёт создаётся только по текущей активной версии доски (`analytics_boards.active_version_id`).
3) Исторические отчёты остаются привязаны к версии, по которой были созданы.
4) Редактирование значений запрещено после перевода отчёта в `confirmed`.
5) Перевод в `confirmed` разрешён только если отчёт полный по required-метрикам.

--------------------------
Как будут строиться графики
Пример:
"Расход топлива по неделям"
SELECT period_id, aggregated_value_number
FROM analytics_aggregated_data
WHERE business_key = 'fuel_consumption'
"Сравнение организаций"
SELECT organization_id, aggregated_value_number
FROM analytics_aggregated_data
WHERE business_key = :target_business_key
  AND period_id = :period_id

Важно:
- в реальном запросе агрегат (`SUM/AVG/MIN/MAX/last`) определяется по `analytics_metrics.aggregation_type`;
- `analytics_aggregated_data.aggregated_value_number` уже содержит предрасчитанное значение по правилу метрики.

Важно для графиков по длинному периоду (когда версии менялись):
- использовать фильтрацию по `business_key` (или по `metric_id` с JOIN к `analytics_metrics` при необходимости);
- версии досок не влияют на чтение агрегатов;
- сложные JOIN по `board_version_metric_id` для длинной истории не требуются.

--------------------------
Архитектура приложения (Symfony, CQRS-lite)
Главная идея:
- разделять контур записи (создание/изменение отчётов) и контур чтения (графики/дашборды).
- не строить архитектуру вокруг таблиц; строить вокруг доменов.

Домены:
1) Data Collection
   - формы по доскам, отчёты, значения метрик.
2) Analytics Config
   - метрики, доски, версии.
3) Analytics Read / BI
   - агрегаты, графики, дашборды.

Логические модули:
1) `AnalyticsConfigModule`
   - сервисы: `CreateMetricService`, `CloneBoardVersionService`, `PublishBoardVersionService`.
2) `AnalyticsCollectionModule`
   - сервисы: `CreateReportService`, `FillReportValueService`, `ApproveReportService` (метод `confirm`).
   - здесь обязательна логика required-метрик; иммутабельность confirmed-отчётов.
3) `AnalyticsAggregationModule`
   - сервисы: `RecalculateAggregatesService`, `AggregateByMetricService`.
   - запуск при confirmed и при изменении confirmed-отчёта.
4) `AnalyticsReadModule`
   - сервисы: `GetChartDataService`, `GetDashboardDataService`.
   - чтение из `analytics_aggregated_data`, fallback на raw-данные.

Критичные принципы реализации:
1) Агрегацию делать только в application services.
2) Не переносить бизнес-агрегацию в контроллеры и Doctrine listeners.
3) Поток данных:
   - draft -> confirmed (валидация required + триггер пересчёта агрегатов);
   - чтение графиков только из read-контура.

Приоритеты внедрения:
1) Обязательно в MVP:
   - разделение Config / Collection / Read;
   - lifecycle `draft/confirmed` + валидация полноты;
   - пересчёт агрегатов по событиям;
   - контур чтения (дашборд/графики) отдельно от контура записи (отчёты); в MVP — Twig, без отдельного HTTP API.
2) Ближайший этап:
   - асинхронный пересчёт через Symfony Messenger;
   - кэш графиков (Redis) по `business_key + filters`.
3) Можно отложить:
   - `analytics_dashboard_snapshots` (денормализация для быстрых директорских дашбордов);
   - обязательный `organization_id` во всех сущностях (если multi-tenant не нужен сейчас).

--------------------------
Жизненный цикл работы с доской (практический сценарий)
1) Администратор создаёт метрики:
   - добавляет записи в `analytics_metrics` (`business_key`, `type`, `unit`, `aggregation_type`).
2) Администратор создаёт доску:
   - создаёт запись в `analytics_boards`.
3) Администратор формирует версию доски:
   - создаёт `analytics_board_versions`;
   - добавляет состав метрик в `analytics_board_version_metrics` (`position`, `is_required`);
   - делает версию активной через `analytics_boards.active_version_id`.
4) Администратор назначает доску организации:
   - создаёт связь в `analytics_organization_boards` (`organization_id`, `board_id`, `is_required`).
5) Руководитель организации заполняет отчёт:
   - создаётся `analytics_reports` по текущей активной версии доски (`board_version_id` + тот же `board_id`, что у версии);
   - значения вносятся в `analytics_report_values`.
6) Подтверждение отчёта:
   - `draft -> confirmed` только после проверки required-метрик;
   - сразу запускается пересчёт агрегатов в `analytics_aggregated_data`.

Важно:
- пользователь заполняет отчёт всегда по конкретной версии доски (`board_version_id`), а не по "доске вообще";
- это гарантирует корректную историчность при изменениях состава метрик.

--------------------------
UI без API: Twig + верстка (без Symfony Form)
Интерфейс — обычные маршруты Symfony (GET/POST), шаблоны Twig с обычным HTML (`<form>`, `<input>`, `<select>`); Symfony Form не используется. Бизнес-логика в сервисах.

Контроллеры (пример разбиения):
1) `AnalyticsAdminMetricController` — CRUD метрик (только админ).
2) `AnalyticsAdminBoardController` — доски, версии, состав метрик версии, выбор активной версии.
3) `AnalyticsAdminOrganizationBoardController` — назначение досок организации (`analytics_organization_boards`).
4) `AnalyticsReportController` — список отчётов, создание draft, страница заполнения, confirm.
5) `AnalyticsDashboardController` — дашборд и страницы графиков (read-only).

Минимальный набор шаблонов Twig:
- `analytics/admin/metric/index.html.twig`, `new.html.twig`, `edit.html.twig`
- `analytics/admin/board/index.html.twig`, `new.html.twig`, `show.html.twig` (версии доски)
- `analytics/admin/board/version_edit.html.twig` (состав метрик версии: порядок, required)
- `analytics/admin/organization_board/index.html.twig` (назначение досок)
- `analytics/report/index.html.twig`, `new.html.twig`, `fill.html.twig` (поля отчёта в разметке)
- `analytics/dashboard/index.html.twig`, `analytics/chart/show.html.twig` (фильтры через GET)

Обработка POST без FormType:
- контроллер читает `Request` (`$request->request->all()` или именованные параметры);
- валидация и приведение типов — в dedicated-сервисах (например `SaveMetricFromRequest`, `SaveReportValuesFromRequest`);
- динамические поля отчёта: в шаблоне цикл по `board_version_metrics`, имена полей вида `values[{{ boardVersionMetricId }}]` или префикс + id; сервис парсит массив и пишет `AnalyticsReportValue` (значение + `created_by`); подписи/единицы берутся из `analytics_metrics` через JOIN;
- CSRF: в каждом `<form method="post">` скрытое поле через `csrf_token('...')` и проверка в контроллере через `CsrfTokenManagerInterface` / `isCsrfTokenValid`;
- confirm — POST-форма (или одна страница с несколькими формами), только с токеном и нужными скрытыми полями.

Принципы:
- POST для всех изменений данных; редирект после успеха (PRG).
- Проверка статуса отчёта в сервисе до сохранения.
- Права: `IsGranted` / Voter на маршруты админки, на confirm, на просмотр чужих отчётов.

Итого (объём UI):
- Контроллеров: 5 (как в списке выше). Действие confirm живёт на `AnalyticsReportController`.
- Страниц (отдельных Twig-шаблонов): 13 (без базового layout).
  - админ / метрики: 3 (`index`, `new`, `edit`);
  - админ / доски: 4 (`index`, `new`, `show`, `version_edit`);
  - админ / назначение досок: 1 (`organization_board/index`);
  - отчёты: 3 (`index`, `new`, `fill`);
  - чтение: 2 (`dashboard/index`, `chart/show`).
- Дополнительно: сделать версию активной, confirm — обычно POST + редирект с уже перечисленных страниц, отдельные twig под это не обязательны.



Страницы (Twig) — 13 шаблонов (без базового layout)
Админ — метрики (3)
analytics/admin/metric/index.html.twig
analytics/admin/metric/new.html.twig
analytics/admin/metric/edit.html.twig
Админ — доски (4)
analytics/admin/board/index.html.twig
analytics/admin/board/new.html.twig
analytics/admin/board/show.html.twig
analytics/admin/board/version_edit.html.twig
Админ — организации (1)
analytics/admin/organization_board/index.html.twig
Отчёты (3)
analytics/report/index.html.twig
analytics/report/new.html.twig
analytics/report/fill.html.twig
Аналитика (2)
analytics/dashboard/index.html.twig
analytics/chart/show.html.twig


$ php bin/console doctrine:fixtures:load --no-interaction --append --group=analytics



Руководство: аналитика в веб-интерфейсе и соответствие реализации
================================================================

Связка с Readme_analitics.txt: там — модель данных и правила; здесь —
порядок действий в UI, оценка кода и замечания по удобству.

--------------------------------------------------------------------------------
1. С чего начать (порядок в интерфейсе)
--------------------------------------------------------------------------------

Цепочка из Readme_analitics.txt: настройка → сбор данных → подтверждение →
просмотр. В проекте она отражена так же.

1.1. Настройки аналитики (боковое меню; только ROLE_ADMIN)

  Шаг | Пункт меню                         | Действия
  ----|------------------------------------|------------------------------------------
  1   | Настройки аналитики → Метрики      | Создать метрики: business_key, тип,
      |                                    | единица, aggregation_type.
  2   | Доски                              | Новая доска → на карточке доски активная версия;
      |                                    | для изменений создать новую версию,
      |                                    | отредактировать состав и сделать её активной.
  3   | Организации и доски               | Привязать доску к организации.

  Периоды создаются автоматически при создании отчёта (по type доски и текущей дате в Europe/Moscow); отдельной админки периодов нет.

1.2. Работа с данными (меню «Аналитика»)

  • ROLE_MANAGER: в подменю только «Отчеты» (заполнение и подтверждение).
  • ROLE_ADMIN: плюс Дашборд, Сравнительная аналитика.

  Шаг | Пункт меню                    | Действия
  ----|-------------------------------|--------------------------------------------
  1   | Отчёты                        | Новый отчёт → доска → Заполнить →
      |                               | сохранить → Подтвердить (после этого
      |                               | пишутся агрегаты).
  2   | Дашборд / Сравнительная       | Фильтры: доска, период, организация
      | аналитика                     | (GET-форма на странице).

1.3. Тестовые данные

  php bin/console doctrine:fixtures:load --no-interaction --append --group=analytics

Основные маршруты (для ссылок и отладки):

  /analytics/admin/metric          — метрики
  /analytics/admin/board           — доски
  /analytics/admin/organization/board — организации и доски
  /analytics/report                — отчёты (подтверждение прямо в карточке/списке)
  /analytics/dashboard             — дашборд
  /analytics/chart                 — сравнительная аналитика

--------------------------------------------------------------------------------
2. Правильно ли реализовано (в целом да, с оговорками)
--------------------------------------------------------------------------------

Совпадает с идеей Readme_analitics.txt:

  • Версии досок, активная версия через `active_version_id`, привязка досок к организациям, периоды.
  • Отчёты: draft → confirmed; проверка обязательных метрик при confirm.
  • Подписи/единицы метрик в отчётах берутся через JOIN к `analytics_metrics`,
    без снимков в `analytics_report_values`.
  • После confirm вызывается пересчёт/запись агрегатов:
    App\Service\Analytics\ApproveReportService::confirm() вызывает
    RecalculateAggregatesService::recalculateForReport().

Оговорки относительно «идеальной» картинки в Readme_analitics.txt:

  • В readme для analytics_aggregated_data в примере заложена одна строка на
    связку метрика + период + организация и агрегация sum/avg/... по правилу
    метрики. Текущий RecalculateAggregatesService по сути копирует значения из
    подтверждённого отчёта в строки агрегатов (с привязкой к report_id). При
    нескольких отчётах за один период поведение может отличаться от ожидания
    «одно число на организацию/неделю/метрику» без уточнения в коде.
  • После перехода на `analytics_boards.active_version_id` нужно сверить миграцию:
    старая колонка `analytics_board_versions.status` должна уйти, а новая FK-связь
    `active_version_id` должна появиться на `analytics_boards`.

--------------------------------------------------------------------------------
3. Удобно ли организовано
--------------------------------------------------------------------------------

Плюсы:

  • Логичное разделение «Настройки аналитики» и «Аналитика».
  • Разбиение контроллеров соответствует черновику в Readme_analitics.txt.
  • Предсказуемые URL под /analytics/...

Меню (templates/sidebar/sidebar_list.html.twig):

  • «Аналитика» — при ROLE_ADMIN или ROLE_MANAGER; подпункты дашборда и
    сравнительной аналитики только у ROLE_ADMIN; «Отчеты» — у обоих
    (ROLE_ADMIN в security.yaml наследует ROLE_MANAGER).
  • «Настройки аналитики» — только ROLE_ADMIN.
  • Маршруты отчётов по-прежнему доступны любому авторизованному пользователю
    с привязкой к организации (см. AnalyticsReportController); меню «Отчеты»
    для менеджеров даёт нормальный вход без прямой ссылки.

Подтверждение выполняется прямо со страницы отчёта (`AnalyticsReportController::confirm`),
отдельный экран/контроллер не выделяется.

--------------------------------------------------------------------------------
4. Краткая шпаргалка
--------------------------------------------------------------------------------

  Настройка: Метрики → Доски (версии + активная версия) → Организации и доски.
  Работа:   Отчёты (с подтверждением) → Дашборд / сравнительная аналитика.
  Периоды создаются автоматически при создании отчёта.

Дата добавления файла: 2026-04-09.



// =======================================================

Финансы предприятия
	1.
ТКО
	Ключевые метрики.
		1. Топливо
		2. Километраж
		3. ТКО

Клиентов
	- При
	- Остатки дс на щетах
	-


По каким показателям будут сравниваться филиалы.
