<?php
declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
 * @contact      Указывается в LICENSE-файле дистрибутива
 *
 * Данный код является интеллектуальной собственностью автора.
 * Любое копирование, распространение, модификация или использование
 * без письменного разрешения правообладателя строго запрещено.
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or use is strictly prohibited.
 */


namespace Core\System\ServiceProvider;

use Core\System\Http\Router;
use Core\System\Http\Response;
use Core\System\Http\Request;
use Core\Module\Install\Controllers\InstallController;
use Core\Module\Auth\Controllers\AuthController;
use Core\Module\Admin\Controllers\DashboardController;
use Core\Module\Install\Services\InstallService;
use Core\Module\Admin\Services\AdminService;
use Core\Module\Auth\Services\SuperAdminAuth;
use Core\Module\Admin\Middleware\AdminMiddleware;
use Core\Module\Install\Middleware\InstallAccessMiddleware;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use Core\System\Security\SecurityManager;
use Core\System\Template\Template;
use Core\Module\Admin\Controllers\PagesController;
use Core\Module\Admin\Services\PageService;
use Core\System\Security\HtmlSanitizer;
use Core\Module\Admin\Controllers\ImportController;
use Core\Module\Admin\Services\HtmlImportParser;
use Core\Module\Admin\Controllers\MenuController;
use Core\Module\Admin\Services\MenuAdminService;
use Core\Module\Admin\Controllers\SchemaController;
use Core\Module\Admin\Controllers\SettingsController;
use Core\Module\Admin\Controllers\ConfigViewerController;
use Core\Module\Admin\Services\SchemaService;
use Core\Module\Admin\Services\SettingsService;
use Core\Module\Admin\Services\ConfigFileParser;
use Core\System\Session\SessionManager;

