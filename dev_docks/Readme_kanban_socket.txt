==============================================================================
 KANBAN REALTIME (Mercure) — документация
==============================================================================
Дата: 2026-06-26
Репозитории:
  - Бэкенд (Symfony):  symfony_documents_flow
  - Фронтенд (Next.js): analytics_platform

Назначение: realtime-синхронизация канбан-доски между всеми пользователями,
которые её открыли. Когда один пользователь меняет карточку (создаёт, двигает,
удаляет, переименовывает, меняет цвет/дату/приоритет/исполнителей/теги, добавляет
подзадачу или комментарий) — изменение применяется у остальных без перезагрузки.


==============================================================================
1. ТЕХНОЛОГИЯ — Mercure, а не WebSocket
==============================================================================
Используется Mercure (протокол поверх HTTP + SSE), а НЕ "голые" сокеты.

Три участника:

    ┌──────────────┐  publish (POST)  ┌──────────────┐   SSE-поток   ┌──────────────┐
    │  Symfony     │ ───────────────▶ │  Mercure Hub │ ────────────▶ │  Браузер     │
    │ (Publisher)  │                  │ dunglas/     │               │ (Subscriber) │
    │              │                  │ mercure (Go) │               │ EventSource  │
    └──────────────┘                  └──────────────┘               └──────────────┘

  - Publisher  — Symfony. Только публикует событие в Hub и сразу освобождается,
                 живое соединение НЕ держит.
  - Hub        — отдельный контейнер dunglas/mercure (Caddy + Go). Держит все
                 живые соединения с браузерами и рассылает события.
  - Subscriber — браузер через EventSource (одно долгоживущее HTTP-соединение).

Ключевая идея: Hub — посредник (брокер). Symfony и браузер напрямую не общаются,
их связывает только строка-ТОПИК, например "/kanban/board/42".

Почему Mercure (а не WebSocket):
  - Канбан — это broadcast-уведомления (сервер → клиент). Двусторонний дуплекс
    не нужен: действия пользователя идут обычным REST-ом, Mercure только оповещает.
  - Авто-reconnect встроен в EventSource.
  - Работает по обычному HTTPS, дружит с Symfony (есть официальный bundle).
  - Не держит долгие соединения на PHP-воркерах.


==============================================================================
2. КОНФИГУРАЦИЯ
==============================================================================
Бэкенд (.env):
    MERCURE_URL=http://mercure/.well-known/mercure          (куда Symfony публикует)
    MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure (откуда читает браузер)
    MERCURE_JWT_SECRET=...                                  (ключ подписи publisher/subscriber)
    MERCURE_CORS_ORIGINS=http://localhost:3001

Хаб (docker-compose.yml, сервис "mercure"):
    image: dunglas/mercure
    MERCURE_PUBLISHER_JWT_KEY / MERCURE_SUBSCRIBER_JWT_KEY = ${MERCURE_JWT_SECRET}
    cors_origins ...
    порт: ${MERCURE_PORT:-3000}:80

Фронтенд:
    NEXT_PUBLIC_MERCURE_URL — публичный URL хаба (читается в resolveMercureUrl()).
    Пустая строка / "undefined" / "null" => realtime выключен, хук не подписывается.

ВАЖНО для прода:
  - Хаб должен отдаваться по HTTPS + HTTP/2, иначе браузер режет SSE и упирается
    в лимит ~6 соединений на домен (HTTP/1.1).
  - EventSource не умеет слать заголовки — подписка идёт по публичному URL хаба.
    Сейчас чтение анонимное (хаб с директивой anonymous) — любой может слушать
    топик доски. Для внутренней системы приемлемо, но это сознательный компромисс.


==============================================================================
3. ТОПИКИ
==============================================================================
  /kanban/board/{boardId}          — все события карточек этой доски
                                     (создание / обновление / удаление / перемещение)
  /kanban/card/{cardId}/comments   — realtime чата открытой карточки
                                     (СЕЙЧАС ОТКЛЮЧЁН — см. раздел 8)

