# VampqweEngineMedium

> **Note for AI agents / разработчиков:** этот документ — полное техническое описание проекта,
> оптимизированное для машинного чтения. Главный раздел — **«§5. Карта модулей: чем именно built каждый модуль»** —
> отвечает на вопрос «модуль X → какие классы его составляют, от чего они наследуются и через какой
> ServiceProvider регистрируются». Все утверждения соответствуют фактическому состоянию репозитория
> (проверено по исходникам `core/classes/`).

---

## 1. TL;DR (кратко для машины)

| Факт | Значение |
|---|---|
| Тип проекта | Собственный (proprietary) PHP-движок + мини-CMS, **не** фреймворк из пакунов |
| Язык / версия | PHP `>= 8.1`, `declare(strict_types=1)` обязателен во всех файлах |
| Зависимости рантайма | **Нет composer-пакетов** (`vendor/` содержит только автозагрузчик). Только `ext-dom`. PHPUnit — dev-only (`tests/`) |
| Autoload | PSR-4: `Core\System\` → `core/classes/system/`, `Core\Module\` → `core/classes/module/`, плюс `core/helpers.php` через `files` |
| Точка входа | `index.php` (front controller), роутинг — собственный `Router` |
| БД | **Только MySQL/MariaDB** (PDO). SQLite — зарезервированный драйвер, НЕ использовать |
| Конфигурация | Один файл `config.env` в корне (KEY=VALUE), читается классом `Config` |
| Модули | Admin, Auth, Install, Page (+ служебные File/Directory). Карта классов → §5 |
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
├── config/             # PHP-конфиги подсистем (не env!): admin-dashboard.php, html-sanitizer.php, import-parser.php, page-content.php — ЕДИНСТВЕННАЯ рабочая папка конфигов; core/config/ удалена. Пути в коде: dirname(__DIR__, N) от файла класса до корня (Page/System-классы N=5, Admin-классы N=6) + '/config/...'
│   ├── admin-dashboard.php
│   ├── html-sanitizer.php   # allowlist тегов/атрибутов для HtmlSanitizer
│   ├── import-parser.php    # настройки HtmlImportParser
│   └── page-content.php     # настройки контента страниц (в т.ч. счётчик просмотров)
├── core/
│   ├── helpers.php     # глобальные хелперы в namespace Core (см. §8)
│   └── classes/
│       ├── system/     # ядро движка (namespace Core\System\*) — см. §3
│       └── module/     # бизнес-модули (namespace Core\Module\*) — см. §5
├── database/
│   └── structure.sql   # DDL: только CREATE TABLE, без INSERT
├── views/              # HTML-шаблоны (компилятор см. §9)
│   ├── layouts/main.html          # публичный layout
│   ├── pages/                     # home, article, hub, 404, dev-preview
│   ├── partials/menu.html
│   └── admin/                     # auth/, config/, import/, install/, menu/, pages/, schema/, settings/, layouts/admin.html
├── assets/             # статика: css/, js/ (admin.js, main.js, menu-editor.js), fonts/
├── storage/            # runtime: cache/templates/, .rate_limits/, .install_key, uploads/import/
├── logs/               # app.log, audit.log (пишет Logger/AuditLogger)
├── tests/              # юнит-тесты PHPUnit (phpunit.xml.dist в корне)
├── vendor/             # composer autoloader (+ phpunit как dev-зависимость)
└── .osp/project.ini    # конфиг shared-хостинга HOSTING (1C-Bitrix-панель .osp)
```

### Система namespace'ов (строго)

