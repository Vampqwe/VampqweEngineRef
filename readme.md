# VampqweEngineMedium

> **Note for AI agents / разработчиков:** этот документ — полное техническое описание проекта.
> Он написан так, чтобы ИИ-ассистент (LLM) мог прочитать его один раз и безопасно вносить изменения
> в кодовую базу без дополнительного исследования архитектуры. Все утверждения ниже соответствуют
> фактическому состоянию репозитория.

---

## 1. TL;DR (кратко для машины)

| Факт | Значение |
|---|---|
| Тип проекта | Собственный (proprietary) PHP-движок + мини-CMS, **не** фреймворк из пакунов |
| Язык / версия | PHP `>= 8.1`, `declare(strict_types=1)` обязателен во всех файлах |
| Зависимости | **Нет composer-пакетов** (`vendor/` содержит только автозагрузчик). Только `ext-dom` |
| Autoload | PSR-4: `Core\System\` → `core/classes/system/`, `Core\Module\` → `core/classes/module/`, плюс `core/helpers.php` через `files` |
| Точка входа | `index.php` (front controller), роутинг — собственный `Router` |
| БД | **Только MySQL/MariaDB** (PDO). SQLite — зарезервированный драйвер, НЕ использовать |
| Конфигурация | Один файл `config.env` в корне (KEY=VALUE), читается классом `Config` |
| Админка | `/admin` (login, dashboard, CRUD страниц, импорт HTML, редактор меню) |
| Установщик | Веб-мастер `/admin/install` (5 шагов) + CLI `bin/install-key.php` |
| Шаблоны | Собственный компилятор Blade-подобных директив в `views/**.html`, кэш в `storage/cache/templates/` |
| Лицензия | Proprietary, © 2024–2026 Дедюля А.И. Копирование запрещено |

---

## 2. Структура каталогов

```
/                       # корень = webroot (Apache DocumentRoot)
├── index.php           # front controller: Application::init(__DIR__)->run(...)
├── router.php          # роутер для `php -S` (эмуляция .htaccess для dev-сервера)
├── .htaccess           # Apache: deny для core/vendor/storage/logs/views/.git, только index.php исполняем
├── config.env.example  # ШАБЛОН конфига → скопировать в config.env и заполнить
├── config.env          # РЕАЛЬНЫЙ конфиг (в .gitignore, никогда не коммитить)
├── composer.json       # только autoload + php>=8.1 + ext-dom
├── bin/
│   └── install-key.php # CLI: generate/revoke ключ активации мастера установки
├── config/             # PHP-конфиги подсистем (не env!)
│   ├── admin-dashboard.php
│   ├── html-sanitizer.php   # allowlist тегов/атрибутов для HtmlSanitizer
│   ├── import-parser.php    # настройки HtmlImportParser
│   └── page-content.php     # настройки контента страниц
├── core/
│   ├── helpers.php     # глобальные хелперы в namespace Core (см. §7)
│   └── classes/
│       ├── system/     # ядро движка (namespace Core\System\*)
│       └── module/     # бизнес-модули (namespace Core\Module\*)
├── database/
│   └── structure.sql   # DDL: только CREATE TABLE, без INSERT
├── views/              # HTML-шаблоны (компилятор см. §6)
│   ├── layouts/main.html          # публичный layout
│   ├── pages/                     # home, article, hub, 404, dev-preview
│   ├── partials/menu.html
│   └── admin/                     # login, install/step1..5, pages, menu, import, layouts/admin.html
├── assets/             # статика: css/, js/ (admin.js, main.js, menu-editor.js), fonts/
├── storage/            # runtime: cache/templates/, .rate_limits/, .install_key, uploads/import/
├── logs/               # app.log, audit.log (пишет Logger/AuditLogger)
├── vendor/             # composer autoloader (без пакетов)
└── .osp/project.ini    # конфиг shared-хостинга HOSTING (1C-Bitrix-панель .osp)
```

### Система namespace'ов (строго)

- `Core\System\` → `core/classes/system/` — переиспользуемое ядро, НЕ знает о контенте CMS.
- `Core\Module\` → `core/classes/module/` — прикладные модули (Admin, Auth, Install, Page, Directory, File).
- Пространство имён внутри `system` дублирует папки: `Core\System\Http\Router` = `core/classes/system/Http/Router.php`.

---

## 3. Жизненный цикл запроса (request flow)

1. Apache/Nginx/php -S направляет все запросы (кроме существующей статики) в `index.php`.
2. `index.php`: `require vendor/autoload.php`, затем
   `Application::init(__DIR__)->run(fn(Application $app) => $app->make(Router::class)->dispatch()->send());`
3. `Application::bootstrap()`:
   - регистрирует emergency error handler (до полноценного `ErrorHandler`);
   - загружает `Config` из `config.env`;
   - создаёт DI-контейнер (`Core\System\Container\Container`);
   - регистрирует и бутит **ServiceProviders** (см. §4);
   - `InstalledGuard` определяет состояние движка: `installed` / `not_installed` / `db_unreachable`
     (enum `EngineState`). Если не установлен — включается InstallGateMiddleware и отдаётся страница
     приглашения на `/admin/install`.
4. `Router::dispatch()` матчит URI → `Request` → middleware stack → closure-controller → `Response::send()`.

### Ключевые классы ядра (все в `core/classes/system/`)

| Класс | Путь | Назначение |
|---|---|---|
| `Application` | `Application/Application.php` | singleton-загрузчик, container, bootstrap providers |
| `Container` | `Container/Container.php` | DI: `singleton()`, `bind()`, `get()`, `has()`; автоматически разрешает конструктор-зависимости |
| `Config` | `Config/Config.php` | парсер `config.env` (KEY=VALUE, кавычки, комментарии); поиск файла детерминирован от base_path, НЕ от getcwd |
| `Router`, `Route`, `RouteGroup` | `Http/` | маршрутизация с параметрами `{id}`, `group('/prefix', $middleware)`, `fallback()` |
| `Request` / `Response` | `Http/` | обёртки над superglobals; `Response::json/html/redirect`, `setHeader()`, `send()` |
| `MiddlewareStack`, `Middleware` | `Http/` | цепочка middleware |
| `CsrfMiddleware` | `Http/` | проверка токена `_csrf_token` на POST |
| `DataBase` | `DataBase/DataBase.php` | PDO-обёртка: `query/fetchAll/fetchOne/fetchColumn/insert/execute/transaction(callable)`, reconnect, таймаут подключения |
| `SqlSanitizer` | `Security/SqlSanitizer.php` | дополнительная защита SQL (принудительно prepared statements, `DB_ENFORCE_PREPARED=true`) |
| `Template`, `TemplateCompiler`, `TemplateCache`, `Section` | `Template/` | компиляция `.html` шаблонов в PHP, кэш в `storage/cache/templates/` |
| `Logger`, `LogLevel` | `Logger/` | file-логгер с ротацией (`LOG_MAX_SIZE`) и маскировкой секретов |
| `AuditLogger` | `Security/AuditLogger.php` | отдельный аудит-лог (`logs/audit.log`) |
| `Validator` + `Rules/*` | `Validator/` | fluent-валидатор: required, email, minLength, maxLength, numeric, between, in, regex, url, date, custom |
| `FileSystem`, `PathProbe` | `FileSystem/` | файловые операции с базовым путём `APP_ROOT_PATH` (через `Config::getRootPath()`), правами, лимитом размера |
| `SecurityManager`, `CsrfToken`, `RateLimiter`, `SecurityHeaders`, `TrustedProxy`, `PathSanitizer`, `SensitiveDataMasker`, `HtmlSanitizer` | `Security/` | единая точка безопасности |
| `SessionManager` | `Session/` | безопасные PHP-сессии (strict mode, HttpOnly, SameSite, Secure) |
| `TimeDate`, `DateFormat`, `TimeUnit` | `TimeDate/` | дата/время по `TIMEZONE` |
| `Map`, `SortOrder` | `Map/` | типизированная упорядоченная карта (служебная структура данных) |
| `ErrorHandler`, `ErrorException`, `ErrorPage` | `Error/` | перехват throwable → красивая 500 + лог |
| `InstalledGuard`, `EngineState` | `Application/` | определение состояния установки без бросания исключений при мёртвой БД |
| `ProjectChecks` | `Diagnostics/ProjectChecks.php` | диагностика окружения (используется мастером установки и dev-preview) |

---

## 4. ServiceProviders (ядро регистрации)

Все в `core/classes/system/ServiceProvider/`, наследуют абстрактный `ServiceProvider`
(`register()` — binds в контейнер, `boot()` — регистрация маршрутов/настроек).
Порядок подключения задан в `Application::bootstrap()`:

| Провайдер | Что делает |
|---|---|
| `ConfigServiceProvider` | грузит `config.env` |
| `DataBaseServiceProvider` | singleton `DataBase` (MySQL, charset utf8mb4, connect timeout) |
| `FileSystemServiceProvider` | singleton `FileSystem` c корнем `APP_ROOT_PATH` |
| `LoggerServiceProvider` | singleton `Logger` |
| `SecurityServiceProvider` | `SecurityManager` (CSRF, rate limit, headers, audit, trusted proxies) |
| `HttpServiceProvider` | `Request` из globals, `Response`, cookie |
| `RouterServiceProvider` | singleton `Router` + **публичные маршруты** (health, storage files, sitemap, robots, fallback-CMS) |
| `TemplateServiceProvider` | `Template` + компилятор + кэш |
| `ValidatorServiceProvider` | `Validator` |
| `TimeDateServiceProvider` | `TimeDate` |
| `HtmlSanitizerServiceProvider` | `HtmlSanitizer` с allowlist из `config/html-sanitizer.php` |
| `PageServiceProvider` | сервисы страниц, меню, редиректов и sitemap |
| `AdminServiceProvider` | **маршруты админки и мастера установки** (см. §5) |

**Как добавить маршрут:** правьте соответствующий провайдер (`RouterServiceProvider` для публичных,
`AdminServiceProvider` для админских). Маршруты — замыкания, получающие `Request` и возвращающие `Response`.
Внутри замыкания берите зависимости через `$container->get(X::class)`. Пример:

```php
$api = $router->group('/api');
$api->get('/health', function () use ($container): Response {
    return Response::json(['status' => 'alive'], StatusCode::OK);
})->name('api.health');
```

⚠️ Исторический баг (зафиксирован в комментариях кода): в замыкании нельзя писать `use ($container)`
до локального присваивания `$container = $this->container;` — это роняло весь bootstrap в HTTP 500.

---

## 5. Таблица маршрутов (фактические)

### Публичные (`RouterServiceProvider`)

| Метод | Путь | Handler / описание |
|---|---|---|
| GET | `/api/health` | liveness: всегда 200, `{status:"alive", state, time}`, заголовок `X-Engine-State`. НЕ трогает БД |
| GET | `/api/health/ready` | readiness: проверяет метку установки + MySQL; при проблемах 503 + `Retry-After: 10` |
| GET | `/storage/{file}` | отдача файлов из `storage/` (нейтральный CSS импорта, загруженные картинки). `.htaccess` закрыл /storage от веб-сервера, поэтому файлы раздаёт движок |
| GET | `/sitemap.xml` | `SitemapController` (генерируется из таблиц pages/menu) |
| GET | `/robots.txt` | `RobotsController` |
| GET | `/__dev/preview` | dev-превью шаблонов. Только loopback + `DEV_PREVIEW_ENABLED=true`. В проде держать false |
| POST | `/__dev/preview/run` | то же, выполнение превью |
| ANY | fallback `/{slug}` | `PageController`: ЧПУ-страницы из БД, редиректы из таблицы `redirects`, рендер через `Template` + `layouts/main.html`; 404 → `views/pages/404.html` |

### Установка и админка (`AdminServiceProvider`)

Мастер `/admin/install` защищён `InstallAccessMiddleware` (ключ `?key=` или заголовок `X-Install-Key`,
значение `INSTALL_KEY` из config.env; хэш ключа хранится в `storage/.install_key`).

| Метод | Путь | Описание |
|---|---|---|
| GET | `/admin/install` | Шаг 1 — проверка окружения (PHP, права, PDO) |
| POST | `/admin/install/step1` | проверка параметров БД → редирект на step2 |
| GET/POST | `/admin/install/step2` | применение `database/structure.sql` |
| GET/POST | `/admin/install/step3` | первичные данные (супер-админ и пр.) |
| GET/POST | `/admin/install/step4` | финальная конфигурация (запись боевого config) |
| GET/POST | `/admin/install/step5` | завершение, отзыв установочного ключа |

Авторизация и кабинет (защищены `AdminMiddleware` + `AuthMiddleware`; CSP — `AdminCspMiddleware`):

| Метод | Путь | Контроллер |
|---|---|---|
| GET/POST | `/admin/login` | `AuthController::showLogin/login` (bcrypt, SUPER_ADMIN из config.env, rate limiting, CSRF) |
| POST | `/admin/logout` | `AuthController::logout` |
| GET | `/admin/` | `DashboardController::index` |
| GET | `/admin/pages` | `PagesController::index` (пагинация `ADMIN_PER_PAGE`, `?per_page=` до 100) |
| GET | `/admin/pages/new` | create |
| POST | `/admin/pages/store` | store |
| GET | `/admin/pages/{id}/edit` | edit |
| POST | `/admin/pages/{id}/update` | update |
| POST | `/admin/pages/{id}/delete` | destroy |
| GET | `/admin/import` | `ImportController::index` — импорт HTML-архивов |
| POST | `/admin/import/upload` | upload (распарсивает `HtmlImportParser`: нейтрализует CSS, скачивает картинки в `storage/uploads/import/`) |
| POST | `/admin/import/confirm` | confirm |
| GET | `/admin/menu` | `MenuController::index` — drag-and-drop редактор (`assets/js/menu-editor.js`) |
| POST | `/admin/menu/items` | store (важно: статический путь регистрируется раньше шаблонного `/menu/items/{id}`) |
| POST | `/admin/menu/structure` | сохранение дерева |
| POST | `/admin/menu/items/{id}` | update |
| POST | `/admin/menu/items/{id}/delete` | destroy |

`SuperAdminMiddleware` — отдельный уровень прав для супер-администратора (`Core\Module\Auth\Services\SuperAdminAuth`).

---

## 6. База данных

DDL: `database/structure.sql` (только CREATE TABLE + индексы + FK, без данных).
Таблицы (utf8mb4_unicode_ci, InnoDB):

| Таблица | Назначение | Ключевые поля |
|---|---|---|
| `pages` | ЧПУ-страницы CMS | `title`, `slug` (UNIQUE), `content` longtext, SEO/OG-поля (`og_title`, description, keywords, meta_robots, canonical) |
| `menu_items` | дерево меню | `title`, parent_id/порядок |
| `authors` | авторы | `slug` (UNIQUE) |
| `redirects` | редиректы старых URL | source → target |
| `schema_org` | микроразметка Schema.org | JSON-LD по страницам |
| `tasks` | служебные задачи | `title` |
| `users` | администраторы (обычные) | bcrypt-хэш пароля |

Важно:
- Супер-админ **не хранится в БД** — он живёт в `config.env` (`SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD` = bcrypt-хэш cost 12). Его нельзя удалить через админку.
- Все обращения к БД — только через `DataBase` с prepared statements (`DB_ENFORCE_PREPARED=true`). Никаких конкатенаций SQL.
- Пароль генерировать так: `php -r "echo password_hash('ПАРОЛЬ', PASSWORD_BCRYPT, ['cost' => 12]);"`

---

## 7. Глобальные хелперы (`core/helpers.php`, namespace `Core`)

Регистрируются composer `files`-autoload. Основные:

- Ответы: `response()`, `json()`, `redirect()`, `download()`, `abort()`
- Запрос: `request()`, `input()`, `query()`, `post()`, `header()`, `cookie()`
- Контейнер/конфиг: `app()`, `config()`, `base_path()`, `storage_path()`, `logs_path()`
- Сервисы: `logger()`, `db()`, `now()`, `validator()`, `security()`, `audit()`, `error_handler()`
- CSRF: `csrf_token()`, `csrf_field()`, `csrf_meta()`, `validate_csrf()`
- Прочее: `rate_limit()`, `sanitize_path()`, `mask_sensitive()`

В шаблонах доступны переменные вида `app_name`, `base_url` (передаются контроллерами в `Template::render`).

---

## 8. Шаблонизатор (Blade-подобный)

Файлы: `views/**/*.html`. Компилятор: `TemplateCompiler` → PHP-код в `storage/cache/templates/{md5}.php`
(кэш включён `TEMPLATE_CACHE=true`; максимальная глубина `@extends/@include` — `TEMPLATE_MAX_RENDER_DEPTH=10`).

Директивы:

| Синтаксис | Смысл |
|---|---|
| `{{ expr }}` | экранированный вывод (dot-нотация: `page.title`) |
| `@extends('layouts.main')` | наследование layout |
| `@section('title') ... @endsection` | определение секции |
| `@yield('title', 'default')` | вставка секции с дефолтом |
| `@include('partials.menu', [...])` | подключение partials с данными |
| `@csrf` / nonce | интеграция с CSP (inline-скрипты получают nonce) |

Компилятор экранирует содержимое `@section`, чтобы `@section("a'");eval(...)` не превращалось в инъекцию кода —
сохраняйте это поведение при правках.

---

## 9. Безопасность (что уже сделано — не ломать)

- **CSRF**: токен `_csrf_token` (`SECURITY_CSRF_*`), `CsrfMiddleware` на всех POST.
- **Rate limiter**: файловый, `storage/.rate_limits/` (`SECURITY_RATE_LIMIT_MAX/WINDOW`).
- **Заголовки**: CSP (`SECURITY_CSP_POLICY`), `X-Frame-Options DENY`, `nosniff`, Referrer-Policy, HSTS.
  Inline JS запрещён политикой; доверенные inline-блоки получают nonce.
- **Trusted proxies**: `TRUSTED_PROXIES` (CIDR-список). `X-Forwarded-For/Proto` принимаются только от них.
- **Сессии**: strict mode, HttpOnly, SameSite=Lax, Secure (для HTTPS-прода).
- **Маскировка секретов** в логах: `SECURITY_SENSITIVE_KEYS`, `SensitiveDataMasker`.
- **Audit log**: `logs/audit.log` (входы, действия админа).
- **.htaccess**: запрет доступа к `core|vendor|storage|logs|views|.git|.osp`, к `config.env*`, `composer.*`,
  `*.sql/log/db/ini/conf/bak/old`, исполнение PHP разрешено только `index.php`. ACME challenge — исключение.
- **HtmlSanitizer**: allowlist тегов/атрибутов из `config/html-sanitizer.php` для пользовательского HTML.
- **Установщик**: доступ по ключу `INSTALL_KEY`, HMAC-шифрование временного конфига БД (`INSTALL_HMAC_KEY`),
  после step5 POST-запросы мастера блокируются навсегда.
- Проверка `Host` по allowlist `APP_ALLOWED_HOSTS`.

Если задача ИИ касается редактирования любого из этих мест — сначала перечитайте §9, изменения не должны
ослаблять защиту (например, нельзя включать `debug`/`DEV_PREVIEW_ENABLED` в проде или убирать nonce-политику).

---

## 10. Конфигурация: как запустить

1. Требования: PHP ≥ 8.1 (extensions: pdo_mysql, dom, mbstring обычно есть), MySQL/MariaDB, Apache (mod_rewrite) или любой сервер с front-controller правилом. Composer — только для генерации автозагрузчика: `composer dump-autoload` (пакетов нет).
2. `cp config.env.example config.env` и заполнить:
   - `APP_ROOT_PATH` — абсолютный путь к **корню проекта** (каталог с `index.php`; НЕ к `storage/`!) — единственная каноническая настройка базового пути; устаревший `FS_BASE_PATH` полностью упразднён и больше не читается (удалите его из старых `config.env`); все файловые пути конфига (`LOG_FILE`, `SECURITY_AUDIT_FILE`) разрешаются относительно корня — ведущий `/` означает корень проекта, напр. `LOG_FILE=/log/app.log`;
   - `DB_*` — параметры MySQL;
   - `SUPER_ADMIN_EMAIL/PASSWORD` — хэш пароля (см. §6);
   - `APP_BASE_URL`, `APP_ALLOWED_HOSTS`, `APP_NAME`;
   - `INSTALL_KEY` — `php -r "echo bin2hex(random_bytes(16));"`;
   - `INSTALL_HMAC_KEY` — `php -r "echo bin2hex(random_bytes(32));"`;
   - `SESSION_COOKIE_SECURE=false` — только для локального HTTP-деба.
3. Права: `storage/` и `logs/` writable для пользователя веб-сервера (`FS_DEFAULT_FILE_PERMISSION=0775`).
4. Локальный dev-сервер: `php -S localhost:8000 router.php` (router.php эмулирует .htaccess).
5. Первичная установка: открыть `http://localhost:8000/admin/install?key=<INSTALL_KEY>` и пройти шаги 1–5.
   Либо сгенерировать/отозвать ключ CLI: `php bin/install-key.php generate|revoke`.