Топик — это просто строка-идентификатор канала. Браузер получает только то, на
чьи топики он подписан. Подписка предшествует событию: Hub историю не хранит,
поэтому при открытии доски сначала грузится актуальное состояние по REST, а
Mercure ловит только дальнейшие изменения.


==============================================================================
4. ЧТО БЫЛО ДО ИЗМЕНЕНИЙ
==============================================================================
Работали только 3 события в топике доски:
    card_created   — создание карточки
    card_moved     — перемещение карточки (позиция + смена колонки)
    card_deleted   — удаление карточки

Изменения отдельных полей карточки (название, цвет, приоритет, дата, исполнители,
теги) и счётчики (подзадачи, комментарии) по сокету НЕ передавались — у других
пользователей они появлялись только после перезагрузки доски.

Чат-комментарии realtime были реализованы (хук + метод публикации), но
ЗАКОММЕНТИРОВАНЫ; чат работал на пуллинге раз в минуту.


==============================================================================
5. ЧТО СДЕЛАНО (ИТОГ)
==============================================================================
Введён ЕДИНЫЙ тип события "card_updated" — частичный патч карточки. Он покрывает
все изменения полей карточки в списке доски и ПОГЛОЩАЕТ перемещение.

  card_moved УДАЛЁН полностью — слит в card_updated.
  card_created / card_deleted оставлены без изменений (их сливать нельзя:
  создание/удаление — это не патч существующей карточки).

Принцип "частичного патча":
  - В payload кладутся ТОЛЬКО реально изменённые поля + всегда id + senderId.
  - На фронте boardCardPatched делает мёрж { ...старое, ...пришедшее } —
    неуказанные поля остаются без изменений.
  - Никаких лишних запросов: при изменении описания/названия счётчики НЕ
    пересчитываются и не шлются; счётчики считаются только в тех событиях,
    где они реально меняются (подзадачи / комментарии).

Что НЕ передаётся через Mercure (намеренно):
  - description — не отображается на карточке списка, правится в модалке.
  - Детальные данные модалки (сами subtasks[], сами comments[]) — при открытии
    карточки делается AJAX за деталями, дублировать их по сокету не нужно.
  - В доску от подзадач/чата идут ТОЛЬКО счётчики (checklistTotal/Done,
    commentsCount), а не массивы.


==============================================================================
6. ФОРМАТ СОБЫТИЙ
==============================================================================
Общий конверт (топик /kanban/board/{boardId}):

  card_updated (частичный патч):
    {
      "type": "card_updated",
      "card": { "id": 123, <только изменённые поля KanbanItem> },
      "senderId": 7
    }

  card_created (полный KanbanItem):
    {
      "type": "card_created",
      "card": { ...полный KanbanItem с дефолтами... },
      "senderId": 7
    }

  card_deleted:
    {
      "type": "card_deleted",
      "cardId": 123,
      "senderId": 7
    }

senderId — id автора события. Фронт сравнивает с текущим пользователем и
ИГНОРИРУЕТ своё же эхо (локально действие уже применено оптимистично).

Формат полей карточки СТРОГО совпадает с BoardController::formatColumn (то есть
с тем, что отдаёт endpoint доски GET /spa/api/projects/{id}/boards/{boardId}):
  id, title, description, position, priority (->value), dueDate (ATOM),
  labels [{id,name,color}], assignees [{id,name,avatarUrl}],
  checklistTotal, checklistDone,
  commentsCount (= комментарии + вложения context='chat'),
  borderColor, updatedAt (ATOM), status (= id колонки строкой).

ВАЖНЫЕ ДЕТАЛИ ФОРМАТА:
  - dueDate / updatedAt — формат ATOM (\DateTimeInterface::ATOM), НЕ 'c'.
    (в JSON-ответах контроллеров встречается 'c', но для Mercure — строго ATOM,
     чтобы совпадало с форматом доски.)
  - priority — это ->value (строка), без priorityLabel/priorityColor.
  - commentsCount ОБЯЗАТЕЛЬНО включает chat-вложения, иначе счётчик "разъедется"
    с тем, что отдаёт доска.


