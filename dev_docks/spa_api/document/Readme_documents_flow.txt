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
  DocumentOrganizationTreeController
  DocumentSignatureController    — электронная подпись (см. раздел 8)

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

  Электронная подпись (раздел 8):
    document_signature_level_required   — переданы signers без signatureLevel
    document_signers_required           — передан signatureLevel без signers
    document_invalid_signature_level    — значение не из {simple, enhanced}
    document_invalid_signers            — неверный формат signers
    document_signer_not_found           — подписант не найден среди пользователей
    document_signing_locked             — попытка менять уровень/подписантов после отправки на подпись
    document_not_approved               — отправка на подпись не из статуса APPROVED
    document_no_signers                 — у документа нет подписантов
    document_signing_forbidden          — отправить на подпись может только автор
    document_not_on_signing             — документ не в статусе ON_SIGNING
    document_signing_not_signer         — текущий пользователь не подписант документа
    document_signing_wrong_turn         — не его очередь (последовательный режим)
    document_signing_already_signed     — уже подписал
    document_signing_level_insufficient — ПЭП на документе уровня enhanced
    invalid_password                    — неверный пароль учётки (ПЭП)
    certificate_not_found               — сертификат не найден или принадлежит другому
    certificate_revoked                 — сертификат отозван
    certificate_expired                 — сертификат просрочен/ещё не действует
    invalid_signature                   — криптоподпись не прошла проверку
    reason_required                     — отказ без причины

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
        "role": string,              // executor | recipient | signer
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
    "createdBy": { "id", "fullName", "profession" } | null,

    // блок электронной подписи (см. раздел 8)
    "signatureLevel": "simple" | "enhanced" | null,
    "signers": [
      {
        "userId": number,
        "fullName": string,
        "signingOrder": number|null,
        "signed": boolean,
        "signedAt": string|null          // ISO 8601 ATOM
      }
    ],                                    // отсортированы по signingOrder
    "allSigned": boolean,                 // все подписанты подписали
    "canSendToSigning": boolean,          // тек. пользователь — автор, статус APPROVED, есть подписанты
    "canSign": boolean,                   // тек. пользователь — подписант, ON_SIGNING, его очередь, ещё не подписал
    "canDeclineSigning": boolean          // тек. пользователь — подписант, ON_SIGNING, ещё не подписал
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
  signatureLevel   string   — "simple" (ПЭП) | "enhanced" (УНЭП) | null; опционально
  signers          array    — подписанты (роль SIGNER): [{ "userId": number, "order": number }]
                              order >= 1. Параллельный режим — у всех order = 1;
                              последовательный — 1, 2, 3, ...

Правила (DocumentCreateService):

  • isPublished=true при status=DRAFT → document_cannot_publish_draft
  • isPublished=true без executor/recipient ids → document_no_recipients
  • Не-admin без organization в профиле → organization_required
  • signers без signatureLevel → document_signature_level_required
  • signatureLevel без signers → document_signers_required
  • неизвестный userId в signers → document_signer_not_found (весь запрос отклоняется)
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
  URL    : /spa/api/documents-flow/organizations/tree

Дерево организаций для picker получателей (один запрос вместо list + N view).
Полное дерево для всех ROLE_USER — как web-форма создания документа.
Получателей/исполнителей можно выбирать из любого подразделения.

Права:
  ROLE_USER — все корневые организации (parent IS NULL) с дочерними до 5 уровней.

Ответ 200:

  {
    "organizations": [
      {
        "id": number,
        "name": string,
        "type": "organization" | "filial" | "department",
        "children": [ ... ]
      }
    ]
  }


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
7. КОНТЕКСТ И МУТАЦИИ
--------------------------------------------------------------------------------

  GET /context
  Ответ: { "isAdmin": boolean, "organization": { id, name, path } | null }

  PATCH /outgoing/{id}
  Body: name, description?, organizationId, status, deadline?, isPublished?,
        signatureLevel?, signers?     // как в POST /documents; после отправки
                                      // на подпись менять нельзя → document_signing_locked
  Ответ: { "document": DocumentListItem }

  POST /outgoing/{id}/publish
  Ответ: { "document": DocumentListItem }

  PUT /outgoing/{id}/recipients
  Body: { "executorUserIds": number[], "recipientUserIds": number[], "signers"?: [{ "userId", "order" }] }
  signers опционален: если ключ не передан — подписанты не меняются.
  Непустые signers требуют, чтобы у документа уже был signatureLevel
  (→ document_signature_level_required); пустые signers при заданном уровне
  → document_signers_required; после отправки на подпись → document_signing_locked.
  Ответ: document + executors + recipients + permissions

  PATCH /incoming/{id}/recipient-status
  Body: { "status": string }
  Ответ: как GET /incoming/{id}

--------------------------------------------------------------------------------
8. ПОДПИСАНИЕ ДОКУМЕНТОВ (электронная подпись)
--------------------------------------------------------------------------------

