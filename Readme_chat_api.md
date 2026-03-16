# Chat API Documentation

Документация API корпоративного чата для интеграции с мобильным приложением (React Native).

## Базовый URL

```
https://<domain>/api/chat
```

## Аутентификация

Сейчас используется сессионная авторизация Symfony. Для мобильного приложения необходимо настроить JWT-аутентификацию.

**Заголовок авторизации (будущий):**
```
Authorization: Bearer <jwt_token>
```

**Общие заголовки для всех запросов:**
```
X-Requested-With: XMLHttpRequest
```

---

## Типы комнат

| Значение     | Описание                          |
|--------------|-----------------------------------|
| `private`    | Приватный чат (2 участника)       |
| `group`      | Групповой чат (создаёт ROLE_MANAGER) |
| `department` | Отделовой чат (создаёт ROLE_MANAGER) |

---

## Модели данных

### Room (в списке комнат)

```json
{
  "id": 1,
  "type": "private",
  "name": "Иванов Иван",
  "last_message": "Привет! Как дела?",
  "last_message_at": "2026-03-16 12:30:00",
  "last_message_sender": "Иванов Иван",
  "unread_count": 3,
  "other_user_id": 5,
  "other_user_avatar": "/media/cache/avatar_medium/5/avatar.jpg"
}
```

| Поле                  | Тип      | Описание                                                     |
|-----------------------|----------|--------------------------------------------------------------|
| `id`                  | int      | ID комнаты                                                   |
| `type`                | string   | Тип: `private`, `group`, `department`                        |
| `name`                | string   | Название (для `private` — ФИО собеседника, для остальных — название группы) |
| `last_message`        | string?  | Превью последнего сообщения (или `"Сообщение удалено"`)      |
| `last_message_at`     | string?  | Дата последнего сообщения (ISO / datetime)                   |
| `last_message_sender` | string?  | ФИО отправителя последнего сообщения                         |
| `unread_count`        | int      | Количество непрочитанных сообщений                           |
| `other_user_id`       | int?     | ID собеседника (только для `private`)                        |
| `other_user_avatar`   | string?  | Аватарка собеседника, ресайз 200x200 (только для `private`)  |

### Message

```json
{
  "id": 42,
  "room_id": 1,
  "sender": {
    "id": 5,
    "lastname": "Иванов",
    "firstname": "Иван",
    "avatar": "/media/cache/avatar_medium/5/avatar.jpg"
  },
  "content": "Привет! Как дела?",
  "is_deleted": false,
  "is_read": true,
  "files": [
    {
      "id": 10,
      "title": "report.pdf",
      "path": "/uploads/chats/42/report.pdf"
    }
  ],
  "created_at": "2026-03-16T12:30:00+00:00",
  "updated_at": null
}
```

| Поле         | Тип      | Описание                                                        |
|--------------|----------|-----------------------------------------------------------------|
| `id`         | int      | ID сообщения                                                    |
| `room_id`    | int      | ID комнаты                                                      |
| `sender`     | object?  | Отправитель (id, lastname, firstname, avatar)                   |
| `content`    | string?  | Текст (`null` если удалено)                                     |
| `is_deleted` | bool     | Удалено ли сообщение (soft delete)                              |
| `is_read`    | bool     | Прочитано ли всеми участниками комнаты                          |
| `files`      | array    | Прикреплённые файлы (пустой массив если удалено)                |
| `created_at` | string   | Дата создания (ISO 8601)                                        |
| `updated_at` | string?  | Дата последнего редактирования (ISO 8601, `null` если не редактировалось) |

**Примечания:**
- Если `updated_at !== null` и `updated_at !== created_at` — сообщение было отредактировано, показывать пометку "изменено".
- `is_read` — `true` когда все участники комнаты прочитали сообщение. Используется для отображения галочек: одна галочка (✓) — отправлено, две синие галочки (✓✓) — прочитано.

### Sender

```json
{
  "id": 5,
  "lastname": "Иванов",
  "firstname": "Иван",
  "avatar": "/media/cache/avatar_medium/5/avatar.jpg"
}
```