==============================================================================
7. ПЕРЕЧЕНЬ ТОЧЕК ПУБЛИКАЦИИ (БЭКЕНД)
==============================================================================
Все события — card_updated в топик /kanban/board/{boardId}, частичный payload.

 №  Endpoint                                              Поля в payload
 -- ----------------------------------------------------- --------------------------------
 1  PATCH /spa/api/cards/{id}                             только изменённые из:
    (CardController::update)                              title, priority, dueDate,
                                                          borderColor (+ updatedAt)
                                                          description НЕ шлём.
                                                          Используются уже существующие
                                                          флаги *Changed.

 2  POST /spa/api/cards/{id}/move                         position (+ updatedAt);
    (CardController::move)                                при смене колонки также
                                                          columnId, columnTitle, status.
                                                          publishCardMoved УДАЛЁН.

 3  PUT /spa/api/cards/{id}/assignees                     assignees (+ updatedAt)
    (CardController::setAssignees)

 4  POST/PATCH/DELETE /spa/api/cards/{cardId}/subtasks    checklistTotal, checklistDone
    (SubtaskController create/update/delete)              (+ updatedAt).
                                                          На update — только если изменился
                                                          isCompleted (смена названия/
                                                          исполнителя счётчики не меняет).

 5  POST .../labels/cards/{cardId}/{labelId}              labels (+ updatedAt)
    (LabelController::toggleLabel)                        create/delete самого лейбла
                                                          НЕ входят (это справочник доски,
                                                          а не поле карточки).

 6  POST/DELETE /spa/api/cards/{cardId}/comments          commentsCount (+ updatedAt).
    (CommentController create/delete)                     update комментария счётчик
                                                          не меняет — не публикуем.

 7  POST/DELETE /spa/api/cards/{cardId}/attachments       commentsCount (+ updatedAt),
    (AttachmentController upload/delete)                  ТОЛЬКО для context='chat'
                                                          (chat-вложения входят в
                                                          commentsCount карточки списка).


==============================================================================
8. АРХИТЕКТУРА БЭКЕНДА
==============================================================================
Новый сервис (единая точка публикации и единый формат полей):

  src/Service/Kanban/KanbanRealtimePublisher.php

  Зачем: чтобы все 6+ точек публикации слали ИДЕНТИЧНЫЙ формат, совпадающий с
  formatColumn, без дублирования кода по контроллерам.

  Зависимости (autowired):
    - Symfony\Component\Mercure\HubInterface
    - App\Service\User\UserAvatarUrlGenerator  (для avatarUrl исполнителей)

  Публичные методы:
    publishCardUpdated(int $boardId, array $card, int $senderId): void
        низкоуровневая публикация card_updated.

    publishCardPatch(KanbanCard $card, array $partial, int $senderId): void
        удобная обёртка: сама достаёт boardId из карточки, добавляет id и
        свежий updatedAt (ATOM). Основной метод, используемый контроллерами.

    publishCardCreated(int $boardId, array $card, int $senderId): void
    publishCardDeleted(int $boardId, int $cardId, int $senderId): void
        перенесены из CardController, формат не менялся.

    buildChecklistCounters(KanbanCard $card): array
        => ['checklistTotal' => N, 'checklistDone' => M]

    buildCommentsCount(KanbanCard $card): array
        => ['commentsCount' => comments.count + chat-attachments.count]

    buildLabels(KanbanCard $card): array
        => ['labels' => [{id,name,color}, ...]]

    buildAssignees(KanbanCard $card): array
        => ['assignees' => [{id,name,avatarUrl}, ...]]

    formatAssignee(User $user): array        — {id,name,avatarUrl}
    formatDate(?\DateTimeInterface): ?string — ATOM или null