Контроллер: DocumentSignatureController.
Сервисы: App\Service\Document\Signature\{SigningService, SignatureVerificationService}.

Два уровня подписи (signatureLevel документа, выбирает автор при создании):
  simple   — ПЭП: подтверждение паролем учётной записи.
  enhanced — УНЭП: RSA-подпись приватным ключом из .p12-контейнера пользователя
             (выпускает админ во внутреннем УЦ). На документе уровня enhanced
             ПЭП недопустима (document_signing_level_insufficient); УНЭП на
             документе уровня simple — допустима.

Жизненный цикл: APPROVED → (send-to-signing) → ON_SIGNING → (все подписали) →
SIGNED. Отказ любого подписанта: ON_SIGNING → REJECTED. После send-to-signing
оригинал заморожен (канонический PDF), уровень/состав подписантов менять нельзя.

Доступ ко всем эндпоинтам раздела: участник документа (автор, любой получатель,
включая подписантов) или admin — как GET /incoming/{id}. Подписанты видят
документ во входящих (role = "signer" в items).

--- POST /documents/{id}/send-to-signing ---------------------------------------

Только автор. Документ должен быть в APPROVED и иметь подписантов.
Замораживает канонический PDF (DOC/DOCX конвертируется), считает SHA-256,
переводит в ON_SIGNING, уведомляет подписантов.

Body: не требуется.

Ответ 200: { "document": { ...DocumentListItem } }
Ошибки: 404 document_not_found, 403 access_denied,
        403 document_signing_forbidden (не автор),
        400 document_not_approved, 400 document_no_signers.

--- GET /documents/{id}/signatures ---------------------------------------------

Блок подписей с криптопроверкой на лету (хэш файла пересчитывается,
УНЭП-подписи проверяются по сертификату).

Ответ 200:

  {
    "signatures": [
      {
        "signer": { "id", "fullName", "profession" } | null,
        "level": "simple" | "enhanced",
        "signedAt": string,                  // ISO 8601 ATOM
        "certificateSerial": string|null,    // null для ПЭП
        "valid": boolean,
        "validityDetails": {                 // null, если нет деталей
          "reason": string,                  // hash_mismatch | invalid_signature |
                                             // certificate_expired | certificate_revoked
          "certificateSerial": string,
          "revokedAt": string,
          "revokedAfterSigning": boolean     // отозван ПОСЛЕ подписания — подпись валидна
        } | null
      }
    ],
    "documentHash": string|null,             // hex SHA-256 канонического PDF
    "verificationCode": string|null,         // код для QR/страницы проверки
    "allSigned": boolean
  }

Ошибки: 404 document_not_found, 403 access_denied.

--- POST /documents/{id}/sign/simple (ПЭП) -------------------------------------

Body: { "password": string }   // пароль учётной записи текущего пользователя

Ответ 200: { "document": { ...DocumentListItem } }
  (после последней подписи document.status = "SIGNED")

Ошибки: 400 document_not_on_signing, 403 document_signing_not_signer,
        400 document_signing_wrong_turn, 400 document_signing_already_signed,
        400 document_signing_level_insufficient, 400 invalid_password.

--- GET /documents/{id}/sign/challenge (УНЭП, шаг 1) ---------------------------

Данные для подписания в браузере: хэш и активные непросроченные сертификаты
ТЕКУЩЕГО пользователя.

Ответ 200:

  {
    "documentHash": string,        // hex SHA-256 — ЭТО подписывает браузер
    "algorithm": "RSA-SHA256",
    "certificates": [
      { "id": number, "serialNumber": string, "validTo": string }
    ]                              // пусто — у пользователя нет действующего сертификата
  }

Ошибки: 404 document_not_found, 403 access_denied, 400 document_not_on_signing.

--- POST /documents/{id}/sign/enhanced (УНЭП, шаг 2) ---------------------------

Body: { "certificateId": number, "signature": string }  // signature — base64

Ответ 200: { "document": { ...DocumentListItem } }
Ошибки: как sign/simple (кроме invalid_password и level_insufficient), плюс:
        404 certificate_not_found (нет такого id),
        403 certificate_not_found (сертификат другого пользователя),
        400 certificate_revoked, 400 certificate_expired,
        400 invalid_signature.

КОНТРАКТ ПОДПИСИ (менять нельзя, сервер проверяет ровно это):
  Подписывается HEX-СТРОКА documentHash из challenge КАК ASCII-БАЙТЫ
  (НЕ бинарный хэш, НЕ сам файл). Алгоритм: RSA-SHA256, паддинг PKCS#1 v1.5.
  Сервер: openssl_verify(hexHashString, signature, certPublicKey, SHA256).

