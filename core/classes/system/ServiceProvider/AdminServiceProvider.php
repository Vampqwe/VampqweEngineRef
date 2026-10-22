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

use Core\Module\Admin\Controllers\ConfigViewerController;
use Core\Module\Admin\Controllers\DashboardController;
use Core\Module\Admin\Controllers\ImportController;
use Core\Module\Admin\Controllers\MenuController;
use Core\Module\Admin\Controllers\PagesController;
use Core\Module\Admin\Controllers\SchemaController;
use Core\Module\Admin\Controllers\SettingsController;
use Core\Module\Admin\Middleware\AdminMiddleware;
use Core\Module\Admin\Services\AdminService;
use Core\Module\Admin\Services\ConfigFileParser;
use Core\Module\Admin\Services\HtmlImportParser;
use Core\Module\Admin\Services\MenuAdminService;
use Core\Module\Admin\Services\PageService;
use Core\Module\Admin\Services\SchemaService;
use Core\Module\Admin\Services\SettingsService;
use Core\Module\Auth\Controllers\AuthController;
use Core\Module\Auth\Services\SuperAdminAuth;
use Core\Module\Install\Controllers\InstallController;
use Core\Module\Install\Middleware\InstallAccessMiddleware;
use Core\Module\Install\Services\InstallService;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\Router;
use Core\System\Security\HtmlSanitizer;
use Core\System\Security\SecurityManager;
use Core\System\Session\SessionManager;
use Core\System\Template\Template;

