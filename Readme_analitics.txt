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
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения

5. analytics_board_versions
    id	PK	Уникальный идентификатор версии
    board_id	FK → analytics_boards.id	Доска
    version_number	int	Номер версии (1,2,3...)
    status	enum	draft / published / archived
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения (для draft-правок и смены статуса)
    UNIQUE(board_id, version_number)		Уникальность номера версии внутри доски
    (PostgreSQL) UNIQUE(board_id) WHERE status = 'published'		Не более одной опубликованной версии на доску; добавить вручную в миграцию (Doctrine mapping не выражает), имя индекса например `uniq_analytics_board_versions_one_published_per_board`

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
    period_date	date NULL	Конкретная дата (для daily)
    iso_year	int NULL	Год по ISO 8601 (для weekly)
    iso_week	int NULL	Номер недели по ISO 8601 (1..53, для weekly)
    year	int NULL	Календарный год (для monthly)
    month	int NULL	Месяц (1..12, для monthly)
    start_date	date	Начало периода (заполнено всегда)
    end_date	date	Конец периода (заполнено всегда)
    is_closed	boolean	Закрыт ли период для редактирования
    description	string	Дополнительно, например «2026-W03» или «Апрель 2026» или «15.04.2026»
    created_at	timestamp	Дата создания периода
    updated_at	timestamp	Дата последнего изменения (например, закрытие периода)
    UNIQUE(period_date)		Один daily-период на дату (NULL пропускается для других типов)
    UNIQUE(iso_year, iso_week)		Одна ISO-неделя в системе (NULL пропускается для других типов)
    UNIQUE(year, month)		Один monthly-период на месяц (NULL пропускается для других типов)

    Семантика ISO 8601 (важно, для weekly):
    - Неделя начинается с понедельника; неделя 1 — та, где есть первый четверг года.
    - У границы декабрь/январь «неделя 1» может начинаться в предыдущем календарном году, а последние дни декабря относиться к iso_year следующего года.
    - Нельзя хранить «календарный год + номер недели» без уточнения: пара (iso_year, iso_week) согласована со стандартом.
    - При создании периода удобно выставлять start_date/end_date через `DateTimeImmutable::setISODate(iso_year, iso_week)` (в сущности — фабрика `AnalyticsPeriod::forIsoWeek`).

    Фабрики создания:
    - `AnalyticsPeriod::forIsoWeek(isoYear, isoWeek)` — weekly
    - `AnalyticsPeriod::forDate(date)` — daily
    - `AnalyticsPeriod::forMonth(year, month)` — monthly

    Правило назначения периода (обновлено):
    - Период отчёта определяется автоматически по `analytics_boards.period_type` и текущей дате (timezone `Europe/Moscow`).
    - daily → период за сегодня; weekly → текущая ISO-неделя; monthly → текущий месяц.
    - Если запись уже есть — используется она. Если нет — создаётся автоматически.

9. analytics_reports
    id	PK	Уникальный идентификатор отчёта
    organization_id	FK → organization.id	Организация, которая заполняет отчёт
    board_id	FK → analytics_boards.id	Доска (денормализация для уникальности и запросов)
    board_version_id	FK → analytics_board_versions.id	Версия доски, по которой заполнен отчёт
    period_id	FK → analytics_periods.id	Период отчёта
    created_by	FK → "user".id	Пользователь, который создал отчёт
    status	enum	draft / submitted / approved
    is_complete	boolean	Все обязательные метрики заполнены (кэш результата проверки)
    comment	text	Комментарий к отчёту (опционально)
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения (пока отчёт не approved)
    submitted_at	timestamp	Дата отправки (если есть)
    approved_at	timestamp	Дата утверждения (если есть)
    approved_by	FK → "user".id	Кто утвердил отчёт (если есть)
    UNIQUE(organization_id, board_id, period_id)		Один отчёт на организацию/доску/период (независимо от версии)
    Примечание: `board_id` дублирует `analytics_board_versions.board_id` выбранной версии. При сохранении отчёта сервис обязан выставить `board_id` в ту же доску, что и у `board_version_id` (консистентность на уровне приложения; при желании можно добавить CHECK в БД).
    Правило назначения периода (актуально):
    - Период отчёта определяется автоматически как текущая ISO-неделя по timezone `Europe/Moscow`.
    - Если запись в `analytics_periods` для `(iso_year, iso_week)` уже есть — используется она.
    - Если записи нет — период создаётся автоматически и сразу используется отчётом.

