    BD structures
    =============

1. users - already exist

2. organization - already exist

3. analytics_metrics
    id	PK	Уникальный идентификатор метрики
    name	string	Название показателя (Расход топлива, Прибыль…)
    type	string	Тип значения: number, currency, distance, liters, count и т.д.
    unit	string	Единица измерения (л, км, руб., шт.)
    input_type	string	(опционально) Тип поля для ввода: text, number, select, checkbox
    is_active	boolean	Включена ли метрика в использование
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения

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

7. analytics_department_boards
    id	PK	Уникальный идентификатор
    department_id	FK → Departments.id	Департамент
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
    updated_at	timestamp	Дата последнего изменения (например, закрытие периода)
    UNIQUE(year, week_number)		Одна календарная неделя один раз

9. analytics_reports
    id	PK	Уникальный идентификатор отчёта
    department_id	FK → Departments.id	Департамент, который заполняет отчёт
    board_id	FK → analytics_boards.id	Доска (бизнес-сущность отчёта)
    board_version_id	FK → analytics_board_versions.id	Версия доски, по которой заполнен отчёт
    period_id	FK → analytics_periods.id	Период отчёта
    created_by	FK → Users.id	Пользователь, который создал отчёт
    status	enum	draft / submitted / approved
    comment	text	Комментарий к отчёту (опционально)
    created_at	timestamp	Дата создания
    updated_at	timestamp	Дата последнего изменения (пока отчёт не approved)
    submitted_at	timestamp	Дата отправки (если есть)
    approved_at	timestamp	Дата утверждения (если есть)
    approved_by	FK → Users.id	Кто утвердил отчёт (если есть)
    UNIQUE(department_id, board_id, period_id)		Один бизнес-отчёт на отдел/доску/период

10. analytics_report_values
    id	PK	Уникальный идентификатор
    report_id	FK → analytics_reports.id	Отчёт, к которому относится значение
    board_version_metric_id	FK → analytics_board_version_metrics.id	Метрика из конкретной версии доски
    value_number decimal NULL
    value_text string NULL
    value_bool boolean NULL
    created_by	FK → Users.id	Кто внес данные
    created_at	timestamp	Дата внесения значения
    updated_at	timestamp	Дата последнего изменения значения
    UNIQUE(report_id, board_version_metric_id)		Одна метрика версии один раз в отчёте
    CHECK (
      (value_number IS NOT NULL)::int +
      (value_text IS NOT NULL)::int +
      (value_bool IS NOT NULL)::int = 1
    )		Ровно один тип значения должен быть заполнен

11. analytics_aggregated_data (optional)
    - id
    - board_version_id
    - board_version_metric_id
    - period_id
    - department_id
    - value_sum
    - value_avg
    - value_min
    - value_max
    - calculated_at
    - UNIQUE(board_version_id, board_version_metric_id, period_id, department_id)

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
Правила MVP
1) В диаграммы и сводные отчёты попадают только approved-отчёты.
2) Published-версия доски не редактируется (изменения только через новую draft-версию).
3) Новый отчёт создаётся только по текущей published-версии доски.
4) Редактирование отчётов запрещено, если analytics_periods.is_closed = true.

--------------------------
Как будут строиться графики
Пример:
"Расход топлива по неделям"
SELECT period_id, SUM(value_number)
FROM analytics_aggregated_data
WHERE board_version_metric_id = :fuel_board_version_metric_id
GROUP BY period_id
"Сравнение отделов"
SELECT department_id, SUM(value_number)
FROM analytics_aggregated_data
WHERE board_version_metric_id = :target_board_version_metric_id
  AND period_id = :period_id
GROUP BY department_id

Важно для графиков по длинному периоду (когда версии менялись):
- не фильтровать одним board_version_id;
- строить выборку по board_id + metric_id через JOIN:
  analytics_aggregated_data.board_version_metric_id
  -> analytics_board_version_metrics.id
  -> analytics_board_versions.id (и board_id)
  -> analytics_metrics.id (бизнес-метрика).