| Поле        | Тип     | Описание                                      |
|-------------|---------|------------------------------------------------|
| `id`        | int     | ID пользователя                                |
| `lastname`  | string  | Фамилия                                        |
| `firstname` | string  | Имя                                             |
| `avatar`    | string? | Аватарка, ресайз 200x200 (`null` если нет)  |

### File

```json
{
  "id": 10,
  "title": "report.pdf",
  "path": "/uploads/chats/42/report.pdf"
}
```

| Поле    | Тип     | Описание                    |
|---------|---------|-----------------------------|
| `id`    | int     | ID файла                    |
| `title` | string  | Оригинальное имя файла      |
| `path`  | string? | Путь для скачивания          |

### Error

```json
{
  "error": "Описание ошибки"
}
```

---

## Эндпоинты

---

### 1. Получить список комнат

```
GET /api/chat/rooms
```

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Параметры:** нет

**Ответ `200 OK`:**
```json
[
  {
    "id": 1,
    "type": "private",
    "name": "Петров Сергей",
    "last_message": "Документ отправлен",
    "last_message_at": "2026-03-16 14:20:00",
    "last_message_sender": "Петров Сергей",
    "unread_count": 2
  },
  {
    "id": 3,
    "type": "group",
    "name": "Проект Альфа",
    "last_message": "Встреча в 15:00",
    "last_message_at": "2026-03-16 13:00:00",
    "last_message_sender": "Иванов Иван",
    "unread_count": 0
  }
]
```

**Сортировка:** по дате последнего сообщения (новые первые). Комнаты без сообщений — в конце.

---

### 2. Создать приватный чат

```
POST /api/chat/rooms/private
```

**Заголовки:**
```
Content-Type: application/json
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:**
```json
{
  "user_id": 15
}
```

| Поле      | Тип | Обязательное | Описание                 |
|-----------|-----|-------------|--------------------------|
| `user_id` | int | Да          | ID собеседника           |

**Ответ `201 Created`:**
```json
{
  "id": 7,
  "type": "private",
  "name": "Сидорова Анна"
}
```

**Ошибки:**
| Код | Описание                            |
|-----|-------------------------------------|
| 400 | `user_id is required`               |
| 400 | `Cannot create chat with yourself`  |
| 404 | `User not found`                    |

**Примечание:** если приватный чат между двумя пользователями уже существует, возвращается существующий (не создаётся дубликат).

---

### 3. Создать групповой чат

```
POST /api/chat/rooms/group
```

**Требуется роль:** `ROLE_MANAGER`

**Заголовки:**
```
Content-Type: application/json
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:**
```json
{
  "name": "Проект Бета",
  "user_ids": [5, 12, 18]
}
```

| Поле       | Тип      | Обязательное | Описание                      |
|------------|----------|-------------|-------------------------------|
| `name`     | string   | Да          | Название группы               |
| `user_ids` | int[]    | Да          | Массив ID участников          |

**Ответ `201 Created`:**
```json
{
  "id": 8,
  "type": "group",
  "name": "Проект Бета"
}
```

**Ошибки:**
| Код | Описание                            |
|-----|-------------------------------------|
| 400 | `name and user_ids are required`    |
| 400 | `No valid users found`              |
| 403 | Нет роли `ROLE_MANAGER`             |

**Примечание:** создатель автоматически добавляется как участник.

---

### 4. Создать отделовой чат

```
POST /api/chat/rooms/department
```

**Требуется роль:** `ROLE_MANAGER`