--------------------------
Проверка полноты отчёта (required-метрики)
1) Перед переводом `draft -> submitted` сервис обязан проверить, что все `is_required = true` из `analytics_board_version_metrics` имеют значение в `analytics_report_values`.
2) Если хотя бы одна обязательная метрика не заполнена, submit отклоняется с ошибкой валидации.
3) `analytics_reports.is_complete` хранит последний результат проверки полноты (опционально для ускорения UI/списков).
4) Для `status = submitted/approved` значение `is_complete` должно быть `true`.

10. analytics_report_values
    id	PK	Уникальный идентификатор
    report_id	FK → analytics_reports.id	Отчёт, к которому относится значение
    board_version_metric_id	FK → analytics_board_version_metrics.id	Метрика из конкретной версии доски
    metric_name_snapshot	string	Название метрики на момент заполнения отчёта
    metric_unit_snapshot	string	Единица измерения на момент заполнения отчёта
    metric_type_snapshot	string	Тип метрики на момент заполнения отчёта
    value_number decimal NULL
    value_text string NULL
    value_bool boolean NULL
    value_json	JSONB NULL	Структурированное значение (select / multi-select / произвольный JSON); в MVP может быть пустым
    effective_at	timestamp NOT NULL DEFAULT NOW()	Business timestamp: момент, на который действует значение (используется для агрегации `last`)
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
Snapshot метрики в отчёте
1) При создании `analytics_report_values` сохраняется snapshot метрики:
   - `metric_name_snapshot`
   - `metric_unit_snapshot`
   - `metric_type_snapshot`
2) Исторические отчёты отображаются по snapshot-полям, а не по текущему состоянию `analytics_metrics`.
3) Это защищает историю от изменений справочника метрик (переименование, смена unit/type).
4) Пример: если метрика позже изменилась с `км` на `мили`, старые отчёты продолжают показываться в `км`.
5) Для расчёта `aggregation_type = last` используется `effective_at` (а не технические `created_at/updated_at`).

11. analytics_aggregated_data (optional)
    - id
    - metric_id	FK → analytics_metrics.id	Ссылка на метрику (целостность, защита от удаления строки метрики при наличии агрегатов)
    - business_key	string	Денормализация `analytics_metrics.business_key` для фильтров графиков без JOIN; при записи должна совпадать с метрикой
    - period_id
    - organization_id
    - aggregated_value_number
    - source_count
    - calculated_at
    - UNIQUE(metric_id, period_id, organization_id)		Один агрегат на метрику / период / организацию
    Примечание: агрегаты логически привязаны к метрике (`metric_id`), а не к версии доски. `business_key` дублируется для удобства чтения и длинной истории по стабильному ключу.
    `source_count` = количество raw-записей (`analytics_report_values`), участвовавших в расчёте агрегата.

--------------------------
Консистентность агрегатов (выбранный подход для MVP)
Выбран Вариант A (простой и достаточный для MVP+):
1) Пересчёт агрегатов выполняется при переходе отчёта в `approved`.
2) Пересчёт агрегатов выполняется при любом изменении уже approved-отчёта (если такой сценарий разрешён).
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
   - INDEX(board_version_metric_id, effective_at) — агрегация `last` (MAX(effective_at)) по метрике версии доски; в PostgreSQL при желании усилить планировщик: вторую колонку объявить `DESC` в ручной миграции (Doctrine mapping это не выражает)
   - INDEX(effective_at) — выборки только по времени без фильтра по метрике
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
1) `analytics_board_versions.status`:
   - CHECK/ENUM: `draft | published | archived`.
   - (PostgreSQL) частичный уникальный индекс: одна строка с `published` на `board_id` — дописать SQL в миграцию после `doctrine:migrations:diff` (генератор сам не создаёт).
2) `analytics_reports.status`:
   - CHECK/ENUM: `draft | submitted | approved`.
3) `analytics_metrics.aggregation_type`:
   - CHECK/ENUM: `sum | avg | min | max | last`.
4) `analytics_metrics.type`:
   - рекомендуется CHECK/ENUM (например: `number | currency | distance | liters | count | text | bool`) и единый справочник допустимых типов.
