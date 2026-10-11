# Gates: analytics

OWNS: src/Controller/Analytics/**, src/Controller/SpaApi/Analytics/**, src/Service/Analytics/**, src/Repository/Analytics/**, src/Security/AnalyticsReportAccess.php, tests/Security/AnalyticsAccessTest.php

- [x] G1: Проверены права до чтения и изменения во всех маршрутах выделенных пунктов.
  EVIDENCE: F10 перечитан в исходном отчёте, цепочки SPA reports/list и dashboard проверены через rg + чтение. AnalyticsReportAccess использует прежнюю политику view/export: ROLE_ADMIN/ROLE_ANALYTIC через Security::isGranted, иначе точный ID роли belongsToRole в getRolesRel. В AnalyticsReportRepository защищены findConfirmedListByCategory (включая COUNT/пагинацию), findAvailableWeeksByCategory; в AnalyticsReportValueRepository — findReportsWithMetricTree (подзапрос периодов И внешний запрос), findValuesByCategory. Все найденные вызовы — только пять SPA tree-сервисов и DashboardDataService; пути редактирования/admin/CLI и findForIndex не изменены. Агрегаты/compare в AnalyticsAggregatedDataRepository уже заглушены и возвращают [], их не изменял. Организационного ограничения у исходного view/export нет, новое не добавлял.

- [x] G2: Добавлены проверки и зафиксированы результаты запуска либо препятствие среды.
  EVIDENCE: tests/Security/AnalyticsAccessTest.php (KernelTestCase): PostgreSQL TEMP tables в транзакции с rollback, все 10 SPA controller actions + dashboard data, org_id=0/1, чужой отчёт в той же неделе, более свежий отчёт без роли, пагинация/total/availableWeeks, значения/notes, ROLE_ADMIN/ROLE_ANALYTIC, отсутствующая роль и отсутствие токена. Чтение итогового кода сверило порядок SQL placeholders, категорию citizen_appeal, оба уровня фильтра дерева и сохранение полного доступа. Runtime не проверен — PHP отсутствует.

Изменённые PHP-файлы:
- src/Security/AnalyticsReportAccess.php
- src/Controller/Analytics/AnalyticsReportController.php
- src/Repository/Analytics/AnalyticsReportRepository.php
- src/Repository/Analytics/AnalyticsReportValueRepository.php
- tests/Security/AnalyticsAccessTest.php

Попытки проверок: php -l для каждого файла выше; php bin/phpunit tests/Security/AnalyticsAccessTest.php; php bin/console lint:container; php bin/console lint:twig. Каждая завершилась до запуска PHP одним точным сообщением PowerShell:

```text
Имя "php" не распознано как имя командлета, функции, файла сценария или выполняемой программы. Проверьте правильность написания имени, а также наличие и правильность пути, после чего повторите попытку.
```

Следовательно php -l/phpunit/container/twig — НЕ ЗАПУСКАЛИСЬ. LoginControllerTest не запускался.

Ручная проверка: на изолированной тестовой PostgreSQL выполнить новый тест; под обычным пользователем без роли доски сверить 404 у /analytics/report/{id}/view и export, отсутствие чужого отчёта/значений/notes/недель/total во всех /spa/api/analytics/{finance,hr,mechanics,clients-department,citizen-appeals}/reports и /reports/list при org_id=0 и конкретной чужой организации. Проверить /analytics/dashboard и /analytics/dashboard/data для week/month. После назначения роли доски данные доступны; ROLE_ADMIN/ROLE_ANALYTIC видят прежний полный набор. Сравнение dashboard сейчас пустое по существующей реализации; при восстановлении агрегатов необходимо применять эту же политику до агрегирования.

Ограничения: интеграционный тест требует PostgreSQL и настроенного test kernel; из-за отсутствия PHP его исполнение и синтаксис PHP не подтверждены. Тест вызывает controller actions внутри KernelTestCase, но не проверяет JWT/firewall HTTP transport. Никаких новых бизнес-ограничений на организации не вводилось.