6. Проверка: `GET /api/health` (liveness) и `GET /api/health/ready` (readiness).

Ключевые группы переменных config.env (полный список — в `config.env.example`, там же комментарии):
`FS_*`, `DB_*`, `SUPER_ADMIN_*`, `TIMEZONE/DATE_FORMAT/TIME_FORMAT/DATETIME_FORMAT/LOCALE`,
`LOG_*`, `SECURITY_*`, `SESSION_*`, `APP_*`, `TEMPLATE_*`, `ADMIN_PER_PAGE`, `INSTALL_KEY`, `INSTALL_HMAC_KEY`,
`DEV_PREVIEW_ENABLED`.

---

## 11. Соглашения кодирования (обязательно соблюдать)

- Первый блок каждого PHP-файла: `<?php`, `declare(strict_types=1);`, proprietary-заголовок с комментарием
  `VampqweEngineMedium — проприетарный PHP-движок` (© 2024–2026). Не удалять.
- Комментарии и сообщения пользователю — на русском; логи/исключения тоже допустимо на русском.
- Все public API типизировано (параметры, возвраты); enum'ы: `Method`, `StatusCode`, `LogLevel`, `EngineState`,
  `FileSystemType`, `DateFormat`, `TimeUnit`, `SortOrder`, `DataBaseDriver`.