Контроллеры, в которые внедрён сервис (через конструктор, autowired):
    CardController        (раньше держал HubInterface напрямую — убран,
                          publishCardMoved/Created/Deleted перенесены в сервис)
    SubtaskController
    LabelController
    CommentController     (HubInterface ОСТАВЛЕН — он для чат-топика,
                          см. раздел 9, это отдельная история)
    AttachmentController

ТАЙМИНГ ПУБЛИКАЦИИ:
  updatedAt у карточки управляется Gedmo Timestampable и проставляется на flush().
  Поэтому во ВСЕХ точках публикация идёт ПОСЛЕ $em->flush() (или после
  service->flush()), чтобы updatedAt в payload был свежим.

КОРРЕКТНОСТЬ СЧЁТЧИКОВ ПРИ УДАЛЕНИИ (важный нюанс):
  $em->remove($entity) удаляет запись из БД, но НЕ убирает её из in-memory
  коллекции карточки ($card->getSubtasks()/getComments()/getAttachments()).
  Поэтому перед подсчётом счётчика выполняется явное снятие с коллекции:
      $card->getSubtasks()->removeElement($subtask);     // SubtaskController::delete
      $card->getComments()->removeElement($comment);     // CommentController::delete
      $card->getAttachments()->removeElement($attachment); // AttachmentController::delete
  Связи имеют orphanRemoval: true, поэтому запись из БД всё равно удаляется.
  Без этого счётчик в payload был бы на единицу больше реального.


==============================================================================
9. АРХИТЕКТУРА ФРОНТЕНДА
==============================================================================
Хуки realtime (Next.js, analytics_platform):

  src/features/Projects/ProjectView/Kanban/hooks/
      useKanbanBoardRealtime.ts   — подписка на топик доски
      useCardCommentsRealtime.ts  — подписка на топик чата карточки (ОТКЛЮЧЁН)
      mercureUrl.ts               — resolveMercureUrl() из NEXT_PUBLIC_MERCURE_URL

  ВАЖНО (исправлено в этой задаче):
    Раньше эти 3 файла лежали в .../ProjectItem/Kanban/hooks/, а импортировались
    из .../ProjectView/Kanban/hooks/ — импорт был СЛОМАН (TS: "Cannot find module").
    Файлы перемещены в ProjectView/Kanban/hooks/, папка ProjectItem удалена.

useKanbanBoardRealtime(boardId):
  - Подключён в KanbanBoard.tsx: useKanbanBoardRealtime(boardId).
  - Открывает один EventSource на топик /kanban/board/{boardId}, живёт всё время,
    пока открыта доска. Закрывается в cleanup useEffect (source.close()).
  - Игнорирует своё эхо: if (data.senderId === currentUserId) return.
  - Обрабатываемые события:
        card_updated  -> dispatch(boardCardPatched({ card: data.card }))
        card_created  -> dispatch(boardItemAdded(data.card))
        card_deleted  -> dispatch(boardItemRemoved(data.cardId))
  - Ветка card_moved УДАЛЕНА (её логика теперь в card_updated; перемещение
    приходит как частичный патч с position и при смене колонки columnId/
    columnTitle/status).

Redux: boardCardPatched (src/redux/projects/projectsSlice.ts)
  - Делает частичный мёрж: board.items[i] = { ...board.items[i], ...card }.
  - Поддерживает перенос между колонками (через columnId/columnTitle/status).
  - Для labels/assignees мёрж заменяет массив целиком (это корректно для патча).
  - Если карточка открыта в модалке (cardView.data.id === card.id) — патч также
    применяется к cardView.data.

Типы события (useKanbanBoardRealtime.ts):
    interface CardUpdatedEvent {
      type: 'card_updated';
      card: Partial<KanbanItem> & { id: number; columnId?: number; columnTitle?: string };
      senderId: number;
    }
    interface CardCreatedEvent  { type: 'card_created';  card: KanbanItem; senderId: number; }
    interface CardDeletedEvent  { type: 'card_deleted';  cardId: number;   senderId: number; }