- `Core\System\` → `core/classes/system/` — переиспользуемое ядро, НЕ знает о контенте CMS.
- `Core\Module\` → `core/classes/module/` — прикладные модули: `Admin`, `Auth`, `Install`, `Page`, `Directory`, `File`.
- Подпространство внутри модуля повторяет папки: контроллеры → `Controllers\`, сервисы → `Services\`, middleware → `Middleware\`.
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
   - подключает глобальный `PageCacheMiddleware` (`Application.php`, до dispatch);
   - `InstalledGuard` определяет состояние движка: `installed` / `not_installed` / `db_unreachable`
     (enum `EngineState`). Если не установлен — включается `InstallGateMiddleware` и отдаётся страница
     приглашения на `/admin/install`.
4. `Router::dispatch()` матчит URI → `Request` → middleware stack → closure-controller → `Response::send()`.

### Классы ядра (`core/classes/system/`, namespace `Core\System\*`)

Модули строятся ИСКЛЮЧИТЕЛЬНО поверх этих классов. Путь в таблице — относительно `core/classes/system/`.

| Класс (подпапка) | Назначение — то, чем пользуются модули |
|---|---|
| `Application` (Application/) | singleton-загрузчик, container, bootstrap providers, EngineState-гарды |
| `Container` (Container/) | DI: `singleton()`, `bind()`, `get()`, `has()`; автовывод конструктор-зависимостей |
| `Config` (Config/) | парсер `config.env`; поиск файла детерминирован от base_path, НЕ от getcwd |
| `Router`, `Route`, `RouteGroup` (Http/) | маршрутизация с параметрами `{id}`, `group('/prefix', $middleware)`, `where()`, `fallback()` |
| `Request` / `Response` (Http/) | обёртки над superglobals; `Response::json/html/redirect`, `setHeader()`, `send()` |
| `MiddlewareStack`, `Middleware` (Http/) | цепочка middleware; интерфейс `Middleware::handle(Request, callable $next): Response` |
| `CsrfMiddleware` (Http/) | проверка токена `_csrf_token` на POST |
| `RateLimitMiddleware` (Http/) | HTTP-обёртка над `Security\RateLimiter` |
| `PageCache` / `PageCacheMiddleware` (Http/) | кэш HTML публичных страниц |
| `InstallGateMiddleware`, `NotInstalledPage`, `DbUnavailablePage` (Http/) | гейт до завершения установки |
| `DataBase` (DataBase/) | PDO-обёртка: `query/fetchAll/fetchOne/fetchColumn/insert/execute/transaction(callable)`, reconnect. **Главная зависимость всех Services модулей** |
| `Template`, `TemplateCompiler`, `TemplateCache`, `Section` (Template/) | рендер `.html` шаблонов. **Главная зависимость всех Controllers модулей** |
| `Validator` + `Rules/*` (Validator/) | fluent-валидация форм в контроллерах/сервисах |
| `FileSystem`, `PathProbe` (FileSystem/) | файловые операции с базовым путём `APP_ROOT_PATH`; база для модулей File/Directory |
| `SecurityManager`, `CsrfToken`, `RateLimiter`, `SecurityHeaders`, `TrustedProxy`, `PathSanitizer`, `SensitiveDataMasker`, `HtmlSanitizer`, `AuditLogger`, `SqlSanitizer` (Security/) | единая точка безопасности; `SecurityManager` внедряется почти в каждый админ-контроллер |
| `SessionManager` + хранилища (Session/) | безопасные PHP-сессии (strict mode, HttpOnly, SameSite, Secure; file/redis) |
| `Logger`, `LogLevel` (Logger/) | file-логгер с ротацией и маскировкой секретов |
| `Queue`, `JobRegistry` (Queue/) | файловая очередь фоновых задач (используется импортом и sitemap) |
| `TimeDate`, `DateFormat`, `TimeUnit` (TimeDate/) | дата/время по `TIMEZONE` |
| `Map`, `SortOrder` (Map/) | типизированная упорядоченная карта |
| `ErrorHandler`, `ErrorException`, `ErrorPage` (Error/) | перехват throwable → красивая 500 + лог |
| `InstalledGuard`, `EngineState`, `ProjectRoot` (Application/) | определение состояния установки без бросания исключений при мёртвой БД |
| `ProjectChecks` (Diagnostics/) | диагностика окружения (мастер установки, dev-preview) |
| `ServiceProvider` + 13 провайдеров (ServiceProvider/) | регистрация всего выше в контейнере — см. §4 |

---

## 4. ServiceProviders (ядро регистрации)

Все в `core/classes/system/ServiceProvider/`, наследуют абстрактный `ServiceProvider`
(`register()` — binds в контейнер, `boot()` — регистрация маршрутов/настроек).
Порядок подключения задан в `Application::bootstrap()`:

| Провайдер | Что регистрирует | Какие модульные классы подключает |
|---|---|---|
| `ConfigServiceProvider` | грузит `config.env` | — |
| `DataBaseServiceProvider` | singleton `DataBase` (MySQL, utf8mb4) | — |
| `FileSystemServiceProvider` | singleton `FileSystem` c корнем `APP_ROOT_PATH` | — |
| `LoggerServiceProvider` | singleton `Logger` | — |
| `SecurityServiceProvider` | `SecurityManager` (CSRF, rate limit, headers, audit, trusted proxies) | — |
| `HttpServiceProvider` | `Request` из globals, `Response`, cookie | — |
| `RouterServiceProvider` | singleton `Router` + **публичные маршруты** | `Core\Module\Page\*`: `PageController`, `SitemapController`, `RobotsController`, `SitemapGenerator` |
| `TemplateServiceProvider` | `Template` + компилятор + кэш | — |
| `ValidatorServiceProvider` | `Validator` | — |
| `TimeDateServiceProvider` | `TimeDate` | — |
| `HtmlSanitizerServiceProvider` | `HtmlSanitizer` с allowlist из `config/html-sanitizer.php` | — |
| `PageServiceProvider` | синглтоны `PageService`, `RedirectService`, `MenuService`, `SitemapService` (все — конструктор от `DataBase`) | `Core\Module\Page\Services` |
| `AdminServiceProvider` | **все модули админки**: bind контроллеров/сервисов + маршруты `/admin/*` и мастера установки | `Core\Module\Admin\*`, `Core\Module\Auth\*`, `Core\Module\Install\*` |

**Как добавить маршрут:** правьте соответствующий провайдер (`RouterServiceProvider` для публичных,
`AdminServiceProvider` для админских). Маршруты — замыкания, получающие `Request` и возвращающие `Response`;
зависимости внутри — `$container->get(X::class)`. Пример:

```php
$api = $router->group('/api');
$api->get('/health', function () use ($container): Response {
    return Response::json(['status' => 'alive'], StatusCode::OK);
})->name('api.health');
```

⚠️ Исторический баг (зафиксирован в комментариях кода): в замыкании нельзя писать `use ($container)`
до локального присваивания `$container = $this->container;` — это роняло весь bootstrap в HTTP 500.

---

## 5. Карта модулей: чем построен каждый модуль (главный раздел для ИИ)

Все модули лежат в `core/classes/module/<Module>/`, namespace `Core\Module\<Module>\`.
Общий паттерн построения модуля:

```
Контроллер  = Template (рендер views/...) + SecurityManager (CSRF/audit) + один или несколько Services
Сервис      = DataBase (CRUD своей таблицы) + иногда Config / FileSystem / Queue / Validator
Middleware  = implements Core\System\Http\Middleware (handle(Request, next): Response)
Маршруты+DI = регистрируются ВНЕ модуля, в System\ServiceProvider\AdminServiceProvider
              (или RouterServiceProvider/PageServiceProvider для публичного модуля Page)
```

### 5.1 Модуль `Admin` (`Core\Module\Admin\*` → `core/classes/module/Admin/`) — админ-панель `/admin`

Регистрация: `AdminServiceProvider::register()` (singletons) + `boot()` (маршруты группы
`$admin = $router->group('/admin', new AdminMiddleware(...))`).

**Controllers** (`Admin/Controllers/`, namespace `Core\Module\Admin\Controllers`) и их зависимости из конструкторов:

| Контроллер | Зависимости (constructor-inject) | Маршруты | Шаблоны |
|---|---|---|---|
| `DashboardController` | `AdminService`, `Template`, `?SessionManager` | GET `/admin/` | `admin.pages.dashboard` → `views/admin/pages/dashboard.html` |
| `PagesController` | `Admin\PageService` (extends Page-модуль!), `AdminService`, `Template`, `SecurityManager` | `/admin/pages`, `/pages/new`, `/pages/store`, `/pages/{id}/edit`, `/pages/{id}/update`, `/pages/{id}/delete` | `admin.pages.list`, `admin.pages.edit` → `views/admin/pages/*` |
| `ImportController` | `HtmlImportParser`, `PageService`, `AdminService`, `Template`, `Config`, `SecurityManager` (+ локально `new ImportJobHandler($config)`) | `/admin/import`, `/import/upload`, `/import/confirm` | `admin.import.index`, `admin.import.preview` → `views/admin/import/*` |
| `MenuController` | `MenuAdminService`, `Template`, `SecurityManager` | `/admin/menu`, `/menu/items`, `/menu/structure`, `/menu/items/{id}`, `/menu/items/{id}/delete` | `admin.menu.index` → `views/admin/menu/index.html` + JS `assets/js/menu-editor.js` |
| `SettingsController` | `SettingsService`, `AdminService`, `Template`, `SecurityManager` | GET/POST `/admin/settings`, POST `/settings/rebuild-seed`. ⚠️ Проверок на супер-админа в коде нет, несмотря на комментарий в файле — см. §13 | `admin.settings.index` → `views/admin/settings/index.html` |
| `SchemaController` | `SchemaService`, `AdminService`, `Template`, `SecurityManager` | `/admin/schema[...]` CRUD (`->where('id','\d+')`) | `admin.schema.index`, `admin.schema.edit` → `views/admin/schema/*` |
| `ConfigViewerController` | `ConfigFileParser`, `AdminService`, `Template`, `SecurityManager` | GET/POST `/admin/config`; приватный `isSuperAdmin()` — единственный в модуле рабочий гейт по роли (сверка email сессии с `SUPER_ADMIN_EMAIL` через `strcasecmp`, прямой доступ к `$_SESSION`) | `admin.config.index` → `views/admin/config/index.html` |

**Services** (`Admin/Services/`, namespace `Core\Module\Admin\Services`):

| Сервис | Зависимости | Что делает / таблица |
|---|---|---|
| `AdminService` | `DataBase`, `?SessionManager` | базовые операции админки, статистика дашборда, сессия админа |
| `PageService` | extends `Core\Module\Page\PageService` (база — `DataBase`) | CRUD `pages` для админки; переопределяет поведение публичного сервиса |
| `ImportService` | `DataBase` | сохранение результатов импорта в `pages` |
| `HtmlImportParser` | `Config` (+ `config/import-parser.php`) | разбор HTML-архива: нейтрализация CSS, скачка картинок в `storage/uploads/import/` |
| `ImportJobHandler` | `Config`, `?Queue` | постановка/обработка тяжёлой части импорта через `Core\System\Queue\Queue` (`isQueueEnabled()`) |
| `MenuAdminService` | `DataBase` | CRUD дерева `menu_items` |
| `SettingsService` | `Config`, `SecurityManager`, `DataBase` | редактирование `config.env` (заявлено «только супер-админ», но проверок в классе нет — см. §13); бэкап config.env + `chmod 0640`, audit-лог изменений |
| `SchemaService` | `DataBase` | CRUD `schema_org` (JSON-LD) |
| `ConfigFileParser` | `Config`, `SecurityManager` | парсинг/маскировка config.env для просмотрщика |

**Middleware** (`Admin/Middleware/`):

| Middleware | Наследование/зависимости | Роль |
|---|---|---|
| `AdminMiddleware` | `implements Core\System\Http\Middleware`; конструктор: `InstallService`, `SecurityManager`; внутри сам собирает `MiddlewareStack`: `InstallMiddleware(InstallService)` → `AuthMiddleware()` → `SuperAdminMiddleware(session())` → `CsrfMiddleware(SecurityManager)` → `AdminCspMiddleware()` | единственный middleware группы `/admin` — композит защиты. Инстанцируется вручную в `AdminServiceProvider::registerRoutes()`, **не** через DI |
| `SuperAdminMiddleware` (`Core\Module\Auth\Middleware`) | `implements Middleware`, конструктор: `?SessionManager` (по умолчанию `null` → глобальный хелпер `session()`) | ✅ подключен в `AdminMiddleware` сразу после `AuthMiddleware()`: пропускает только `admin_user['is_super'] === true`; неавторизованный → 302 `/admin/login` (+ flash «Требуется вход…» + `redirect_after_login`), авторизованный не-супер → **403** со страницей `admin/error/403.html` (или текстовым fallback). Модель доступа — см. примечание ниже |
| `AdminCspMiddleware` | `implements Middleware`, конструктор без аргументов | переопределяет CSP админ-страниц: `'unsafe-inline'` для script-src/style-src (ради WYSIWYG), img-src + data:/blob: — **не** nonce (см. §13) |

> Проверено: стек `AdminMiddleware` собирается через `MiddlewareStack::process()`, который строит цепочку с конца — контроллер выполняется ровно один раз; при редиректе любого из middleware `$next` не вызывается. Фактический порядок: `InstallMiddleware → AuthMiddleware → SuperAdminMiddleware → CsrfMiddleware → AdminCspMiddleware`.

**Модель доступа админки (зафиксировано в коде):** вся группа `/admin` требует **роль супер-администратора**. Обычные пользователи таблицы `users` (`role admin/editor`) проходят `AuthMiddleware` (вход по логину/паролю работает), но получают **403 на всех страницах админки**, включая `/admin/login` после входа. Разделение прав «редактор пишет страницы, админ управляет» на уровне маршрутов отсутствует — это осознанная временная мера до появления ролевой модели (RBAC); если она понадобится, уберите `SuperAdminMiddleware` из общего стека `AdminMiddleware` и навесьте его точечно на чувствительные маршруты (`settings`, `config`, `schema`, `import`, `menu`), оставив остальное за `AuthMiddleware`. Второй гейт — сверка email сессии с `SUPER_ADMIN_EMAIL` через `ConfigViewerController::isSuperAdmin()` / `SettingsController::isSuperAdmin()` — сохранён как defense-in-depth (не зависит от целостности ключа сессии).

### 5.2 Модуль `Auth` (`Core\Module\Auth\*` → `core/classes/module/Auth/`) — вход и права

Регистрация: классы создаёт `AdminServiceProvider` (для `AuthController`) и `AdminMiddleware` (для `AuthMiddleware`, `SuperAdminMiddleware`).

| Класс | Зависимости | Роль |
|---|---|---|
| `Controllers\AuthController` | `SuperAdminAuth`, `AdminService`, `Config`, `DataBase`, `SecurityManager`, `Template` | GET/POST `/admin/login`, POST `/admin/logout`; bcrypt, rate limiting, CSRF |
| `Services\SuperAdminAuth` | `Config` | аутентификация супер-админа из `SUPER_ADMIN_EMAIL/SUPER_ADMIN_PASSWORD` (bcrypt cost 12) — **вне БД** |
| `Middleware\AuthMiddleware` | `implements Middleware`, конструктор без аргументов | первый уровень допуска внутри `AdminMiddleware`: наличие `admin_user` в сессии |
| `Middleware\SuperAdminMiddleware` | `implements Middleware`, конструктор: `?SessionManager` | ✅ второй уровень допуска, подключен в стек `AdminMiddleware` после `AuthMiddleware`: `admin_user['is_super'] === true`, иначе 403 (`admin/error/403.html`); неавторизованный → 302 на `/admin/login`. Модель доступа всей группы `/admin` описана в §5.1 |

**Как грузится (аутентифицируется) супер-админ — полный поток:**

1. `SuperAdminAuth` регистрируется синглтоном в `AdminServiceProvider::register()`: `new SuperAdminAuth($config)` — только `Config`, БД на этом шаге **не участвует**; `AuthController` получает его через DI-фабрику `$authController`.
2. POST `/admin/login` → `AuthController::login()` сначала применяет rate limit `$this->security->attempt('admin:login:' . $ip)` и CSRF `validateCsrf()` (обе ошибки — flash + редирект, без исключения).
3. Ветка супер-админа проверяется **первой**, до обращения к таблице `users`: `SuperAdminAuth::isSuperAdmin($email, $password)` читает `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` (bcrypt-хеш) из `Config` (config.env); при пустых значениях возвращает `false`. Email сравнивается `hash_equals()`, пароль — `password_verify()`. Хеш генерируется `SuperAdminAuth::hashPassword()` (bcrypt cost 12, CLI-утилита).
4. При успехе: `SessionManager::loginAdmin(SuperAdminAuth::getSuperAdminData())` — данные `{id: 0, email, name, role: 'super_admin', is_super: true}` записываются в сессию под ключом `admin_user`; `loginAdmin()` перед записью вызывает `regenerate(true)` (`session_regenerate_id`, защита от session fixation). Супер-админ в БД не хранится и работает даже при удалённой/повреждённой базе.
5. Редирект после входа — через `safePostLoginRedirect()`: принимается только внутренний относительный путь из `redirect_after_login` (ставит его `AuthMiddleware` при недопуске); внешние/`//host`/`/\…`/CRLF URL отбрасываются (защита от open-redirect).
6. Обычные админы: второй веткой `SELECT id, email, name, role, password_hash FROM users WHERE email = :email AND is_active = 1` + `password_verify()`, затем тот же `loginAdmin()` с `is_super => false` и `UPDATE users SET last_login_at = NOW()`. Обе ветки выполняются при **обеих** парах учётных данных (сначала super, потом users) — для нормализации времени ответа `hashPassword()` использует `bcrypt cost 12`, но явной тайминг-заглушки (`dummy_verify`) во второй ветке нет; практический риск низкий (пароль супер-админа всё равно проверяется первым).
7. Дальнейшие запросы к группе `/admin` проходят два уровня: `AuthMiddleware` (наличие `admin_user` в сессии) → `SuperAdminMiddleware` (`is_super === true`, иначе 403). То есть обычный admin из таблицы `users` авторизуется, но в админку не попадает (модель доступа — §5.1).
8. Logout: `forgetTransientMarkers('viewed_page_')` → `logoutAdmin()` (снимает `admin_user` + регенерация ID) → flash → `/admin/login`.

### 5.3 Модуль `Install` (`Core\Module\Install\*` → `core/classes/module/Install/`) — мастер установки

Регистрация: `AdminServiceProvider` (группы `$router->group('/admin/install', $installAccess)`, маршруты step1–5).

| Класс | Зависимости | Роль |
|---|---|---|
| `Controllers\InstallController` | `InstallService`, `AdminService`, `Config`, `Template`, `SecurityManager`, `?SessionManager` | шаги 1–5 мастера (`views/admin/install/step1..5.html`) |
| `Services\InstallService` | `Config`, `?string $basePath` | проверка окружения, применение `database/structure.sql`, первичные данные, запись боевого config, отзыв ключа (HMAC `INSTALL_HMAC_KEY`, метка `storage/.install_key`) |
| `Middleware\InstallAccessMiddleware` | `implements Middleware` | доступ к мастеру по `?key=` / заголовку `X-Install-Key` (= `INSTALL_KEY`) |
| `Middleware\InstallMiddleware` | конструктор: `InstallService` | внутренний элемент стека `AdminMiddleware` (запрет админки до конца установки) |

### 5.4 Модуль `Page` (`Core\Module\Page\*` → `core/classes/module/Page/`) — публичная CMS

Регистрация: сервисы — синглтоны в `PageServiceProvider`; контроллеры и маршруты — в `RouterServiceProvider`
(fallback `/{slug}`, `/sitemap.xml`, `/robots.txt`). Плоская структура (без подпапок Controllers/Services).

| Класс | Зависимости | Роль |
|---|---|---|
| `PageController` | `PageService`, `RedirectService`, `MenuService`, `Template`, `Config` | fallback-роут: ЧПУ-страница из `pages`, редиректы из `redirects`, рендер через `layouts/main.html`, 404 |
| `PageService` | `DataBase` | чтение `pages`; инкремент просмотров через `ViewCounter` (настройка `config/page-content.php`) |
| `MenuService` | `DataBase` | дерево `menu_items` для `partials/menu.html` |
| `RedirectService` | `DataBase` | таблица `redirects` source→target |
| `SitemapService` | `DataBase` | URL-лист из `pages`/`menu_items` |
| `SitemapController` | `SitemapService`, `Config`, `?SitemapGenerator`, `?Queue` | GET `/sitemap.xml` (генерация in-request или через очередь) |
| `SitemapGenerator` | `SitemapService`, `Config` | сборка XML |
| `RobotsController` | `Config` | GET `/robots.txt` |
| `ViewCounter` | `DataBase`, `Config` | асинхронный счётчик просмотров страниц |

### 5.5 Модули `File` и `Directory` (`Core\Module\File\File`, `Core\Module\Directory\Directory`)

Не MVC-модули, а тонкие ООП-надстройки над ядром: оба **`extends Core\System\FileSystem\FileSystem`**,
конструктор `(string $path, Config $config)`. `File` добавляет лимит размера, MIME-allowlist, защиту путей;
`Directory` — работу с деревом (`children: FileSystem[]`, содержит `File`). Используются импортом и
работой со storage напрямую; маршрутов не имеют.

### 5.6 Как собрать новый модуль (рецепт для ИИ)

1. Создать `core/classes/module/<Name>/Controllers/XController.php`
   (`namespace Core\Module\<Name>\Controllers`, ctor: нужные сервисы + `Template` + `SecurityManager`).
2. Создать сервис в `<Name>/Services/` (ctor: `Core\System\DataBase\DataBase`).
3. При необходимости middleware — `implements Core\System\Http\Middleware`.
4. Зарегистрировать singletons в `register()` и маршруты в `boot()` нового
   `Core\System\ServiceProvider\<Name>ServiceProvider` (extends `ServiceProvider`) + подключить
   провайдер в `Application::bootstrap()`. Либо — если модуль админский — добавить в `AdminServiceProvider`
   внутрь группы `$admin` (она уже несёт всю цепочку `AdminMiddleware`).
5. Таблицы — в `database/structure.sql`; шаблоны — `views/<area>/...` с `@extends`.
6. Никогда не тянуть ядро напрямую через `new` — только `$container->get(...)` / хелперы (§8).

---

## 6. Таблица маршрутов (фактические)

### Публичные (`RouterServiceProvider`)

| Метод | Путь | Handler / описание |
|---|---|---|
| GET | `/api/health` | liveness: всегда 200, `{status:"alive", state, time}`, заголовок `X-Engine-State`. НЕ трогает БД |
| GET | `/api/health/ready` | readiness: метка установки + MySQL; при проблемах 503 + `Retry-After: 10` |
| GET | `/storage/{file}` | отдача файлов из `storage/` (нейтральный CSS импорта, картинки). `.htaccess` закрыл /storage, поэтому раздаёт движок |
| GET | `/sitemap.xml` | `SitemapController` (из таблиц pages/menu) |
| GET | `/robots.txt` | `RobotsController` |
| GET | `/__dev/preview` | dev-превью шаблонов. Только loopback + `DEV_PREVIEW_ENABLED=true`. В проде держать false |
| POST | `/__dev/preview/run` | то же, выполнение превью |
| ANY | fallback `/{slug}` | `PageController`: ЧПУ-страницы, редиректы, `Template` + `layouts/main.html`; 404 → `views/pages/404.html` |

### Установка и админка (`AdminServiceProvider`)

Мастер `/admin/install` защищён `InstallAccessMiddleware` (ключ `?key=` или `X-Install-Key`, значение `INSTALL_KEY`; хэш — `storage/.install_key`).

| Метод | Путь | Описание |
|---|---|---|
| GET | `/admin/install` | Шаг 1 — проверка окружения |
| POST | `/admin/install/step1` | параметры БД |
| GET/POST | `/admin/install/step2` | применение `database/structure.sql` |
| GET/POST | `/admin/install/step3` | первичные данные (супер-админ и пр.) |
| GET/POST | `/admin/install/step4` | финальная конфигурация |
| GET/POST | `/admin/install/step5` | завершение, отзыв ключа |

| Метод | Путь | Контроллер (модуль) |
|---|---|---|
| GET/POST | `/admin/login` | `AuthController` (Auth); POST `/admin/logout` — в группе `/admin` |
| GET | `/admin/` | `DashboardController::index` |
| GET/POST | `/admin/pages*` | `PagesController` (полный список путей — §5.1) |
| GET/POST | `/admin/import*` | `ImportController` |
| GET/POST | `/admin/menu*` | `MenuController` (важно: статический `/menu/items` раньше шаблонного `/menu/items/{id}`) |
| GET/POST | `/admin/settings`, `/settings/rebuild-seed` | `SettingsController` |
| GET/POST | `/admin/schema*` | `SchemaController` |
| GET/POST | `/admin/config` | `ConfigViewerController` |

Все защищённые маршруты идут через группу `AdminMiddleware` = стек
`InstallMiddleware → AuthMiddleware → CsrfMiddleware → AdminCspMiddleware`.

---

## 7. База данных

DDL: `database/structure.sql` (только CREATE TABLE + индексы + FK, без данных).
Таблицы (utf8mb4_unicode_ci, InnoDB) и «их» сервисы модулей:

| Таблица | Сервис модуля | Назначение / ключевые поля |
|---|---|---|
| `pages` | `Admin\PageService`, `Page\PageService`, `ImportService` | ЧПУ-страницы: `title`, `slug` (UNIQUE), `content` longtext, SEO/OG-поля |
| `menu_items` | `MenuAdminService`, `MenuService` | дерево меню: `title`, parent_id/порядок |
| `authors` | — (пока только DDL) | авторы, `slug` UNIQUE |
| `redirects` | `RedirectService` | source → target |
| `schema_org` | `SchemaService` | JSON-LD по страницам |
| `tasks` | — (пока только DDL) | служебные задачи |
| `users` | `AdminService` | обычные администраторы (bcrypt-хэш) |

Важно:
- Супер-админ **не хранится в БД** — `config.env` (`SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD` = bcrypt cost 12). Не удаляется через админку.
- Все обращения к БД — только через `DataBase` с prepared statements (`DB_ENFORCE_PREPARED=true`). Никаких конкатенаций SQL.
- Хэш пароля: `php -r "echo password_hash('ПАРОЛЬ', PASSWORD_BCRYPT, ['cost' => 12]);"`

---

## 8. Глобальные хелперы (`core/helpers.php`, namespace `Core`)

Регистрируются composer `files`-autoload. Основные:

- Ответы: `response()`, `json()`, `redirect()`, `download()`, `abort()`
- Запрос: `request()`, `input()`, `query()`, `post()`, `header()`, `cookie()`
- Контейнер/конфиг: `app()`, `config()`, `base_path()`, `storage_path()`, `logs_path()`
- Сервисы: `logger()`, `db()`, `now()`, `validator()`, `security()`, `audit()`, `error_handler()`
- CSRF: `csrf_token()`, `csrf_field()`, `csrf_meta()`, `validate_csrf()`
- Прочее: `rate_limit()`, `sanitize_path()`, `mask_sensitive()`

В шаблонах доступны переменные вида `app_name`, `base_url` (передаются контроллерами в `Template::render`).

---

## 9. Шаблонизатор (Blade-подобный)

Файлы: `views/**/*.html`. Компилятор: `TemplateCompiler` → PHP-код в `storage/cache/templates/{md5}.php`
(кэш `TEMPLATE_CACHE=true`; глубина `@extends/@include` — `TEMPLATE_MAX_RENDER_DEPTH=10`).

| Синтаксис | Смысл |
|---|---|
| `{{ expr }}` | экранированный вывод (dot-нотация: `page.title`) |
| `@extends('layouts.main')` / `@extends('admin.layouts.admin')` | наследование layout |
| `@section('title') ... @endsection` | определение секции |
| `@yield('title', 'default')` | вставка секции с дефолтом |
| `@include('partials.menu', [...])` | подключение partials с данными |
| `@csrf` / nonce | интеграция с CSP (inline-скрипты получают nonce) |

Компилятор экранирует содержимое `@section`, чтобы `@section("a'");eval(...)` не превращалось в инъекцию кода — сохраняйте это поведение.

---

## 10. Безопасность (что уже сделано — не ломать)

- **CSRF**: токен `_csrf_token` (`SECURITY_CSRF_*`), `CsrfMiddleware` на всех POST (в админке — внутри `AdminMiddleware`).
- **Rate limiter**: файловый, `storage/.rate_limits/` (`SECURITY_RATE_LIMIT_MAX/WINDOW`).
- **Заголовки**: CSP (`SECURITY_CSP_POLICY`), `X-Frame-Options DENY`, `nosniff`, Referrer-Policy, HSTS; inline JS запрещён, доверенные блоки получают nonce.
- **Trusted proxies**: `TRUSTED_PROXIES` (CIDR). `X-Forwarded-For/Proto` принимаются только от них.
- **Сессии**: strict mode, HttpOnly, SameSite=Lax, Secure (HTTPS-prod).
- **Маскировка секретов** в логах: `SECURITY_SENSITIVE_KEYS`, `SensitiveDataMasker`.
- **Audit log**: `logs/audit.log` (входы, действия админа) — вызывается из контроллеров через `SecurityManager`/`audit()`.
- **.htaccess**: запрет доступа к `core|vendor|storage|logs|views|.git|.osp`, к `config.env*`, `composer.*`, `*.sql/log/db/ini/conf/bak/old`; PHP исполняется только через `index.php`. ACME challenge — исключение.
- **HtmlSanitizer**: allowlist из `config/html-sanitizer.php` для пользовательского HTML.
- **Установщик**: доступ по `INSTALL_KEY`, HMAC временного конфига БД (`INSTALL_HMAC_KEY`), после step5 POST мастера блокируются навсегда.
- Проверка `Host` по allowlist `APP_ALLOWED_HOSTS`.

Изменения ИИ не должны ослаблять эти механизмы (не включать `debug`/`DEV_PREVIEW_ENABLED` в проде, не убирать nonce-политику).

---

## 11. Конфигурация: как запустить

1. Требования: PHP ≥ 8.1 (pdo_mysql, dom, mbstring), MySQL/MariaDB, Apache (mod_rewrite) или любой сервер с front-controller правилом. Composer — только для автозагрузчика: `composer dump-autoload`.
2. `cp config.env.example config.env` и заполнить:
   - `APP_ROOT_PATH` — абсолютный путь к **корню проекта** (каталог с `index.php`; НЕ к `storage/`) — единственная каноническая настройка базового пути; устаревший `FS_BASE_PATH` упразднён и не читается; все файловые пути конфига (`LOG_FILE`, `SECURITY_AUDIT_FILE`) разрешаются относительно корня — ведущий `/` означает корень проекта;
   - `DB_*` — параметры MySQL;
   - `SUPER_ADMIN_EMAIL/PASSWORD` — хэш пароля (см. §7);
   - `APP_BASE_URL`, `APP_ALLOWED_HOSTS`, `APP_NAME`;
   - `INSTALL_KEY` — `php -r "echo bin2hex(random_bytes(16));"`;
   - `INSTALL_HMAC_KEY` — `php -r "echo bin2hex(random_bytes(32));"`;
   - `SESSION_COOKIE_SECURE=false` — только для локального HTTP-деба.
3. Права: `storage/` и `logs/` writable для веб-сервера (`FS_DEFAULT_FILE_PERMISSION=0775`).
4. Локальный dev-сервер: `php -S localhost:8000 router.php`.
5. Первичная установка: `http://localhost:8000/admin/install?key=<INSTALL_KEY>`, шаги 1–5. Либо CLI: `php bin/install-key.php generate|revoke`.
6. Проверка: `GET /api/health`, `GET /api/health/ready`.

