Analytics — прогресс реализации
================================
Дата старта: 2026-04-07

[ = done | / = in progress | - = not started | N/A = skipped ]

Инфраструктура
==============
[=] Все Entity (AnalyticsMetric, AnalyticsBoard, AnalyticsBoardVersion,
    AnalyticsBoardVersionMetric, AnalyticsOrganizationBoard, AnalyticsPeriod,
    AnalyticsReport, AnalyticsReportValue, AnalyticsAggregatedData)
[=] Enums (AnalyticsMetricAggregationType, AnalyticsBoardVersionStatus, AnalyticsReportStatus)
[=] Все Repository (9 штук — автосгенерированные)
[=] Контроллеры-стабы (7 штук — маршруты прописаны, но внутри только index с заглушкой)

Шаг 1. Метрики (AnalyticsAdminMetric — CRUD)
=============================================
[=] 1.1 AnalyticsMetricService (create, update, findAll, findById, delete)
[=] 1.2 Контроллер: index + DELETE, new, edit — полная логика
[=] 1.6 CSRF в формах и валидация токенов в контроллере
[=] 1.7 Валидация/обработка ошибок (duplicate business_key)
[=] 1.3 Шаблон: analytics/admin/metric/index.html.twig (список метрик)
[=] 1.4 Шаблон: analytics/admin/metric/new.html.twig (форма создания)
[=] 1.5 Шаблон: analytics/admin/metric/edit.html.twig (форма редактирования)

Шаг 2. Доски (AnalyticsAdminBoard — CRUD версий)
=================================================
[=] 2.1 BoardService + CloneBoardVersionService + PublishBoardVersionService
[=] 2.2 Контроллер: index, new, show, version_edit + publish/clone/delete
[=] 2.3 Шаблон: analytics/admin/board/index.html.twig
[=] 2.4 Шаблон: analytics/admin/board/new.html.twig
[=] 2.5 Шаблон: analytics/admin/board/show.html.twig
[=] 2.6 Шаблон: analytics/admin/board/version_edit.html.twig

Шаг 3. Организация и доски
===========================
[=] 3.1 OrganizationBoardService (findAll, findByOrganization, create, toggleRequired, delete)
[=] 3.2 Контроллер: index, create, toggle-required, delete
[=] 3.3 Шаблон: analytics/admin/organization_board/index.html.twig

Шаг 4. Периоды
===============
[=] 4.1 PeriodService (findAll, createByIsoWeek, close, open, generateUpcomingWeeks)
[=] 4.2 Контроллер: index, create, generate, close/open
[=] 4.3 Шаблон: analytics/admin/period/index.html.twig

Шаг 5. Отчёты (Collection)
===========================
[=] 5.1 CreateReportService, FillReportValueService, SubmitReportService
[=] 5.2 Контроллер: index, new, fill, submit
[=] 5.3 Шаблон: analytics/report/index.html.twig
[=] 5.4 Шаблон: analytics/report/new.html.twig
[=] 5.5 Шаблон: analytics/report/fill.html.twig

Шаг 6. Утверждение отчётов
===========================
[=] 6.1 ApproveReportService (findPendingReports, findById, approve)
[=] 6.2 Контроллер: index, approve
[=] 6.3 Шаблон: analytics/approval/index.html.twig

Шаг 7. Агрегация и чтение
==========================
[=] 7.1 RecalculateAggregatesService (recalculateForReport, recalculateAll)
[=] 7.2 GetDashboardDataService (getDashboardData)
[=] 7.3 Шаблон: analytics/dashboard/index.html.twig
[=] 7.4 Шаблон: analytics/chart/show.html.twig (сравнительная аналитика)

Порядок выполнения
==================
1. Шаблоны метрик (index/new/edit) — проверить инфраструктуру
2. BoardService + контроллер досок + 4 шаблона
3. OrganizationBoardService + контроллер + шаблон
4. PeriodService + контроллер + шаблон
5. Report-сервисы + контроллер + 3 шаблона
6. ApproveReportService + контроллер + шаблон
7. RecalculateAggregatesService + GetDashboardDataService + 2 шаблона

Маршруты
========
Метрики (ready):
  GET  /analytics/admin/metric              → app_analytics_admin_metric_index
  GET  /analytics/admin/metric/new           → app_analytics_admin_metric_new
  POST /analytics/admin/metric/new           → app_analytics_admin_metric_new
  GET  /analytics/admin/metric/{id}/edit     → app_analytics_admin_metric_edit
  POST /analytics/admin/metric/{id}/edit     → app_analytics_admin_metric_edit
  POST /analytics/admin/metric/{id}/delete   → app_analytics_admin_metric_delete

