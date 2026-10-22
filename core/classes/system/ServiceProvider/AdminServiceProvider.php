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
use Core\Module\Admin\Services\SchemaService;
use Core\Module\Admin\Controllers\ConfigViewerController;
use Core\Module\Admin\Services\ConfigFileParser;

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
        
        // ═══ ДОБАВЛЕНО: Регистрация SchemaService ═══
        $this->container->singleton(SchemaService::class, fn() =>
            new SchemaService($this->container->get(DataBase::class))
        );

        $this->container->singleton(\Core\Module\Admin\Services\SettingsService::class, fn() =>
            new \Core\Module\Admin\Services\SettingsService(
                $this->container->get(\Core\System\Config\Config::class),
                $this->container->get(\Core\System\Security\SecurityManager::class),
				$this->container->get(\Core\System\DataBase\DataBase::class)
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
		
		$configViewerController = static fn(): ConfigViewerController => new ConfigViewerController(
			$container->get(ConfigFileParser::class),
			$container->get(AdminService::class),
			$container->get(Template::class),
			$container->get(SecurityManager::class)
		);

        // ─── Фабрики контроллеров ───
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
			$container->get(\Core\System\Session\SessionManager::class)
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
		$settingsController = static fn(): \Core\Module\Admin\Controllers\SettingsController => 
			new \Core\Module\Admin\Controllers\SettingsController(
				$container->get(\Core\Module\Admin\Services\SettingsService::class),
				$container->get(\Core\Module\Admin\Services\AdminService::class),
				$container->get(\Core\System\Template\Template::class),
				$container->get(\Core\System\Security\SecurityManager::class)
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

        $admin->get('/', function (Request $request) use ($container): Response {
			$controller = new DashboardController(
			$container->get(AdminService::class),
			$container->get(Template::class),
			$container->get(SecurityManager::class),                                // 3-й: SecurityManager
			$container->get(\Core\System\Session\SessionManager::class)             // 4-й: SessionManager
			);
			return $controller->index($request);
		})->name('admin.dashboard');

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
        // Используем $this->app->make, так как SchemaController зарегистрирован в DI и его зависимости разрешатся автоматически
        $admin->get('/schema', fn(Request $request) => $this->app->make(SchemaController::class)->index($request))->name('admin.schema.index');
        $admin->get('/schema/new', fn(Request $request) => $this->app->make(SchemaController::class)->create($request))->name('admin.schema.create');
        $admin->post('/schema/store', fn(Request $request) => $this->app->make(SchemaController::class)->store($request))->name('admin.schema.store');
        $admin->get('/schema/{id}/edit', fn(Request $request) => $this->app->make(SchemaController::class)->edit($request))->name('admin.schema.edit')->where('id', '\d+');
        $admin->post('/schema/{id}/update', fn(Request $request) => $this->app->make(SchemaController::class)->update($request))->name('admin.schema.update')->where('id', '\d+');
        $admin->post('/schema/{id}/delete', fn(Request $request) => $this->app->make(SchemaController::class)->destroy($request))->name('admin.schema.delete')->where('id', '\d+');
        
		$admin->post('/logout', fn(Request $request) => $authController()->logout($request))->name('admin.logout');
        // ─── Настройки (только для супер-админа) ───
		$admin->get('/settings', function (Request $request) use ($settingsController): Response {
			return $settingsController()->index($request);
		})->name('admin.settings.index');

		$admin->post('/settings', function (Request $request) use ($settingsController): Response {
			return $settingsController()->update($request);
		})->name('admin.settings.update');
		
		$admin->post('/settings/rebuild-seed', function (Request $request) use ($settingsController): Response {
			return $settingsController()->rebuildSeed($request);
		})->name('admin.settings.rebuild-seed');

        //$admin->post('/logout', fn(Request $request) => $authController()->logout($request))->name('admin.logout');

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
		$admin->get('/config', function (Request $request) use ($configViewerController): Response {
			return $configViewerController()->index($request);
		})->name('admin.config.index');

		$admin->post('/config', function (Request $request) use ($configViewerController): Response {
			return $configViewerController()->save($request);
		})->name('admin.config.save');
		
    }
}