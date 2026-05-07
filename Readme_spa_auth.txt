================================================================================
АВТОРИЗАЦИЯ SPA ЧЕРЕЗ JWT
================================================================================

В проекте параллельно работают две схемы авторизации:

  1. Сессионная (cookie) — для SSR-страниц на Twig и существующих AJAX-роутов
     `/api/*` (Kanban, Chat, Notification). Без изменений.

  2. JWT — для SPA, под URL-префиксом `/spa/api/*`. Описана в этом документе.


--------------------------------------------------------------------------------
СТЕК
--------------------------------------------------------------------------------

  - lexik/jwt-authentication-bundle  — выпуск и проверка JWT (RS256)
  - gesdinet/jwt-refresh-token-bundle — refresh-токены, хранятся в БД
                                        (таблица `refresh_tokens`,
                                         сущность App\Entity\RefreshToken)


--------------------------------------------------------------------------------
КЛЮЧИ
--------------------------------------------------------------------------------

Файлы PEM-ключей лежат в `config/jwt/`:

  config/jwt/private.pem   — подписывает токены
  config/jwt/public.pem    — проверяет подпись

Оба файла в `.gitignore` и в репозитории отсутствуют. На каждом окружении
ключи генерируются один раз командой:

  php bin/console lexik:jwt:generate-keypair

В `.env.local` обязательно должна быть переменная `JWT_PASSPHRASE` —
пароль, которым зашифрован private.pem. Без неё подпись не будет работать.

Внимание: при перевыпуске ключей все ранее выданные JWT моментально
становятся невалидными. Это нормально — пользователи перелогинятся.


--------------------------------------------------------------------------------
ЭНДПОИНТЫ
--------------------------------------------------------------------------------

  POST /spa/api/login_check       Логин по login + password.
                                  Возвращает { token, refresh_token }.

  POST /spa/api/token/refresh     Обмен refresh-токена на новый JWT.
                                  Старый refresh-токен инвалидируется
                                  (включён single_use), выдаётся новый.

  POST /spa/api/logout            Удаление refresh-токена из БД.
                                  Полезно для серверного отзыва доступа.
                                  JWT остаётся валидным до своего exp —
                                  фронт должен сам его выкинуть.

  GET  /spa/api/me                Возвращает данные текущего пользователя
                                  (id, login, roles). Требует Bearer JWT.

Любой другой `/spa/api/*` эндпоинт по умолчанию требует валидный JWT
(access_control: `^/spa/api  →  ROLE_USER`). Конкретные роли проверяются
атрибутом `#[IsGranted(...)]` в контроллерах.


--------------------------------------------------------------------------------
ПРИМЕРЫ ЗАПРОСОВ
--------------------------------------------------------------------------------

# Логин
curl -X POST http://localhost:8080/spa/api/login_check \
  -H "Content-Type: application/json" \
  -d '{"login":"admin","password":"1234"}'

→ 200
{
  "token": "eyJ0eXAi...",
  "refresh_token": "5b89d0a7..."
}


# Защищённый запрос
curl http://localhost:8080/spa/api/me \
  -H "Authorization: Bearer eyJ0eXAi..."

→ 200
{ "id": 1, "login": "admin", "roles": ["ROLE_ADMIN"] }


# Обновление JWT (старый refresh уходит, выдаётся новый)
curl -X POST http://localhost:8080/spa/api/token/refresh \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"5b89d0a7..."}'

→ 200
{
  "token": "eyJ0eXAi...",
  "refresh_token": "новый_refresh"
}


# Логаут
curl -X POST http://localhost:8080/spa/api/logout \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"новый_refresh"}'

→ 200
{ "code": 200, "message": "The supplied refresh_token has been invalidated." }


--------------------------------------------------------------------------------
TTL И РОТАЦИЯ
--------------------------------------------------------------------------------

  JWT (access)    — 1 час          (дефолт Lexik, параметр token_ttl)
  Refresh         — 1 месяц        (дефолт Gesdinet, параметр ttl)
  Single-use      — включён        старый refresh после /refresh инвалиден

Дефолтные значения TTL используются из коробки — явно в конфигах не
прописаны. Если нужно изменить:

  config/packages/lexik_jwt_authentication.yaml:
      token_ttl: 3600   # секунды, 3600 = 1 час

  config/packages/gesdinet_jwt_refresh_token.yaml:
      ttl: 2592000      # секунды, 2592000 = 30 дней

