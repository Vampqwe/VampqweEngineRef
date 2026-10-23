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
use Core\System\Http\StatusCode;
use Core\System\Application\EngineState;
use Core\System\Application\InstalledGuard;
use Core\System\Diagnostics\ProjectChecks;
use Core\System\Security\SecurityManager;
use Core\System\Security\SecurityException;
use Core\Module\Page\PageController;
use Core\Module\Page\PageService;
use Core\Module\Page\RedirectService;
use Core\Module\Page\MenuService;
use Core\System\Template\Template;
use Core\Module\Page\SitemapController;

class RouterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Container::singleton() кеширует результат фабрики в $instances,
        // поэтому ленивое создание гарантирует единственный экземпляр Router
        // в рамках контейнера (без утечки состояния между приложениями).
        $this->container->singleton(Router::class, fn() => new Router(
            $this->container->get(\Core\System\Http\Request::class),
            $this->config
        ));
    }

    public function boot(): void
    {
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);

        $api = $router->group('/api');

        // ─── Health checks: liveness и readiness разделены ───
        //
        // /api/health       — liveness: process alive? НЕ трогает БД.
        //                     Ответ всегда 200 + честное состояние движка,
        //                     чтобы оркестратор не перезапускал контейнер
        //                     при недоступной базе (шторм рестартов).
        // /api/health/ready — readiness: готов ли serving трафика? Проверяет
        //                     метку установки, соединение с MySQL (с таймаутом)
        //                     и наличие таблиц; 503 + Retry-After при проблемах.

        // ВАЖНО: $container — это свойство провайдера ($this->container),
        // в замыкание оно передаётся через use ($container) только после
        // локального присваивания. Раньше здесь стоял "use ($container)"
        // без определения переменной → на регистрации маршрутов PHP бросал
        // Error «Undefined variable $container», bootstrap падал, и весь
        // сайт (включая /api/health и мастер установки) отдавал HTTP 500.
        $container = $this->container;

        $api->get('/health', function () use ($container): Response {
            /** @var InstalledGuard $guard */
            $guard = $container->get(InstalledGuard::class);
            $state = $guard->state();

            return Response::json([
                'status' => 'alive',                 // процесс жив — всегда 200
                'state'  => $state->value,           // installed | not_installed | db_unreachable
                'time'   => time(),
            ], StatusCode::OK)
                ->setHeader('Cache-Control', 'no-store')
                ->setHeader('X-Engine-State', $state->value);
        })->name('api.health');

        $api->get('/health/ready', function () use ($container): Response {
            /** @var InstalledGuard $guard */
            $guard = $container->get(InstalledGuard::class);
            $state = $guard->state();

            if ($state === EngineState::Installed) {
                return Response::json([
                    'status' => 'ready',
                    'state'  => $state->value,
                    'time'   => time(),
                ], StatusCode::OK)
                    ->setHeader('Cache-Control', 'no-store')
                    ->setHeader('X-Engine-State', $state->value);
            }

            [$code, $message] = match ($state) {
                EngineState::DbUnreachable => [
                    'database_unavailable',
                    'Нет соединения с базой данных. Повторите пробу позже.',
                ],
                default => [
                    'engine_not_installed',
                    'Движок не установлен. Выполните установку через /admin/install.',
                ],
            };

            return Response::json([
                'status'  => 'not_ready',
                'state'   => $state->value,
                'code'    => $code,
                'message' => $message,
                'time'    => time(),
            ], StatusCode::SERVICE_UNAVAILABLE)
                ->setHeader('Cache-Control', 'no-store')
                ->setHeader('Retry-After', '10')
                ->setHeader('X-Engine-State', $state->value);
        })->name('api.health.ready');
		
		// ─── Хранилище импорта (нейтральный CSS и скачанные картинки) ───
		// .htaccess закрывает /storage от веб-сервера, поэтому файлы отдаёт движок.
		// URL в контенте страниц: /storage/neutral-style.css, /storage/uploads/import/*.jpg|png|...
		$router->get('/storage/{file}', function (Request $request) use ($container): Response {
			/** @var \Core\System\Config\Config $config */
			$config = $container->get(\Core\System\Config\Config::class);
			// Корень проекта — единый резолвер (канон — APP_ROOT_PATH,
			// см. Config::getStoragePath()); storage/ вычисляется от корня,
			// а не из упразднённого FS_BASE_PATH.
			$baseDir = realpath($config->getStoragePath());
			if ($baseDir === false) {
				return Response::text('Not Found', StatusCode::NOT_FOUND);
			}

			$rel = (string) $request->getAttribute('file', '');
			// Белый список: только neutral-style.css и картинки из uploads/import
			if ($rel !== 'neutral-style.css' && !preg_match('#^uploads/import/[A-Za-z0-9._-]+$#', $rel)) {
				return Response::text('Not Found', StatusCode::NOT_FOUND);
			}

			$real = realpath($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
			if ($real === false || !is_file($real) || !str_starts_with($real, $baseDir . DIRECTORY_SEPARATOR)) {
				return Response::text('Not Found', StatusCode::NOT_FOUND);
			}

			$mime = match (strtolower(pathinfo($real, PATHINFO_EXTENSION))) {
				'css'         => 'text/css; charset=UTF-8',
				'png'         => 'image/png',
				'jpg', 'jpeg' => 'image/jpeg',
				'gif'         => 'image/gif',
				'webp'        => 'image/webp',
				'svg'         => 'image/svg+xml',
				default       => 'application/octet-stream',
			};
			if (!in_array($mime, ['text/css; charset=UTF-8', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'], true)) {
				return Response::text('Not Found', StatusCode::NOT_FOUND);
			}

			return Response::file($real, $mime)
				->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
		})->where('file', '.+')->name('storage.file');

		// ─── SITEMAP ───
	$router->get('/sitemap.xml', function (Request $request): Response {
		$controller = new \Core\Module\Page\SitemapController(
			$this->container->get(\Core\Module\Page\SitemapService::class),
			$this->config
		);
		return $controller->index($request);
	})->name('sitemap');

        // ─── ROBOTS.TXT (динамический, домен из APP_BASE_URL) ───
        $router->get('/robots.txt', function (Request $request): Response {
            $controller = new \Core\Module\Page\RobotsController($this->config);
            return $controller->index();
        })->name('robots');

        $router->get('/__dev/preview', function (Request $request): Response {
            if (!$this->isPreviewRequestAllowed($request)) {
                return Response::text('Not Found', StatusCode::NOT_FOUND);
            }

            $security = $this->container->get(SecurityManager::class);
            $template = $this->container->get(Template::class);

            return Response::html($template->render('pages/dev-preview', [
                'template' => $template,
                'csrf_token' => $security->getCsrfToken()->getToken(),
                'db_driver_label' => $this->config->get('DB_DRIVER', 'unknown'),
                'preview_tests' => ProjectChecks::definitions(),
            ]));
        })->name('dev.preview');

        $router->post('/__dev/preview/run', function (Request $request): Response {
            if (!$this->isPreviewRequestAllowed($request)) {
                return Response::json(['error' => 'Not Found'], StatusCode::NOT_FOUND);
            }

            $security = $this->container->get(SecurityManager::class);
            $limit = $security->attempt('action:dev-preview:' . $request->getIp());
            if (!$limit['allowed']) {
                return Response::json(['error' => 'Too many test runs'], StatusCode::TOO_MANY_REQUESTS)
                    ->setHeader('Retry-After', (string) $limit['retry_after']);
            }

            try {
                $security->validateCsrf($request->input('csrf_token'));
            } catch (SecurityException) {
                return Response::json(['error' => 'Invalid CSRF token'], StatusCode::FORBIDDEN);
            }

            $selected = $request->input('tests', []);
            if (!is_array($selected) || $selected === []) {
                return Response::json(['error' => 'Select at least one test'], StatusCode::BAD_REQUEST);
            }

            $allowedTests = array_keys(ProjectChecks::definitions());
            foreach ($selected as $testId) {
                if (!is_string($testId) || !in_array($testId, $allowedTests, true)) {
                    return Response::json(['error' => 'Unknown test selection'], StatusCode::BAD_REQUEST);
                }
            }

            $checks = new ProjectChecks($this->app);
            $results = $checks->run($selected);

            return Response::json([
                'results' => $results,
                'passed' => count(array_filter($results, static fn(array $result): bool => $result['status'] === 'passed')),
                'failed' => count(array_filter($results, static fn(array $result): bool => $result['status'] === 'failed')),
            ]);
        })->name('dev.preview.run');

        // Fallback — все остальные запросы идут в PageController
        $router->fallback(function (\Core\System\Http\Request $request) {
            $controller = new PageController(
                $this->container->get(PageService::class),
                $this->container->get(RedirectService::class),
                $this->container->get(MenuService::class),
                $this->container->get(Template::class),
                $this->config
            );

            return $controller->show($request);
        });
    }

    private function isPreviewRequestAllowed(Request $request): bool
    {
        if (!$this->config->getBool('DEV_PREVIEW_ENABLED', false)
            || !$this->config->getBool('APP_DEBUG', false)) {
            return false;
        }

        $packedIp = inet_pton($request->getIp());
        if ($packedIp === false) {
            return false;
        }

        $ipv4Loopback = inet_pton('127.0.0.1');
        $ipv6Loopback = inet_pton('::1');

        return $packedIp === $ipv6Loopback
            || (strlen($packedIp) === 4 && ord($packedIp[0]) === 127);
    }
}