class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $db = $this->container->get(DataBase::class);
        $config = $this->config;

        // Корень проекта — единый резолвер (канон — APP_ROOT_PATH,
        // см. Config::getRootPath() → PathResolver/ProjectRoot).
        $basePath = $config->getRootPath();
        $this->container->singleton(InstallService::class, fn() =>
            new InstallService($config, $basePath)
        );

        $this->container->singleton(AdminService::class, fn() =>
            new AdminService($db)
        );

        $this->container->singleton(SuperAdminAuth::class, fn() =>
            new SuperAdminAuth($config)
        );

        $this->container->singleton(PageService::class, fn() =>
            new PageService($this->container->get(DataBase::class))
        );

        $this->container->singleton(HtmlSanitizer::class, fn() => new HtmlSanitizer());

        $this->container->singleton(HtmlImportParser::class, fn() =>
            new HtmlImportParser($config)
        );
        $this->container->singleton(MenuAdminService::class, fn() =>
            new MenuAdminService($db)
        );

        // Schema.org: глобальные схемы + AJAX-привязка к страницам.
        $this->container->singleton(SchemaService::class, fn() =>
            new SchemaService($db)
        );

        // Настройки движка (редактирование config.env через UI супер-админа).
        $this->container->singleton(SettingsService::class, fn() =>
            new SettingsService(
                $this->container->get(Config::class),
                $this->container->get(SecurityManager::class),
                $db
            )
        );

        $this->container->singleton(ConfigFileParser::class, fn() =>
            new ConfigFileParser(
                $this->container->get(Config::class),
                $this->container->get(SecurityManager::class)
            )
        );

        // Контроллеры как синглтоны DI — зависимости разрешаются контейнером.
        $this->container->singleton(SchemaController::class, fn() =>
            new SchemaController(
                $this->container->get(SchemaService::class),
                $this->container->get(AdminService::class),
                $this->container->get(Template::class),
                $this->container->get(SecurityManager::class)
            )
        );

        $this->container->singleton(SettingsController::class, fn() =>
            new SettingsController(
                $this->container->get(SettingsService::class),
                $this->container->get(AdminService::class),
                $this->container->get(Template::class),
                $this->container->get(SecurityManager::class),
                $this->container->get(SessionManager::class)
            )
        );

        $this->container->singleton(ConfigViewerController::class, fn() =>
            new ConfigViewerController(
                $this->container->get(ConfigFileParser::class),
                $this->container->get(AdminService::class),
                $this->container->get(Template::class),
                $this->container->get(SecurityManager::class),
                $this->container->get(SessionManager::class)
            )
        );
    }

    public function boot(): void
    {
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $container = $this->container;

        // ─── Фабрики контроллеров (единая точка сборки зависимостей,
        //     устраняет дублирование бойлерплейта на каждый маршрут) ───
        $installController = static fn(): InstallController => new InstallController(
            $container->get(InstallService::class),
            $container->get(AdminService::class),
            $container->get(Config::class),
            $container->get(Template::class),
            $container->get(SecurityManager::class)
        );

        $authController = static fn(): AuthController => new AuthController(
            $container->get(SuperAdminAuth::class),
            $container->get(AdminService::class),
            $container->get(Config::class),
            $container->get(DataBase::class),
            $container->get(SecurityManager::class),
            $container->get(Template::class)
        );

        // Контроллеры Admin-модуля получают SecurityManager обязательным
        // аргументом конструктора (CSRF/audit) — порядок параметров должен
        // совпадать с сигнатурами контроллеров.
        $pagesController = static fn(): PagesController => new PagesController(
            $container->get(PageService::class),
            $container->get(AdminService::class),
            $container->get(Template::class),
            $container->get(SecurityManager::class)
        );

        $importController = static fn(): ImportController => new ImportController(
            $container->get(HtmlImportParser::class),
            $container->get(PageService::class),
            $container->get(AdminService::class),
            $container->get(Template::class),
            $container->get(Config::class),
            $container->get(SecurityManager::class)
        );

        $menuController = static fn(): MenuController => new MenuController(
            $container->get(MenuAdminService::class),
            $container->get(Template::class),
            $container->get(SecurityManager::class)
        );

        $dashboardController = static fn(): DashboardController => new DashboardController(
            $container->get(AdminService::class),
            $container->get(Template::class),
            $container->get(SessionManager::class)
        );

        // Критическая защита мастера установки: доступ только по ключу
        // активации (INSTALL_KEY в config.env) либо по сессии администратора,
        // обязательная CSRF-валидация POST и rate limiting.
        $installAccess = new InstallAccessMiddleware(
            $container->get(InstallService::class),
            $container->get(SecurityManager::class)
        );

        // ─── Маршруты установки ───
        $router->group('/admin/install', $installAccess)
            ->get('/', function (Request $request) use ($installController): Response {
                return $installController()->step1($request);
            })->name('admin.install.step1');

        $router->group('/admin/install', $installAccess)
            ->post('/step1', function (Request $request) use ($installController): Response {
                return $installController()->step1($request);
            });

        $router->group('/admin/install', $installAccess)
            ->get('/step2', function (Request $request) use ($installController): Response {
                return $installController()->step2($request);
            });

        $router->group('/admin/install', $installAccess)
            ->post('/step2', function (Request $request) use ($installController): Response {
                return $installController()->step2($request);
            });

        $router->group('/admin/install', $installAccess)
            ->get('/step3', function (Request $request) use ($installController): Response {
                return $installController()->step3($request);
            });

        $router->group('/admin/install', $installAccess)
            ->post('/step3', function (Request $request) use ($installController): Response {
                return $installController()->step3($request);
            });

        $router->group('/admin/install', $installAccess)
            ->get('/step4', function (Request $request) use ($installController): Response {
                return $installController()->step4($request);
            });

        $router->group('/admin/install', $installAccess)
            ->post('/step4', function (Request $request) use ($installController): Response {
                return $installController()->step4($request);
            });

        $router->group('/admin/install', $installAccess)
            ->get('/step5', function (Request $request) use ($installController): Response {
                return $installController()->step5($request);
            });

        // Шаг 5 финализирует установку — только POST (CSRF проверяется middleware).
        $router->group('/admin/install', $installAccess)
            ->post('/step5', function (Request $request) use ($installController): Response {
                return $installController()->step5($request);
            });

        // ─── Публичные маршруты авторизации ───
        $router->get('/admin/login', function (Request $request) use ($authController): Response {
            return $authController()->showLogin($request);
        })->name('admin.login');

        $router->post('/admin/login', function (Request $request) use ($authController): Response {
            return $authController()->login($request);
        });

        // ─── Защищённые маршруты ───
        // Передаем InstallService, SecurityManager и SessionManager в конструктор AdminMiddleware
        $admin = $router->group('/admin', new AdminMiddleware(
            $container->get(InstallService::class),
            $container->get(SecurityManager::class),
            $container->get(SessionManager::class)
        ));

        $admin->get('/', fn(Request $request): Response => $dashboardController()->index($request))
            ->name('admin.dashboard');

        // ─── CRUD страниц ───
        $admin->get('/pages', function (Request $request) use ($pagesController): Response {
            return $pagesController()->index($request);
        })->name('admin.pages.index');

        $admin->get('/pages/new', function (Request $request) use ($pagesController): Response {
            return $pagesController()->create($request);
        })->name('admin.pages.create');

        $admin->post('/pages/store', function (Request $request) use ($pagesController): Response {
            return $pagesController()->store($request);
        })->name('admin.pages.store');

        $admin->get('/pages/{id}/edit', function (Request $request) use ($pagesController): Response {
            return $pagesController()->edit($request);
        })->name('admin.pages.edit');

        $admin->post('/pages/{id}/update', function (Request $request) use ($pagesController): Response {
            return $pagesController()->update($request);
        })->name('admin.pages.update');

        $admin->post('/pages/{id}/delete', function (Request $request) use ($pagesController): Response {
            return $pagesController()->destroy($request);
        })->name('admin.pages.delete');

        $admin->post('/logout', function (Request $request) use ($authController): Response {
            return $authController()->logout($request);
        })->name('admin.logout');

        // ─── Импорт страниц ───
        $admin->get('/import', function (Request $request) use ($importController): Response {
            return $importController()->index($request);
        })->name('admin.import.index');

        $admin->post('/import/upload', function (Request $request) use ($importController): Response {
            return $importController()->upload($request);
        })->name('admin.import.upload');

        $admin->post('/import/confirm', function (Request $request) use ($importController): Response {
            return $importController()->confirm($request);
        })->name('admin.import.confirm');

        // ─── Редактор меню (drag-and-drop) ───
        // Важно: статический /menu/items регистрируется раньше, чем шаблонный
        // /menu/items/{id}, чтобы точное совпадение имело приоритет.
        $admin->get('/menu', function (Request $request) use ($menuController): Response {
            return $menuController()->index($request);
        })->name('admin.menu.index');

        $admin->post('/menu/items', function (Request $request) use ($menuController): Response {
            return $menuController()->store($request);
        })->name('admin.menu.store');

        $admin->post('/menu/structure', function (Request $request) use ($menuController): Response {
            return $menuController()->structure($request);
        })->name('admin.menu.structure');

        $admin->post('/menu/items/{id}', function (Request $request) use ($menuController): Response {
            return $menuController()->update($request);
        })->name('admin.menu.update');

        $admin->post('/menu/items/{id}/delete', function (Request $request) use ($menuController): Response {
            return $menuController()->destroy($request);
        })->name('admin.menu.delete');

        // ─── Настройки (редактирование config.env, супер-админ) ───
        $admin->get('/settings', fn(Request $request): Response => $container->get(SettingsController::class)->index($request))
            ->name('admin.settings.index');
        $admin->post('/settings', fn(Request $request): Response => $container->get(SettingsController::class)->update($request))
            ->name('admin.settings.update');
        $admin->post('/settings/rebuild-seed', fn(Request $request): Response => $container->get(SettingsController::class)->rebuildSeed($request))
            ->name('admin.settings.rebuild-seed');

        // ─── Управление Schema.org (глобальные схемы) ───
        $admin->get('/schema', fn(Request $request): Response => $container->get(SchemaController::class)->index($request))
            ->name('admin.schema.index');
        $admin->get('/schema/new', fn(Request $request): Response => $container->get(SchemaController::class)->create($request))
            ->name('admin.schema.create');
        $admin->post('/schema/store', fn(Request $request): Response => $container->get(SchemaController::class)->store($request))
            ->name('admin.schema.store');
        $admin->get('/schema/{id}/edit', fn(Request $request): Response => $container->get(SchemaController::class)->edit($request))
            ->name('admin.schema.edit')->where('id', '\d+');
        $admin->post('/schema/{id}/update', fn(Request $request): Response => $container->get(SchemaController::class)->update($request))
            ->name('admin.schema.update')->where('id', '\d+');
        $admin->post('/schema/{id}/delete', fn(Request $request): Response => $container->get(SchemaController::class)->destroy($request))
            ->name('admin.schema.delete')->where('id', '\d+');

        // ─── Просмотр/редактирование config.env напрямую (только супер-админ) ───
        $admin->get('/config', fn(Request $request): Response => $container->get(ConfigViewerController::class)->index($request))
            ->name('admin.config.index');
        $admin->post('/config', fn(Request $request): Response => $container->get(ConfigViewerController::class)->save($request))
            ->name('admin.config.save');
    }
}