- Исключения по слоям: `ApplicationException`, `HttpException`, `DataBaseException`, `TemplateException`,
  `ValidationException`, `FileSystemException`, `LoggerException`, `MapException`, `SecurityException`,
  `ErrorException`, `TimeDateException` — фабрики вида `X::someReason(...)` вместо голых `new Exception`.
- Стиль неймспейсов — PascalCase даже для слов из нескольких частей (`DataBase`, `TimeDate`, `Http`),
  не переименовывать в «правильный» camelCase — на это завязаны пути PSR-4.
- Контроллеры модулей собираются вручную в провайдерах через `$container->get(...)` (поле-замыкание
  `fn(Request $r) => $controller()->method($r)`), а не аннотациями.
- Никаких сторонних библиотек. Новая функциональность — только средствами ядра.
- Не хранить секреты в коде/репозитории; всё в `config.env` (в .gitignore).

---

## 12. Как вносить изменения (playbook для ИИ)

1. **Новая публичная страница CMS** → ничего писать не нужно: создать запись в `pages` через `/admin/pages`
   (slug = ЧПУ-URL). Fallback-маршрут подхватит её автоматически.
2. **Новый API-эндпоинт** → `RouterServiceProvider::registerRoutes()`, группа `$api`, ответ через
   `Response::json(..., StatusCode::...)`.
