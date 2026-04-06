    BD structures
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

8. analytics_periods
    id	PK	Уникальный идентификатор периода
    year	int	Год периода (например 2026)
    week_number	int	Номер недели в году (1..53)
    start_date	date	Дата начала периода
    end_date	date	Дата окончания периода
    is_closed	boolean	Закрыт ли период для редактирования
    description	string	Дополнительно, например “Неделя 15 2026”
    created_at	timestamp	Дата создания периода
    updated_at	timestamp	Дата последнего изменения (например, закрытие периода)
    UNIQUE(year, week_number)		Одна календарная неделя один раз

9. analytics_reports
    id	PK	Уникальный идентификатор отчёта
    organization_id	FK → organization.id	Организация, которая заполняет отчёт
    board_version_id	FK → analytics_board_versions.id	Версия доски, по которой заполнен отчёт
    period_id	FK → analytics_periods.id	Период отчёта
    created_by	FK → Users.id	Пользователь, который создал отчёт
    status	enum	draft / submitted / approved
    is_complete	boolean	Все обязательные метрики заполнены (кэш результата проверки)
    comment	text	Комментарий к отчёту (опционально)
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения (пока отчёт не approved)
    submitted_at	timestamp	Дата отправки (если есть)
    approved_at	timestamp	Дата утверждения (если есть)
    approved_by	FK → Users.id	Кто утвердил отчёт (если есть)
    UNIQUE(organization_id, board_version_id, period_id)		Один отчёт на организацию/версию/период
    Примечание: `board_id` в отчёте не хранится; при необходимости берётся через `analytics_board_versions.board_id`.
    Бизнес-правило "один отчёт на доску (вне зависимости от версии)" контролируется сервисом (проверка через JOIN).

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
    effective_at	timestamp NOT NULL DEFAULT NOW()	Business timestamp: момент, на который действует значение (используется для агрегации `last`)
    created_by	FK → Users.id	Кто внес данные
    created_at	timestamp	Технический timestamp создания записи
    updated_at	timestamp	Дата последнего изменения значения
    UNIQUE(report_id, board_version_metric_id)		Одна метрика версии один раз в отчёте
    CHECK (
      (value_number IS NOT NULL)::int +
      (value_text IS NOT NULL)::int +
      (value_bool IS NOT NULL)::int = 1
    )		Ровно один тип значения должен быть заполнен

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
    - business_key
    - period_id
    - organization_id
    - aggregated_value_number
    - source_count
    - calculated_at
    - UNIQUE(business_key, period_id, organization_id)
    Примечание: агрегаты хранятся по бизнес-смыслу метрики (`business_key`), а не по версии доски.
    `source_count` = количество raw-записей (`analytics_report_values`), участвовавших в расчёте агрегата.

--------------------------
Консистентность агрегатов (выбранный подход для MVP)
Выбран Вариант A (простой и достаточный для MVP+):
1) Пересчёт агрегатов выполняется при переходе отчёта в `approved`.
2) Пересчёт агрегатов выполняется при любом изменении уже approved-отчёта (если такой сценарий разрешён).
3) Пересчёт делается адресно по связке `business_key + period_id + organization_id`, без полного rebuild.
4) До первого пересчёта графики могут читать данные напрямую из `analytics_report_values` (fallback), если запись в агрегатах отсутствует.
5) Если в проекте будет разрешено массовое ретро-редактирование, следующий шаг — перейти на Вариант B (`source_updated_at`) или materialized view/джобу.
6) `RecalculateAggregatesService` выполняется в транзакции.
7) При upsert агрегата используется блокировка строки по ключу (`business_key`, `period_id`, `organization_id`) для защиты от гонок.

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
   - INDEX(status)
3) `analytics_aggregated_data`
   - INDEX(business_key, period_id, organization_id)
   - INDEX(period_id, organization_id)
Примечание: без этих индексов выборки для графиков и сводной аналитики будут деградировать на росте данных.

--------------------------
ENUM/CHECK ограничения (обязательно в БД)
1) `analytics_board_versions.status`:
   - CHECK/ENUM: `draft | published | archived`.
2) `analytics_reports.status`:
   - CHECK/ENUM: `draft | submitted | approved`.
3) `analytics_metrics.aggregation_type`:
   - CHECK/ENUM: `sum | avg | min | max | last`.
4) `analytics_metrics.type`:
   - рекомендуется CHECK/ENUM (например: `number | currency | distance | liters | count | text | bool`) и единый справочник допустимых типов.
5) Для `analytics_report_values` сохраняется CHECK "ровно одно из value_* заполнено".

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
7) `analytics_reports.board_version_id -> analytics_board_versions.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
8) `analytics_reports.period_id -> analytics_periods.id`
   - ON DELETE RESTRICT, ON UPDATE CASCADE
9) `analytics_reports.created_by / approved_by -> users.id`
   - ON DELETE RESTRICT (или SET NULL для `approved_by`, если допустим soft-delete пользователей), ON UPDATE CASCADE
10) `analytics_report_values.report_id -> analytics_reports.id`
    - ON DELETE CASCADE, ON UPDATE CASCADE
11) `analytics_report_values.board_version_metric_id -> analytics_board_version_metrics.id`
    - ON DELETE RESTRICT, ON UPDATE CASCADE
12) `analytics_report_values.created_by -> users.id`
    - ON DELETE RESTRICT, ON UPDATE CASCADE

--------------------------
    Версионирование.

Инструкция по изменению метрик (через версию доски)
1) Published-версию нельзя редактировать напрямую.
2) Если нужно добавить/удалить/изменить метрику:
   - создать новую версию доски из текущей (копия состава метрик),
   - внести изменения в новой версии (draft),
   - опубликовать новую версию (status = published),
   - предыдущую версию перевести в archived (или оставить для истории).
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
- использовать фильтрацию по `business_key`;
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
   - создаётся `analytics_reports` по текущей published-версии доски (`board_version_id`);
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