class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $db = $this->container->get(DataBase::class);
        $config = $this->config;
        $basePath = $config->getRootPath();

        $this->container->singleton(InstallService::class, fn() =>
            new InstallService($config, $basePath)
        );
        // Единый экземпляр SessionManager: flash-сообщения AdminService и
        // сессионные данные AuthMiddleware должны жить в одном месте.
        $this->container->singleton(AdminService::class, fn() =>
            new AdminService($db, $this->container->get(SessionManager::class))
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

        // Регистрация SchemaService
        $this->container->singleton(SchemaService::class, fn() =>
            new SchemaService($this->container->get(DataBase::class))
        );

        $this->container->singleton(SettingsService::class, fn() =>
            new SettingsService(
                $this->container->get(Config::class),
                $this->container->get(SecurityManager::class),
                $this->container->get(DataBase::class)
            )
        );

        $this->container->singleton(ConfigFileParser::class, fn() =>
            new ConfigFileParser(
                $this->container->get(Config::class),
                $this->container->get(SecurityManager::class)
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

        // ─── Фабрики контроллеров ───
        $configViewerController = static fn(): ConfigViewerController => new ConfigViewerController(
            $container->get(ConfigFileParser::class),
            $container->get(AdminService::class),
            $container->get(Template::class),
            $container->get(SecurityManager::class)
        );

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
            $container->get(Template::class),
            $container->get(SessionManager::class)
        );
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
            $container->get(SecurityManager::class),
            $container->get(AdminService::class)
        );
        $settingsController = static fn(): SettingsController => new SettingsController(
            $container->get(SettingsService::class),
            $container->get(AdminService::class),
            $container->get(Template::class),
            $container->get(SecurityManager::class)
        );
        $dashboardController = static fn(): DashboardController => new DashboardController(
            $container->get(AdminService::class),
            $container->get(Template::class),
            $container->get(SecurityManager::class),
            $container->get(SessionManager::class)
        );

        $installAccess = new InstallAccessMiddleware(
            $container->get(InstallService::class),
            $container->get(SecurityManager::class)
        );

        // ─── Маршруты установки ───
        $router->group('/admin/install', $installAccess)
            ->get('/', fn(Request $request) => $installController()->step1($request))->name('admin.install.step1');
        $router->group('/admin/install', $installAccess)->post('/step1', fn(Request $request) => $installController()->step1($request));
        $router->group('/admin/install', $installAccess)->get('/step2', fn(Request $request) => $installController()->step2($request));
        $router->group('/admin/install', $installAccess)->post('/step2', fn(Request $request) => $installController()->step2($request));
        $router->group('/admin/install', $installAccess)->get('/step3', fn(Request $request) => $installController()->step3($request));
        $router->group('/admin/install', $installAccess)->post('/step3', fn(Request $request) => $installController()->step3($request));
        $router->group('/admin/install', $installAccess)->get('/step4', fn(Request $request) => $installController()->step4($request));
        $router->group('/admin/install', $installAccess)->post('/step4', fn(Request $request) => $installController()->step4($request));
        $router->group('/admin/install', $installAccess)->get('/step5', fn(Request $request) => $installController()->step5($request));
        $router->group('/admin/install', $installAccess)->post('/step5', fn(Request $request) => $installController()->step5($request));

        // ─── Публичные маршруты авторизации ───
        $router->get('/admin/login', fn(Request $request) => $authController()->showLogin($request))->name('admin.login');
        $router->post('/admin/login', fn(Request $request) => $authController()->login($request));

        // ─── Защищённые маршруты ───
        $admin = $router->group('/admin', new AdminMiddleware(
            $container->get(InstallService::class),
            $container->get(SecurityManager::class),
            $container->get(AdminService::class)
        ));

        $admin->get('/', fn(Request $request) => $dashboardController()->index($request))->name('admin.dashboard');

        // ─── Выход (POST + CSRF через CsrfMiddleware группы /admin) ───
        $admin->post('/logout', fn(Request $request) => $authController()->logout($request))->name('admin.logout');

        // ─── CRUD страниц ───
        $admin->get('/pages', fn(Request $request) => $pagesController()->index($request))->name('admin.pages.index');
        $admin->get('/pages/new', fn(Request $request) => $pagesController()->create($request))->name('admin.pages.create');
        $admin->post('/pages/store', fn(Request $request) => $pagesController()->store($request))->name('admin.pages.store');
        $admin->get('/pages/{id}/edit', fn(Request $request) => $pagesController()->edit($request))->name('admin.pages.edit');
        $admin->post('/pages/{id}/update', fn(Request $request) => $pagesController()->update($request))->name('admin.pages.update');
        $admin->post('/pages/{id}/delete', fn(Request $request) => $pagesController()->destroy($request))->name('admin.pages.delete');

        // ─── Schema.org страницы — AJAX CRUD ───
        $admin->get('/pages/{id}/schemas', fn(Request $request) => $pagesController()->schemas($request))->name('admin.pages.schemas');
        $admin->post('/pages/{id}/schemas', fn(Request $request) => $pagesController()->schemaStore($request))->name('admin.pages.schemas.store');
        $admin->post('/pages/{id}/schemas/{schemaId}', fn(Request $request) => $pagesController()->schemaUpdate($request))->name('admin.pages.schemas.update');
        $admin->post('/pages/{id}/schemas/{schemaId}/delete', fn(Request $request) => $pagesController()->schemaDelete($request))->name('admin.pages.schemas.delete');

        // ─── Управление Schema.org (Глобальное) ───
        // Контроллер берётся из DI — зависимости разрешаются автоматически.
        $admin->get('/schema', fn(Request $request) => $container->get(SchemaController::class)->index($request))->name('admin.schema.index');
        $admin->get('/schema/new', fn(Request $request) => $container->get(SchemaController::class)->create($request))->name('admin.schema.create');
        $admin->post('/schema/store', fn(Request $request) => $container->get(SchemaController::class)->store($request))->name('admin.schema.store');
        $admin->get('/schema/{id}/edit', fn(Request $request) => $container->get(SchemaController::class)->edit($request))->name('admin.schema.edit')->where('id', '\d+');
        $admin->post('/schema/{id}/update', fn(Request $request) => $container->get(SchemaController::class)->update($request))->name('admin.schema.update')->where('id', '\d+');
        $admin->post('/schema/{id}/delete', fn(Request $request) => $container->get(SchemaController::class)->destroy($request))->name('admin.schema.delete')->where('id', '\d+');

        // ─── Настройки (только для супер-админа) ───
        $admin->get('/settings', fn(Request $request): Response => $settingsController()->index($request))->name('admin.settings.index');
        $admin->post('/settings', fn(Request $request): Response => $settingsController()->update($request))->name('admin.settings.update');
        $admin->post('/settings/rebuild-seed', fn(Request $request): Response => $settingsController()->rebuildSeed($request))->name('admin.settings.rebuild-seed');

        // ─── Импорт страниц ───
        $admin->get('/import', fn(Request $request) => $importController()->index($request))->name('admin.import.index');
        $admin->post('/import/upload', fn(Request $request) => $importController()->upload($request))->name('admin.import.upload');
        $admin->post('/import/confirm', fn(Request $request) => $importController()->confirm($request))->name('admin.import.confirm');

        // ─── Редактор меню (drag-and-drop) ───
        $admin->get('/menu', fn(Request $request) => $menuController()->index($request))->name('admin.menu.index');
        $admin->post('/menu/items', fn(Request $request) => $menuController()->store($request))->name('admin.menu.store');
        $admin->post('/menu/structure', fn(Request $request) => $menuController()->structure($request))->name('admin.menu.structure');
        $admin->post('/menu/items/{id}', fn(Request $request) => $menuController()->update($request))->name('admin.menu.update');
        $admin->post('/menu/items/{id}/delete', fn(Request $request) => $menuController()->destroy($request))->name('admin.menu.delete');

        // ─── Редактор config.env (только супер-админ) ───
        $admin->get('/config', fn(Request $request): Response => $configViewerController()->index($request))->name('admin.config.index');
        $admin->post('/config', fn(Request $request): Response => $configViewerController()->save($request))->name('admin.config.save');
    }
}