Single-use означает: на каждый успешный `/token/refresh` старая запись
в `refresh_tokens` удаляется, выдаётся новая. Это защита от утечки —
украденный refresh сработает только один раз. Частота ротации = частота
вызовов /refresh = JWT TTL (т.е. раз в час для активного пользователя).


--------------------------------------------------------------------------------
PAYLOAD JWT
--------------------------------------------------------------------------------

{
  "iat": 1778061030,             // issued at
  "exp": 1778064630,             // expires
  "username": "admin",           // = поле `login` пользователя
  "roles": ["ROLE_ADMIN"]
}

JWT подписан, но НЕ зашифрован — содержимое видно любому держателю.
Поэтому в payload не кладутся секреты, ПДн и динамика
(email, ФИО, баланс, статусы). Для свежих данных — отдельный запрос /me.


--------------------------------------------------------------------------------
КОНФИГ-ФАЙЛЫ (где что лежит)
--------------------------------------------------------------------------------

  config/packages/security.yaml
      — три firewall'а: refresh_jwt (token/refresh + logout), spa_api (всё
        остальное под /spa/api), main (SSR и старый /api).
        Порядок важен: специфичные сверху.
        У refresh_jwt: invalidate_token_on_logout: true — без этого Symfony
        возвращает 302 редирект вместо JSON при /logout.
        У spa_api: login_throttling (5 попыток / 15 минут).
        В access_control: login_check/refresh/logout — PUBLIC_ACCESS,
        весь остальной ^/spa/api — ROLE_USER (требует валидный JWT).

  config/packages/lexik_jwt_authentication.yaml
      — ссылки на файлы ключей и passphrase.
        Дефолтный token_ttl = 3600 секунд (1 час) используется неявно.

  config/packages/gesdinet_jwt_refresh_token.yaml
      — refresh_token_class и single_use.
        Дефолтный ttl = 2592000 секунд (30 дней) используется неявно.

  config/routes/spa_api.yaml
      — роуты /spa/api/login_check и /spa/api/logout.
        Контроллеров у них нет — обработка внутри firewall'а.

  config/routes/gesdinet_jwt_refresh_token.yaml
      — роут /spa/api/token/refresh.

  src/Controller/SpaApi/
      — все бизнес-контроллеры под JWT.
        Не путать с src/Controller/Api/ — там старые сессионные эндпоинты.

  src/Entity/RefreshToken.php
      — наследник базовой сущности Gesdinet.


--------------------------------------------------------------------------------
КАК ДОБАВИТЬ НОВЫЙ ЭНДПОИНТ /spa/api/...
--------------------------------------------------------------------------------