5) Для `analytics_report_values` — CHECK: ровно одно из `value_number`, `value_text`, `value_bool`, `value_json` заполнено.

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
10) `analytics_reports.created_by / approved_by -> "user".id`
    - ON DELETE RESTRICT (или SET NULL для `approved_by`, если допустим soft-delete пользователей), ON UPDATE CASCADE
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
1) Published-версию нельзя редактировать напрямую.
2) Если нужно добавить/удалить/изменить метрику:
   - создать новую версию доски из текущей (копия состава метрик),
   - внести изменения в новой версии (draft),
   - в одной транзакции: перевести предыдущую published в `archived`, затем выставить новой `published` (иначе нарушится частичный UNIQUE по `board_id` для `published` в PostgreSQL),
   - либо сначала archived у старой, затем published у новой — без окна «две published».
3) Все новые отчёты создаются только по текущей published-версии.
4) Старые отчёты не изменяются и остаются привязаны к своей версии (analytics_reports.board_version_id).
5) Пример:
   - v1: топливо, запчасти
   - через месяц добавили "простой" -> создаём v2 и публикуем
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
4) Для `aggregation_type = last` используется только одно правило:
   - брать значение с максимальным `effective_at` внутри периода (`MAX(effective_at)`).
5) Семантика timestamp-полей:
   - `effective_at` = business timestamp (момент актуальности показателя);
   - `created_at` / `updated_at` = технические timestamps хранения/редактирования записи.

--------------------------
Правила MVP
1) В диаграммы и сводные отчёты попадают только approved-отчёты.
2) Published-версия доски не редактируется (изменения только через новую draft-версию).
3) Новый отчёт создаётся только по текущей published-версии доски.
4) Редактирование отчётов запрещено, если analytics_periods.is_closed = true.
5) Перевод в `submitted` разрешён только если отчёт полный по required-метрикам (`is_complete = true`).

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
   - сервисы: `CreateReportService`, `FillReportValueService`, `SubmitReportService`, `ApproveReportService`.
   - здесь обязательна логика required/snapshot/блокировок периода.
3) `AnalyticsAggregationModule`
   - сервисы: `RecalculateAggregatesService`, `AggregateByMetricService`.
   - запуск при approved и при изменении approved-отчёта.
4) `AnalyticsReadModule`
   - сервисы: `GetChartDataService`, `GetDashboardDataService`.
   - чтение из `analytics_aggregated_data`, fallback на raw-данные.

Критичные принципы реализации:
1) Агрегацию делать только в application services.
2) Не переносить бизнес-агрегацию в контроллеры и Doctrine listeners.
3) Поток данных:
   - draft -> submitted (валидация required + `is_complete`);
   - submitted -> approved (триггер пересчёта агрегатов);
   - чтение графиков только из read-контура.

Приоритеты внедрения:
1) Обязательно в MVP:
   - разделение Config / Collection / Read;
   - lifecycle `draft/submitted/approved` + валидация полноты;
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
   - создаёт `analytics_board_versions` со статусом `draft`;
   - добавляет состав метрик в `analytics_board_version_metrics` (`position`, `is_required`);
   - публикует версию (`status = published`).
4) Администратор назначает доску организации:
   - создаёт связь в `analytics_organization_boards` (`organization_id`, `board_id`, `is_required`).
5) Руководитель организации заполняет отчёт:
   - создаётся `analytics_reports` по текущей published-версии доски (`board_version_id` + тот же `board_id`, что у версии);
   - значения вносятся в `analytics_report_values`.
6) Отправка отчёта:
   - `draft -> submitted` только после проверки required-метрик (`is_complete = true`).
7) Утверждение отчёта:
   - `submitted -> approved`;
   - запускается пересчёт агрегатов в `analytics_aggregated_data`.

Важно:
- пользователь заполняет отчёт всегда по конкретной версии доски (`board_version_id`), а не по "доске вообще";
- это гарантирует корректную историчность при изменениях состава метрик.

--------------------------
UI без API: Twig + верстка (без Symfony Form)
Интерфейс — обычные маршруты Symfony (GET/POST), шаблоны Twig с обычным HTML (`<form>`, `<input>`, `<select>`); Symfony Form не используется. Бизнес-логика в сервисах.