Группы переменных config.env: `FS_*`, `DB_*`, `SUPER_ADMIN_*`, `TIMEZONE/DATE_FORMAT/TIME_FORMAT/DATETIME_FORMAT/LOCALE`, `LOG_*`, `SECURITY_*`, `SESSION_*`, `APP_*`, `TEMPLATE_*`, `ADMIN_PER_PAGE`, `INSTALL_KEY`, `INSTALL_HMAC_KEY`, `DEV_PREVIEW_ENABLED`.

---

## 12. Как вносить изменения (playbook для ИИ)

1. **Новая публичная страница CMS** → ничего писать не нужно: создать запись в `pages` через `/admin/pages`
   (slug = ЧПУ-URL). Fallback-маршрут подхватит её автоматически.
2. **Новый API-эндпоинт** → `RouterServiceProvider::registerRoutes()`, группа `$api`, ответ через
   `Response::json(..., StatusCode::...)`.
3. **Новый раздел админки** → см. рецепт §5.6: контроллер в `Admin/Controllers/`, сервис в `Admin/Services/`,
   маршруты в `AdminServiceProvider` внутри группы `$admin`, шаблоны в `views/admin/...` с
   `@extends('admin.layouts.admin')`. Не забыть CSRF-поле в формах и audit-запись.