**Заголовки:**
```
Content-Type: application/json
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:**
```json
{
  "department_id": 3
}
```

| Поле            | Тип | Обязательное | Описание         |
|-----------------|-----|-------------|------------------|
| `department_id` | int | Да          | ID отдела        |

**Ответ `201 Created`:**
```json
{
  "id": 9,
  "type": "department",
  "name": "Бухгалтерия"
}
```

**Ошибки:**
| Код | Описание                            |
|-----|-------------------------------------|
| 400 | `department_id is required`         |
| 403 | Нет роли `ROLE_MANAGER`             |
| 404 | `Department not found`              |

**Примечание:** все сотрудники отдела автоматически добавляются как участники.

---

### 5. Получить детали комнаты

```
GET /api/chat/rooms/{roomId}
```

**Доступ:** только участник комнаты

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Ответ `200 OK`:**
```json
{
  "id": 3,
  "type": "group",
  "name": "Проект Альфа",
  "created_by": {
    "id": 1,
    "lastname": "Иванов",
    "firstname": "Иван"
  },
  "participants": [
    {
      "id": 1,
      "lastname": "Иванов",
      "firstname": "Иван",
      "avatar": "/media/cache/avatar_medium/1/avatar.jpg"
    },
    {
      "id": 5,
      "lastname": "Петров",
      "firstname": "Сергей",
      "avatar": null
    }
  ]
}
```

| Поле           | Тип      | Описание                                              |
|----------------|----------|-------------------------------------------------------|
| `id`           | int      | ID комнаты                                            |
| `type`         | string   | Тип: `private`, `group`, `department`                 |
| `name`         | string?  | Название комнаты                                      |
| `created_by`   | object?  | Создатель комнаты (id, lastname, firstname) или `null` |
| `participants` | array    | Список участников (id, lastname, firstname, avatar)   |

**Ошибки:**
| Код | Описание          |
|-----|-------------------|
| 403 | `Access denied`   |
| 404 | `Room not found`  |

**Использование:** для отображения панели участников в групповых/отделовых чатах. Поле `created_by` определяет кто может добавлять/удалять участников.

---

### 6. Добавить участника в комнату

```
POST /api/chat/rooms/{roomId}/participants
```

**Доступ:** только создатель комнаты (`createdBy`)

**Заголовки:**
```
Content-Type: application/json
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:**
```json
{
  "user_id": 20
}
```

**Ответ `200 OK`:**
```json
{
  "success": true
}
```

**Ошибки:**
| Код | Описание                                    |
|-----|---------------------------------------------|
| 403 | `Only room creator can add participants`    |
| 404 | `Room not found` / `User not found`         |

---

### 7. Удалить участника из комнаты

```
DELETE /api/chat/rooms/{roomId}/participants/{userId}
```

**Доступ:** только создатель комнаты (`createdBy`)

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Параметры URL:**
| Параметр  | Тип | Описание              |
|-----------|-----|-----------------------|
| `roomId`  | int | ID комнаты            |
| `userId`  | int | ID удаляемого участника |

**Ответ `200 OK`:**
```json
{
  "success": true
}
```

**Ошибки:**
| Код | Описание                                      |
|-----|-----------------------------------------------|
| 403 | `Only room creator can remove participants`   |
| 404 | `Room not found` / `User not found`           |

---

### 8. Получить сообщения комнаты

```
GET /api/chat/rooms/{roomId}/messages?before={messageId}
```

**Доступ:** только участник комнаты

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Query-параметры:**
| Параметр | Тип  | Обязательное | Описание                                      |
|----------|------|-------------|-----------------------------------------------|
| `before` | int  | Нет         | ID сообщения для cursor-пагинации (загрузить старше этого) |

**Пагинация:** cursor-based, по 30 сообщений за запрос. Если вернулось < 30, значит больше нет.

**Ответ `200 OK`:**
```json
[
  {
    "id": 100,
    "room_id": 1,
    "sender": {
      "id": 5,
      "lastname": "Иванов",
      "firstname": "Иван",
      "avatar": "/media/cache/avatar_medium/5/avatar.jpg"
    },
    "content": "Привет!",
    "is_deleted": false,
    "is_read": true,
    "files": [],
    "created_at": "2026-03-16T12:30:00+00:00",
    "updated_at": null
  },
  {
    "id": 95,
    "room_id": 1,
    "sender": {
      "id": 5,
      "lastname": "Иванов",
      "firstname": "Иван",
      "avatar": null
    },
    "content": null,
    "is_deleted": true,
    "is_read": false,
    "files": [],
    "created_at": "2026-03-16T12:25:00+00:00",
    "updated_at": null
  }
]
```

