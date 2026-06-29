================================================================================
  ВЫНОС КАНБАНА В ОТДЕЛЬНЫЙ МИКРОСЕРВИС (Go)
  Рабочая документация / чек-лист для плана миграции
================================================================================
  Статус: ЧЕРНОВИК ПЛАНА (проговариваем по пунктам, ничего ещё не делаем)
  Дата создания: 2026-06-29
  Стек источника: Symfony 8, Doctrine, Twig (SSR) + React SPA (отдельный API)
  Цель: вырезать канбан из Symfony и вынести в самостоятельный сервис на Golang
================================================================================


--------------------------------------------------------------------------------
0. КОНТЕКСТ / ПОСТАНОВКА ЗАДАЧИ
--------------------------------------------------------------------------------
Сейчас канбан существует в ДВУХ местах на одном Symfony-бэке:
  1) SSR-вариант на Twig  -> templates/kanban/kanban_board.html.twig
     (~2585 строк: стили, разметка досок/колонок/карточек, сайдбар задачи —
      чат, описание, подзадачи, история, вложения, + большой JS-блок,
      дёргающий Symfony-эндпоинты).
  2) React SPA-вариант со своим API (ходит на /spa/api, stateless JWT).

Хотим: единый канбан-домен вынести в Go-микросервис.

Домен канбана обособлен (доски, колонки, карточки, чат, подзадачи, история,
вложения) и слабо связан с остальным документооборотом -> режется по чёткой
границе. Это аргумент ЗА вынос.


--------------------------------------------------------------------------------
1. ПЛЮСЫ ВЫНОСА
--------------------------------------------------------------------------------
- Канбан — обособленный домен, отделяется по чёткой границе.
- Уже есть React-клиент + API => контракт частично продуман, Go может его
  переиспользовать/повторить => ниже риск.
- Go хорошо ложится на realtime (чат, drag-n-drop, история) через WebSocket/SSE.
- Realtime-инфраструктура уже есть в проекте (Mercure).


--------------------------------------------------------------------------------
2. ГЛАВНЫЕ РИСКИ / ТОЧКИ ВНИМАНИЯ (общие)
--------------------------------------------------------------------------------
[ ] 2.1 Аутентификация / права (см. раздел 4 — проработано отдельно).
[V] 2.2 Данные / БД. РЕШЕНО: у микросервиса СВОЯ БД. Канбан-таблицы
        вырезаются из Symfony и переносятся в Go. См. раздел 6.
[ ] 2.3 Вложения / файлы. В шаблоне есть загрузка файлов + dropzone.
        Решить, кто хранит файлы (общий S3/диск). В проекте есть VichUploader +
        LiipImagine (обработка картинок) — учесть при переносе.
[ ] 2.4 Два клиента -> один контракт. Логично похоронить SSR-вариант и оставить
        только React (SPA) поверх Go-API. Держать SSR на Go = тащить весь Twig
        в Go-шаблоны, смысла мало.
[ ] 2.5 История / чат realtime — продумать модель событий ЗАРАНЕЕ, иначе
        перепишешь дважды.


--------------------------------------------------------------------------------
3. ПРЕДВАРИТЕЛЬНАЯ ПОСЛЕДОВАТЕЛЬНОСТЬ МИГРАЦИИ (верхнеуровнево)
--------------------------------------------------------------------------------
[ ] 3.1 Зафиксировать API-контракт по существующему React-API как основу.
[ ] 3.2 Решить вопрос auth (см. раздел 4 — есть рекомендация).
[V] 3.3 Вопрос БД РЕШЁН: отдельная БД канбана (см. раздел 6).
[ ] 3.4 Поднять Go-сервис, перенести эндпоинты, переключить React на него.
[ ] 3.5 Только ПОТОМ удалять SSR (kanban_board.html.twig, контроллеры, роуты)
        из Symfony.


================================================================================
4. AUTH — ПРОРАБОТАНО (главное решение)
================================================================================

4.1 ЧТО ЕСТЬ ПО ФАКТУ (из кодовой базы)
--------------------------------------------------------------------------------
- Бандлы: lexik/jwt-authentication-bundle ^3.2,
          gesdinet/jwt-refresh-token-bundle ^2.0,
          symfony/security-bundle 8.0.*
- JWT подписан RS256 (RSA-пара):
      config/jwt/private.pem  (приватный — ТОЛЬКО Symfony)
      config/jwt/public.pem   (публичный — можно отдать Go)
  Конфиг: config/packages/lexik_jwt_authentication.yaml
      secret_key  = %env(resolve:JWT_SECRET_KEY)%
      public_key  = %env(resolve:JWT_PUBLIC_KEY)%
      pass_phrase = %env(JWT_PASSPHRASE)%
- Firewalls (config/packages/security.yaml):
      spa_api:     pattern ^/spa/api, stateless: true, jwt: ~,
                   json_login check_path /spa/api/login_check
                   (username_path: login, password_path: password),
                   login_throttling 5 попыток / 15 минут
      refresh_jwt: ^/spa/api/(token/refresh|logout), stateless,
                   check_path /spa/api/token/refresh,
                   invalidate_token_on_logout: true
      main:        form_login (SSR, сессии) — это «старый» веб-вход
- User entity: src/Entity/User/User.php
      implements UserInterface, PasswordAuthenticatedUserInterface
      getUserIdentifier() == login   (идентификатор = LOGIN, не id!)
      есть getId(): ?int, getRoles(): array, getRolesRel(): Collection
- Кастомизации JWT payload НЕТ (нет listener на JWTCreatedEvent).
  => токен содержит дефолт Lexik: username (= login), roles, iat, exp.
  !!! Идентификатор пользователя в токене сейчас = LOGIN, а не id.
- Role hierarchy: ROLE_ADMIN -> ... -> ROLE_USER (см. security.yaml).
- Доменные права канбана (isBoardAdmin, участник доски) В ТОКЕНЕ НЕТ —
  это данные в БД.
- Voters: src/Security/Voter/PermissionVoter.php (один воутер).
- Mercure уже настроен: config/packages/mercure.yaml (hub default, publish '*').


4.2 РЕШЕНИЕ ПО AUTH (рекомендация)
--------------------------------------------------------------------------------
МОДЕЛЬ: Symfony = Identity Provider (эмитент токенов),
        Go      = Resource Server (только проверяет токен).

1) Логин / refresh / logout ОСТАЮТСЯ в Symfony. Не трогаем — React уже
   это использует. /spa/api/login_check, /spa/api/token/refresh, /spa/api/logout
   никуда не переносим.
2) Go ТОЛЬКО проверяет JWT публичным ключом (public.pem).
   Приватный ключ Go НЕ отдаём (Go не должен уметь выпускать токены).
   Библиотека-кандидат: github.com/golang-jwt/jwt/v5, метод RS256.
3) Из токена Go берёт: username (login) + roles => аутентификация + ГЛОБАЛЬНЫЕ роли.
4) Права на конкретную доску Go проверяет САМ по своей доменной БД
   (membership / admin таблицы канбана). Токена для этого недостаточно
   и не должно быть. Go владеет доменом канбана => это нормально.

React уже носит JWT в Authorization: Bearer к /spa/api — будет носить ТОТ ЖЕ
токен к Go-сервису. Без второго логина, без синхронизации сессий.


4.3 СРАВНЕНИЕ ВАРИАНТОВ (почему именно так)
--------------------------------------------------------------------------------
[V] Go валидирует тот же RS256 JWT публичным ключом
       -> РЕКОМЕНДУЮ. Stateless, нулевая связанность, приватный ключ не покидает
          Symfony, React не трогаем.
[X] Go вызывает Symfony для интроспекции токена на каждый запрос
       -> лишний round-trip на каждый запрос, Symfony = узкое место и точка
          отказа, смысл выноса теряется.
[X] Свой логин/сессии в Go
       -> дублирование пользователей/паролей, две системы auth — худшее.
[!] Общий симметричный секрет (HS256)
       -> у нас RS256, понижать не надо; секрет дал бы Go право ВЫПУСКАТЬ токены.


4.4 id vs login в токене — ПРОВЕРЕНО ПО КОДУ
--------------------------------------------------------------------------------
ПРОВЕРКА ФАКТА (2026-06-29), lexik/jwt-authentication-bundle v3.2.0:
  - config user_id_claim НЕ переопределён -> дефолт = 'username'
    (Configuration.php:66).
  - JWTManager.php:138-141 addUserIdentityToPayload():
        $payload['username'] = $user->getUserIdentifier()
    а getUserIdentifier() у нас == LOGIN.
  - Кастомизаций payload (listener на JWTCreatedEvent) в проекте НЕТ.

ИТОГ: в токене СЕЙЧАС НЕТ числового id. Есть claim `username`, и в нём лежит
LOGIN (строка). Полный payload: { username(=login), roles, iat, exp }.
=> «id в токене уже есть» — это недоразумение: там login, а не User.id (int).

ПОЧЕМУ ВАЖНО: Go во всех 8 точках связи пишет user_id (int) — тот же id, что в
User.id и в дампе перенесённых таблиц. С login Go пришлось бы резолвить
login -> id (через реплику users, см. 6.3) на каждую запись.

