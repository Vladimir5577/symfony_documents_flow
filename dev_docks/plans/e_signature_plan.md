# План внедрения электронной подписи документов

> Статус: утверждён 2026-07-17. Арбитр/архитектор — Claude, решения согласованы с владельцем проекта.
> Исполнители — суб-агенты. Каждая задача атомарна: имеет входы, выходы, файлы и критерии приёмки.
> Контракты (имена сущностей, полей, сервисов, эндпоинтов, кодов ошибок) в этом документе — **обязательные**.
> Суб-агент не имеет права переименовывать контрактные имена без возврата к арбитру.

---

## 1. Утверждённые продуктовые решения

| # | Решение |
|---|---------|
| 1 | Два уровня подписи: **ПЭП** (`simple`) — повторный ввод пароля учётки; **УНЭП** (`enhanced`) — индивидуальный ключ `.p12` на флешке, свой внутренний УЦ. Архитектура расширяема под третий уровень (`qualified`, КриптоПро) — сейчас НЕ реализуется. |
| 2 | Только внутренний ЭДО. Никаких внешних провайдеров, ГОСТ-крипто, лицензий. |
| 3 | Уровень подписи выбирает **автор** документа при создании/редактировании. |
| 4 | Режим подписания выбирает автор: **параллельный** или **последовательный** (поле порядка). |
| 5 | Оригинал после отправки на подпись **неизменяем**. Подписывается SHA-256 хэш канонического PDF. Подписи хранятся в БД (откреплённые). Штампы — только на генерируемой печатной форме. Авторазмещение штампов (лист подписей). Ручное позиционирование — вне скоупа, задел не строим. |
| 6 | ПЭП-фактор: повторный ввод пароля учётки в момент подписания. |
| 7 | УЦ: админ выпускает ключ, сервер генерирует пару и отдаёт `.p12` **один раз**, хранит только сертификат. Срок действия 1 год. Отзыв при утере. Приватные ключи пользователей на сервере не хранятся. |
| 8 | Рабочий UI подписания — **Twig**. Для SPA — эндпоинты + документация в `dev_docks/spa_api/document/` (Next.js делает другая команда). |
| 9 | Строгая цепочка: подписание возможно **только из статуса APPROVED**. APPROVED → ON_SIGNING → SIGNED. Отказ любого подписанта → REJECTED (с комментарием). |
| 10 | Канонический подписываемый файл — **PDF**. DOC/DOCX конвертируется при отправке на подпись (LibreOffice), Word-исходник сохраняется рядом. |
| 11 | Проверка: блок подписей на карточке + страница проверки загруженного файла + QR-код на штампе. |
| 12 | Обвязка: уведомления (существующий Notifier/Mercure), админка УЦ (Twig), мониторинг, тесты (юнит крипто-ядра + функциональные переходов). |
| 13 | Старый прототип `DocumentSignController` и его шаблоны — удаляются. Конвертация DOCX→PDF из него извлекается в сервис ДО удаления. |
| 14 | Корневой ключ УЦ — файл на сервере вне webroot, passphrase в env. HSM не используется. |

---

## 2. Целевая архитектура (обзор)

```
Автор: создаёт документ [+ уровень подписи + подписанты + порядок]
  → согласование (существующий флоу) → APPROVED
  → «Отправить на подпись»:
      DOCX→PDF (если нужно) → канонический PDF заморожен → SHA-256 → ON_SIGNING
  → подписанты по очереди/параллельно:
      ПЭП: пароль → запись DocumentSignature(level=simple)
      УНЭП: браузер читает .p12 с флешки (node-forge), подписывает хэш,
            сервер проверяет подпись по сертификату → DocumentSignature(level=enhanced)
  → все подписали → SIGNED → генерация печатной формы (PDF + штампы + QR)
  → проверка: карточка / загрузка файла / QR-ссылка
Отказ любого подписанта → REJECTED + комментарий.
```

