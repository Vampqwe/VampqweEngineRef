<?php

declare(strict_types=1);

namespace Tests\Unit\Module\Auth;

use Core\Module\Admin\Controllers\ConfigViewerController;
use Core\Module\Admin\Controllers\SettingsController;
use Core\Module\Admin\Services\AdminService;
use Core\Module\Admin\Services\ConfigFileParser;
use Core\Module\Admin\Services\SettingsService;
use Core\Module\Auth\Middleware\AuthMiddleware;
use Core\Module\Auth\Middleware\SuperAdminMiddleware;
use Core\Module\Auth\Services\SuperAdminAuth;
use Core\System\Config\Config;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Security\SecurityManager;
use Core\System\Template\Template;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Unit\Module\Auth\Fakes\FakeSessionManager;

/**
 * Тесты разграничения прав модуля админки (исправление уязвимости
 * «любой аутентифицированный пользователь получал доступ к /admin/settings»).
 *
 * Покрывают:
 *  - SuperAdminMiddleware: 302 для гостя, визуальный 403 для обычного админа,
 *    пропуск супер-админа;
 *  - defence-in-depth в SettingsController/ConfigViewerController (прямые вызовы
 *    методов мимо middleware);
 *  - загрузку супер-админа из config.env (SuperAdminAuth), включая анти-timing
 *    ветку с DECOY_HASH при пустом хеше.
 */
final class AdminAccessTest extends TestCase
{
    private string $tmpRoot;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/adminaccess_' . uniqid('', true);
        mkdir($this->tmpRoot . '/logs', 0777, true);
        mkdir($this->tmpRoot . '/storage', 0777, true);

        // Валидный bcrypt cost=12 для пароля 'super-secret' + email супер-админа.
        $hash = password_hash('super-secret', PASSWORD_BCRYPT, ['cost' => 12]);
        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=test\n"
            . "SECURITY_ENFORCE_CONFIG_PERMS=false\n"
            . "SUPER_ADMIN_EMAIL=root@example.test\n"
            . "SUPER_ADMIN_PASSWORD={$hash}\n"
            . "SUPER_ADMIN_NAME=Тест Супер Админ\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        $this->config = Config::init($this->tmpRoot . '/config.env');

        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/settings';
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

    private function makeRequest(string $path = '/admin/settings'): Request
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

    // ─────────── SuperAdminMiddleware ───────────

    public function testGuestIsRedirectedToLogin(): void
    {
        $middleware = new SuperAdminMiddleware($this->sessionWith(null));

        $response = $middleware->handle(
            $this->makeRequest(),
            static fn (): Response => $this->fail('next() не должен вызываться для гостя')
        );

        self::assertSame(302, $response->getStatusCode()->value);
        self::assertStringContainsString('/admin/login', (string) $response->getHeader('Location'));
    }

    public function testRegularAdminGetsVisual403(): void
    {
        $middleware = new SuperAdminMiddleware($this->regularAdminSession());

        $response = $middleware->handle(
            $this->makeRequest(),
            static fn (): Response => $this->fail('next() не должен вызываться для обычного админа')
        );

        self::assertSame(403, $response->getStatusCode()->value);
        $body = $response->getContent();
        self::assertStringContainsString('403', $body);
        self::assertStringContainsString('Доступ запрещён', $body);
        // Не раскрываем детали учётной записи в ответе.
        self::assertStringNotContainsString('editor@example.test', $body);
    }

    public function testSuperAdminPassesMiddleware(): void
    {
        $middleware = new SuperAdminMiddleware($this->superAdminSession());

        $reached = false;
        $response = $middleware->handle($this->makeRequest(), function () use (&$reached): Response {
            $reached = true;
            return Response::text('controller-reached');
        });

        self::assertTrue($reached, 'Супер-админ должен доходить до контроллера');
        self::assertSame('controller-reached', $response->getContent());
    }

    public function testAuthMiddlewareBlocksGuestButLetsAnyAdminThrough(): void
    {
        $guestResponse = (new AuthMiddleware($this->sessionWith(null)))->handle(
            $this->makeRequest(),
            static fn (): Response => $this->fail('гость не должен проходить AuthMiddleware')
        );
        self::assertSame(302, $guestResponse->getStatusCode()->value);

        $adminResponse = (new AuthMiddleware($this->regularAdminSession()))->handle(
            $this->makeRequest(),
            static fn (): Response => Response::text('ok')
        );
        self::assertSame('ok', $adminResponse->getContent());
    }

    // ─────────── Defence-in-depth в контроллерах (вызовы напрямую) ───────────