3. **Новый раздел админки** → контроллер в `core/classes/module/Admin/Controllers/`, сервис в `Services/`,
   маршруты в `AdminServiceProvider` внутри группы `$admin` (она уже несёт `AdminMiddleware`), шаблоны в
   `views/admin/...` с `@extends('admin.layouts.admin')`. Не забыть CSRF-поле в формах и audit-запись.
4. **Новая таблица** → добавить CREATE TABLE в `database/structure.sql` (мастер step2 применяет именно этот файл;
   для существующих инсталляций нужен отдельный миграционный скрипт — их в проекте нет).
5. **Новое правило валидации** → класс в `core/classes/system/Validator/Rules/`, реализующий `ValidationRule`,
   + fluent-метод в `Validator`.
6. **Новая настройка** → ключ в `config.env.example` (+ комментарий), чтение через `config('KEY')` / `Config::get`.
7. **Проверка изменений**: `php -l` на затронутых файлах; запуск `php -S localhost:8000 router.php`
   и smoke-тест `/api/health`, `/api/health/ready`, `/admin/login`. Полноценного тест-сьютa в репозитории нет.

---

## 13. Известные особенности и подводные камни

- `vendor/autoload.php` обязателен: без него сайт белый. После клонирования выполнить `composer dump-autoload`.
- До завершения установки весь трафик перехватывается `InstallGateMiddleware` (страница «движок не установлен»).
  `EngineState::DbUnreachable` отличается от `not_installed` — health-endpoint'ы честно отражают оба случая.