Доски (ready):
  GET  /analytics/admin/board                                    → app_analytics_admin_board
  GET  /analytics/admin/board/new                                → app_analytics_admin_board_new
  POST /analytics/admin/board/new                                → app_analytics_admin_board_new
  GET  /analytics/admin/board/{id}                               → app_analytics_admin_board_show
  GET  /analytics/admin/board/{boardId}/version/{versionId}/edit → app_analytics_admin_board_version_edit
  POST /analytics/admin/board/{boardId}/version/{versionId}/edit → app_analytics_admin_board_version_edit
  POST /analytics/admin/board/{id}/clone                         → app_analytics_admin_board_clone
  POST /analytics/admin/board/version/{versionId}/publish        → app_analytics_admin_board_publish
  POST /analytics/admin/board/{id}/version/{versionId}/delete    → app_analytics_admin_board_version_delete
  POST /analytics/admin/board/{id}/delete                        → app_analytics_admin_board_delete

Организация/доски (ready):
  GET  /analytics/admin/organization/board                      → app_analytics_admin_organization_board
  POST /analytics/admin/organization/board/create               → app_analytics_admin_organization_board_create
  POST /analytics/admin/organization/board/{id}/toggle-required → app_analytics_admin_organization_board_toggle_required
  POST /analytics/admin/organization/board/{id}/delete          → app_analytics_admin_organization_board_delete

Периоды (ready):
  GET  /analytics/admin/period                       → app_analytics_admin_period
  POST /analytics/admin/period/create                → app_analytics_admin_period_create
  POST /analytics/admin/period/generate              → app_analytics_admin_period_generate
  POST /analytics/admin/period/{id}/close            → app_analytics_admin_period_close
  POST /analytics/admin/period/{id}/open             → app_analytics_admin_period_open

Отчёты (ready):
  GET  /analytics/report                  → app_analytics_report
  GET  /analytics/report/new              → app_analytics_report_new
  POST /analytics/report/new              → app_analytics_report_new
  GET  /analytics/report/{id}/fill        → app_analytics_report_fill
  POST /analytics/report/{id}/fill        → app_analytics_report_fill
  POST /analytics/report/{id}/submit      → app_analytics_report_submit

Утверждение (ready):
  GET  /analytics/approval                       → app_analytics_approval
  POST /analytics/approval/{id}/approve          → app_analytics_approval_approve

Дашборд (ready):
  GET  /analytics/dashboard                      → app_analytics_dashboard

Сравнительная аналитика (ready):
  GET  /analytics/chart                        → app_analytics_chart_show

Заметки
=======
Поток данных аналитики (end-to-end):

  1. Админ создаёт метрики через AnalyticsAdminMetricController
     Каждая метрика: business_key, имя, тип (number/text/bool), ед. измерения,
     тип агрегации (sum/avg/min/max/last).

  2. Админ создаёт доску через AnalyticsAdminBoardController
     Доска = набор версий. Каждая версия = набор метрик (AnalyticsBoardVersionMetric).
     Версия может быть Draft или Published.
     CloneBoardVersionService копирует метрики из предыдущей версии.

  3. Админ назначает доски организациям (AnalyticsAdminOrganizationBoardController)
     Связка Organisation <-> Доска через AnalyticsOrganizationBoard.
     Can пометить доску как обязательную (isRequired).

  4. Админ создаёт/управляет периодами (AnalyticsAdminPeriodController)
     Период = ISO-неделя. Автогенерация 4 ближайших или ручной ввод.
     Закрытый период блокирует редактирование отчётов.

  5. Организация заполняет отчёты (AnalyticsReportController)
     - Выбор доски → создаётся черновик отчёта со всеми метриками
       опубликованной версии доски.
     - Заполнение значений метрик (AnalyticsReportValue).
       Типизированно: value_number / value_text / value_bool / value_json.
     - Snapshot метрики (имя, единица, тип на момент заполнения).
     - Полнота проверяется (все required-метрики заполнены).
     - Отправка на утверждение → статус меняется на Submitted.

  6. Админ утверждает отчёты (AnalyticsApprovalController)
     - Список отчётов Submitted → кнопка «Утвердить».
     - Статус: Approved + approvedAt.
     - Автоматически: RecalculateAggregatesService записывает
       данные отчёта в AnalyticsAggregatedData (агрегация).
     - Сравнительная аналитика обновляется мгновенно.

  7. Дашборд и сравнительная аналитика
     - Дашборд (analytics/dashboard): значения метрик организации за период
     - Сравнительная аналитика (analytics/chart): таблица
       «Организации x Метрики» + тренды за несколько периодов

  Перевычисление агрегатов:
  - Автоматическое при approve (через ApproveReportService → RecalculateAggregatesService)
  - Ручное (полное): RecalculateAggregatesService::recalculateAll()
    Пересчитывает ВСЕ утверждённые отчёты (восстановление)

  CSRF-защита: все формы через Symfony CsrfTokenManager.
  Токены привязаны к действию ('report_fill_42', 'org_board_delete_7').

  Зависимости сервисов:
  - ApproveReportService → RecalculateAggregatesService
  - FillReportValueService — независимый
  - CreateReportService — независимый
  - SubmitReportService → FillReportValueService
  - BoardService, CloneBoardVersionService, PublishBoardVersionService — независимые
