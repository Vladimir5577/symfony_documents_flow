# Gates: access

OWNS: src/Controller/Organization/**, src/Controller/SpaApi/Organization/**, src/Controller/ApiExternal/HR/**, tests/Security/StructureHrAccessTest.php

- [x] G1: Проверены права до чтения и изменения во всех маршрутах выделенных пунктов.
  EVIDENCE: ручной разбор кода (не HTTP-проверка): F08 edit/create/delete имеют IsGranted ROLE_ADMIN до тела метода; parent_id не обходит атрибут. Основание роли: templates/organization/all_organizations.html.twig:16 и глобальный пикер ROLE_ADMIN в OrganizationController. SPA Organization содержит только два GET-метода, менять нечего. F09 все 11 legacy HR-маршрутов находятся в трёх контроллерах с новым атрибутом класса ROLE_HR; методы не обращаются к внешнему API до проверки. SPA HR уже защищён ROLE_HR.

- [x] G2: Добавлены проверки и зафиксированы результаты запуска либо препятствие среды.
  EVIDENCE: tests/Security/StructureHrAccessTest.php — KernelTestCase с реальным роутером, dispatcher/IsGranted и иерархией ролей, 53 комбинации методов/путей/ролей. Проверяет gate до тела контроллера; не проверяет бизнес-операции после разрешения. PHP-команды ниже НЕ ЗАПУСКАЛИСЬ: PHP отсутствует в PATH. Это не успешная валидация PHP/тестов.

Попытки запуска:

```text
php -l src/Controller/Organization/OrganizationController.php
php -l src/Controller/ApiExternal/HR/VacancyController.php
php -l src/Controller/ApiExternal/HR/VacancyApplicationController.php
php -l src/Controller/ApiExternal/HR/VacancyApplicationResumeProxyController.php
php -l tests/Security/StructureHrAccessTest.php
php bin/phpunit tests/Security/StructureHrAccessTest.php
php bin/console lint:container
php bin/console lint:twig
```

Каждая попытка: exit code 1; точные последние строки PowerShell:

```text
    + CategoryInfo          : ObjectNotFound: (php:String) [], CommandNotFoundException
    + FullyQualifiedErrorId : CommandNotFoundException
```

Основное сообщение:

```text
php : Имя "php" не распознано как имя командлета, функции, файла сценария или выполняемой программы. Проверьте правильн
ость написания имени, а также наличие и правильность пути, после чего повторите попытку.
```

Изменённые файлы: src/Controller/Organization/OrganizationController.php; src/Controller/ApiExternal/HR/VacancyController.php; src/Controller/ApiExternal/HR/VacancyApplicationController.php; src/Controller/ApiExternal/HR/VacancyApplicationResumeProxyController.php; tests/Security/StructureHrAccessTest.php.

Повторный просмотр: все три изменения организации ограждены на уровне метода; общий ROLE_USER firewall больше не достаточен. Политика ROLE_ADMIN глобальна, дополнительный scope выбранного родителя ей не требуется. Все legacy HR actions покрыты атрибутами класса, публичных исключений access_control для HR нет. Синтаксис Kernel events сверён с установленным vendor. Git, .env, миграции и зависимости не затронуты.

Ручная проверка на тестовом стенде:
1. ROLE_USER, ROLE_HR и ROLE_INVENTORY_MANAGER: GET и POST create/edit, POST delete организации, в том числе чужая организация и parent_id, должны дать 403. Дерево, реквизиты и область имущества не должны измениться. ROLE_ADMIN должен сохранить прежнюю возможность операций с действительным CSRF.
2. ROLE_USER: все 11 legacy HR-путей из provider должны дать 403; мок/лог HTTP-клиента внешнего HR не должен фиксировать вызов. ROLE_HR и ROLE_ADMIN: списки, карточки, резюме и штатные изменения должны работать.
3. Повторить перечисленные PHP-проверки там, где установлен PHP и доступна тестовая конфигурация.

Ограничения: HR CSRF из дополнительной рекомендации ревью не менялся — явный F09 пользователя требует ролевой защиты. Не считать текущую работу исправлением CSRF. Шаблоны edit/delete могут показывать сотруднику кнопки, которые сервер теперь корректно отклоняет; тексты и шаблоны не менялись. Полный HTTP-проход и операции с БД не запускались.