1. Создать контроллер в src/Controller/SpaApi/, например:

   <?php
   namespace App\Controller\SpaApi;

   use App\Entity\User\User;
   use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
   use Symfony\Component\HttpFoundation\JsonResponse;
   use Symfony\Component\Routing\Attribute\Route;
   use Symfony\Component\Security\Http\Attribute\CurrentUser;
   use Symfony\Component\Security\Http\Attribute\IsGranted;

   final class DocumentsController extends AbstractController
   {
       #[Route('/spa/api/documents', methods: ['GET'])]
       #[IsGranted('ROLE_USER')]
       public function list(#[CurrentUser] User $user): JsonResponse
       {
           // ...
       }
   }

2. Никаких правок в security.yaml/yaml-routes делать не надо —
   firewall и access_control уже покрывают весь префикс /spa/api/*.


--------------------------------------------------------------------------------
CORS
--------------------------------------------------------------------------------

Не настраивается — SPA живёт на том же домене и порту, что и Symfony,
браузер не считает запрос cross-origin. Если когда-нибудь SPA переедет
на отдельный домен (api.example.ru / app.example.ru) — потребуется
nelmio/cors-bundle с конфигом, ограниченным префиксом /spa/api/.


--------------------------------------------------------------------------------
RATE LIMITING (защита от брутфорса)
--------------------------------------------------------------------------------

На firewall `spa_api` включён `login_throttling`. Защищает /login_check
от перебора пароля. Refresh-токены защищать не нужно — они длиной 128
случайных символов, перебрать невозможно.

Конфиг (config/packages/security.yaml):

    spa_api:
        ...
        login_throttling:
            max_attempts: 5
            interval: '15 minutes'

Как работает:

  - Считаются ТОЛЬКО неудачные попытки. Успешный логин счётчик не
    инкрементит, пользователь не блокируется после верного входа.
  - Два независимых счётчика:
      по (IP + login)  →  лимит 5 / 15 мин   — защита конкретной учётки
      по IP            →  лимит 25 / 15 мин  — защита от перебора логинов
                                               (max_attempts * 5)
  - При превышении: HTTP 429
        { "code": 429, "message": "Too many failed login attempts,
                                   please try again in 900 seconds." }
  - Через 15 минут счётчик сбрасывается. Это НЕ блокировка учётки —
    пробовать можно снова. Сделано так специально, чтобы атакующий
    не мог DOS'ить чужой аккаунт, забивая счётчик.

Хранилище счётчика — Symfony cache (cache.app, по умолчанию файлы).
Для прод-кластера из нескольких php-fpm контейнеров надо переключить
framework.rate_limiter на shared backend (Redis), иначе у каждого узла
свои счётчики и реальный лимит = N × max_attempts.

Не защищает от распределённого брутфорса (тысячи IP по 1 попытке) —
это задача WAF/CDN уровня (Cloudflare и т.п.).

Проверка работы rate limiting (после php bin/console cache:clear):

    for i in 1 2 3 4 5 6; do
      echo -n "попытка $i → "
      curl -s -o /dev/null -w "%{http_code}\n" \
        -X POST http://localhost:8080/spa/api/login_check \
        -H "Content-Type: application/json" \
        -d '{"login":"admin","password":"wrong"}'
    done

Ожидаемое поведение:
  - попытки 1–5 → 401 Invalid credentials
  - попытка 6 → 429 Too Many Failed Login Attempts

Счётчик сбрасывается через 15 минут. Для быстрого сброса (повторный
тест) — php bin/console cache:clear.


--------------------------------------------------------------------------------
TODO И KNOWN ISSUES
--------------------------------------------------------------------------------

[ ] HTTPS в проде — обязательно. JWT летит в Authorization header
    в открытом виде, без TLS перехватывается MITM.

[ ] Перенести JWT_PASSPHRASE из .env в .env.local — семантически
    .env.local предназначен для локальных секретов, .env — для общих
    дефолтов. Сейчас оба в gitignore, утечки нет, но смысл правильный.

[ ] Cron на чистку просроченных refresh-токенов:
        php bin/console gesdinet:jwt:clear
    Команда удаляет записи из refresh_tokens, у которых поле valid < NOW().
    Просроченные токены не представляют угрозы безопасности (они уже не
    работают), но копятся в БД. Без чистки таблица растёт со временем.
    Рекомендуется запускать раз в сутки через cron.

[ ] Тесты на auth (WebTestCase) — login/me/refresh/logout. Сейчас
    функциональных тестов нет.

[ ] Решить процесс деплоя ключей JWT:
      - генерировать на проде один раз вручную и сохранить как секрет, ИЛИ
      - регенерировать каждый деплой (инвалидирует все JWT, пользователи
        перелогинятся)
    Бэкап config/jwt/ обязателен — без private.pem refresh-токены
    в БД становятся бесполезны.

[ ] Если в кластере несколько php-fpm — переключить framework.rate_limiter
    на Redis (shared backend для счётчиков login_throttling).

[!] Гонка single_use при параллельных запросах — известная проблема SPA:
    JWT истёк, фронт делает 2+ параллельных запроса → оба триггерят
    /token/refresh с тем же токеном → один успех, второй 401 → юзера
    выкидывает. Решается ТОЛЬКО на фронте — очередью refresh-запросов
    в HTTP-клиенте (axios interceptor / fetch wrapper). На бэке
    исправить нельзя без потери single_use.

[!] Свежесть ролей и блокировки пользователя — JWT валиден до своего
    exp (1 час), даже если в БД роль убрали или юзера заблокировали.
    Это цена stateless-подхода. Если критично — снизить token_ttl
    или для критичных операций реализовать кастомный voter, который
    перечитывает User из БД.


--------------------------------------------------------------------------------
ОТЛАДКА
--------------------------------------------------------------------------------

  php bin/console debug:firewall                     — список firewall'ов
  php bin/console debug:router | grep spa_api        — список SPA-роутов
  php bin/console debug:router | grep gesdinet       — refresh-роут

Профайлер для запросов с Bearer-токеном доступен по X-Debug-Token-Link
из заголовков ответа (в dev-окружении).