4. **Новая таблица** → CREATE TABLE в `database/structure.sql` (мастер step2 применяет именно этот файл;
   миграций нет — для существующих инсталляций нужен отдельный скрипт).
5. **Новое правило валидации** → класс в `core/classes/system/Validator/Rules/`, реализующий `ValidationRule`,
   + fluent-метод в `Validator`.
6. **Новая настройка** → ключ в `config.env.example` (+ комментарий), чтение через `config('KEY')` / `Config::get`.
7. **Проверка изменений**: `php -l` на затронутых файлах; `vendor/bin/phpunit` (юнит-тесты в `tests/`);
   smoke-тест `php -S localhost:8000 router.php` + `/api/health`, `/api/health/ready`, `/admin/login`.

---

## 13. Известные особенности и подводные камни

- `vendor/autoload.php` обязателен: без него сайт белый. После клонирования — `composer dump-autoload`.
- До завершения установки весь трафик перехватывает `InstallGateMiddleware`. `EngineState::DbUnreachable` ≠ `not_installed` — health-endpoint'ы честно отражают оба случая.
- `Config` ищет `config.env` детерминированно от корня проекта, а не от `getcwd()` — важно для CLI-скриптов.
- Rate-limiter и установочный ключ — файлы в `storage/` (`.rate_limits/`, `.install_key`); чистка `storage/` собьёт их.
- Импорт HTML: нейтральный CSS → `storage/neutral-style.css`, изображения → `storage/uploads/import/`; URLs в контенте переписываются на `/storage/...` (маршрут раздачи).
- Приоритет маршрутов: точное совпадение важнее шаблона (`/menu/items` раньше `/menu/items/{id}`).
- ✅ **Защита `/admin/settings` и `/admin/config` исправлена** (ранее — критическая дыра: любой аутентифицированный админ мог писать в `config.env`). Теперь три уровня: 1) `SuperAdminMiddleware` подключен к маршрутам settings/config в `AdminServiceProvider`; 2) `SettingsController::isSuperAdmin()` и `ConfigViewerController::isSuperAdmin()` — defence-in-depth при прямом вызове мимо middleware; 3) проверка идёт по флагу `admin_user['is_super']` (не по email) через `SessionManager`, а не прямым чтением `$_SESSION`. Тесты: `tests/Unit/Module/Auth/AdminAccessTest.php`.
- Тайминг-нормализация входа реализована в `SuperAdminAuth`: при пустом/неверном пароле супер-админа выполняется `password_verify` против заведомо ложного bcrypt-хеша (`DECOY_HASH`, cost 12) — время ответа неотличимо от успешного входа. Обычные пользователи из таблицы `users`: ветка с неверным email по-прежнему пропускает bcrypt; brute-force гасится rate limiter'ом (`SecurityManager::attempt('admin:login:' . $ip)`).
- ⚠️ **Модуль «Редиректы» (админка) не реализован в коде**: ссылка `/admin/redirects` в topbar ведёт на несуществующие маршруты (`RedirectsController`/`RedirectAdminService` отсутствуют, как и `/admin/authors`). Работает только рантайм-разыменование `Core\Module\Page\RedirectService` (таблица `redirects`, source→target) в fallback-роуте. При реализации соблюдать правила: сборка в `AdminServiceProvider`, стек `AdminMiddleware` + CSRF, проверка прав, GitHub Dark классы из `admin.css`.
- `AdminCspMiddleware` переопределяет CSP админки на `'unsafe-inline'` для `script-src`/`style-src` (ослабление ради WYSIWYG) — nonce-политика публичной части в админке не действует.
- Rate limit логина привязан к IP (`$request->getIp()` зависит от корректности trusted proxies / заголовков); `SECURITY_RATE_LIMIT_MAX=60`, `WINDOW=60` (config.env) — это общий лимит, отдельного порога именно для login не задано.
- `vendor/autoload.php` обязателен: без него сайт белый. После клонирования — `composer dump-autoload`.
- Миграций нет: `structure.sql` применяется мастером (step2) только при чистой установке.
- Проект разрабатывается под shared-хостинг (`.osp/project.ini`, `.htaccess`), корень = webroot; перенос точки входа потребует правки `.htaccess`, `APP_ROOT_PATH` и `Config`.