Контроллеры (пример разбиения):
1) `AnalyticsAdminMetricController` — CRUD метрик (только админ).
2) `AnalyticsAdminBoardController` — доски, версии, состав метрик версии, publish.
3) `AnalyticsAdminOrganizationBoardController` — назначение досок организации (`analytics_organization_boards`).
4) `AnalyticsAdminPeriodController` — список периодов, закрытие/открытие.
5) `AnalyticsReportController` — список отчётов, создание draft, страница заполнения, submit.
6) `AnalyticsApprovalController` — approve отчёта (отдельная роль/проверка доступа).
7) `AnalyticsDashboardController` — дашборд и страницы графиков (read-only).

Минимальный набор шаблонов Twig:
- `analytics/admin/metric/index.html.twig`, `new.html.twig`, `edit.html.twig`
- `analytics/admin/board/index.html.twig`, `new.html.twig`, `show.html.twig` (версии доски)
- `analytics/admin/board/version_edit.html.twig` (состав метрик версии: порядок, required)
- `analytics/admin/organization_board/index.html.twig` (назначение досок)
- `analytics/admin/period/index.html.twig`
- `analytics/report/index.html.twig`, `new.html.twig`, `fill.html.twig` (поля отчёта в разметке)
- `analytics/dashboard/index.html.twig`, `analytics/chart/show.html.twig` (фильтры через GET)

Обработка POST без FormType:
- контроллер читает `Request` (`$request->request->all()` или именованные параметры);
- валидация и приведение типов — в dedicated-сервисах (например `SaveMetricFromRequest`, `SaveReportValuesFromRequest`);
- динамические поля отчёта: в шаблоне цикл по `board_version_metrics`, имена полей вида `values[{{ boardVersionMetricId }}]` или префикс + id; сервис парсит массив и пишет `AnalyticsReportValue` + snapshot + `effective_at`;
- CSRF: в каждом `<form method="post">` скрытое поле через `csrf_token('...')` и проверка в контроллере через `CsrfTokenManagerInterface` / `isCsrfTokenValid`;
- submit / approve — отдельные POST-формы (или одна страница с несколькими формами), только с токеном и нужными скрытыми полями.

Принципы:
- POST для всех изменений данных; редирект после успеха (PRG).
- Проверка периода `is_closed` и статуса отчёта в сервисе до сохранения.
- Права: `IsGranted` / Voter на маршруты админки, на approve, на просмотр чужих отчётов.

Итого (объём UI):
- Контроллеров: 7 (как в списке выше).
  Упрощение: `AnalyticsApprovalController` можно не выделять — действие approve повесить на `AnalyticsReportController`, тогда контроллеров 6.
- Страниц (отдельных Twig-шаблонов): 14 (без базового layout).
  - админ / метрики: 3 (`index`, `new`, `edit`);
  - админ / доски: 4 (`index`, `new`, `show`, `version_edit`);
  - админ / назначение досок: 1 (`organization_board/index`);
  - админ / периоды: 1 (`period/index`);
  - отчёты: 3 (`index`, `new`, `fill`);
  - чтение: 2 (`dashboard/index`, `chart/show`).
- Дополнительно: publish версии, закрытие периода, submit/approve — обычно POST + редирект с уже перечисленных страниц, отдельные twig под это не обязательны.



Страницы (Twig) — 14 шаблонов (без базового layout)
Админ — метрики (3)
analytics/admin/metric/index.html.twig
analytics/admin/metric/new.html.twig
analytics/admin/metric/edit.html.twig
Админ — доски (4)
analytics/admin/board/index.html.twig
analytics/admin/board/new.html.twig
analytics/admin/board/show.html.twig
analytics/admin/board/version_edit.html.twig
Админ — организации и периоды (2)
analytics/admin/organization_board/index.html.twig
analytics/admin/period/index.html.twig
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

Цепочка из Readme_analitics.txt: настройка → сбор данных → утверждение →
просмотр. В проекте она отражена так же.