==============================================================================
10. ПОТОК ДАННЫХ (ПРИМЕР: пользователь A меняет цвет карточки)
==============================================================================
  1. Браузер A: PATCH /spa/api/cards/123  { borderColor: "danger" }
  2. Symfony (CardController::update):
       - сохраняет, flush()
       - colorChanged === true
       - realtimePublisher->publishCardPatch($card, ['borderColor'=>'danger'], A.id)
         => POST в Hub, топик /kanban/board/42,
            { type:'card_updated', card:{id:123, borderColor:'danger', updatedAt:...},
              senderId: A.id }
       - возвращает JSON-ответ автору
  3. Hub рассылает событие всем, кто подписан на /kanban/board/42.
  4. Браузер B (useKanbanBoardRealtime): onmessage
       - data.senderId (A) !== currentUserId (B) => применяем
       - dispatch(boardCardPatched({ card:{id:123, borderColor:'danger', ...} }))
       - Redux мёржит только borderColor у карточки 123
       - React перерисовывает ТОЛЬКО эту карточку (вся доска не перестраивается)
  5. Браузер A: получает то же событие, но senderId === currentUserId => игнор
       (у A цвет уже применён оптимистично из ответа REST).

СЕТЬ vs UI:
  - Событие ДОСТАВЛЯЕТСЯ всем подписчикам доски (так работает топик),
    но НЕСЁТ и ПРИМЕНЯЕТ изменение только одной карточки.
  - Ни по сети, ни в UI вся доска не перегружается.


==============================================================================
11. ОЦЕНКА НАГРУЗКИ
==============================================================================
Метрика: исходящих доставок/сек = частота событий × число подписчиков топика.
Размер payload вторичен (события мелкие, частичный патч).

dunglas/mercure (Go) держит:
  - 10k–20k одновременных SSE-соединений на скромном сервере (2–4 vCPU),
    40k–100k+ на хорошем;
  - десятки тысяч доставок/сек.

Для внутренней системы (сотни–тысячи сотрудников, события человеческого темпа,
на доску смотрят единицы-десятки человек) нагрузка на Hub — доли процента.
Реальным ограничителем станут БД и PHP-воркеры на REST-запросах, а не Mercure.

Где Mercure начал бы "болеть" (НЕ наш случай):
  - десятки тысяч подписчиков на ОДИН топик + частые события;
  - typing-индикаторы / presence / live-курсоры (генерят события каждые
    пару секунд на пользователя) — если их добавлять, это осознанная нагрузка;
  - горизонтальное масштабирование Hub (несколько инстансов) — требует
    Mercure HA (платная версия); до этого порога нам очень далеко.


==============================================================================
12. ЧТО НЕ ВХОДИЛО В ЗАДАЧУ / ОСТАЛОСЬ ОТКЛЮЧЁННЫМ
==============================================================================
ЧАТ-REALTIME (топик /kanban/card/{cardId}/comments) — ОТКЛЮЧЁН:
  Бэкенд: CommentController::publishCommentEvent() существует, но все его вызовы
          в create/update/delete ЗАКОММЕНТИРОВАНЫ (HubInterface в контроллере
          оставлен специально для быстрого возврата).
  Фронт:  useCardCommentsRealtime.ts полностью написан, но его импорт и вызов
          в EditDialog/index.tsx ЗАКОММЕНТИРОВАНЫ. Чат работает на пуллинге
          (Comments/index.tsx, COMMENT_POLL_INTERVAL_MS = 60_000, раз в минуту).

  В рамках ЭТОЙ задачи в доску добавлен только СЧЁТЧИК commentsCount
  (через card_updated) — сам realtime чата не включался.

  Как вернуть чат-realtime (на будущее):
    1. Раскомментировать вызовы publishCommentEvent в CommentController
       (create/update/delete).
    2. Раскомментировать import и вызов useCardCommentsRealtime(cardId)
       в EditDialog/index.tsx.
    3. (опц.) убрать setInterval-пуллинг в Comments/index.tsx.
  Особенность подписки чата: EventSource открывается ПРИ ОТКРЫТИИ карточки и
  закрывается при закрытии (хук зависит от cardId, cleanup = source.close()).
  Одновременно у пользователя открыт максимум один топик карточки.