**Порядок:** от новых к старым (DESC по ID). Для отображения в UI — реверсировать массив.

**Удалённые сообщения:** возвращаются с `is_deleted: true`, `content: null`, `files: []`. Отображать как "Сообщение удалено".

**Ошибки:**
| Код | Описание          |
|-----|-------------------|
| 403 | `Access denied`   |
| 404 | `Room not found`  |

---

### 9. Отправить сообщение

```
POST /api/chat/rooms/{roomId}/messages
```

**Доступ:** только участник комнаты

#### Вариант A: Только текст (JSON)

**Заголовки:**
```
Content-Type: application/json
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:**
```json
{
  "content": "Привет! Отправляю документ."
}
```

#### Вариант B: Текст + файлы (multipart)

**Заголовки:**
```
Content-Type: multipart/form-data
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Поля формы:**
| Поле      | Тип      | Обязательное | Описание                                           |
|-----------|----------|-------------|-----------------------------------------------------|
| `content` | string   | Нет*        | Текст сообщения                                     |
| `files[]` | File[]   | Нет*        | Массив файлов                                       |

*Хотя бы одно из полей (`content` или `files[]`) должно быть заполнено.

**Допустимые типы файлов:** `.doc`, `.docx`, `.xls`, `.xlsx`, `.pdf`, `.png`, `.jpg`, `.jpeg`

**Ответ `201 Created`:**
```json
{
  "id": 101,
  "room_id": 1,
  "sender": {
    "id": 5,
    "lastname": "Иванов",
    "firstname": "Иван",
    "avatar": "/media/cache/avatar_medium/5/avatar.jpg"
  },
  "content": "Привет! Отправляю документ.",
  "is_deleted": false,
  "is_read": false,
  "files": [
    {
      "id": 15,
      "title": "report.pdf",
      "path": "/uploads/chats/101/report.pdf"
    }
  ],
  "created_at": "2026-03-16T14:00:00+00:00",
  "updated_at": null
}
```

**Ошибки:**
| Код | Описание                              |
|-----|---------------------------------------|
| 400 | `Message content or files required`   |
| 403 | `Access denied`                       |
| 404 | `Room not found`                      |

**Примечание:** сообщение автоматически отмечается как прочитанное для отправителя.

---

### 10. Редактировать сообщение

```
PUT /api/chat/messages/{messageId}
```

**Доступ:** только отправитель сообщения

