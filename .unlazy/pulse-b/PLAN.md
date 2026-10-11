# Pulse B — контракт и учёт готовности

Результат: серверные проверки F01/F05/F06/F08/F09/F10/F11/F12, без миграций, зависимостей, git, .env и внешних действий. Документы/вложения/подписание/ознакомление не править; исключение F01/F06 ожидает ответа.

| Leaf | Owns | Needs | Tier | Planned wave | State |
| --- | --- | --- | --- | --- | --- |
| leaf-access | src/Controller/Organization/**, src/Controller/SpaApi/Organization/**, src/Controller/ApiExternal/HR/**, tests/Security/StructureHrAccessTest.php | none | judgment | ready-1 | VERIFIED |
| leaf-analytics | src/Controller/Analytics/**, src/Controller/SpaApi/Analytics/**, src/Service/Analytics/**, src/Repository/Analytics/**, src/Security/AnalyticsReportAccess.php, tests/Security/AnalyticsAccessTest.php | none | judgment | ready-1 | VERIFIED |
| leaf-social | src/Controller/Post/**, src/Controller/SpaApi/Post/**, src/Controller/Chat/**, src/Controller/Api/Chat/**, src/Service/Chat/**, src/Security/Voter/PostVoter.php, templates/chat/**, assets/controllers/chat*, tests/Security/PostChatAccessTest.php, public/front_end/assets/static/js/chat.js, src/Service/SpaApi/Post/PostResponseFormatter.php, src/Service/SpaApi/Post/PostImagePreviewUrlGenerator.php, templates/post/partials/_post_card.html.twig, templates/dash_board/index.html.twig | none | judgment | ready-1 | VERIFIED |

Контроль: F08/F09 — access; F10 — analytics; F11/F12 — social; F01/F05/F06 — root. Каждый исполнитель проверяет цепочку доступа до данных/мутации, добавляет подходящие тесты, фиксирует точные результаты. Root повторно читает совокупные изменения, запускает доступные php -l, phpunit, lint:container и lint:twig; пишет CODEX-REPORT.md с ручными сценариями и ограничениями.

Итог родительской проверки: F08/F09 и F10 проверены чтением, F10 дополнительно независимым исполнителем. F11/F12 перечитаны root; JS-проверки повторены. Runtime PHP не подтверждён. Root F05/F06 дополнительно проверены access; исправлена повторная замена сервиса в тесте. F01 и документная часть F06 переданы владельцу из-за запрета области; PHP/Nginx/Mercure runtime остаются передачей из-за среды. VERIFIED у листьев означает их ручные gates (анализ и запись результата проверок), не успешный PHP runtime.
