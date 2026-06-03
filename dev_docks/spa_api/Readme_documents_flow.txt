================================================================================
SPA API — Документооборот (documents-flow)
================================================================================

Эндпоинты для SPA: типы документов, входящие/исходящие списки, создание,
карточка документа, справочник пользователей для форм (по организации и поиск).

Контроллеры: App\Controller\SpaApi\DocumentsFlow\*
  DocumentTypeController
  DocumentIncomingController
  DocumentOutgoingController
  DocumentCreateController
  DocumentUsersController

Сервисы (бизнес-логика и JSON): App\Service\SpaApi\Documents\*
  DocumentAccessService   — права доступа, permissions для фронта
  DocumentApiPresenter    — сериализация Entity → JSON (camelCase)
  DocumentCreateService   — POST /documents (создание + получатели + notify)

Общие web-сервисы документов (не SpaApi): App\Service\Document\*
  DocumentService, FileUploadService, …

Базовый путь: /spa/api/documents-flow

Авторизация:
  JWT в заголовке Authorization (как у всех /spa/api/*).
  Требуется ROLE_USER.
  См. dev_docks/spa_api/Readme_spa_auth.txt.

Связанные SpaApi:
  /spa/api/organizations — список и дерево организаций для форм (admin).
  См. Readme_organizations.txt.

Фронтенд (Next.js): frontend_analytics_platform
  API: src/lib/documentsFlowApi.ts  (DOCUMENTS_FLOW_API, documentsFlowUrl)
  Типы: src/types/features/documentsFlow.ts
  Redux: src/redux/documentsFlow/*
  UI: src/features/DocumentsFlow/*, маршруты /documents-flow, /document-in, /document-out

Прокси в dev: Next.js rewrites → http://localhost:8080


--------------------------------------------------------------------------------
ОБЩИЕ СОГЛАШЕНИЯ
--------------------------------------------------------------------------------

Пагинация (как organizations/users):

  Query: page (>=1, по умолчанию 1), page_size (1..100, по умолчанию 10).

  Ответ pagination:
  {
    "current_page":   number,
    "total_pages":    number,
    "total_items":    number,
    "items_per_page": number
  }

Ошибки:

  Тело: { "error": "<код>" }
  Коды — константы App\Controller\SpaApi\SpaApiError, словарь на фронте:
    src/localization/spaApiErrorDictionary.ts

  Документооборот:
    document_not_found
    document_type_not_found
    document_name_required
    document_invalid_status
    document_invalid_deadline
    document_cannot_publish_draft
    document_no_recipients
    document_validation_failed
    organization_required
    organization_not_found
    access_denied
    invalid_json

  POST /documents дополнительно: HTTP 400/404 с тем же полем error.


--------------------------------------------------------------------------------
1. ТИПЫ ДОКУМЕНТОВ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/documents-flow/types

Ответ 200:

  {
    "types": [
      { "id": number, "name": string, "description": string|null }
    ]
  }


--------------------------------------------------------------------------------
2. ВХОДЯЩИЕ ДОКУМЕНТЫ (получатель)
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/documents-flow/incoming

Query:

  page, page_size
  status       — значение enum DocumentStatus (фильтр по статусу получателя)
  type_id      — id типа документа
  creator      — строка поиска по создателю
  name         — строка поиска по названию документа
  created_from — Y-m-d
  created_to   — Y-m-d (конец дня 23:59:59)

Ответ 200:

  {
    "items": [
      {
        "recipientId": number,
        "recipientStatus": string|null,
        "recipientStatusLabel": string|null,
        "role": string,              // EXECUTOR | RECIPIENT
        "document": { ...DocumentListItem } | null
      }
    ],
    "pagination": { ... },
    "filters": {
      "statusChoices": { "<statusValue>": "<label>", ... },
      "types": [ { "id", "name", "description" } ]
    }
  }

DocumentListItem (вложенный document и списки outgoing):

  {
    "id": number,
    "name": string,
    "description": string|null,
    "status": string|null,
    "statusLabel": string|null,
    "isPublished": boolean,
    "createdAt": string|null,      // ISO 8601 ATOM
    "updatedAt": string|null,
    "deadline": string|null,       // Y-m-d
    "documentType": { "id", "name", "description" } | null,
    "organization": { "id", "name", "path" } | null,
    "createdBy": { "id", "fullName", "profession" } | null
  }


--------------------------------------------------------------------------------
3. ИСХОДЯЩИЕ ДОКУМЕНТЫ (создатель)
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/documents-flow/outgoing

Query:

  page, page_size
  type_id  — id типа документа
  name     — поиск по названию

Ответ 200:

  {
    "items": [ ...DocumentListItem ],
    "pagination": { ... },
    "filters": {
      "types": [ { "id", "name", "description" } ]
    }
  }

Выборка: документы, созданные текущим пользователем (DocumentRepository::findPaginatedByCreatedBy).


--------------------------------------------------------------------------------
4. СОЗДАНИЕ ДОКУМЕНТА
--------------------------------------------------------------------------------

  Method : POST
  URL    : /spa/api/documents-flow/documents
  Content-Type: application/json

Body:

  documentTypeId   number   — обязательно
  name             string   — обязательно
  description      string   — опционально
  organizationId   number   — только ROLE_ADMIN; иначе org берётся из профиля
  status           string   — DRAFT | NEW (по умолчанию DRAFT)
  isPublished      boolean  — false = черновик; true = создать и отправить
  deadline         string   — Y-m-d, опционально
  executorUserIds  number[] — исполнители (роль EXECUTOR)
  recipientUserIds number[] — получатели (роль RECIPIENT)

Правила (DocumentCreateService):

  • isPublished=true при status=DRAFT → document_cannot_publish_draft
  • isPublished=true без executor/recipient ids → document_no_recipients
  • Не-admin без organization в профиле → organization_required
  • При isPublished=true после сохранения — уведомления получателям (web-ссылка
    app_view_incoming_document; для полного SPA позже заменить на /document-in/{id})

Режимы на фронте:

  Черновик:  status=DRAFT,  isPublished=false
  Отправка:  status=NEW,    isPublished=true  (+ получатели обязательны)

Ответ 201:

  { "document": { ...DocumentListItem } }


--------------------------------------------------------------------------------
5. КАРТОЧКА ДОКУМЕНТА
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/documents-flow/incoming/{id}
           /spa/api/documents-flow/outgoing/{id}

  {id} — id документа (не recipientId).

Доступ: DocumentAccessService::canViewDocument
  (admin | создатель | пользователь в списке получателей документа).

Ответ 403: { "error": "access_denied" }
Ответ 404: { "error": "document_not_found" }

--- GET /incoming/{id} ---------------------------------------------------------

  {
    "document": { ...DocumentListItem },
    "executors": [ ...RecipientRow ],
    "recipients": [ ...RecipientRow ],
    "userRecipient": {
      "recipientId": number,
      "status": string|null,
      "statusLabel": string|null
    } | null,
    "allowedRecipientStatuses": [
      { "value": string, "label": string }
    ],
    "permissions": {
      "canView": boolean,
      "canEdit": boolean,
      "canPublish": boolean,
      "canChangeRecipientStatus": boolean
    }
  }

--- GET /outgoing/{id} ---------------------------------------------------------

  {
    "document": { ...DocumentListItem },
    "executors": [ ...RecipientRow ],
    "recipients": [ ...RecipientRow ],
    "permissions": { ... },
    "statusChoices": { "<statusValue>": "<label>", ... }   // для редактирования
  }

RecipientRow:

  {
    "recipientId": number,
    "role": string,
    "status": string|null,
    "statusLabel": string|null,
    "user": { "id", "fullName", "profession" } | null
  }

permissions.canPublish (исходящий):
  canEdit && статус документа задан && !isPublished && есть получатели.


--------------------------------------------------------------------------------
6. ПОЛЬЗОВАТЕЛИ ДЛЯ ФОРМ
--------------------------------------------------------------------------------

  Method : GET
  URL    : /spa/api/documents-flow/organizations/{id}/users

Ответ 200: { "users": [ { "id", "fullName", "profession" } ] }
Ответ 404: { "error": "organization_not_found" }


  Method : GET
  URL    : /spa/api/documents-flow/users/search?query=<строка>

  query < 2 символов → { "users": [] }
  Иначе до 20 пользователей (UserRepository::findPaginated).

Ответ 200: { "users": [ { "id", "fullName", "profession" } ] }


--------------------------------------------------------------------------------
НЕ РЕАЛИЗОВАНО В SpaApi (есть только в web DocumentController)
--------------------------------------------------------------------------------

  PATCH /outgoing/{id}              — редактирование полей документа
  POST  /outgoing/{id}/publish      — публикация
  PUT   /outgoing/{id}/recipients   — смена исполнителей/получателей
  PATCH /incoming/{id}/recipient-status — смена статуса получателя

  Загрузка файлов, комментарии, OnlyOffice — отдельные web/API контуры.


--------------------------------------------------------------------------------
ПРИМЕРЫ
--------------------------------------------------------------------------------

curl — типы:

  curl -H "Authorization: Bearer <token>" \
       "http://localhost:8080/spa/api/documents-flow/types"

curl — создать черновик:

  curl -X POST -H "Authorization: Bearer <token>" \
       -H "Content-Type: application/json" \
       -d '{"documentTypeId":1,"name":"Тест","status":"DRAFT","isPublished":false}' \
       "http://localhost:8080/spa/api/documents-flow/documents"

fetch — входящие (через Next proxy):

  const q = new URLSearchParams({ page: "1", page_size: "10" });
  const res = await fetch(`/spa/api/documents-flow/incoming?${q}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