ВАРИАНТЫ:
  (1) [РЕКОМЕНДУЮ] Listener на JWTCreatedEvent — ДОБАВИТЬ claim `id`:
          $payload = $event->getData();
          $payload['id'] = $event->getUser()->getId();
          $event->setData($payload);
      Токен станет { username(=login), id:42, roles, iat, exp }.
      + React не ломается (старые claim'ы на месте, только добавлен новый).
      + Go берёт id напрямую, не дёргает реплику на запись.
      ~15 строк, один класс.
  (2) Поменять user_id_claim на 'id' в конфиге — НЕ СОВЕТУЮ: заменит сам
      идентификатор аутентификации (login -> id), риск задеть login/refresh.
      Способ (1) добавляет, а не заменяет — безопаснее.
  (3) Не трогать токен, Go резолвит login -> id через свою реплику users.
      Работает (реплика всё равно будет), но лишний lookup на запись и
      зависимость записи от наличия юзера в реплике.

РЕШЕНИЕ (2026-06-29): ВЫБРАН ВАРИАНТ 1 — listener на JWTCreatedEvent добавляет
claim `id` (= User.getId()). claim `username` (=login) остаётся как есть.
Статус: запланировано к реализации (правка маленькая, обратно совместимая).

ЗАМЕТКА: при выборе (1) — добавить тест, что в payload есть и `id`, и `roles`;
проверить, что refresh-токен (gesdinet) корректно переносит новый claim.


4.5 ЧЕК-ЛИСТ AUTH ДЛЯ GO-СЕРВИСА
--------------------------------------------------------------------------------
[ ] Проверка подписи RS256 по public.pem
[ ] Проверка exp (и iat), отказ -> 401
[ ] Middleware кладёт userId / login / roles в контекст запроса
[ ] Доменные проверки доски (admin / участник) — отдельным слоем по БД -> 403
[ ] CORS: тот же front-origin, что и сейчас у React
[ ] (опц.) clock skew пара секунд
[ ] Поведение refresh для React не меняется: Go отдаёт 401 на протухший токен,
    клиент идёт рефрешить в Symfony (как сейчас).


4.6 REALTIME — РЕШЕНО: ОСТАЁМСЯ НА MERCURE (см. раздел 11)
--------------------------------------------------------------------------------
Кратко: текущая realtime-модель канбана = server->client broadcast по топику,
ровно то, для чего Mercure создан. Go публикует в существующий hub обычным
HTTP POST с Mercure-JWT, фронт не меняет подписку. Подробно — раздел 11.


================================================================================
6. БД — РЕШЕНО: ОТДЕЛЬНАЯ БД У МИКРОСЕРВИСА
================================================================================
РЕШЕНИЕ (2026-06-29): у Go-сервиса своя БД. Канбан-таблицы вырезаются из
Symfony и переносятся в Go. Общую БД не делим (антипаттерн).

6.1 КАНБАН-СУЩНОСТИ DOCTRINE (что переносим) — src/Entity/Kanban/
--------------------------------------------------------------------------------
  KanbanBoard            (доска)        -> KanbanProject, User, [Columns, Labels]
  KanbanColumn           (колонка)      -> KanbanBoard, [Cards]
  KanbanCard             (карточка)     -> KanbanColumn, User x3 (см. ниже),
                                           [Subtasks, Comments, Attachments, Labels]
  KanbanCardSubtask      (подзадача)    -> KanbanCard, User
  KanbanCardComment      (чат/коммент)  -> KanbanCard, User
  KanbanCardActivity     (история)      -> KanbanCard, User
  KanbanAttachment       (вложение)     -> KanbanCard
  KanbanLabel            (метка)        -> KanbanBoard, KanbanCard
  Project/KanbanProject  (проект)       -> User x2 (owner и т.д.), [Boards]
  Project/KanbanProjectUser (участник)  -> KanbanProject, User

  Сопутствующее (проверить, тоже переносится):
    src/Repository/Kanban, src/Service/Kanban, src/Enum/Kanban,
    src/Controller/Kanban (SSR), src/Controller/SpaApi/Kanban (React API)

6.2 ГРАНИЦА РАЗРЕЗА — ЕДИНСТВЕННАЯ ВНЕШНЯЯ СВЯЗЬ = User  !!! КЛЮЧЕВОЕ !!!
--------------------------------------------------------------------------------
Анализ всех targetEntity в src/Entity/Kanban показал: ВСЕ связи внутренние
(Kanban -> Kanban), КРОМЕ ссылок на User. Канбан НЕ цепляется за Document,
Organization, Department и прочий домен Symfony. => Разрез чистый, ровно по
одной внешней сущности — User.

Ссылки канбана на User (8 точек):
  - KanbanProject:        User x2  (owner + ещё одна роль)
  - KanbanProjectUser:    User     (участник проекта)
  - KanbanBoard:          User
  - KanbanCard:           User x3  (вероятно author / assignee / ещё одна — уточнить)
  - KanbanCardSubtask:    User
  - KanbanCardComment:    User
  - KanbanCardActivity:   User

СЛЕДСТВИЕ: после выноса Go хранит у себя только user_id (int) во всех этих
местах. Данные пользователя (ФИО, логин, аватар для assignee/автора/чата) —
их в БД канбана НЕТ. Нужно решить, КАК Go их показывает (см. 6.3).

ЭТО НАПРЯМУЮ СВЯЗАНО С п.4.4 (id vs login в токене): чтобы Go писал user_id,
ему нужен именно id из токена -> укрепляет выбор варианта (A) в 4.4.

6.3 КАК ПОЛЬЗОВАТЕЛИ ХРАНЯТСЯ / ПОКАЗЫВАЮТСЯ В GO
--------------------------------------------------------------------------------
ФАКТ ПО КОДУ: в БД канбана ОБЯЗАТЕЛЬНО хранится только user_id (int) в 8 точках
связи (см. 6.2). Вопрос — откуда брать человекочитаемое (ФИО + аватар).

Что канбан реально показывает (из kanban_board.html.twig):
  - assignees (исполнители) на карточках и в сайдбаре задачи
  - авторы в чате (KanbanCardComment) и в истории (KanbanCardActivity)
  - аватарки
Поля User для отображения: lastname + firstname + patronymic (ФИО),
  avatarName (аватар), login.
ВАЖНО: у User есть deletedAt (soft-delete) -> пользователи бывают
  удалённые/уволенные, это надо корректно показывать.

Варианты источника ФИО/аватара:
  (A) Snapshot (денормализация имени в момент действия): рядом с author_id
      пишем author_name строкой.
      + просто, нет рантайм-зависимости, истории чата/активности «историчность»
        естественна (кто писал тогда — так и осталось).
      - для assignee НЕ годится: при переименовании зависнет старое имя.
  (B) Batch-API в Symfony (/spa/api/users?ids=...): Go отдаёт user_id, имена
      резолвятся отдельно.
      + всегда актуально, единый источник правды.
      - рантайм-зависимость от Symfony, доп. round-trip.
  (C) Реплика таблицы users в БД канбана (id, login, fio, avatar_url,
      is_deleted), синхронизируется из Symfony (Messenger/Mercure/webhook),
      первично — bulk-импорт.
      + Go автономен, локальные JOIN, быстро, актуально.
      - нужен механизм синхронизации + первичный импорт (лишний движущийся узел).
  (D) Фронт резолвит сам: Go отдаёт только user_id, React тянет юзеров через
      существующий /spa/api.
      + Go вообще не знает про пользователей кроме id (чистейший разрез).
      - логика на фронте, не работает для не-React/серверного рендера.

РЕКОМЕНДАЦИЯ: ГИБРИД C + A («local read model»).
  1. Везде хранить user_id (обязательно).
  2. Лёгкая реплика users в БД Go (C) — для АКТУАЛЬНОГО: assignee, список
     участников, выпадашки выбора. Поля: id, login, fio, avatar_url, is_deleted.
     Синхронизация из Symfony при изменении пользователя + первичный bulk-импорт.
  3. Snapshot имени (A) — ТОЛЬКО для чата и истории (KanbanCardComment,
     KanbanCardActivity): там имя должно отражать момент события и это
     страхует от удалённых пользователей.
  Логика: assignee требует свежести -> реплика; чат/история требуют
  исторической точности -> снимок. Go остаётся автономным (не падает вместе
  с Symfony).

РЕШЕНИЕ (2026-06-29): ВЫБРАН ГИБРИД C + A.
  - user_id везде (обязательно)
  - реплика users в БД Go для актуального (assignee/участники/выбор)
  - snapshot имени автора для чата и истории
  Канал синхронизации: предварительно RabbitMQ (НЕ финально, ещё думаем —
  см. 6.3.1).

6.3.1 КАНАЛ СИНХРОНИЗАЦИИ РЕПЛИКИ users — РЕШЕНО: RabbitMQ (общая шина)
--------------------------------------------------------------------------------
Состояние инфраструктуры по факту:
  - Symfony Messenger настроен (config/packages/messenger.yaml):
      transport `async` = %env(MESSENGER_TRANSPORT_DSN)%, retry max 3, x2
      failure_transport `failed` = doctrine://default (сейчас на Doctrine)
  - В .env есть ЗАКОММЕНТИРОВАННЫЙ пример amqp DSN:
      amqp://guest:guest@localhost:5672/%2f/messages
    => AMQP в проекте предусмотрен, но RabbitMQ как сервис пока НЕ поднят.
  - Также есть Mercure (можно как альтернативный канал событий).

Идея потока (если RabbitMQ):
  Symfony: Doctrine-событие изменения User (postPersist/postUpdate, +
  смена ФИО/аватара/deletedAt) -> публикация сообщения user.upserted /
  user.deleted в обменник RabbitMQ.
  Go: consumer слушает очередь -> upsert/мягкое удаление строки в реплике users.
  + Первичный bulk-импорт всех пользователей при первом старте сервиса.

Кандидаты канала (рассмотрены):
  [V] RabbitMQ — ВЫБРАН (см. ниже обоснование).
  [ ] Symfony Messenger поверх того же RabbitMQ (переиспользовать `async`).
  [ ] Mercure — уже есть, но pub/sub без гарантий доставки (consumer лежал —
      событие потеряно) -> слабее для синхронизации БД.
  [ ] Периодический pull Go -> Symfony API (просто, без брокера, но лаг).
  [ ] Webhook Symfony -> Go (просто, но без гарантий доставки/ретраев).

СОСТОЯНИЕ ИНФРЫ (факт): Messenger СЕЙЧАС на doctrine://default (НЕ AMQP).
  RabbitMQ не поднят. symfony/amqp-messenger НЕ установлен (есть только
  doctrine-messenger). Message/Handler-классов нет. Doctrine-листенера на
  изменение User НЕТ — точку «поймать изменение юзера» надо создавать.
  => RabbitMQ = новая инфра + новый код с обеих сторон.

РЕШЕНИЕ ПО КАНАЛУ (2026-06-29): RabbitMQ.
  ВХОДНЫЕ (через AskUserQuestion):
    - пользователи меняются РЕДКО (справочник) -> сама по себе синхра users
      НЕ требует брокера, хватило бы periodic pull;
    - НО канбан — НАЧАЛО, будут ЕЩЁ микросервисы -> общая шина окупится.
  Второй фактор перевешивает. Обоснование СМЕЩАЕТСЯ:
    RabbitMQ строим НЕ ради таблицы users, а как ОБЩУЮ событийную шину проекта.
    users — идеальный ПИЛОТНЫЙ кейс (редко меняется -> низкий риск обкатки).
    Та же логика, что MinIO/imgproxy: канбан = полигон для инфраструктуры,
    переиспользуемой дальше.

ЧТО ЗАЛОЖИТЬ СРАЗУ (раз это ФУНДАМЕНТ для будущих сервисов, не разовое):
  [ ] Topic exchange с осмысленным неймингом routing keys: user.upserted,
      user.deleted (на будущее document.created и т.п.). Exchange НЕ привязывать
      к канбану — он общий.
  [ ] Каждый сервис — СВОЯ очередь на нужные routing keys. Канбан слушает user.*.
  [ ] Dead-letter queue с самого начала (куда падают необработанные сообщения).
  [ ] Идемпотентность consumer: INSERT ... ON CONFLICT DO UPDATE (повторная
      доставка не ломает реплику).
  [ ] Контракт/схема сообщений: зафиксировать формат payload + версионирование,
      чтобы новые сервисы знали что приходит.
  [ ] Грациозная деградация: брокер недоступен -> Go на старых данных реплики
      (для редкого справочника безопасно).

СТЕК RabbitMQ:
  - Symfony producer: symfony/amqp-messenger + php-ext amqp (СЕЙЧАС НЕ
    установлены -> ставим). Doctrine entity listener на User (postPersist/
    postUpdate/postRemove на ФИО/аватар/deletedAt) -> publish.
  - Go consumer: amqp091-go (см. раздел 12), постоянный, переподключения.
  - Команда bulk-импорта всех users при первом старте сервиса.

ПОДВОПРОСЫ (реплика C):
  [ ] Какие поля реплицируем (минимум: id, login, lastname, firstname,
      patronymic, avatarName, deletedAt)?
  [ ] Аватары: храним URL и Go проксирует/imgproxy, или копируем файл? (8.9)
  [ ] Обработка soft-deleted (deletedAt): показывать «Удалённый пользователь»?

6.4 МИГРАЦИЯ ДАННЫХ (перенос существующих канбан-строк) — ДЕТАЛИ В РАЗДЕЛЕ 15
--------------------------------------------------------------------------------
[ ] Дамп канбан-таблиц из БД Symfony.
[ ] Схема в БД Go (миграции Go — выбрать инструмент).
[ ] Перенос данных + сохранение user_id как есть (id пользователей общие).
[ ] FK на User в канбан-таблицах превратятся в «голый» user_id без FK
    (т.к. таблицы users в БД канбана может не быть — зависит от 6.3).
[ ] Перенос файлов/вложений (см. 2.3) синхронно с переносом KanbanAttachment.
[ ] План отката / проверка целостности после миграции.

6.5 ЧТО УДАЛЯЕТСЯ ИЗ SYMFONY (после успешного переключения)
--------------------------------------------------------------------------------
[ ] src/Entity/Kanban/** (+ Project/)
[ ] src/Repository/Kanban, src/Service/Kanban, src/Enum/Kanban
[ ] src/Controller/Kanban (SSR), src/Controller/SpaApi/Kanban
[ ] templates/kanban/** (в т.ч. kanban_board.html.twig, ~2585 строк)
[ ] Канбан-роуты, пункты меню/сайдбара (sidebar/sidebar_list.html.twig)
[ ] Doctrine-миграция на DROP канбан-таблиц (последним шагом, после бэкапа)
[ ] !!! ВАЖНО: связи User -> Kanban (inverse-side OneToMany в User entity, если
    есть) тоже надо вычистить, иначе Doctrine сломается. ПРОВЕРИТЬ User.php.


================================================================================
8. ФАЙЛЫ / ВЛОЖЕНИЯ (к задачам и в чат) — РАЗОБРАНО ПО КОДУ
================================================================================

8.1 МОДЕЛЬ — ЕДИНАЯ ТАБЛИЦА kanban_attachment (различие по context)
--------------------------------------------------------------------------------
ФАКТ: все вложения — ОДНА сущность KanbanAttachment (таблица kanban_attachment).
Тип вложения задаётся полем context:
    'info'        — вложения к задаче (вкладка «инфо»)
    'chat'        — файлы/картинки в чате (входят в commentsCount карточки)
    'description' — файлы в описании
Разрешённые контексты: ['chat','info','description']
  (AttachmentController.php:32, KanbanAttachmentApiController.php:56)

ВАЖНО: у KanbanCardComment СВОИХ файловых полей НЕТ. Чат-вложения — это
attachment с context='chat', привязанный к КАРТОЧКЕ (не к комментарию).
=> Переносим ОДНУ файловую подсистему, а не три. Чат и задача делят таблицу.

Поля kanban_attachment:
    id, filename (оригинальное имя), storage_key (length 500),
    content_type, size_bytes, context (default 'info'),
    card_id  -> KanbanCard, ON DELETE CASCADE,
    author_id -> User, nullable, ON DELETE SET NULL,
    created_at (Gedmo Timestampable on create)

8.2 ХРАНЕНИЕ — ПРОСТОЙ ДИСК, НЕ VichUploader  (хорошо для Go)
--------------------------------------------------------------------------------
src/Service/Kanban/KanbanAttachmentService.php:
  - storageKey = "{cardId}/{16 байт hex}.{ext}"  (рандомное имя файла)
  - база: %kanbanUploadDir% (инжектится в сервис)
  - upload(): mkdir(targetDir, 0755) + $file->move()
  - getFilePath(): kanbanUploadDir . '/' . storageKey
  - delete(): unlink(файл) + em->remove()
=> Никакой магии Doctrine-расширений. В Go воспроизводится тривиально
   (S3 или локальный диск с тем же storage_key).

8.3 ПРЕВЬЮ КАРТИНОК — ЕДИНСТВЕННАЯ РЕАЛЬНАЯ ЗАВИСИМОСТЬ (LiipImagine)
--------------------------------------------------------------------------------
  - src/Controller/SpaApi/Kanban/AttachmentController.php: download/preview
    использует Liip\ImagineBundle FilterService, фильтр 'kanban_attachment_preview'
    (warmUpCache + getBrowserPath).
  - src/Service/Kanban/KanbanAttachmentPreviewUrlGenerator.php — генерит URL превью.
РЕШИТЬ для Go: воспроизвести ресайз/превью (Go image libs / imgproxy /
  отдавать оригинал)? Это единственное, что не переносится «один в один».

8.4 ВАЖНЫЕ НЮАНСЫ / РИСКИ (учесть при переносе)
--------------------------------------------------------------------------------
[ ] !!! ВАЛИДАЦИЯ ТИПОВ ФАЙЛОВ СЕЙЧАС ОТКЛЮЧЕНА (закомментирована в
    KanbanAttachmentService): whitelist расширений (pdf,png,jpg,jpeg,webp,
    docx,xlsx) и проверка magic bytes. Принимается ЛЮБОЙ файл — дыра.
    В Go включить нормальную валидацию (расширение + magic bytes + лимит размера).
[ ] Лимит 16 вложений на карточку (MAX_ATTACHMENTS_PER_CARD) — перенести.
[ ] author_id ON DELETE SET NULL — вложение переживает удаление автора.
[ ] card_id ON DELETE CASCADE — при удалении карточки строки чистятся в БД,
    НО файлы на диске удаляются только поштучно в delete(). При cascade-удалении
    карточки файлы остаются СИРОТАМИ на диске (уже сейчас потенциальный мусор).
    В Go предусмотреть удаление файлов при удалении карточки/колонки/доски
    (явный обход или фоновая GC-задача).
[ ] chat-вложения участвуют в realtime (publishCardPatch + buildCommentsCount):
    при переносе чата в Go это надо повторить (см. realtime 4.6).
[ ] Есть ДВА API вложений в Symfony (дубли — оба под удаление после миграции):
      src/Controller/SpaApi/Kanban/AttachmentController.php (React/SPA)
      src/Controller/Kanban/Api/KanbanAttachmentApiController.php (SSR)

8.5 ENDPOINTS ВЛОЖЕНИЙ (контракт для Go)
--------------------------------------------------------------------------------
Базовый префикс (SPA): /spa/api/cards/{cardId}/attachments
  POST   ''                 — upload (multipart: file + context), 201 -> attachment JSON
  GET    '/{id}/download'   — отдать файл (BinaryFileResponse)
  (+ превью через LiipImagine; + delete — доуточнить полный список из контроллера)
[ ] Снять ПОЛНЫЙ список методов из AttachmentController при инвентаризации API.

8.6 ОТКРЫТЫЕ РЕШЕНИЯ ПО ФАЙЛАМ
--------------------------------------------------------------------------------
[ ] Перенос существующих файлов с диска вместе с дампом таблицы (см. 6.4).
[ ] Валидация типов: какой whitelist расширений/размеров включаем в Go.
[V] Кто отдаёт файл клиенту — РЕШЕНО: проксирование через Go (Go проверяет JWT
    + права доски, читает из MinIO, отдаёт). Клиент: minio-go. См. 12.1.


8.7 СТРАТЕГИЯ ХРАНИЛИЩА: MinIO (S3) — КАНБАН КАК ПИЛОТ ДЛЯ ВСЕГО ПРОЕКТА
--------------------------------------------------------------------------------
КОНТЕКСТ ПО ПРОЕКТУ (как файлы хранятся сейчас) — ДВА подхода:
  (1) VichUploader — 7 mappings: document_files, post_files, post_cover,
      user_avatar, user_files, document_comment_files, chat_files.
      Завязан на диск (upload_destination), namer + directory_namer
      (document.id / post.id / user.id ...).
  (2) Ручной storage_key — ТОЛЬКО канбан. Абстрактный ключ + move().
Параметры путей: config/services.yaml (private_upload_dir_*),
  канбан: private_upload_kanban_dir -> $kanbanUploadDir.

ОТВЕТ НА ВОПРОС «можно ли постепенно весь проект на MinIO»: ДА.
  - MinIO = S3-совместимый, поднимается ОДИН на всю инфраструктуру.
  - Канбан — ИДЕАЛЬНЫЙ ПИЛОТ: его storage_key уже абстрактный, не завязан на
    структуру Vich => переезд на S3 безболезненный.
  - Потом Symfony переключает Vich-mappings на S3 ПО ОДНОМУ
    (oneup/flysystem-bundle + S3-адаптер). Namer'ы/directory_namer'ы станут
    ключами объектов. Это и есть «постепенно», без big bang.
РЕКОМЕНДАЦИЯ: поднять MinIO один на всех; канбан-сервис — первый клиент и
  образец. Остальной проект мигрирует mapping-за-mapping'ом позже.
РЕШЕНИЕ: MinIO (предварительно согласовано как направление) — подтвердить.

8.8 ОДНА ТАБЛИЦА ATTACHMENT vs РАЗДЕЛИТЬ — ОСТАВЛЯЕМ ОДНУ (хорошо)
--------------------------------------------------------------------------------
ВОПРОС: хорошо ли что все вложения в одной таблице? разделить на
  комментарии/описание отдельно?
ОТВЕТ: ОСТАВИТЬ ОДНУ ТАБЛИЦУ. Разделять НЕ надо. Это хорошо.
ПОЧЕМУ:
  - Это один и тот же объект: filename, storage_key, content_type, size,
    author, created_at — поля идентичны во всех контекстах. Различие только
    «где показан» = ровно назначение поля context.
  - Единый код: один upload-сервис, одна валидация, одна логика превью, одна
    GC сирот, один лимит. Разделение размножает всё это x3.
  - Гибкость: новый контекст (напр. 'cover') = одно значение enum, не новая
    таблица + миграция + код.
  - context уже корректно используется в realtime, commentsCount, фильтрации —
    разделение сломало бы рабочую логику без выгоды.
КОГДА бы разделяли: если бы у типов были РАЗНЫЕ поля (напр. длительность у
  голосовых в чате, порядок сортировки у описания). Сейчас поля одинаковые ->
  разделение = оверинжиниринг.
УЛУЧШЕНИЕ (не разделение): context сделать строгим enum/типом
  (Go: type AttachmentContext string + константы), убрать «магические строки».
РЕШЕНИЕ: одна таблица + context как enum. (подтвердить)

8.9 РЕСАЙЗИНГ: imgproxy — ОБЩЕПРОЕКТНАЯ ЗАМЕНА LiipImagine
--------------------------------------------------------------------------------
ЧТО НА LiipImagine СЕЙЧАС (4 filter_set, все — ресайз-превью):
  avatar_thumbnail (50x50), avatar_medium (200x200),
  kanban_attachment_preview (400x400 inbound), post_image_preview (400x400).
  Loaders: default(public), user_avatar, kanban_attachment, post_uploads.
  Resolver: web_path -> public/media/cache (кэш на диск, warmUpCache).

ОТВЕТ НА ВОПРОС «можно ли потом весь проект на imgproxy»: ДА, хороший выбор.
  - imgproxy = ОТДЕЛЬНЫЙ сервис (не библиотека), один на всю инфраструктуру,
    не зависит от языка: и Go-канбан, и Symfony ходят к одному imgproxy.
  - Идеально сочетается с MinIO/S3: imgproxy берёт исходник прямо из S3 и
    ресайзит на лету -> не нужен warmUpCache, как в Liip.
  - Подписанный URL imgproxy (/resize:fit:400:400/...) заменяет
    getBrowserPath() Liip; каждый filter_set -> набор параметров в URL.
СТРАТЕГИЯ: imgproxy + MinIO ставятся в паре, канбан — первый потребитель.
  Потом Symfony заменяет каждый Liip filter_set на imgproxy-URL по одному.
!!! НЮАНС: imgproxy ресайзит НА ЛЕТУ и по умолчанию НИЧЕГО не хранит -> без
  кэша каждый показ = новый ресайз (нагрузка на CPU). Liip сейчас кэширует
  готовое превью на диск (public/media/cache) и отдаёт повторно без ресайза.
  Нужно воспроизвести этот кэш-слой ОТДЕЛЬНО.

  ЧТО ТАКОЕ КЭШ/CDN ЗДЕСЬ (это УРОВНИ, не «или-или»):
   - КЭШ = промежуточный слой, хранит готовое превью и отдаёт повторно.
     Минимальный вариант: nginx proxy_cache перед imgproxy:
        браузер -> nginx (есть готовое? отдать) -> [нет] imgproxy (ресайз) ->
        nginx запоминает -> отдаёт. imgproxy ресайзит картинку ОДИН раз.
        По сути воспроизводит то, что Liip делал через диск.
   - CDN (Content Delivery Network, напр. Cloudflare/Fastly) = распределённая
     сеть, кэширует статику БЛИЖЕ к пользователю; при повторных открытиях
     запрос до нашего сервера/imgproxy вообще не доходит. Быстрее + снимает
     трафик. Платно/сложнее.

  РЕШЕНИЕ ПО КЭШУ (2026-06-29):
   - ОБЯЗАТЕЛЬНО: nginx proxy_cache перед imgproxy (минимум, его достаточно).
   - CDN — ОПЦИОНАЛЬНО, только если появятся внешние/географически разные
     пользователи или большой трафик. Для внутреннего документооборота
     (пользователи в одной сети/регионе) CDN скорее всего ИЗБЫТОЧЕН.

РЕШЕНИЕ: imgproxy + ОБЯЗАТЕЛЬНЫЙ nginx-кэш перед ним; CDN опционально (на вырост).


================================================================================
10. ИНВЕНТАРИЗАЦИЯ ЭНДПОИНТОВ (контракт API для Go)
================================================================================
ФАКТ: канбан в Symfony имеет ДВА параллельных API:
  - SSR-API:  src/Controller/Kanban/Api/*  + ProjectKanbanController (17 роутов)
              -> обслуживает Twig-канбан, ПОД УДАЛЕНИЕ.
  - SPA-API:  src/Controller/SpaApi/Kanban/*  -> React, ОСНОВА КОНТРАКТА для Go.
Ниже — SPA-API (то, что Go должен воспроизвести). Всё под firewall ^/spa/api
(stateless JWT, ROLE_USER).

10.1 SPA-API — ПОЛНЫЙ СПИСОК (источник истины для Go)
--------------------------------------------------------------------------------
PROJECTS (ProjectController) — /spa/api/projects
  POST   /spa/api/projects                       project_create
  GET    /spa/api/projects/{id}                  project_view
  PATCH  /spa/api/projects/{id}                  project_update
  DELETE /spa/api/projects/{id}                  project_delete
  [?] НЕТ GET /spa/api/projects (список всех проектов) — уточнить, где берётся
      список проектов (отдельный эндпоинт? страница SSR?). ВОПРОС.

PROJECT MEMBERS (ProjectMemberController) — /spa/api/projects
  PUT    /spa/api/projects/{id}/members              members_replace (替换 весь список)
  PATCH  /spa/api/projects/{id}/members/{userId}     member_update_role
  DELETE /spa/api/projects/{id}/members/{userId}     member_remove

BOARDS (BoardController) — /spa/api/projects
  GET    /spa/api/projects/{id}/boards/{boardId}          board_show
  GET    /spa/api/projects/{id}/boards/{boardId}/archive  board_archive (архив задач)
  POST   /spa/api/projects/{id}/boards                    board_create
  PATCH  /spa/api/projects/{id}/boards/{boardId}          board_update
  DELETE /spa/api/projects/{id}/boards/{boardId}          board_delete

COLUMNS (ColumnController) — /spa/api/projects/{projectId}/boards/{boardId}/columns
  POST   .../columns                 column_create
  PATCH  .../columns/{columnId}      column_patch
  DELETE .../columns/{columnId}      column_delete

CARDS (CardController) — /spa/api/cards
  POST   /spa/api/cards                  cards_create
  GET    /spa/api/cards/{id}             cards_show
  PATCH  /spa/api/cards/{id}             cards_update
  PUT    /spa/api/cards/{id}/assignees   cards_assignees (替换 исполнителей)
  POST   /spa/api/cards/{id}/move        cards_move (перемещение между колонками)
  DELETE /spa/api/cards/{id}             cards_delete
  PATCH  /spa/api/cards/{id}/archive     cards_archive

LABELS (LabelController) — /spa/api/projects
  GET    /spa/api/projects/{id}/boards/{boardId}/labels                       labels_list
  POST   /spa/api/projects/{id}/boards/{boardId}/labels                       labels_create
  DELETE /spa/api/projects/{id}/boards/{boardId}/labels/{labelId}             labels_delete
  POST   /spa/api/projects/{id}/boards/{boardId}/labels/cards/{cardId}/{labelId}  labels_toggle

SUBTASKS / CHECKLIST (SubtaskController) — /spa/api/cards/{cardId}/subtasks
  GET    .../subtasks            subtasks_list
  POST   .../subtasks            subtasks_create
  PATCH  .../subtasks/{id}       subtasks_update
  DELETE .../subtasks/{id}       subtasks_delete

COMMENTS / ЧАТ (CommentController) — /spa/api/cards/{cardId}/comments
  GET    .../comments                comments_list
  POST   .../comments                comments_create
  PUT    .../comments/{commentId}    comments_update
  DELETE .../comments/{commentId}    comments_delete

ATTACHMENTS (AttachmentController) — /spa/api/cards/{cardId}/attachments
  POST   .../attachments               attachments_upload (multipart: file+context)
  GET    .../attachments/{id}/download attachments_download (BinaryFileResponse)
  GET    .../attachments/{id}/preview  attachments_preview (LiipImagine -> imgproxy)
  DELETE .../attachments/{id}          attachments_delete

ACTIVITY / ИСТОРИЯ (ActivityController) — /spa/api/cards/{cardId}/activities
  GET    .../activities          activities_list

10.2 ПОГРАНИЧНЫЕ ЭНДПОИНТЫ — ОСТАЮТСЯ В SYMFONY (Go их НЕ переносит)
--------------------------------------------------------------------------------
Источник пользователей для assignee/members — НЕ канбанный, общий:
  GET /spa/api/users               (UserController) — список пользователей
  GET /spa/api/users/roles         (UserController) — список ролей
  GET /spa/api/users/search        (DocumentUsersController) users_search
  GET /spa/api/organizations/{id}/users  (DocumentUsersController) org_users
=> Это про ПОЛЬЗОВАТЕЛЕЙ, не про канбан. Остаются в Symfony.
   Go либо опирается на них (фронт резолвит), либо использует реплику users
   (см. 6.3). Решить связку: кто отдаёт список юзеров для выпадашек в Go-канбане.

10.3 ВЫВОДЫ / ЗАДАЧИ ПО КОНТРАКТУ
--------------------------------------------------------------------------------
[~] JSON-схемы ОТВЕТОВ сняты (раздел 16): KanbanItem, колонка, доска, архив,
    assignee, comment, subtask, attachment. Осталось: детальный show карточки
    (cards/{id}) и схемы ТЕЛ запросов (POST/PATCH).
[ ] Уточнить ВОПРОС: список проектов (нет GET /spa/api/projects).
[ ] Решить пограничный список пользователей (10.2): реплика vs проксирование.
[ ] Маппинг URL: оставить тот же префикс (/spa/api/...) и роутить на Go через
    gateway/nginx, ИЛИ дать Go свой префикс и переключить фронт. (см. деплой)
[ ] SSR-API (Controller/Kanban/Api/* + ProjectKanbanController, 17 роутов) —
    в инвентаризацию НЕ детализируем: целиком под удаление вместе с Twig.
[ ] Realtime-эндпоинты/каналы (Mercure publishCardPatch и т.п.) — отдельно
    в разделе про realtime (см. 4.6).


================================================================================
11. REALTIME — MERCURE vs WEBSOCKET В GO — РЕШЕНО: MERCURE
================================================================================

11.1 КАК REALTIME УСТРОЕН СЕЙЧАС (факт по коду)
--------------------------------------------------------------------------------
Единая точка: src/Service/Kanban/KanbanRealtimePublisher.php
  - Всё летит в ОДИН топик: /kanban/board/{boardId}
  - Типы событий: card_created, card_updated, card_deleted
  - Полезная нагрузка: { type, card|cardId, senderId }
    card — в формате KanbanItem (тот же, что отдаёт BoardController::formatColumn)
  - senderId — id автора события; фронт игнорирует СВОЁ эхо по нему.
Реально публикуются ТОЛЬКО события карточек (grep по контроллерам):
  publishCardCreated x1, publishCardDeleted x1, publishCardUpdated x1,
  publishCardPatch x10 (патчи: assignees, labels, checklist-счётчики,
  commentsCount при chat-вложении, и т.д.)
  => колонки/доски/лейблы realtime НЕ покрыты — обновляются перезагрузкой/ответом.
Направление: server -> client broadcast. Фронт ТОЛЬКО слушает (EventSource/SSE).
Двунаправленного WS-обмена, presence, «печатает...», курсоров — НЕТ.

Mercure — это ОБЩАЯ инфраструктура проекта, не канбан-специфика:
  публикуют также src/Service/Chat/ChatMessageService.php и
  src/Controller/SpaApi/DocumentsFlow/DocumentOutgoingController.php
Конфиг (.env):
  MERCURE_URL=http://mercure/.well-known/mercure         (внутр., для publish)
  MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure (для подписки)
  MERCURE_JWT_SECRET=...   (симметричный секрет hub'а; publish: '*')
Hub — ОТДЕЛЬНЫЙ сервис, уже поднят.

11.2 РЕШЕНИЕ: ОСТАЁМСЯ НА MERCURE (Go публикует HTTP POST в hub)
--------------------------------------------------------------------------------
Поток:  Go-канбан --HTTP POST(JWT)--> Mercure hub <--SSE-- React
Go НЕ нужна Symfony-библиотека Mercure: публикация = обычный HTTP POST на hub
с Mercure-JWT. Go остаётся STATELESS (не держит соединений). Фронт не меняет
способ подписки. Топик /kanban/board/{boardId} сохраняется.

ПОЧЕМУ Mercure, а не WS в Go:
  + текущая модель = чистый broadcast, Mercure для этого и сделан
  + уже работает, фронт подписан той же моделью (не переписываем подписку)
  + общий механизм проекта (чат, документы) — канбан не становится особняком
  + авторизация подписки, переподключение, SSE-fallback, масштабирование —
    из коробки
  + Go проще: только HTTP POST, без управления WS-соединениями и hub-в-памяти
  - WS в Go дал бы двусторонний обмен/presence — сейчас НЕ используется (избыточно)

КОГДА вернуться к WS в Go (на будущее, не сейчас):
  presence («кто смотрит карточку»), совместное редактирование с курсорами,
  «печатает...», мгновенный двусторонний обмен. Можно добавить точечно позже,
  не ломая Mercure-broadcast.

11.3 РИСКИ / ЧТО УЧЕСТЬ ПРИ ПЕРЕНОСЕ В GO
--------------------------------------------------------------------------------
[ ] Формат событий БАЙТ-В-БАЙТ: фронт ждёт { type, card, senderId }, card в
    формате KanbanItem. Go обязан генерить тот же JSON, что formatColumn
    (часть контракта, см. раздел 10) — иначе фронт сломается.
[ ] senderId ОБЯЗАТЕЛЕН (игнор собственного эха). Go берёт его из claim `id`
    в JWT -> ещё один довод за id в токене (см. 4.4).
[ ] avatarUrl в событиях (formatAssignee) сейчас строит UserAvatarUrlGenerator
    через LiipImagine. В Go -> imgproxy + данные из реплики users
    (связь с 6.3 и 8.9).
[ ] Mercure-JWT для публикации: Go нужен MERCURE_JWT_SECRET с правом publish.
    Это СИММЕТРИЧНЫЙ секрет hub'а — НЕ путать с RS256-ключами auth (раздел 4)!
[ ] Решить: расширять ли realtime на колонки/доски при переносе, или повторить
    как есть (только карточки). Рекомендация: повторить как есть (паритет),
    расширять отдельно.
[ ] Топик и формат имени канала оставить идентичными (/kanban/board/{boardId}).


================================================================================
12. СТЕК GO-СЕРВИСА — РЕШЕНО
================================================================================
КОНТЕКСТ:
  - Уже есть Go-наработка рядом: 11_go_grpc/ (go.mod, Go 1.23.1, gRPC) -> опыт
    с Go/gRPC присутствует.
  - БД проекта: PostgreSQL 16 (DATABASE_URL в .env, активен postgres).
  - Контракт сервиса: REST/JSON для React (раздел 10), JWT RS256 (раздел 4),
    файлы в MinIO/S3 (раздел 8), публикация realtime в Mercure (раздел 11).

12.1 ВЫБРАННЫЙ СТЕК
--------------------------------------------------------------------------------
  Язык:        Go (1.23+, как в 11_go_grpc).
  HTTP:        chi (+ stdlib net/http).  [РЕШЕНО]
               Лёгкий роутер поверх net/http, без своего контекста, идиоматично,
               легко тестировать. Под чистый REST-контракт раздела 10.
  Доступ к БД: sqlc + pgx — ОСНОВНОЙ слой.  [РЕШЕНО]
               Пишем SQL -> sqlc генерит типобезопасный Go-код под pgx.
               Проверка SQL против схемы на этапе генерации (ловит ошибки
               переноса схемы из Doctrine до рантайма).
               squirrel — ТОЧЕЧНО, только для динамических запросов (переменный
               набор фильтров), где sqlc неудобен. (есть прошлый опыт со squirrel)
  Драйвер:     pgx (нативные типы Postgres, пулинг). sqlc генерит код под pgx.
  Миграции:    goose (pressly/goose).  [РЕШЕНО]
               Причины: знаком (прошлый опыт); встраивается в сервис
               (goose.Up при старте -> схема накатывается при подъёме контейнера);
               SQL-миграции ложатся на ту же схему, что читает sqlc (единый
               источник правды); умеет Go-миграции -> удобно для разового
               переноса данных из старой БД (раздел 6.4).
  JWT:         github.com/golang-jwt/jwt/v5 — валидация RS256 по public.pem
               (раздел 4). Go только проверяет, не выпускает.
  S3/MinIO:    minio-go (родной клиент MinIO).  [РЕШЕНО]
               Причины: выбран MinIO (8.7) -> minio-go его родной клиент;
               простой API (PutObject/GetObject/PresignedGetObject); легче по
               зависимостям, чем AWS SDK v2; storage_key (8.2) напрямую = ключ
               объекта. AWS SDK v2 был бы нужен только при реальном AWS S3.
               ОТДАЧА ФАЙЛОВ: проксирование через Go (вариант 1, см. 8.6) —
               клиент -> Go (JWT + права на доску) -> читает из MinIO -> отдаёт.
               Сохраняет текущую модель (Symfony сейчас отдаёт BinaryFileResponse
               с проверкой прав), доступ остаётся под контролем прав доски.
               Presigned URL — НЕ основной путь; можно добавить точечно для
               превью, если понадобится разгрузка.
  Mercure:     обычный net/http POST в hub с Mercure-JWT (раздел 11),
               без спец-библиотек. Go stateless.
  RabbitMQ:    amqp091-go — ЕСЛИ подтвердим RabbitMQ каналом синхронизации
               реплики users (см. 6.3.1, ещё не финал).
  Картинки:    ресайз НЕ в Go — отдельный сервис imgproxy + nginx-кэш (раздел 8.9).

12.2 ПОЧЕМУ ИМЕННО ТАК (ключевые доводы)
--------------------------------------------------------------------------------
  - chi + sqlc = «идиоматичный Go без магии»: chi не прячет net/http, sqlc не
    прячет SQL. Предсказуемо, тестируемо, читаемо.
  - Уходим ОТ ORM сознательно (один из мотивов выноса — уйти от тяжести
    Doctrine). GORM притащил бы ту же ORM-магию/N+1 -> отвергнут.
  - Запросы канбана в основном статические (CRUD, выборки по id) -> sqlc на
    них оптимален; squirrel оставлен для редких динамических мест.
  - pgx — лучший драйвер под PostgreSQL 16.

12.3 ОТВЕРГНУТЫЕ ВАРИАНТЫ (зафиксировано, чтоб не возвращаться)
--------------------------------------------------------------------------------
  - HTTP: Gin/Echo (свой Context, чуть больше магии), Fiber (на fasthttp, не
    net/http — несовместимость с частью экосистемы). chi предпочтён.
  - БД: GORM (ORM-магия, N+1, уходим от ORM); чистый pgx вручную (ручной маппинг
    каждой строки — рутина, sqlc делает это за нас); squirrel как ОСНОВНОЙ
    (не избавляет от ручного Scan, нет проверки схемы) — оставлен только точечно.

12.4 ОТКРЫТЫЕ ПОД-РЕШЕНИЯ ПО СТЕКУ
--------------------------------------------------------------------------------
  [V] Инструмент миграций: goose (РЕШЕНО, см. 12.1).
  [V] S3-клиент: minio-go (РЕШЕНО, см. 12.1); отдача файлов — проксирование
      через Go.
  [ ] Структура проекта (layout): cmd/ + internal/ (handlers, service, repo,
      db (sqlc), domain) — согласовать перед стартом кода.
  [ ] Валидация входных DTO: stdlib + ручная, или go-playground/validator.
  [ ] Логирование: log/slog (stdlib) — кандидат по умолчанию.
  [ ] Конфиг: env (как у Symfony .env) — caarlos0/env или ручной os.Getenv.


================================================================================
13. ИНФРАСТРУКТУРА / DOCKER (реализовано)
================================================================================
ПРИНЦИП группировки compose: по ЖИЗНЕННОМУ ЦИКЛУ и зависимостям, НЕ «файл на
сервис». В проекте уже принят паттерн отдельных compose-файлов
(docker-compose.dbgate.yml, docker-compose.monitoring.yml).

13.1 RabbitMQ — В ОСНОВНОМ docker-compose.yml (ядро / общая шина)
--------------------------------------------------------------------------------
Добавлен сервис rabbitmq в основной docker-compose.yml (рядом с php, nginx,
postgres, mercure). Это шина ядра (синхронизация реплики users + будущие
межсервисные события, см. 6.3.1) -> место в ядре, не в файловом хранилище.
  - image: rabbitmq:4-management  (закреплён по мажору; UI на :15672)
  - сеть: project-net (общая, named bridge)
  - порты: 5672 (AMQP), 15672 (management UI)
  - данные: ./../rabbitmq_data ; healthcheck (rabbitmq-diagnostics ping)
  - креды через env: RABBITMQ_USER/PASSWORD/VHOST (дефолт guest/guest//)

13.2 ФАЙЛОВОЕ ХРАНИЛИЩЕ — папка file_storage/ (АВТОНОМНЫЙ стек)
--------------------------------------------------------------------------------
MinIO + imgproxy + nginx-кэш = ОДИН файл (общий жизненный цикл: imgproxy
читает из MinIO, nginx кэширует imgproxy). Вынесено в отдельную папку.
ВАЖНО: папка спроектирована АВТОНОМНОЙ — рассчитана на ПЕРЕЕЗД в отдельный
репозиторий (свой .env/.gitignore/README/compose). Структура nginx зеркалит
корень проекта (Dockerfile + config/).
  file_storage/
  ├── docker-compose.yml         (minio, imgproxy, imgproxy-cache)
  ├── .env / .env.example        (свои переменные; .env в .gitignore)
  ├── .gitignore                 (.env, minio_data/, imgproxy_cache/)
  ├── README.md                  (состав, старт по шагам, прод-чеклист)
  └── docker_env/nginx/
      ├── Dockerfile             (FROM nginx:1.27.0-alpine-slim, как осн. nginx)
      └── config/default.conf    (конфиг кэша перед imgproxy)

Сервисы и образы (версии ЗАКРЕПЛЕНЫ, не latest):
  - minio          minio/minio:RELEASE.2025-09-07T16-13-09Z
  - imgproxy       darthsim/imgproxy:v4.0.8 (источник — MinIO по s3, IMGPROXY_USE_S3=1)
  - imgproxy-cache build из docker_env/nginx/Dockerfile (nginx:1.27.0-alpine-slim)
  NB: minio-init УБРАН (не плодить Exited-контейнер) -> bucket создаётся ВРУЧНУЮ
      один раз (см. README, шаг 4).

Пути volume'ов (file_storage/ на уровень глубже корня):
  - data -> ./../../ (рядом с корнем, как postgres_data/uploads):
      ./../../minio_data, ./../../imgproxy_cache
  - конфиг -> ./docker_env/nginx/config/default.conf
  ПРИ ПЕРЕЕЗДЕ: data-пути ./../../ -> ./ (папка станет корнем).

СТАРТ (кратко; подробно — file_storage/README.md):
  1) cp .env.example .env  + задать MINIO_ROOT_PASSWORD
  2) сеть: в матер. проекте создаётся ядром; в отдельном репо —
     docker network create project-net
  3) docker compose up -d  (или с ядром через -f ... -f ...)
  4) создать bucket ОДИН РАЗ: веб-консоль :9001 ИЛИ
     docker run --rm --network project-net minio/mc:REL... \
       sh -c "mc alias set local http://minio:9000 USER PASS && mc mb -p local/kanban"

13.3 СХЕМА СЕТЕЙ (реализовано)
--------------------------------------------------------------------------------
Общая external-сеть project-net (создаётся основным docker-compose.yml,
name: project-net). file_storage использует её как external: true -> контейнеры
видят друг друга по именам (minio:9000, imgproxy:8080, rabbitmq:5672).

Публичность портов (по решениям проекта):
  ВНУТРЕННИЕ (наружу НЕ публикуются):
    - MinIO S3 API :9000  — файлы клиенту отдаёт Go проксированием (12.1)
    - imgproxy :8080      — доступен только через nginx-кэш
    - rabbitmq :5672      — AMQP только для приложений
  LOCALHOST-ONLY:
    - MinIO Console :9001 — 127.0.0.1 (админка)
  ПУБЛИЧНЫЕ (дверь наружу):
    - imgproxy-cache :8082 — попадает в <img src>
    - rabbitmq management :15672 — UI (при желании ограничить)

Поток:
  браузер --публ(:8082)--> nginx(кэш) --внутр--> imgproxy --внутр--> MinIO
  браузер --публ--> Go/Symfony --внутр--> MinIO        (скачивание файлов)
                    Go/Symfony --внутр--> RabbitMQ

13.4 ЗАПУСК
--------------------------------------------------------------------------------
  Ядро + хранилище:
    docker compose -f docker-compose.yml -f file_storage/docker-compose.yml up -d
  Только хранилище автономно (из file_storage/, сеть project-net должна быть):
    cd file_storage && docker compose up -d
  + создать bucket один раз (см. 13.2 / README шаг 4).
  Проверка: docker compose ... config --quiet -> прошла в ОБОИХ режимах
  (материнский и автономный).

13.5 ОТКРЫТО / TODO ПО ИНФРЕ
--------------------------------------------------------------------------------
  [ ] .env переменные (сейчас работают дефолты): MINIO_ROOT_USER/PASSWORD,
      MINIO_KANBAN_BUCKET, RABBITMQ_USER/PASSWORD, IMGPROXY_KEY/SALT.
  [ ] ПРОД: включить подпись URL imgproxy (IMGPROXY_KEY/SALT через
      openssl rand -hex 32) и убрать IMGPROXY_ALLOW_UNSAFE_URL=1.
  [ ] mercure в основном compose без версии (image: dunglas/mercure) — закрепить
      по той же логике (уже было до нас, не трогали).
  [ ] Включить imgproxy в реальную работу, когда в MinIO появятся картинки
      (после старта Go-канбана). Сейчас стек поднимается, но ресайзить нечего.
  [ ] Подпись imgproxy-URL генерит Go/Symfony (тот, кто отдаёт <img src>).


================================================================================
14. МАРШРУТИЗАЦИЯ — РЕШЕНО
================================================================================
КЛЮЧЕВОЙ ФАКТ: у React СВОЙ nginx (фронт за ним). Он и есть точка
маршрутизации, а НЕ nginx Symfony. Разделение трафика происходит на nginx
React, раньше, чем запрос дошёл бы до Symfony.

14.1 СХЕМА
--------------------------------------------------------------------------------
  браузер -> nginx(React) --/spa/api/kanban/*------> Go-канбан
                          --/spa/api/* (остальное)-> Symfony (PHP-FPM)
                          --/ (статика SPA)--------> React build

  => nginx Symfony (docker_env/nginx/config/default.conf) ТРОГАТЬ НЕ НАДО.
     Вся маршрутизация — в nginx React.

14.2 ПРЕФИКС И РОУТЫ
--------------------------------------------------------------------------------
Go получает НОВЫЙ префикс: /spa/api/kanban/...
  (сейчас канбан на /spa/api/projects, /spa/api/cards — при переезде на Go
   фронт переключается на /spa/api/kanban/...).

nginx СРЕЗАЕТ префикс (rewrite): /spa/api/kanban/cards -> Go видит /cards.
  => Go НЕ знает про внешний префикс, роуты чистые (/cards, /projects,
     /cards/{id}/comments, ...). Префикс можно сменить позже без правок Go.
  Пример nginx React:
     location /spa/api/kanban/ {
         rewrite ^/spa/api/kanban/(.*)$ /$1 break;
         proxy_pass http://kanban:8080;     # Go-контейнер в общей сети
         proxy_set_header Host $host;
         proxy_set_header Authorization $http_authorization;  # НЕ резать токен!
     }

ГРАНИЦА ЧЁТКАЯ (префикс /kanban снимает прежнюю проблему):
  /spa/api/kanban/*  -> Go
  /spa/api/*  (users, users/search, organizations, login_check, token/refresh,
              документы и пр.) -> Symfony

14.3 ВАЖНЫЕ СЛЕДСТВИЯ / РИСКИ
--------------------------------------------------------------------------------
[ ] React: все канбанные fetch'и -> базовый путь /spa/api/kanban/... (правка
    фронта — часть переезда).
[ ] Go-роуты строятся из раздела 10 БЕЗ префикса (nginx срежет): /projects,
    /cards, /cards/{cardId}/comments, /cards/{cardId}/attachments, и т.д.
[V] CORS НЕ нужен: всё под одним origin (nginx React отдаёт и фронт, и
    проксирует /spa/api/*). Появился бы только при отдельном поддомене.
[ ] Auth: тот же JWT (раздел 4) шлётся и на Symfony, и на Go. nginx React
    обязан ПРОБРАСЫВАТЬ заголовок Authorization (не резать).
[ ] Файлы (download/preview) идут через Go проксированием (12.1) -> nginx React
    проксирует и бинарные ответы: проверить proxy_buffering и лимиты размера
    (client_max_body_size / proxy_read_timeout) для крупных файлов.
[ ] Realtime (Mercure) идёт МИМО этой маршрутизации: фронт подписывается прямо
    на Mercure hub (MERCURE_PUBLIC_URL), Go публикует в hub напрямую. Префикс
    /spa/api/kanban — только для REST.
[ ] Go-сервис должен быть в общей docker-сети с nginx React (proxy_pass по
    имени контейнера, напр. http://kanban:8080).


================================================================================
15. МИГРАЦИЯ ДАННЫХ — РАЗОБРАНО
================================================================================
Две НЕЗАВИСИМЫЕ части: (А) таблицы БД, (Б) файлы с диска в MinIO.

15.1 ЧАСТЬ А — ТАБЛИЦЫ БД (Postgres Symfony -> Postgres Go)
--------------------------------------------------------------------------------
ОБЕ БД — PostgreSQL -> типы совместимы, перенос почти «как есть», без конвертации.

Переносимые таблицы (10 основных + 2 join):
  kanban_board, kanban_column, kanban_card, kanban_card_subtask,
  kanban_card_comment, kanban_card_activity, kanban_attachment, kanban_label,
  kanban_project, kanban_project_user
  kanban_card_label, kanban_card_assignee   (join ManyToMany)

FK на user_id (8 мест, см. 6.2):
  - user_id переносим КАК ЕСТЬ (id пользователей общие Symfony<->Go).
  - FK-constraint на таблицу users УБИРАЕМ (в БД Go её нет; либо вешаем на
    таблицу-реплику users, если она появится первой).

15.2 ЧАСТЬ Б — ФАЙЛЫ (диск /uploads/kanban -> MinIO)
--------------------------------------------------------------------------------
  - Сейчас: диск, PRIVATE_UPLOAD_KANBAN_DIR=/uploads/kanban,
    storage_key = "{cardId}/{hex}.{ext}".
  - В MinIO: те же объекты с ТЕМ ЖЕ ключом в бакете kanban.
  - storage_key в kanban_attachment НЕ меняется (он абстрактный, 8.2) ->
    путь остаётся валиден и для MinIO.
  - Инструмент: mc mirror /uploads/kanban  local/kanban

15.3 СТРАТЕГИЯ — ОДНОМОМЕНТНО, КОРОТКОЕ ОКНО (big bang, не двойная запись)
--------------------------------------------------------------------------------
Обоснование: внутренний документооборот (не 24/7), канбан обособлен -> короткий
даунтайм допустим. Двойная запись/онлайн-миграция = сложно (синхра в обе
стороны), выгода (0 даунтайма) не стоит сложности.

Порядок:
  1. Объявить окно (канбан недоступен N минут; ночь/выходной).
  2. Read-only / погасить запись в канбан Symfony.
  3. pg_dump --table='kanban_*' (структура + данные).
  4. БД Go: goose накатывает схему -> импорт данных (goose Go-миграция, 12.1).
  5. mc mirror /uploads/kanban -> local/kanban (файлы в MinIO).
  6. Проверка целостности (счётчики строк по таблицам, выборочные файлы).
  7. Переключить nginx React: /spa/api/kanban -> Go (раздел 14).
  8. Снять read-only -> канбан снова доступен, уже на Go.
  9. Symfony-канбан НЕ удалять сразу (откат); удалить позже (6.5).

15.4 РИСКИ / ЧЕКЛИСТ
--------------------------------------------------------------------------------
[ ] Порядок вставки из-за FK между канбан-таблицами
    (board->column->card->subtask/comment/activity/attachment; project->board;
    join — последними). Импорт по порядку или с отложенной проверкой constraints.
[ ] Sequences: после импорта выставить Postgres sequence на max(id) каждой
    таблицы, иначе новые INSERT конфликтнут по id.
[ ] Файлы-сироты (8.4): на диске возможны файлы без строки в БД (от cascade-
    удалений карточек). mc mirror перенесёт и их — почистить до/после.
[ ] storage_key -> правильный бакет (kanban); проверить, что imgproxy их видит.
[ ] Откат: Symfony-канбан рабочий до подтверждения Go; nginx можно вернуть.
[ ] Названия таблиц проекта (kanban_project / kanban_project_user) — уточнить
    точные имена из БД при написании дампа.
[ ] Проверить чистоту переноса ENUM/JSON-полей (цвета колонок/карточек,
    context вложений) — Postgres->Postgres обычно без проблем, но сверить.


================================================================================
16. JSON-КОНТРАКТ — СХЕМЫ ОТВЕТОВ (сняты из SPA-контроллеров)
================================================================================
Go обязан повторить эти форматы БАЙТ-В-БАЙТ (имена/типы полей), иначе React
сломается. Источник истины — методы format*() в src/Controller/SpaApi/Kanban/.

16.1 KanbanItem (КАРТОЧКА в списке колонки) — BoardController::formatColumn
--------------------------------------------------------------------------------
  {
    id: int, title: string, description: string|null,
    position: int, priority: string|null, dueDate: ISO8601|null,
    labels: [{ id:int, name:string, color:string }],
    assignees: [{ id:int, name:string, avatarUrl:string|null }],
    checklistTotal: int, checklistDone: int,
    commentsCount: int,   // = comments + attachments(context='chat')
    borderColor: string|null,
    updatedAt: ISO8601|null
  }

КОЛОНКА (formatColumn):
  { id:int, title:string, headerColor:string, position:int, cards:[KanbanItem...] }
  (архивированные карточки исключаются: isArchived() -> skip)

ДОСКА (formatBoard):
  { id:int, title:string, position:int, updatedAt:ISO8601|null }

АРХИВНАЯ КАРТОЧКА (formatArchivedCard):
  { id:int, title:string, description:string|null, columnTitle:string,
    borderColor:string|null, archivedAt:ISO8601|null,
    archivedBy: assignee|null }

ASSIGNEE (formatAssignee — общий вид пользователя в канбане):
  { id:int,
    name: "Lastname Firstname" (trim; фолбэк = (string)id если пусто),
    avatarUrl: string|null  // LiipThumbnail FILTER_THUMBNAIL -> в Go: imgproxy }

16.2 COMMENT / ЧАТ (CommentController::formatComment)
--------------------------------------------------------------------------------
  { id:int, body:string,
    authorName: "Lastname Firstname" (trim),   // SNAPSHOT-источник (6.3)
    authorId:int,
    createdAt: ISO8601, updatedAt: ISO8601|null }

16.3 SUBTASK (SubtaskController::formatSubtask)
--------------------------------------------------------------------------------
  { id:int, title:string, status:string, isCompleted:bool, position:int,
    userId:int|null, userName: "Lastname Firstname"|null }

16.4 ATTACHMENT (AttachmentController::formatAttachment)
--------------------------------------------------------------------------------
  { id:int, filename:string, contentType:string, sizeBytes:int,
    context:string,  // info|chat|description
    createdAt: ISO8601,
    previewUrl:string,   // LiipImagine kanban_attachment_preview -> в Go: imgproxy
    authorId:int|null, authorName: "Lastname Firstname"|null }

16.5 ВАЖНЫЕ НАБЛЮДЕНИЯ ДЛЯ GO
--------------------------------------------------------------------------------
[ ] ДАТЫ: в коде разнобой формата — formatColumn/board используют ATOM,
    comment/subtask/attachment используют 'c'. ОБА = ISO8601 (одинаковая
    строка). Go: везде отдавать ISO8601 (RFC3339).
[ ] name/authorName/userName: всюду "Lastname Firstname" (trim). Источник:
    - assignee.name / avatarUrl -> из реплики users (актуальное, 6.3)
    - comment.authorName, attachment.authorName -> SNAPSHOT (заморожено, 6.3)
[ ] avatarUrl и previewUrl сейчас строят генераторы на LiipImagine. В Go ->
    URL imgproxy (раздел 8.9). ФОРМАТ поля (string|null) сохранить.
[ ] enum-поля отдаются как .value (priority, headerColor, label.color,
    subtask.status). В Go — те же строковые значения (сверить словарь значений).
[ ] commentsCount = comments + attachments(context='chat') — повторить ровно
    эту формулу (есть и в realtime buildCommentsCount, 11.1).
[ ] Детальный show карточки (cards/{id}) — снять ОТДЕЛЬНО при реализации
    (там доп. поля: полный чеклист, список вложений, и т.п.). TODO.
[ ] Снять схемы запросов (POST/PATCH тела) при написании каждого эндпоинта —
    тут только ответы.
[ ] Открытый вопрос из 10.1: нет GET /spa/api/projects (список) — уточнить.


================================================================================
9. ОТКРЫТЫЕ ВОПРОСЫ (проговорить по очереди)
================================================================================
[V] БД: отдельная (РЕШЕНО, см. раздел 6).
[V] Как Go получает ФИО/аватар пользователя — РЕШЕНО: гибрид C+A (см. 6.3).
[V] Канал синхронизации реплики users — РЕШЕНО: RabbitMQ как общая шина
    событий проекта, users — пилотный кейс (см. 6.3.1). Осталось: поля реплики,
    аватары, soft-delete, schema сообщений.
[V] id vs login в токене — РЕШЕНО: listener добавляет claim `id` (вариант 1,
    см. 4.4). Статус: запланировано к реализации.
[V] Инвентаризация канбан-эндпоинтов — СДЕЛАНО (см. раздел 10). SPA-API = контракт
    для Go; SSR-API (17 роутов) под удаление. Осталось: JSON-схемы запросов/
    ответов, вопрос про список проектов, пограничный список юзеров.
[V] Инвентаризация канбан-сущностей Doctrine (сделано, см. 6.1).
[V] Хранилище файлов/вложений — РЕШЕНО ОКОНЧАТЕЛЬНО (см. раздел 8):
      - MinIO (S3), канбан как пилот для всего проекта (8.7)
      - одна таблица attachment + context как enum, НЕ разделять (8.8)
      - imgproxy как замена LiipImagine + ОБЯЗАТЕЛЬНЫЙ nginx-кэш перед ним;
        CDN опционально на вырост (8.9)
    Осталось: валидация типов (сейчас отключена!), перенос файлов, auth на download.
[V] Realtime — РЕШЕНО: остаёмся на Mercure, Go публикует HTTP POST в hub
    (см. раздел 11). Осталось: формат событий байт-в-байт, Mercure-JWT для Go.
[V] Технологии Go — РЕШЕНО (раздел 12): chi + sqlc/pgx (+ squirrel точечно) +
    golang-jwt/v5 + Mercure POST + minio-go + goose (миграции). Осталось:
    layout проекта.
[V] Маршрутизация — РЕШЕНО (раздел 14): nginx React (свой!) проксирует
    /spa/api/kanban/* -> Go (срезая префикс), остальное -> Symfony. CORS не
    нужен. nginx Symfony не трогаем. Осталось: правка fetch'ей в React,
    проброс Authorization, лимиты для файлов.
[ ] Деплой Go-сервиса: docker-образ, где живёт контейнер (общая сеть с nginx
    React), переменные окружения, CI.
[ ] Проверить inverse-side связи User -> Kanban в src/Entity/User/User.php
    (их надо будет вычистить при удалении канбана из Symfony, см. 6.5).
[ ] План отключения SSR (что и когда удаляем из Symfony, см. 6.5).


================================================================================
  ЛОГ ПРИНЯТЫХ РЕШЕНИЙ (заполняем по ходу)
================================================================================
- 2026-06-29: Согласована модель auth: Symfony = эмитент токенов, Go = только
  валидация RS256 по public.pem. (Деталь id-vs-login — открыта, см. 4.4.)
- 2026-06-29: РЕШЕНО — у микросервиса своя БД; канбан-таблицы вырезаются из
  Symfony и переносятся в Go. Анализ связей показал: единственная внешняя
  граница разреза = User (8 ссылок); за остальной домен Symfony канбан не
  цепляется. См. раздел 6.
- 2026-06-29: РЕШЕНО — хранение пользователей в Go = гибрид C+A: user_id везде +
  реплика users (актуальное: assignee/участники) + snapshot имени для чата и
  истории. Канал синхронизации реплики — кандидат RabbitMQ, окончательно ещё
  не выбран (есть Messenger c amqp-примером в .env, есть Mercure). См. 6.3 / 6.3.1.
- 2026-06-29: РЕШЕНО (4.4) — добавить claim `id` в JWT через listener на
  JWTCreatedEvent (вариант 1). claim `username` (=login) сохраняется, изменение
  обратно совместимо с React. Проверено: сейчас в токене id НЕТ, только login
  в claim `username` (Lexik v3.2.0, user_id_claim=дефолт 'username', кастомизаций
  payload нет). Статус: запланировано к реализации.
- 2026-06-29: РАЗОБРАНЫ файлы/вложения (раздел 8). Ключевое: единая таблица
  kanban_attachment с полем context ('info'/'chat'/'description'); чат-вложения
  висят на карточке, у комментариев своих файлов нет; хранение — простой диск
  (storage_key), НЕ VichUploader; превью через LiipImagine — единственная
  зависимость для переноса. ВАЖНО: валидация типов файлов сейчас ОТКЛЮЧЕНА;
  при cascade-удалении карточки файлы остаются сиротами на диске. Решения по
  хранилищу (диск/S3) и превью — открыты.
- 2026-06-29: СТРАТЕГИЯ ХРАНИЛИЩА (разделы 8.7-8.9):
  * MinIO (S3) как единое хранилище; канбан — пилот, остальной проект (7 Vich
    mappings) мигрирует постепенно mapping-за-mapping'ом. (направление выбрано)
  * Таблицу вложений НЕ разделять — оставить одну с полем context (сделать enum).
  * imgproxy как общепроектная замена LiipImagine (4 filter_set), ставится в
    паре с MinIO; канбан первый потребитель; нужен кэш/CDN перед imgproxy.
  Статус: направления согласованы, требуют финального подтверждения.
- 2026-06-29: ПОДТВЕРЖДЕНО ОКОНЧАТЕЛЬНО — три направления по файлам приняты:
  (1) MinIO (S3) единое хранилище, канбан-пилот; (2) одна таблица attachment +
  context как enum (не разделять); (3) imgproxy как замена LiipImagine +
  ОБЯЗАТЕЛЬНЫЙ nginx-кэш перед ним (CDN опционально, на вырост — для внутр.
  документооборота избыточен). Эти решения финальные.
- 2026-06-29: ИНВЕНТАРИЗАЦИЯ ЭНДПОИНТОВ (раздел 10). Два параллельных API:
  SSR (Controller/Kanban/Api/* + ProjectKanbanController, 17 роутов, под удаление)
  и SPA (Controller/SpaApi/Kanban/*) — SPA = контракт для Go. Полный список
  SPA-роутов выписан (projects, members, boards, columns, cards, labels,
  subtasks, comments/чат, attachments, activities). Выявлено: список юзеров
  для assignee/members берётся из ОБЩИХ /spa/api/users* (не канбан -> остаётся
  в Symfony); нет GET-списка проектов (вопрос). Осталось снять JSON-схемы.
- 2026-06-29: РЕШЕНО (раздел 11) — realtime остаётся на Mercure, НЕ WebSocket
  в Go. Причина: текущая модель = server->client broadcast в топик
  /kanban/board/{boardId} (события card_created/updated/deleted + senderId),
  ровно профиль Mercure; Mercure уже общий для проекта (чат, документы); фронт
  слушает по SSE и не меняется. Go публикует обычным HTTP POST в hub с
  Mercure-JWT, остаётся stateless. WS в Go отложен (нужен только для presence/
  совместного редактирования, которых сейчас нет). Учесть: формат событий
  байт-в-байт (KanbanItem), senderId из claim id, avatarUrl -> imgproxy/реплика,
  MERCURE_JWT_SECRET (симметричный, не путать с RS256 auth).
- 2026-06-29: РЕШЁН СТЕК GO (раздел 12): chi (HTTP) + sqlc/pgx как основной слой
  БД + squirrel точечно для динамических запросов; PostgreSQL 16; JWT
  golang-jwt/v5 (RS256, только валидация); Mercure через net/http POST; MinIO
  (minio-go/AWS SDK v2); RabbitMQ amqp091-go если подтвердим канал; ресайз —
  imgproxy, не в Go. Go 1.23+ (есть наработка 11_go_grpc). Отвергнуты: Gin/Echo/
  Fiber, GORM, чистый pgx-вручную, squirrel-как-основной. Открыто: S3-клиент,
  layout проекта.
- 2026-06-29: РЕШЕНО — миграции goose (pressly/goose). Причины: знаком,
  встраивается в сервис (goose.Up при старте), SQL ложится на sqlc, умеет
  Go-миграции для разового переноса данных (раздел 6.4).
- 2026-06-29: РЕШЕНО — S3-клиент minio-go (родной для MinIO, простой API, легче
  AWS SDK v2). Отдача файлов клиенту — ПРОКСИРОВАНИЕ через Go (JWT + права доски
  -> чтение из MinIO -> отдача), сохраняет текущую модель BinaryFileResponse.
  Presigned URL не основной путь (точечно для превью при необходимости).
- 2026-06-29: РЕШЕНО — канал синхронизации реплики users = RabbitMQ. ВХОДНЫЕ:
  пользователи меняются редко (сама задача брокера не требует), НО будут ещё
  микросервисы. Поэтому RabbitMQ строим как ОБЩУЮ событийную шину проекта, а
  users — пилотный/низкорисковый кейс (логика «канбан = полигон инфраструктуры»,
  как MinIO/imgproxy). Заложить сразу: topic exchange (user.upserted/deleted),
  очередь на сервис, DLQ, идемпотентность (ON CONFLICT), контракт+версия
  сообщений, грациозная деградация. Стек: Symfony amqp-messenger + ext amqp
  (ставим) + Doctrine listener на User; Go amqp091-go; команда bulk-импорта.
  Факт: сейчас Messenger на doctrine://, RabbitMQ не поднят — новая инфра.
- 2026-06-29: РЕАЛИЗОВАНА ИНФРАСТРУКТУРА (раздел 13). RabbitMQ добавлен в
  основной docker-compose.yml (rabbitmq:4-management). Файловое хранилище
  (MinIO + imgproxy + nginx-кэш) вынесено в папку file_storage/ единым стеком
  (docker_env/imgproxy/nginx-cache.conf). Версии образов ЗАКРЕПЛЕНЫ (не latest):
  minio RELEASE.2025-09-07T16-13-09Z, mc та же, imgproxy v4.0.8, nginx
  1.27-alpine. Сети: общая external project-net; MinIO API/imgproxy/RabbitMQ —
  внутренние, MinIO Console — localhost, nginx-кэш :8082 — публичная дверь.
  compose валиден (config --quiet). Открыто: .env креды, подпись imgproxy в
  проде, версия mercure.
- 2026-06-29: СНЯТ JSON-КОНТРАКТ ответов (раздел 16) из format*()-методов SPA:
  KanbanItem (карточка), колонка, доска, архивная карточка, assignee, comment,
  subtask, attachment. Go повторяет байт-в-байт. Наблюдения: даты ISO8601
  (в коде разнобой ATOM/'c', но значение одно); name = "Lastname Firstname";
  avatarUrl/previewUrl -> imgproxy; enum как .value; commentsCount = comments +
  chat-вложения. Осталось снять: детальный show карточки (cards/{id}) и тела
  POST/PATCH-запросов.
- 2026-06-29: РАЗОБРАНА МИГРАЦИЯ ДАННЫХ (раздел 15). Две части: (А) таблицы
  Postgres->Postgres (12 таблиц вкл. join; user_id как есть, FK на users убрать),
  (Б) файлы /uploads/kanban -> MinIO (mc mirror, storage_key не меняется).
  Стратегия: одномоментно, короткое окно read-only (big bang, НЕ двойная
  запись — для внутр. инструмента сложность не оправдана). Импорт через
  goose Go-миграцию. Учесть: порядок FK, sequences на max(id), файлы-сироты,
  откат (Symfony-канбан жив до подтверждения).
- 2026-06-29: РЕШЕНА МАРШРУТИЗАЦИЯ (раздел 14). У React СВОЙ nginx — он точка
  маршрутизации (не nginx Symfony). Префикс /spa/api/kanban/* -> Go (nginx
  СРЕЗАЕТ префикс, Go видит чистые /cards, /projects), остальное /spa/api/* ->
  Symfony. CORS не нужен (один origin). Auth: тот же JWT, nginx пробрасывает
  Authorization. Realtime (Mercure) идёт мимо — фронт подписан прямо на hub.
  Осталось: правка fetch'ей React, лимиты nginx для файлов, Go в общей сети.
- 2026-06-29: file_storage/ сделана АВТОНОМНОЙ (под переезд в отдельный репо):
  свой .env/.env.example/.gitignore/README.md, env_file в сервисах, сеть
  параметризована (SHARED_NETWORK, external). nginx оформлен как в проекте
  (Dockerfile FROM nginx:1.27.0-alpine-slim + config/). minio-init УБРАН —
  bucket создаётся вручную один раз (инструкция в README, шаг 4). Валидно в
  обоих режимах запуска (материнский + автономный из папки). При переезде
  поправить data-пути ./../../ -> ./.
