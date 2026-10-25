<?php

declare(strict_types=1);

namespace Tests\Unit\Module\Admin;

use Core\Module\Admin\Controllers\RedirectsController;
use Core\Module\Admin\Services\AdminService;
use Core\Module\Admin\Services\RedirectAdminService;
use Core\Module\Auth\Middleware\SuperAdminMiddleware;
use Core\System\Config\Config;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Security\SecurityManager;
use Core\System\Template\Template;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Module\Auth\Fakes\FakeSessionManager;

/**
 * Тесты защиты страницы «Редиректы» (/admin/redirects).
 *
 * Покрывают:
 *  - SuperAdminMiddleware на маршрутах редиректов: гость → 302 на логин,
 *    обычный админ → визуальный 403 без утечки email, супер-админ → next;
 *  - defence-in-depth в RedirectsController: прямой вызов index/edit/store/
 *    update/destroy/resetHits мимо middleware возвращает 403 для гостя и
 *    обычного админа (проверка по флагу is_super из сессии).
 */
final class RedirectsAccessTest extends TestCase
{
    private string $tmpRoot;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/redirectsaccess_' . uniqid('', true);
        mkdir($this->tmpRoot . '/logs', 0777, true);
        mkdir($this->tmpRoot . '/storage', 0777, true);
        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=test\n"
            . "SECURITY_ENFORCE_CONFIG_PERMS=false\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        $this->config = Config::init($this->tmpRoot . '/config.env');

        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/redirects';
        unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }

    protected function tearDown(): void
    {
        Config::reset();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];

        foreach (array_merge(
            glob($this->tmpRoot . '/logs/*') ?: [],
            glob($this->tmpRoot . '/storage/*') ?: [],
            [$this->tmpRoot . '/config.env', $this->tmpRoot . '/logs', $this->tmpRoot . '/storage', $this->tmpRoot]
        ) as $item) {
            is_file($item) ? @unlink($item) : @rmdir($item);
        }

        parent::tearDown();
    }

    private function makeRequest(string $path = '/admin/redirects'): Request
    {
        $_SERVER['REQUEST_URI'] = $path;
        return new Request($this->config);
    }

    /** @param array<string, mixed>|null $adminUser */
    private function sessionWith(?array $adminUser): FakeSessionManager
    {
        $session = new FakeSessionManager($this->config);
        if ($adminUser !== null) {
            $session->set(FakeSessionManager::ADMIN_KEY, $adminUser);
        }
        return $session;
    }

    private function superAdminSession(): FakeSessionManager
    {
        return $this->sessionWith([
            'id' => 0,
            'email' => 'root@example.test',
            'role' => 'super_admin',
            'is_super' => true,
        ]);
    }

    private function regularAdminSession(): FakeSessionManager
    {
        return $this->sessionWith([
            'id' => 5,
            'email' => 'editor@example.test',
            'role' => 'admin',
            'is_super' => false,
        ]);
    }

    private function makeController(FakeSessionManager $session): RedirectsController
    {
        return new RedirectsController(
            $this->createMock(RedirectAdminService::class),
            $this->createMock(AdminService::class),
            $this->createMock(Template::class),
            $this->createMock(SecurityManager::class),
            $session,
        );
    }

    // ─────────── SuperAdminMiddleware на /admin/redirects ───────────

    public function testGuestOnRedirectsIsRedirectedToLogin(): void
    {
        $middleware = new SuperAdminMiddleware($this->sessionWith(null));

        $response = $middleware->handle(
            $this->makeRequest(),
            static fn (): Response => Response::text('reached')
        );

        self::assertSame(302, $response->getStatusCode()->value);
        self::assertStringContainsString('/admin/login', $response->getHeader('Location') ?? '');
    }

    public function testRegularAdminOnRedirectsGetsVisualForbiddenWithoutEmailLeak(): void
    {
        $middleware = new SuperAdminMiddleware($this->regularAdminSession());

        $response = $middleware->handle(
            $this->makeRequest(),
            static fn (): Response => Response::text('reached')
        );

        self::assertSame(403, $response->getStatusCode()->value);
        self::assertStringContainsString('Доступ запрещён', $response->getContent());
        self::assertStringNotContainsString('editor@example.test', $response->getContent());
    }

    public function testSuperAdminPassesMiddlewareOnRedirects(): void
    {
        $middleware = new SuperAdminMiddleware($this->superAdminSession());

        $response = $middleware->handle(
            $this->makeRequest(),
            static fn (): Response => Response::text('reached')
        );

        self::assertSame('reached', $response->getContent());
    }

    // ─────────── Defence-in-depth в контроллере (прямые вызовы) ───────────

    public function testIndexForbiddenForRegularAdminEvenWhenCalledDirectly(): void
    {
        $controller = $this->makeController($this->regularAdminSession());

        $response = $controller->index($this->makeRequest());

        self::assertSame(403, $response->getStatusCode()->value);
    }

    public function testEditForbiddenForGuestEvenWhenCalledDirectly(): void
    {
        $controller = $this->makeController($this->sessionWith(null));

        $response = $controller->edit($this->makeRequest());

        self::assertSame(403, $response->getStatusCode()->value);
    }

    public function testStoreUpdateDestroyResetHitsForbiddenForRegularAdmin(): void
    {
        $controller = $this->makeController($this->regularAdminSession());
        $request = $this->makeRequest();

        foreach (['store', 'update', 'destroy', 'resetHits'] as $action) {
            $response = $controller->{$action}($request);
            self::assertSame(403, $response->getStatusCode()->value, "метод {$action} обязан отдавать 403");
        }
    }

    public function testEmailComparisonAloneDoesNotGrantAccess(): void
    {
        // Пользователь с email супер-админа, но без флага is_super — доступ закрыт
        // (регрессия на прежнюю сверку прав по email).
        $session = $this->sessionWith([
            'id' => 7,
            'email' => 'root@example.test',
            'role' => 'admin',
            'is_super' => false,
        ]);
        $controller = $this->makeController($session);

        $response = $controller->index($this->makeRequest());

        self::assertSame(403, $response->getStatusCode()->value);
    }
}