1.1. Настройки аналитики (боковое меню; только ROLE_ADMIN)

  Шаг | Пункт меню                         | Действия
  ----|------------------------------------|------------------------------------------
  1   | Настройки аналитики → Метрики      | Создать метрики: business_key, тип,
      |                                    | единица, aggregation_type.
  2   | Доски                              | Новая доска → на карточке доски версия;
      |                                    | «Редактировать состав» версии — порядок
      |                                    | метрик, обязательность; опубликовать
      |                                    | черновик (publish).
  3   | Организации и доски               | Привязать доску к организации.
  4   | Периоды                            | Управление периодами (close/open);
      |                                    | создание выполняется автоматически при создании отчёта в новой ISO-неделе.

1.2. Работа с данными (меню «Аналитика»)

  • ROLE_MANAGER: в подменю только «Отчеты» (заполнение и отправка).
  • ROLE_ADMIN: плюс Дашборд, Сравнительная аналитика, Утверждение.

  Шаг | Пункт меню                    | Действия
  ----|-------------------------------|--------------------------------------------
  1   | Отчёты                        | Новый отчёт → доска → Заполнить →
      |                               | сохранить → Отправить на утверждение.
  2   | Утверждение                   | Для отчёта в статусе «отправлен» —
      |                               | утвердить (после этого пишутся агрегаты).
  3   | Дашборд / Сравнительная       | Фильтры: доска, период, организация
      | аналитика                     | (GET-форма на странице).

1.3. Тестовые данные

  php bin/console doctrine:fixtures:load --no-interaction --append --group=analytics

Основные маршруты (для ссылок и отладки):

  /analytics/admin/metric          — метрики
  /analytics/admin/board           — доски
  /analytics/admin/organization/board — организации и доски
  /analytics/admin/period          — периоды
  /analytics/report                — отчёты
  /analytics/approval              — утверждение
  /analytics/dashboard             — дашборд
  /analytics/chart                 — сравнительная аналитика

--------------------------------------------------------------------------------
2. Правильно ли реализовано (в целом да, с оговорками)
--------------------------------------------------------------------------------

Совпадает с идеей Readme_analitics.txt:

  • Версии досок, publish, привязка досок к организациям, периоды.
  • Отчёты: draft → submitted → approved; проверка обязательных метрик при submit.
  • Снимки метрик в analytics_report_values.
  • После approve вызывается пересчёт/запись агрегатов:
    App\Service\Analytics\ApproveReportService::approve() вызывает
    RecalculateAggregatesService::recalculateForReport().

Оговорки относительно «идеальной» картинки в Readme_analitics.txt:

  • В readme для analytics_aggregated_data в примере заложена одна строка на
    связку метрика + период + организация и агрегация sum/avg/... по правилу
    метрики. Текущий RecalculateAggregatesService по сути копирует значения из
    утверждённого отчёта в строки агрегатов (с привязкой к report_id). При
    нескольких отчётах за один период поведение может отличаться от ожидания
    «одно число на организацию/неделю/метрику» без уточнения в коде.
  • Часть ограничений БД из readme (например, ровно одна published-версия на
    доску) нужно сверять с миграциями: Doctrine не всегда создаёт частичные
    уникальные индексы вручную.

--------------------------------------------------------------------------------
3. Удобно ли организовано
--------------------------------------------------------------------------------

Плюсы:

  • Логичное разделение «Настройки аналитики» и «Аналитика».
  • Разбиение контроллеров соответствует черновику в Readme_analitics.txt.
  • Предсказуемые URL под /analytics/...

Меню (templates/sidebar/sidebar_list.html.twig):

  • «Аналитика» — при ROLE_ADMIN или ROLE_MANAGER; подпункты дашборда,
    сравнительной аналитики и утверждения только у ROLE_ADMIN; «Отчеты» —
    у обоих (ROLE_ADMIN в security.yaml наследует ROLE_MANAGER).
  • «Настройки аналитики» — только ROLE_ADMIN.
  • Маршруты отчётов по-прежнему доступны любому авторизованному пользователю
    с привязкой к организации (см. AnalyticsReportController); меню «Отчеты»
    для менеджеров даёт нормальный вход без прямой ссылки.

Утверждение намеренно только у админа: AnalyticsApprovalController с
#[IsGranted('ROLE_ADMIN')] — нормально, если утверждает только администратор.

--------------------------------------------------------------------------------
4. Краткая шпаргалка
--------------------------------------------------------------------------------

  Настройка: Метрики → Доски (версия + publish) → Организации и доски → Периоды.
  Работа:   Отчёты → Утверждение → Дашборд / сравнительная аналитика.

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