---

## 14. Соглашения кодирования (обязательно соблюдать)

- Первый блок каждого PHP-файла: `<?php`, `declare(strict_types=1);`, proprietary-заголовок `VampqweEngineMedium — проприетарный PHP-движок` (© 2024–2026). Не удалять.
- Комментарии и сообщения пользователю — на русском; логи/исключения тоже допустимо на русском.
- Все public API типизировано; enum'ы: `Method`, `StatusCode`, `LogLevel`, `EngineState`, `FileSystemType`, `DateFormat`, `TimeUnit`, `SortOrder`, `DataBaseDriver`.
- Исключения по слоям: `ApplicationException`, `HttpException`, `DataBaseException`, `TemplateException`, `ValidationException`, `FileSystemException`, `LoggerException`, `MapException`, `SecurityException`, `ErrorException`, `TimeDateException` — фабрики вида `X::someReason(...)`.
- Неймспейсы PascalCase даже для составных слов (`DataBase`, `TimeDate`) — не переименовывать: на это завязаны пути PSR-4.
- Контроллеры модулей собираются вручную в провайдерах через `$container->get(...)` (замыкание `fn(Request $r) => $controller()->method($r)`), а не аннотациями.
- Никаких сторонних библиотек; новая функциональность — только средствами ядра.
- Не хранить секреты в коде/репозитории; всё в `config.env` (в .gitignore).

---

## 15. Лицензия

Проприетарное ПО © 2024–2026, автор — Дедюля Александр Иванович (VampqweEngine).
Копирование, распространение, модификация и использование без письменного разрешения правообладателя запрещены.
Контакт — в LICENSE-файле дистрибутива. Репозиторий: `https://github.com/Vampqwe/VampqweEngine.git`.