Также НЕ передаётся через Mercure:
  - description карточки;
  - детальные subtasks[] / comments[] (грузятся AJAX при открытии модалки);
  - create/delete самого лейбла в справочнике доски (не поле карточки).


==============================================================================
13. ИЗМЕНЁННЫЕ / СОЗДАННЫЕ ФАЙЛЫ
==============================================================================
Бэкенд (symfony_documents_flow):
  NEW  src/Service/Kanban/KanbanRealtimePublisher.php
  MOD  src/Controller/SpaApi/Kanban/CardController.php
         - внедрён KanbanRealtimePublisher, убран прямой HubInterface/Update
         - publishCardMoved УДАЛЁН, move публикует card_updated
         - update публикует card_updated по изменённым полям
         - setAssignees публикует assignees
         - create/delete используют сервис (формат не менялся)
  MOD  src/Controller/SpaApi/Kanban/SubtaskController.php (счётчики checklist)
  MOD  src/Controller/SpaApi/Kanban/LabelController.php   (labels на toggle)
  MOD  src/Controller/SpaApi/Kanban/CommentController.php (commentsCount; чат-топик
                                                          остаётся закомментирован)
  MOD  src/Controller/SpaApi/Kanban/AttachmentController.php (commentsCount, context=chat)

Фронтенд (analytics_platform):
  MOD  src/features/Projects/ProjectView/Kanban/hooks/useKanbanBoardRealtime.ts
         - card_moved -> card_updated, типы обновлены
  MOVE src/features/Projects/ProjectItem/Kanban/hooks/* ->
       src/features/Projects/ProjectView/Kanban/hooks/*
         (useKanbanBoardRealtime.ts, useCardCommentsRealtime.ts, mercureUrl.ts)
         папка ProjectItem удалена (фикс сломанного импорта)


==============================================================================
14. ПРОВЕРКИ, КОТОРЫЕ ПРОЙДЕНЫ
==============================================================================
  - php -l по всем изменённым PHP-файлам — без ошибок.
  - php bin/console lint:container — OK (новый сервис и все инъекции валидны).
  - Фронт: tsc по realtime-хуку — без ошибок; eslint useKanbanBoardRealtime.ts — OK.
  - Нет остаточных ссылок на publishCardMoved / card_moved (кроме поясняющего
    комментария в хуке).

  Примечание: пре-существующие TS-ошибки "implicit any" на col/index в
  KanbanBoard.tsx (sortedColumns: any[]) к этой задаче НЕ относятся
  (файл realtime-логики не редактировался по этим строкам).


==============================================================================
15. ПАМЯТКА ПО РАСШИРЕНИЮ (как добавить новое поле в realtime)
==============================================================================
  1. Бэкенд: в нужном методе контроллера ПОСЛЕ flush() вызвать
       $this->realtimePublisher->publishCardPatch($card, ['поле' => значение], $user->getId());
     Формат поля — строго как в BoardController::formatColumn (даты ATOM и т.д.).
     Для labels/assignees/счётчиков использовать готовые build*-методы сервиса.
  2. Фронт: ничего не нужно, если поле уже есть в KanbanItem —
     boardCardPatched применит его автоматически (частичный мёрж).
     Если поле новое — добавить в тип KanbanItem.
  3. Слать ТОЛЬКО изменённые поля (частичный патч), не всю карточку —
     это экономит запросы и не перетирает чужие параллельные изменения.
==============================================================================