    public function testSettingsIndexForbiddenForRegularAdminEvenWhenCalledDirectly(): void
    {
        $controller = $this->makeSettingsController($this->regularAdminSession());

        $response = $controller->index($this->makeRequest());

        self::assertSame(403, $response->getStatusCode()->value);
    }

    public function testSettingsUpdateForbiddenForGuestEvenWhenCalledDirectly(): void
    {
        $controller = $this->makeSettingsController($this->sessionWith(null));

        $response = $controller->update($this->makeRequest());

        self::assertSame(403, $response->getStatusCode()->value);
    }

    public function testConfigViewerIndexForbiddenForRegularAdmin(): void
    {
        $controller = new ConfigViewerController(
            $this->createMock(ConfigFileParser::class),
            $this->createMock(AdminService::class),
            $this->createMock(Template::class),
            $this->createMock(SecurityManager::class),
            $this->regularAdminSession(),
        );

        $response = $controller->index($this->makeRequest());

        self::assertSame(403, $response->getStatusCode()->value);
    }

    public function testConfigViewerSaveForbiddenForGuest(): void
    {
        $controller = new ConfigViewerController(
            $this->createMock(ConfigFileParser::class),
            $this->createMock(AdminService::class),
            $this->createMock(Template::class),
            $this->createMock(SecurityManager::class),
            $this->sessionWith(null),
        );

        $response = $controller->save($this->makeRequest());

        self::assertSame(403, $response->getStatusCode()->value);
    }

    // ─────────── Загрузка супер-админа (SuperAdminAuth) ───────────

    public function testSuperAdminLoginSucceedsFromConfigEnv(): void
    {
        $auth = new SuperAdminAuth($this->config);

        self::assertTrue($auth->isSuperAdmin('root@example.test', 'super-secret'));

        $data = $auth->getSuperAdminData();
        self::assertSame(0, $data['id'], 'Супер-админ не хранится в БД — id всегда 0');
        self::assertSame('super_admin', $data['role']);
        self::assertTrue($data['is_super']);
        self::assertSame('root@example.test', $data['email']);
    }

    public function testWrongPasswordOrEmailRejected(): void
    {
        $auth = new SuperAdminAuth($this->config);

        self::assertFalse($auth->isSuperAdmin('root@example.test', 'wrong-password'));
        self::assertFalse($auth->isSuperAdmin('hacker@example.test', 'super-secret'));
    }

    public function testEmptyHashConfigDisablesSuperAdminAndUsesDecoyBranch(): void
    {
        // SUPER_ADMIN_PASSWORD пустой → super-admin недоступен, но ветка
        // password_verify(DECOY_HASH) обязана отрабатывать без fatal/warning.
        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=test\nSECURITY_ENFORCE_CONFIG_PERMS=false\n"
            . "SUPER_ADMIN_EMAIL=root@example.test\nSUPER_ADMIN_PASSWORD=\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);
        Config::reset();
        $config = Config::init($this->tmpRoot . '/config.env');

        $auth = new SuperAdminAuth($config);

        self::assertFalse($auth->isSuperAdmin('root@example.test', 'super-secret'));
        self::assertFalse($auth->isSuperAdmin('root@example.test', ''));
    }

    public function testTimingNormalizedBetweenBadEmailAndGoodEmailBranches(): void
    {
        $auth = new SuperAdminAuth($this->config);

        // Замеры зашумлены в CI — проверяем только нижнюю границу нормализации
        // (MIN_RESPONSE_MS = 50 мс): обе ветки не быстрее порога.
        $measure = static function (string $email) use ($auth): float {
            $start = microtime(true);
            $auth->isSuperAdmin($email, 'definitely-wrong-password');
            return (microtime(true) - $start) * 1000;
        };

        self::assertGreaterThanOrEqual(40.0, $measure('nobody@example.test'),
            'ветка неверного email не быстрее анти-timing порога');
        self::assertGreaterThanOrEqual(40.0, $measure('root@example.test'),
            'ветка верного email не быстрее анти-timing порога');
    }

    public function testDecoyHashIsValidBcryptAndNeverMatches(): void
    {
        $decoy = (string) (new ReflectionClass(SuperAdminAuth::class))->getConstant('DECOY_HASH');

        self::assertSame('2y', password_get_info($decoy)['algo'], 'DECOY_HASH обязан быть валидным bcrypt');
        self::assertFalse(password_verify('any-password', $decoy));
    }

    // ─────────── helpers ───────────

    private function makeSettingsController(FakeSessionManager $session): SettingsController
    {
        return new SettingsController(
            $this->createMock(SettingsService::class),
            $this->createMock(AdminService::class),
            $this->createMock(Template::class),
            $this->createMock(SecurityManager::class),
            $session,
        );
    }
}