Крипто-примитивы: `ext-openssl` PHP (RSA-2048, SHA-256, X.509, PKCS#12). Никаких новых composer-зависимостей для крипто. QR — встроенный `write2DBarcode` TCPDF. Подпись в браузере — `node-forge` через importmap (единственная новая JS-зависимость).

---

## 3. Контракты

### 3.1 Enum'ы

`src/Enum/Document/SignatureLevel.php` (новый):
```php
enum SignatureLevel: string {
    case SIMPLE = 'simple';      // ПЭП
    case ENHANCED = 'enhanced';  // УНЭП
    // qualified — зарезервировано, не добавлять сейчас
}
```

`src/Enum/Document/DocumentStatus.php` — добавить кейсы:
```php
case ON_SIGNING = 'ON_SIGNING';  // «На подписании» (UPPERCASE — как существующие кейсы)
case SIGNED = 'SIGNED';          // «Подписан»
```
Правила переходов: `APPROVED → ON_SIGNING` (только автор), `ON_SIGNING → SIGNED` (система, когда подписали все), `ON_SIGNING → REJECTED` (отказ подписанта). В `getReceiverAllowedStatuses()` новые статусы НЕ добавлять — подписание идёт через отдельные эндпоинты, не через смену статуса получателем.

`src/Enum/Document/DocumentRecipientRole.php` — добавить:
```php
case SIGNER = 'signer'; // «Подписант»
```

`src/Enum/Document/CertificateStatus.php` (новый):
```php
enum CertificateStatus: string {
    case ACTIVE = 'active';
    case REVOKED = 'revoked';
}
```
(Истечение срока — вычисляемое свойство по `validTo`, НЕ отдельный статус в БД.)

### 3.2 Сущности

`src/Entity/Document/UserCertificate.php` (новая, таблица `user_certificate`):
- `id`, `user` (ManyToOne User, not null), `serialNumber` (string, unique), `subjectDn` (string),
  `certificatePem` (text), `validFrom` (datetime_immutable), `validTo` (datetime_immutable),
  `status` (CertificateStatus, default ACTIVE), `revokedAt` (nullable), `revocationReason` (string nullable),
  `issuedBy` (ManyToOne User — админ), `createdAt`.
- Приватный ключ НЕ хранится нигде. Поле для него не создавать.

`src/Entity/Document/DocumentSignature.php` (новая, таблица `document_signature`):
- `id`, `document` (ManyToOne Document, not null), `signer` (ManyToOne User, not null),
  `level` (SignatureLevel), `documentHash` (string(64) — hex SHA-256 канонического PDF на момент подписания),
  `signatureValue` (text nullable — base64 RSA-подписи, null для ПЭП),
  `certificate` (ManyToOne UserCertificate, nullable — null для ПЭП),
  `algorithm` (string, для УНЭП = `'RSA-SHA256'`, для ПЭП = `'password-confirmation'`),
  `signedAt` (datetime_immutable), `ipAddress` (string nullable), `userAgent` (string nullable).
- Unique constraint: `(document_id, signer_id)` — один пользователь подписывает документ один раз.

`src/Entity/Document/Document.php` — добавить поля:
- `signatureLevel` (SignatureLevel, nullable — null = документ без подписания),
- `canonicalFile` (string nullable — путь к замороженному PDF),
- `canonicalFileHash` (string(64) nullable),
- `signedFormFile` (string nullable — путь к печатной форме),
- `verificationCode` (string(16) nullable, unique — код для QR/страницы проверки, генерация: `substr(bin2hex(random_bytes(8)), 0, 16)`),
- `sentToSigningAt` (datetime_immutable nullable).

`src/Entity/Document/DocumentUserRecipient.php` — добавить поле:
- `signingOrder` (int nullable). Для роли SIGNER: параллельный режим — у всех `1`; последовательный — `1, 2, 3...`. Для других ролей — null. Право подписи имеет подписант с минимальным `signingOrder` среди ещё не подписавших (при параллельном режиме — все сразу).

### 3.3 Сервисы (namespace `App\Service\Document\Signature\`)

| Сервис | Ответственность | Ключевые методы |
|---|---|---|
| `CertificateAuthorityService` | Внутренний УЦ | `issueCertificate(User $user, string $p12Password, User $issuedBy): IssuedCertificateResult` (генерирует пару, подписывает CA-ключом, собирает .p12, возвращает бинарник .p12 + сохранённый UserCertificate; приватный ключ существует только в памяти запроса); `revoke(UserCertificate $cert, string $reason, User $admin): void` |
| `DocumentFreezeService` | Заморозка файла | `freeze(Document $doc): void` — DOCX→PDF при необходимости (LibreOffice `soffice`, логика переносится из старого контроллера), сохранение в canonical-директорию, `hash_file('sha256')`, запись `canonicalFile`/`canonicalFileHash`/`verificationCode` |
| `SigningService` | Оркестрация подписания | `signSimple(Document, User, string $password): DocumentSignature`; `signEnhanced(Document, User, string $signatureB64, UserCertificate): DocumentSignature`; `decline(Document, User, string $reason): void`. Внутри: проверка статуса ON_SIGNING, роли SIGNER, очереди (`signingOrder`), уровня документа (ПЭП-подпись недопустима на документе уровня `enhanced`; УНЭП на документе уровня `simple` — допустима), создание DocumentSignature, DocumentHistory, уведомления, при полном комплекте — переход в SIGNED + генерация печатной формы |
| `SignatureVerificationService` | Проверка | `verifyDocument(Document): DocumentVerificationResult` — пересчёт хэша canonical-файла, для каждой подписи: сверка `documentHash`, для УНЭП `openssl_verify` по сертификату, проверка статуса/срока сертификата **на момент signedAt** (отзыв ПОСЛЕ подписания не инвалидирует подпись); `findByFileContent(string $binary): ?Document` — поиск по SHA-256 |
| `SignedFormGenerator` | Печатная форма | `generate(Document): string` — FPDI-импорт канонического PDF + лист подписей: по штампу на подписанта («Подписано электронной подписью», уровень, ФИО, дата/время, № сертификата для УНЭП, хэш) + QR (TCPDF `write2DBarcode`, URL = `{APP_URL}/verify/{verificationCode}`). Идемпотентна: пересоздаёт файл при каждом вызове |

DTO результатов — в `src/DTO/Document/Signature/`. Пароль ПЭП проверяется через `UserPasswordHasherInterface::isPasswordValid`.

### 3.4 Конфигурация (env)

```
CA_ROOT_CERT_PATH=/var/ca/root_ca.crt        # вне webroot
CA_ROOT_KEY_PATH=/var/ca/root_ca.key
CA_ROOT_KEY_PASSPHRASE=...                    # секрет
PRIVATE_UPLOAD_DIR_DOCUMENTS_CANONICAL=/uploads/documents/canonical
PRIVATE_UPLOAD_DIR_DOCUMENTS_SIGNED_FORMS=/uploads/documents/signed_forms
APP_PUBLIC_URL=https://...                    # для QR-ссылок
```
Console-команда `app:ca:init` — генерирует корневую пару УЦ (самоподписанный сертификат, 10 лет), отказывается перезаписывать существующую.

### 3.5 SPA API (база `/spa/api/documents-flow`, JWT, конвенции проекта)

| Метод | Путь | Тело | Успех | Коды ошибок |
|---|---|---|---|---|
| POST | `/documents/{id}/send-to-signing` | — | 200, карточка | `document_not_found`, `document_not_approved`, `document_no_signers`, `document_signing_forbidden` (не автор) |
| GET | `/documents/{id}/signatures` | — | 200: `{signatures: [{signer, level, signedAt, certificateSerial, valid, validityDetails}], documentHash, verificationCode, allSigned}` | `document_not_found` |
| POST | `/documents/{id}/sign/simple` | `{password}` | 200 | `document_not_on_signing`, `document_signing_not_signer`, `document_signing_wrong_turn`, `document_signing_already_signed`, `document_signing_level_insufficient`, `invalid_password` |
| GET | `/documents/{id}/sign/challenge` | — | 200: `{documentHash, algorithm: "RSA-SHA256", certificates: [{id, serialNumber, validTo}]}` (активные сертификаты текущего пользователя) | те же + `certificate_not_found` |
| POST | `/documents/{id}/sign/enhanced` | `{certificateId, signature}` (base64 RSA-SHA256 подписи хэш-строки) | 200 | те же + `certificate_revoked`, `certificate_expired`, `invalid_signature` |
| POST | `/documents/{id}/decline-signing` | `{reason}` (обязателен) | 200 | `document_not_on_signing`, `document_signing_not_signer`, `reason_required` |
| POST | `/verify-file` | multipart file | 200: `{found, document?, signatures?}` | `file_too_large`, `file_invalid_type` |
| GET | `/documents/{id}/signed-form` | — | файл печатной формы | `document_not_found`, `signed_form_not_ready` |

**Что именно подписывает браузер (УНЭП), зафиксировано:** RSA-SHA256-подпись **hex-строки хэша как ASCII-байтов** (не бинарного хэша, не файла). Сервер проверяет `openssl_verify($hexHashString, $signature, $certPublicKey, OPENSSL_ALGO_SHA256)`. Это соглашение — контракт между фронтом и бэком, менять нельзя.

Расширение create/update документа: принимать `signatureLevel` (`simple|enhanced|null`), `signers: [{userId, order}]`. Валидация: подписанты требуют `signatureLevel != null` и наоборот.

### 3.6 Twig-маршруты

| Маршрут | Что |
|---|---|
| `/document/{id}` (существующая карточка) | + блок «Подписи», кнопки «Отправить на подпись» / «Подписать» / «Отказаться» |
| `/document/{id}/sign` | Страница подписания: для ПЭП — форма пароля; для УНЭП — file input (.p12) + пароль контейнера, подпись через node-forge, POST на API |
| `/verify` | Страница проверки: загрузка файла → результат |
| `/verify/{code}` | Публичная (для авторизованных) страница проверки по QR-коду |
| `/admin/certificates` | Админка УЦ: список, выпуск (форма: пользователь + пароль .p12 → отдаёт файл на скачивание один раз), отзыв (с причиной). Доступ ROLE_ADMIN |

---

## 4. Фазы и атомарные задачи

Граф зависимостей фаз:
```
Ф0 → Ф1 → { Ф2, Ф6 } → Ф3 → { Ф4, Ф5 } → Ф7
```
Внутри фазы задачи параллелятся, если не указано иное.

### Фаза 0 — Фундамент данных (блокирует всё)

**T0.1 Enum'ы.** Создать `SignatureLevel`, `CertificateStatus`; дополнить `DocumentStatus` (ON_SIGNING, SIGNED + русские лейблы по образцу существующих), `DocumentRecipientRole` (SIGNER).
*Приёмка:* enum'ы соответствуют §3.1, существующие тесты проекта зелёные.

**T0.2 Сущности + миграция.** `UserCertificate`, `DocumentSignature` (новые), поля в `Document` и `DocumentUserRecipient` по §3.2. Репозитории по образцу существующих. Одна Doctrine-миграция.
*Зависит от T0.1.*
*Приёмка:* `doctrine:schema:validate` чистый; миграция накатывается и откатывается; unique-констрейнты `(document_id, signer_id)` и `serial_number` на месте.

### Фаза 1 — Крипто-ядро (чистые сервисы, без HTTP)

**T1.1 УЦ.** `CertificateAuthorityService` + команда `app:ca:init` + env-параметры (§3.3, §3.4). OpenSSL: RSA-2048, X.509 v3, subjectDn из ФИО+login, срок 365 дней, серийник уникальный. `.p12` собирается `openssl_pkcs12_export` с пользовательским паролем.
*Приёмка (юнит-тесты в `tests/Service/Signature/`):* выпущенный сертификат верифицируется против корневого; .p12 открывается тем же паролем и содержит ключ+сертификат; повторный `app:ca:init` отказывается перезаписать; `revoke` меняет статус и пишет причину.

**T1.2 Заморозка и хэш.** `DocumentFreezeService`: перенос DOCX→PDF конвертации (LibreOffice shell-out) из `DocumentSignController::convertDocxToPdfDocument` в сервис; canonical-директория; SHA-256; `verificationCode`.
*Приёмка:* юнит-тест с PDF-фикстурой (файл скопирован, хэш совпадает с `hash_file`); DOCX-путь покрыт тестом, который скипается, если `soffice` недоступен в окружении.

**T1.3 Проверка подписи.** `SignatureVerificationService` (§3.3). Правило: сертификат должен быть действителен (срок + не отозван) **на момент `signedAt`**; текущий отзыв помечается в деталях, но не инвалидирует старую подпись.
*Приёмка (юнит):* корректная подпись → valid; подменённый файл (хэш не сходится) → invalid с причиной `hash_mismatch`; подпись чужим ключом → `invalid_signature`; сертификат, отозванный до `signedAt` → invalid, после `signedAt` → valid с пометкой; ПЭП-запись с несходящимся `documentHash` → invalid.

### Фаза 2 — Процесс подписания (домен)

**T2.1 SigningService.** Полная оркестрация по §3.3: send-to-signing (внутри — вызов Freeze), signSimple, signEnhanced, decline. Все переходы пишут `DocumentHistory` (действия: `sent_to_signing`, `signed`, `signing_declined`, `fully_signed`). Очередь: при последовательном режиме подписывать может только минимальный неподписанный `signingOrder`; decline доступен любому назначенному подписанту в ON_SIGNING.
*Зависит от Ф1 целиком.*
*Приёмка (юнит + функциональные):* матрица кейсов из §3.5 (каждый код ошибки воспроизводится тестом); последовательность: второй не может подписать раньше первого; параллельность: оба подписывают в любом порядке; последний подписант переводит документ в SIGNED; decline → REJECTED.

**T2.2 Уведомления.** Встраивание в существующий Notification/Mercure-механизм (по образцу `notifyStatusChange` в `DocumentRecipientStatusService`): «вам на подпись» (при send-to-signing — всем при параллельном, первому при последовательном), «ваша очередь» (следующему), «документ подписан всеми» (автору), «отказ» (автору + подписантам).
*Зависит от T2.1.*
*Приёмка:* функциональный тест фиксирует создание уведомлений на каждом событии.

**T2.3 Расширение create/update.** `DocumentCreateService`/`DocumentUpdateService`/`DocumentRecipientsService`: приём `signatureLevel` и `signers[{userId, order}]`, валидация связки (§3.5), запрет менять подписантов/уровень после ON_SIGNING.
*Зависит от T0.2 (может идти параллельно T2.1).*
*Приёмка:* функциональные тесты create/update с подписантами; попытка изменить после отправки на подпись → ошибка.

### Фаза 3 — SPA API

**T3.1 Эндпоинты подписания.** Контроллер `src/Controller/SpaApi/DocumentsFlow/DocumentSignatureController.php`: все маршруты §3.5, тонкие — вся логика в сервисах Ф2. Права через существующий `DocumentAccessService`/voter-паттерн.
*Зависит от Ф2.*
*Приёмка:* функциональные тесты на каждый эндпоинт (успех + каждый код ошибки); формат ответов соответствует конвенциям существующих SPA-контроллеров.

**T3.2 Презентация.** `DocumentApiPresenter`: в карточку документа добавить `signatureLevel`, `signers` (с порядком и статусом подписи), `signaturesSummary`, `canSign`/`canSendToSigning`/`canDeclineSigning` для текущего пользователя.
*Приёмка:* тест сериализации карточки в статусах APPROVED/ON_SIGNING/SIGNED.

**T3.3 Документация API.** Дополнить `dev_docks/spa_api/document/Readme_documents_flow.txt` (в стиле существующего файла): все эндпоинты, тела, коды ошибок, сценарии для Next.js-команды, включая точное описание того, что подписывает браузер (§3.5, контракт про hex-строку) и рекомендацию использовать node-forge.
*Приёмка:* документ покрывает 100% эндпоинтов §3.5.

### Фаза 4 — Twig UI

**T4.1 Подписание УНЭП в браузере.** `node-forge` через importmap; JS-модуль `assets/js/document_sign.js`: file input → `forge.pkcs12.pkcs12FromAsn1` с паролем → извлечение ключа → `md.sha256` + RSA-подпись hex-строки хэша (контракт §3.5) → POST `sign/enhanced`. Ключ и пароль не покидают браузер, после подписания ссылки на ключ обнуляются. Ошибки: неверный пароль контейнера, файл не .p12 — человекочитаемо.
*Зависит от Ф3.*
*Приёмка:* ручной сценарий: выпуск сертификата → скачивание .p12 → подписание документа с «флешки» → подпись валидна на карточке. Автотест: юнит на JS не требуем; серверная сторона уже покрыта Ф2/Ф3.

**T4.2 Карточка и страница подписания.** Блок «Подписи» на карточке документа (список подписантов, статусы, валидность, кнопки по правам), страница `/document/{id}/sign` (форма ПЭП-пароля ИЛИ .p12-флоу по уровню), кнопка «Отправить на подпись» для автора в APPROVED, форма отказа с причиной. Стиль — по существующим шаблонам `templates/document/`.
*Зависит от T4.1 (общий JS), может стартовать параллельно по разметке.*
*Приёмка:* полный сценарий ПЭП и УНЭП проходит из UI; отказ с причиной виден в истории.

**T4.3 Формы создания.** В Twig-формы создания/редактирования документа: выбор уровня подписи, выбор подписантов, переключатель параллельно/последовательно (порядок — drag или числовые поля, по простейшему пути).
*Приёмка:* созданный из UI документ содержит корректные `signatureLevel`/`signingOrder`.

### Фаза 5 — Печатная форма и проверка

**T5.1 Генератор печатной формы.** `SignedFormGenerator` по §3.3: FPDI + лист подписей + QR (TCPDF `write2DBarcode`, `QRCODE,M`). Вызывается автоматически при переходе в SIGNED (из SigningService) и лениво при запросе `signed-form`, если файл отсутствует.
*Зависит от Ф2.*
*Приёмка:* юнит-тест: сгенерированный PDF существует, страниц больше, чем в оригинале; QR-URL содержит `verificationCode`; повторная генерация идемпотентна.

**T5.2 Эндпоинт и страницы проверки.** SPA `POST /verify-file` + `GET .../signed-form`; Twig `/verify` (загрузка файла) и `/verify/{code}` (по QR): документ, список подписей, итог проверки `SignatureVerificationService` на лету. Загрузка файла: лимит 9 МБ (как в проекте), файл не сохраняется — только хэш в памяти.
*Зависит от T1.3, Ф3.*
*Приёмка:* функциональные тесты: подписанный файл находится по содержимому; изменённый файл — «не найден/не сходится»; страница по коду отображает валидные подписи.

### Фаза 6 — Админка УЦ (параллельно Ф2+)

**T6.1 Админ-раздел сертификатов.** Twig `/admin/certificates` (§3.6): список (фильтр по статусу/сроку), выпуск (пользователь + пароль .p12; ответ — скачивание файла; повторное скачивание невозможно), отзыв с причиной, перевыпуск (= отзыв + выпуск). Все действия — в `DocumentHistory`-подобный аудит (использовать отдельные записи? — нет: логировать через стандартный канал monolog `ca` + поля issuedBy/revokedAt в сущности, этого достаточно).
*Зависит от T1.1.*
*Приёмка:* функциональные тесты: выпуск доступен только ROLE_ADMIN; отозванным сертификатом подписать нельзя (интеграция с Ф2 — тест после её готовности).

### Фаза 7 — Финализация

**T7.1 Удаление прототипа.** Удалить `DocumentSignController.php`, `templates/document_sign/`, `templates/document/sign_document.html.twig`, `test.html.twig`, демо-файлы `public/files/dummy_*.pdf`, `word.docx` и маршруты. Убедиться, что конвертация DOCX→PDF уже живёт в `DocumentFreezeService` (T1.2).
*Зависит от Ф4 (UI-замена готова).*
*Приёмка:* grep по проекту не находит ссылок на удалённые маршруты/шаблоны; smoke существующих страниц документов.

**T7.2 Мониторинг.** Изучить `docker-compose.monitoring.yml` и существующий стек; добавить метрики: счётчик подписаний по уровням, счётчик отказов, счётчик ошибок проверки (`invalid_signature`/`hash_mismatch`), gauge сертификатов, истекающих в 30 дней. Реализация — по средствам имеющегося стека (если Prometheus — экспорт эндпоинтом; если только логи — структурированные monolog-записи канала `signature` + алерт-правила описать в README монorінга).
*Приёмка:* метрики видны в стеке мониторинга либо (fallback) структурированные логи пишутся и задокументированы.

**T7.3 Итоговая документация.** `dev_docks/signature/Readme_signature.txt`: архитектура, схема данных, процедуры (init УЦ, выпуск/отзыв, восстановление при утере флешки, ротация корневого ключа), env-переменные, ограничения (что НЕ является УКЭП), инструкция пользователя (как подписать с флешки).
*Приёмка:* новый разработчик по документу может развернуть УЦ и пройти полный цикл.

---

## 5. Правила для суб-агентов

1. **Контракты §3 неизменяемы.** Обнаружил противоречие с реальным кодом — стоп, доклад арбитру, не импровизировать.
2. **Стиль проекта обязателен:** feature-папки `App\<Layer>\Document`, тонкие контроллеры, сервисы с конструкторной инъекцией, коды ошибок snake_case как в существующем SPA API, русские лейблы enum'ов по образцу.
3. **Никаких новых composer-зависимостей.** Крипто — `ext-openssl`, PDF — FPDI/TCPDF, QR — TCPDF. Единственная новая JS-зависимость — `node-forge` (importmap).
4. **Приватные ключи пользователей не сохраняются** ни в файлах, ни в БД, ни в логах. Пароли (.p12 и учётки) не логировать. `.p12` отдаётся один раз в ответе на выпуск.
5. **Замороженные файлы не перезаписываются.** Любая генерация — только новые артефакты (печатная форма).
6. Каждая задача завершается прогоном затронутых тестов; фаза — полным прогоном `bin/phpunit`.
7. Миграции — только в Ф0 (T0.2). Если позже потребуется поле — возврат к арбитру.

## 6. Definition of Done (весь проект)

- [ ] Полный цикл ПЭП: создание → согласование → отправка на подпись → подпись паролем → SIGNED → печатная форма с QR.
- [ ] Полный цикл УНЭП: выпуск .p12 админом → подписание файлом «с флешки» → криптопроверка валидна.
- [ ] Последовательный маршрут: второй подписант не может подписать раньше первого.
- [ ] Отказ → REJECTED с причиной в истории и уведомлениями.
- [ ] Подмена файла детектируется на всех трёх поверхностях проверки.
- [ ] Отозванный сертификат: новые подписи невозможны, старые остаются валидными.
- [ ] Прототип удалён, документация (SPA API + Readme_signature) написана, тесты зелёные.