Рекомендация: node-forge (ключ и пароль .p12 НЕ покидают браузер):

  import forge from "node-forge";

  // 1. file input → ArrayBuffer .p12-файла с флешки + пароль контейнера
  const der = forge.util.createBuffer(new Uint8Array(p12ArrayBuffer));
  const p12 = forge.pkcs12.pkcs12FromAsn1(
    forge.asn1.fromDer(der), /*strict*/ false, p12Password);
  const bags = p12.getBags({ bagType: forge.pki.oids.pkcs8ShroudedKeyBag });
  const privateKey = bags[forge.pki.oids.pkcs8ShroudedKeyBag][0].key;

  // 2. подпись hex-СТРОКИ хэша как ASCII-байтов (RSA-SHA256, PKCS#1 v1.5)
  const { documentHash } = await api.get(`/documents/${id}/sign/challenge`);
  const md = forge.md.sha256.create();
  md.update(documentHash, "utf8");          // именно строка, не hex-decode!
  const signature = forge.util.encode64(privateKey.sign(md)); // sign() = PKCS#1 v1.5

  // 3. отправка
  await api.post(`/documents/${id}/sign/enhanced`, { certificateId, signature });

  Неверный пароль контейнера — forge бросает исключение из pkcs12FromAsn1:
  показать «неверный пароль контейнера», ключ не извлечён.

--- POST /documents/{id}/decline-signing ---------------------------------------

Отказ от подписания: доступен любому назначенному подписанту в ON_SIGNING
(даже если не его очередь). Документ → REJECTED, причина в истории.

Body: { "reason": string }   // обязателен, непустой

Ответ 200: { "document": { ...DocumentListItem } }
Ошибки: 400 document_not_on_signing, 403 document_signing_not_signer,
        400 reason_required.

--- Последовательный vs параллельный режим -------------------------------------

Режим задаётся полем order в signers при создании/редактировании:

  Параллельный:      у всех подписантов order = 1 — подписывают в любом порядке.
  Последовательный:  order = 1, 2, 3, ... — подписать может только подписант
                     с минимальным order среди ещё не подписавших.
  Смешанный:         одинаковый order = параллельная «ступень»
                     (напр. 1, 1, 2 — двое в любом порядке, третий после них).

Фронту не нужно вычислять очередь: сервер отдаёт per-user флаг canSign
в DocumentListItem. Показывайте кнопку «Подписать» по canSign, кнопку
«Отказаться» по canDeclineSigning, кнопку «Отправить на подпись» по
canSendToSigning.

Обработка document_signing_wrong_turn:
  Возникает при гонке (canSign устарел: очередь ещё не дошла или состав
  изменился между рендером и кликом). Не показывать как фатальную ошибку:
  сообщение «Сейчас не ваша очередь подписания», перезапросить карточку
  (GET /incoming/{id}) и перерисовать кнопки по свежим can*-флагам.
  Аналогично document_signing_already_signed после двойного клика.

--- Сценарий: полный флоу ПЭП (simple) -----------------------------------------

  1. POST /documents  { ..., "signatureLevel": "simple",
                        "signers": [{ "userId": 10, "order": 1 },
                                    { "userId": 20, "order": 2 }] }
  2. Согласование существующим флоу до статуса APPROVED.
  3. Автор (canSendToSigning=true): POST /documents/{id}/send-to-signing.
  4. Подписант 10 (canSign=true): POST /documents/{id}/sign/simple
     { "password": "<пароль его учётки>" }.
  5. Подписант 20 — то же; после его подписи document.status = "SIGNED".
  6. Блок подписей: GET /documents/{id}/signatures.

--- Сценарий: полный флоу УНЭП (enhanced) --------------------------------------

  1. Админ выпускает пользователю сертификат (.p12 скачивается ОДИН раз,
     хранится у пользователя на флешке) — админка /admin/certificates, вне SPA.
  2. POST /documents { ..., "signatureLevel": "enhanced",
                       "signers": [{ "userId": 10, "order": 1 }] }
  3. APPROVED → POST /documents/{id}/send-to-signing (автор).
  4. Подписант: GET /documents/{id}/sign/challenge →
     { documentHash, algorithm, certificates }.
     certificates пуст → показать «нет действующего сертификата, обратитесь
     к администратору», подписать нельзя.
  5. Пользователь выбирает .p12 с флешки, вводит пароль контейнера;
     браузер подписывает documentHash по контракту выше (node-forge).
  6. POST /documents/{id}/sign/enhanced { certificateId, signature }.
  7. GET /documents/{id}/signatures — подпись valid, certificateSerial заполнен.


--------------------------------------------------------------------------------
НЕ РЕАЛИЗОВАНО В SpaApi
--------------------------------------------------------------------------------

  Загрузка файлов, комментарии, OnlyOffice — отдельные web/API контуры.

  Проверка загруженного файла (POST /verify-file) и скачивание печатной формы
  (GET /documents/{id}/signed-form) — Фаза 5, будут добавлены отдельно.


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
