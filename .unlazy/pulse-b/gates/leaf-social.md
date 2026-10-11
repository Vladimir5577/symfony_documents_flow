# Gates: social

OWNS: src/Controller/Post/**, src/Controller/SpaApi/Post/**, src/Controller/Chat/**, src/Controller/Api/Chat/**, src/Service/Chat/**, src/Security/Voter/PostVoter.php, templates/chat/**, assets/controllers/chat*, tests/Security/PostChatAccessTest.php, public/front_end/assets/static/js/chat.js, src/Service/SpaApi/Post/PostResponseFormatter.php, src/Service/SpaApi/Post/PostImagePreviewUrlGenerator.php, templates/post/partials/_post_card.html.twig, templates/dash_board/index.html.twig

- [x] G1: Проверены права до чтения и изменения во всех маршрутах выделенных пунктов.
  EVIDENCE: PostVoter::VIEW (post.view, отдельно от общего PermissionVoter) разрешает опубликованное авторизованному, черновик автору/ROLE_ADMIN, удалённое никому. Применён legacy comments/create/acknowledge/download/cover и SPA view/comments/create/acknowledge/download/cover. setActive/delete уже проверяли автора/ROLE_ADMIN. List уже ограничивал черновики. Файлы/обложки private,no-store. Публичные thumbnail URL заменены защищёнными. Root уведомлён о необходимости закрыть /uploads/posts/, /media/cache/post_image_preview/, /media/cache/resolve/post_image_preview/.
  Дополнительные сведения: ChatMessageService больше не публикует /chat/room. Каждый message/new/edit/delete/read и room_updated публикуется private=true на персональные topics текущих участников. Удаление участника сразу исключает новые события без ожидания истечения JWT. GET /api/chat/subscription выдаёт HttpOnly cookie с JWT только на /chat/user/{currentUserId}, publish=[], TTL 1h, no-store. JS получает cookie до подписки, использует withCredentials, обновляет JWT через 50 мин и повторяет после ошибки. Все найденные подписчики находятся в chat.js.

- [x] G2: Добавлены проверки и зафиксированы результаты запуска либо препятствие среды.
  EVIDENCE: tests/Security/PostChatAccessTest.php — KernelTestCase с реальным security voter/role hierarchy; проверка раннего отказа SPA; legacy kernel.controller_arguments ACL и download; private Updates и исключение удалённого участника; JWT scope без wildcard/чужого user_id.
  Дополнительные сведения: node --check public/front_end/assets/static/js/chat.js: exit 0, stdout пуст.
  Дополнительные сведения: временный Node vm harness (без записанного тестового файла) выполнил клиентскую подписку с mock fetch/EventSource: exit 0; точный stdout: PASS: subscriber credentials, personal topic, room isolation, JWT refresh and retry
  Дополнительные сведения: php -l вызван для всех 8 изменённых PHP-файлов; каждый exit 1 до запуска PHP. php bin/phpunit tests/Security/PostChatAccessTest.php: exit 1 до запуска PHP. Точный общий вывод:

```
php : Имя "php" не распознано как имя командлета, функции, файла сценария или выполняемой программы. Проверьте правильн
ость написания имени, а также наличие и правильность пути, после чего повторите попытку.
    + CategoryInfo          : ObjectNotFound: (php:String) [], CommandNotFoundException
    + FullyQualifiedErrorId : CommandNotFoundException
```

Риски/ручная проверка:
1. На тестовых данных: A — автор черновика с обложкой/файлом; B — обычный сотрудник и отдельно чужой ROLE_MANAGER; C — ROLE_ADMIN. B должен получать 403/404 на все перечисленные legacy/SPA маршруты до чтения/мутации; A/C сохраняют доступ. После публикации B получает доступ, после снятия — теряет. Проверить прямые uploads и media/cache пути (root nginx).
2. Внешний SPA-клиент в worktree отсутствует. cover/preview теперь защищённые SPA API URL: необходим Authorization Bearer при загрузке изображения (fetch→blob). Обычный img без JWT получит 401; проверить/адаптировать внешний клиент. Превью отдаёт оригинал изображения вместо 400×400 thumbnail, возможен рост трафика.
3. Mercure A↔B: C анонимно и со своим JWT не должен получать marker ни по старому /chat/room/{id}, ни по чужому /chat/user/{id}; A/B должны получать новые/редактированные/удалённые/read события и room_updated. Удалить B из group, оставить SSE и старый JWT — новые события B не приходят. Проверить переключение комнаты, reconnect/обновление JWT, CORS credentials.
4. Cookie Authorization требует одного сайта приложения и Mercure (одинаковый host/разрешённые поддомены; разные порты допустимы), корректного MERCURE_CORS_ORIGINS; конфигурация .env не читалась. Отдельный живой Mercure не запускался. Старые публичные события в истории hub нужно учитывать при вводе исправления; новые публикации приватны.
5. Файлы чатов /uploads/chats остаются публичными по согласованному исключению F05: контроллера выдачи нет, расширение работы на чатовые загрузки не выполнялось.
6. lint:container/lint:twig запускает root вместе с общей проверкой; PHP здесь отсутствует. PHPUnit не запускался. Git, .env, зависимости, документы и User/UserController.php не затронуты.

Изменённые PHP:
- src/Controller/Post/PostController.php
- src/Controller/SpaApi/Post/PostController.php
- src/Controller/Chat/ChatController.php
- src/Service/Chat/ChatMessageService.php
- src/Service/SpaApi/Post/PostResponseFormatter.php
- src/Service/SpaApi/Post/PostImagePreviewUrlGenerator.php
- src/Security/Voter/PostVoter.php
- tests/Security/PostChatAccessTest.php
Другие продуктовые файлы: public/front_end/assets/static/js/chat.js; templates/post/partials/_post_card.html.twig; templates/dash_board/index.html.twig.

????????? ???????? ?????????????: ????????? PostVoter::voteOnAttribute ???????? ?Vote $vote = null ???????? ?????????????? vendor/symfony/security-core. ????? ????????? ????????? php -l ????? ?????????? (CommandNotFoundException).