- `Config` ищет `config.env` детерминированно от корня проекта, а не от `getcwd()` — учитывайте при запуске CLI-скриптов.
- Хранилище rate-limiter'а и установочный ключ — файлы в `storage/` (скрытые: `.rate_limits/`, `.install_key`);
  чистка `storage/` собьёт лимитеры и метку отзыва ключа.
- Импорт HTML: нейтральный CSS кладётся в `storage/neutral-style.css`, изображения — в `storage/uploads/import/`;
  URLs внутри контента переписываются на `/storage/...`, которые раздаёт движок (см. маршрут `/storage/{file}`).
- Приоритет маршрутов: точное совпадение важнее шаблона (`/menu/items` раньше `/menu/items/{id}`).
- Проект разрабатывается под shared-хостинг (`.osp/project.ini`, `.htaccess`), поэтому корень = webroot;
  перенос точки входа в подкаталог потребует правки `.htaccess`, `APP_ROOT_PATH` и `Config`.

---

## 14. Лицензия

Проприетарное ПО © 2024–2026, автор — Дедюля Александр Иванович (VampqweEngine).
Копирование, распространение, модификация и использование без письменного разрешения правообладателя запрещены.
Контакт — в LICENSE-файле дистрибутива. Репозиторий: `https://github.com/Vampqwe/VampqweEngine.git`.