**Заголовки:**
```
Content-Type: application/json
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:**
```json
{
  "content": "Исправленный текст"
}
```

| Поле      | Тип    | Обязательное | Описание              |
|-----------|--------|--------------|-----------------------|
| `content` | string | Да           | Новый текст сообщения |

**Ответ `200 OK`:**
```json
{
  "id": 101,
  "room_id": 1,
  "sender": {
    "id": 5,
    "lastname": "Иванов",
    "firstname": "Иван",
    "avatar": "/media/cache/avatar_medium/5/avatar.jpg"
  },
  "content": "Исправленный текст",
  "is_deleted": false,
  "is_read": true,
  "files": [],
  "created_at": "2026-03-16T14:00:00+00:00",
  "updated_at": "2026-03-16T14:05:00+00:00"
}
```

**Ошибки:**
| Код | Описание                                  |
|-----|-------------------------------------------|
| 400 | `Content is required`                     |
| 400 | `Cannot edit a deleted message`           |
| 403 | `Only the sender can edit a message`      |
| 404 | `Message not found`                       |

**Ограничения:**
- Редактируется только текст (файлы не меняются)
- Нельзя редактировать удалённые сообщения
- Без ограничения по времени
- После редактирования `updated_at` обновляется — показывать пометку "изменено"

---

### 11. Удалить сообщение (soft delete)

```
DELETE /api/chat/messages/{messageId}
```

**Доступ:** только отправитель сообщения

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:** нет

**Ответ `200 OK`:**
```json
{
  "success": true
}
```

**Ошибки:**
| Код | Описание                                    |
|-----|---------------------------------------------|
| 403 | `Only the sender can delete a message`      |
| 404 | `Message not found`                         |

**Примечание:** это soft delete. Сообщение остаётся в базе, но при запросе сообщений возвращается с `is_deleted: true`, `content: null`.

---

### 12. Отметить все сообщения прочитанными

```
POST /api/chat/rooms/{roomId}/read
```

**Доступ:** только участник комнаты

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Тело запроса:** нет

**Ответ `200 OK`:**
```json
{
  "success": true
}
```

**Ошибки:**
| Код | Описание          |
|-----|-------------------|
| 403 | `Access denied`   |
| 404 | `Room not found`  |

**Когда вызывать:** при открытии комнаты и при получении нового сообщения от другого пользователя.

---

### 13. Получить общее количество непрочитанных

```
GET /api/chat/unread-count
```

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Параметры:** нет

**Ответ `200 OK`:**
```json
{
  "count": 7
}
```

**Использование:** для badge на иконке чата в навигации / таб-баре.

---

### 14. Получить профиль пользователя

```
GET /api/chat/users/{userId}/profile
```

**Доступ:** любой авторизованный пользователь

**Заголовки:**
```
X-Requested-With: XMLHttpRequest
Authorization: Bearer <token>
```

**Ответ `200 OK`:**
```json
{
  "id": 5,
  "lastname": "Иванов",
  "firstname": "Иван",
  "patronymic": "Сергеевич",
  "avatar": "/media/cache/avatar_medium/5/avatar.jpg",
  "phone": "+7 (999) 123 45 67",
  "email": "ivanov@company.ru",
  "profession": "Инженер",
  "department": "Административно-управленческий аппарат",
  "organization": "ДонСнабКомплект",
  "last_seen_at": "2026-03-16T14:30:00+00:00"
}
```

| Поле           | Тип     | Описание                                    |
|----------------|---------|---------------------------------------------|
| `id`           | int     | ID пользователя                             |
| `lastname`     | string  | Фамилия                                     |
| `firstname`    | string  | Имя                                          |
| `patronymic`   | string? | Отчество                                    |
| `avatar`       | string? | Аватарка, ресайз 200x200 (LiipImagine)      |
| `phone`        | string? | Телефон                                      |
| `email`        | string? | Email                                        |
| `profession`   | string? | Должность (из сущности Worker)              |
| `department`   | string? | Название департамента (если пользователь в Department) |
| `organization` | string? | Название корневой организации               |
| `last_seen_at` | string? | Последний раз в сети (ISO 8601)             |

**Ошибки:**
| Код | Описание           |
|-----|--------------------|
| 404 | `User not found`   |

**Использование:** popover-карточка при клике на имя отправителя в чате. Форматирование `last_seen_at`:
- < 5 мин. назад → "в сети"
- < 60 мин. → "был(а) X мин. назад"
- Сегодня → "был(а) сегодня в HH:MM"
- Вчера → "был(а) вчера в HH:MM"
- Ранее → "был(а) DD.MM.YYYY"

---

## Real-time: Mercure (Server-Sent Events)

Для получения обновлений в реальном времени используется [Mercure](https://mercure.rocks/).

### Подключение

```
GET {MERCURE_PUBLIC_URL}?topic=/chat/user/{userId}
GET {MERCURE_PUBLIC_URL}?topic=/chat/room/{roomId}
```

Подключение через `EventSource` (SSE). Для React Native можно использовать библиотеку `react-native-sse` или `EventSource` polyfill.

### Топики

| Топик                      | Когда подписываться      | Описание                                |
|----------------------------|--------------------------|-----------------------------------------|
| `/chat/user/{userId}`      | При запуске приложения   | Уведомления об обновлении списка комнат |
| `/chat/room/{roomId}`      | При открытии комнаты     | Новые сообщения, удаления, редактирования |

### События

#### `room_updated` (топик: `/chat/user/{userId}`)

Приходит когда нужно обновить список комнат (новое сообщение в любой комнате, изменения участников).

```json
{
  "type": "room_updated",
  "room_id": 1
}
```

**Действие:** вызвать `GET /api/chat/rooms` для обновления списка.

#### `new_message` (топик: `/chat/room/{roomId}`)

Новое сообщение в открытой комнате.

```json
{
  "type": "new_message",
  "message": {
    "id": 102,
    "room_id": 1,
    "sender": {
      "id": 12,
      "lastname": "Петров",
      "firstname": "Сергей",
      "avatar": null
    },
    "content": "Привет!",
    "is_deleted": false,
    "is_read": false,
    "files": [],
    "created_at": "2026-03-16T14:10:00+00:00",
    "updated_at": null
  }
}
```

**Действие:** добавить сообщение в UI. Если отправитель не текущий пользователь — вызвать `POST /rooms/{id}/read`.

#### `message_edited` (топик: `/chat/room/{roomId}`)

Сообщение было отредактировано.

```json
{
  "type": "message_edited",
  "message": {
    "id": 101,
    "room_id": 1,
    "sender": {
      "id": 5,
      "lastname": "Иванов",
      "firstname": "Иван",
      "avatar": "/media/cache/avatar_medium/5/avatar.jpg"
    },
    "content": "Обновлённый текст",
    "is_deleted": false,
    "is_read": true,
    "files": [],
    "created_at": "2026-03-16T14:00:00+00:00",
    "updated_at": "2026-03-16T14:05:00+00:00"
  }
}
```

**Действие:** найти сообщение по ID в UI и обновить текст + показать пометку "изменено".

#### `message_deleted` (топик: `/chat/room/{roomId}`)

Сообщение было удалено.

```json
{
  "type": "message_deleted",
  "message_id": 101,
  "room_id": 1
}
```

**Действие:** найти сообщение по ID в UI и заменить контент на "Сообщение удалено".

#### `read` (топик: `/chat/room/{roomId}`)

Пользователь прочитал сообщения в комнате.

```json
{
  "type": "read",
  "room_id": 1,
  "user_id": 12
}
```

**Действие:** можно обновить статус прочтения (галочки) если реализованы.

**Логика галочек:**
- Одна галочка (✓) — сообщение доставлено, `is_read: false`
- Две синие галочки (✓✓) — все участники прочитали, `is_read: true`
- При получении события `read` от другого пользователя — обновить все свои сообщения на двойную галочку

---

## Коды ошибок (общие)

| Код | Описание                                       |
|-----|-------------------------------------------------|
| 400 | Некорректные параметры запроса                  |
| 401 | Не авторизован (нет токена / невалидный токен)  |
| 403 | Нет прав (не участник комнаты / не создатель / не отправитель / нет роли) |
| 404 | Ресурс не найден (комната / сообщение / пользователь) |

---

## Сценарии использования

### Загрузка чата при запуске

1. `GET /api/chat/rooms` — загрузить список комнат
2. Подписаться на Mercure топик `/chat/user/{userId}`
3. `GET /api/chat/unread-count` — для badge в навигации

### Открытие комнаты

1. `GET /api/chat/rooms/{id}/messages` — загрузить последние 30 сообщений
2. `POST /api/chat/rooms/{id}/read` — отметить как прочитанное
3. Подписаться на Mercure топик `/chat/room/{roomId}`
4. Для групповых/отделовых: `GET /api/chat/rooms/{id}` — загрузить участников

### Бесконечный скролл вверх

1. Взять `id` самого старого загруженного сообщения
2. `GET /api/chat/rooms/{id}/messages?before={oldestMessageId}`
3. Если вернулось < 30 сообщений — дальше загружать нечего

### Отправка сообщения

1. `POST /api/chat/rooms/{id}/messages` (JSON или multipart)
2. Сообщение придёт обратно через Mercure `new_message` — добавить в UI

### Редактирование сообщения

1. `PUT /api/chat/messages/{id}` с новым текстом
2. Обновлённое сообщение придёт через Mercure `message_edited` — обновить в UI

### Удаление сообщения

1. `DELETE /api/chat/messages/{id}`
2. Уведомление придёт через Mercure `message_deleted` — обновить в UI

---

## TODO для мобильного приложения

### Backend (Symfony)

- [ ] Настроить JWT-аутентификацию (`lexik/jwt-authentication-bundle`)
- [ ] Добавить эндпоинт `POST /api/login` — принимает `{login, password}`, возвращает `{token, refresh_token}`
- [ ] Добавить эндпоинт `POST /api/token/refresh` — принимает `{refresh_token}`, возвращает новый `{token, refresh_token}`
- [ ] Добавить эндпоинт `GET /api/user/me` — возвращает данные текущего пользователя (id, lastname, firstname, avatar, roles)
- [ ] Настроить Symfony Security firewall: `/api/*` — stateless JWT, остальное — сессионное
- [ ] Настроить Mercure авторизацию через JWT (передача токена в cookie или query-параметре)
- [ ] Добавить эндпоинт `POST /api/user/device-token` — сохранение FCM-токена для push-уведомлений
- [ ] Реализовать отправку push-уведомлений через Firebase Cloud Messaging при новых сообщениях (когда приложение в фоне)
- [ ] Добавить эндпоинт поиска пользователей `GET /api/users/search?query=` для мобильного создания чатов
- [ ] Добавить эндпоинт организационного дерева `GET /api/organizations/tree` для мобильного выбора пользователей
- [ ] Добавить эндпоинт пользователей организации `GET /api/organizations/{id}/users`
- [ ] Добавить Rate Limiting на API-эндпоинты (Symfony RateLimiter)
- [ ] Добавить валидацию размера файлов и типов на уровне API

### Mobile (React Native)

- [ ] Настроить навигацию: Tab Navigator (чаты, контакты, профиль)
- [ ] Экран списка чатов — `GET /api/chat/rooms`
- [ ] Экран переписки — `GET /api/chat/rooms/{id}/messages` с бесконечным скроллом
- [ ] Компонент bubble сообщения (свои справа, чужие слева, удалённые курсивом, пометка "изменено")
- [ ] Отправка текстовых сообщений — `POST /api/chat/rooms/{id}/messages`
- [ ] Прикрепление файлов (document picker + camera) — multipart/form-data
- [ ] Контекстное меню сообщения (long press): редактировать / удалить
- [ ] Редактирование сообщения — `PUT /api/chat/messages/{id}`
- [ ] Удаление сообщения с подтверждением — `DELETE /api/chat/messages/{id}`
- [ ] Экран создания приватного чата — поиск пользователей + создание через `POST /api/chat/rooms/private`
- [ ] Экран создания группового чата (ROLE_MANAGER) — `POST /api/chat/rooms/group`
- [ ] Real-time через Mercure SSE (`react-native-sse` или `EventSource` polyfill)
- [ ] Подписка на `/chat/user/{userId}` при запуске — обновление списка комнат
- [ ] Подписка на `/chat/room/{roomId}` при открытии комнаты — новые сообщения, редактирования, удаления
- [ ] Хранение JWT токена (SecureStore / EncryptedStorage)
- [ ] Автообновление токена при 401 (axios interceptor)
- [ ] Push-уведомления через Firebase (react-native-firebase)
- [ ] Отправка FCM device token на сервер при логине — `POST /api/user/device-token`
- [ ] Badge непрочитанных на иконке чата — `GET /api/chat/unread-count`
- [ ] Отметка прочитанного при открытии комнаты — `POST /api/chat/rooms/{id}/read`
- [ ] Галочки прочтения на своих сообщениях (✓ отправлено / ✓✓ прочитано) — на основе `is_read` из API
- [ ] Обновление галочек в реальном времени при получении события Mercure `read`
- [ ] Экран участников группового чата — `GET /api/chat/rooms/{id}` (список, добавление, удаление)
- [ ] Offline-режим: кэширование списка комнат и последних сообщений (AsyncStorage / MMKV)
- [ ] Индикатор "печатает..." (опционально, требует дополнительного Mercure-события)
- [ ] Превью изображений в чате (для .png, .jpg, .jpeg)
- [ ] Скачивание файлов с авторизацией (передача JWT в заголовке)
