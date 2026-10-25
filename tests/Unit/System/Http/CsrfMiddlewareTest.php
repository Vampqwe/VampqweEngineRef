<?php

declare(strict_types=1);

namespace Tests\Unit\System\Http;

use Core\Module\Admin\Services\AdminService;
use Core\System\Config\Config;
use Core\System\Http\CsrfMiddleware;
use Core\System\Http\Header;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Logger\Logger;
use Core\System\Security\AuditLogger;
use Core\System\Security\CsrfToken;
use Core\System\Security\PathSanitizer;
use Core\System\Security\RateLimiter;
use Core\System\Security\SecurityHeaders;
use Core\System\Security\SecurityManager;
use Core\System\Security\SensitiveDataMasker;
use Core\System\Security\SqlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты CsrfMiddleware: единая CSRF-защита POST-запросов админки
 * и безопасный возврат по Referer (защита от open-redirect).
 */
final class CsrfMiddlewareTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/csrfmw_' . uniqid('', true);
        mkdir($this->tmpRoot . '/logs', 0777, true);
        mkdir($this->tmpRoot . '/storage', 0777, true);

        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=test\nSECURITY_ENFORCE_CONFIG_PERMS=false\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        Config::init($this->tmpRoot . '/config.env');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/settings';
        unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Config::reset();
        $_POST = [];
        $_GET = [];
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        foreach ([$this->tmpRoot . '/config.env',
                 glob($this->tmpRoot . '/logs/*') ?: [],
                 glob($this->tmpRoot . '/storage/*') ?: [],
                 $this->tmpRoot . '/logs', $this->tmpRoot . '/storage', $this->tmpRoot] as $item) {
            if (is_array($item)) {
                continue;
            }
            is_file($item) ? @unlink($item) : @rmdir($item);
        }

        parent::tearDown();
    }

    private function makeSecurity(?CsrfToken $csrf = null): SecurityManager
    {
        $config = Config::getInstance();
        $logger = new Logger($config);

        return new SecurityManager(
            $config,
            $logger,
            new PathSanitizer($this->tmpRoot),
            new SensitiveDataMasker(),
            $csrf ?? new CsrfToken(),
            new RateLimiter($this->tmpRoot . '/storage'),
            new SecurityHeaders(),
            new AuditLogger($logger, new SensitiveDataMasker()),
            new SqlSanitizer()
        );
    }

    private function makeRequest(): Request
    {
        return new Request(Config::getInstance());
    }

    /** @param array<string, mixed> $overrides */
    private function passThrough(array $overrides = []): Response
    {
        $security = $this->makeSecurity();
        $token = $security->getCsrfToken()->getToken();

        $_POST = ['_csrf_token' => $token];
        $request = $this->makeRequest();
        $middleware = new CsrfMiddleware($security);

        return $middleware->handle($request, static fn (Request $r): Response => Response::text('next-called'));
    }

    public function testValidPostPassesMiddleware(): void
    {
        $response = $this->passThrough();

        self::assertSame('next-called', $this->bodyOf($response));
    }

    public function testMissingTokenRedirectsAndDoesNotCallNext(): void
    {
        $security = $this->makeSecurity();
        $flash = $this->createMock(AdminService::class);
        $flash->expects(self::once())->method('setFlash')->with('error', self::anything());

        $middleware = new CsrfMiddleware($security, $flash);
        $response = $middleware->handle($this->makeRequest(),
            static fn (): Response => $this->fail('next() не должен вызываться при невалидном токене'));

        self::assertStringContainsString('/admin', (string) $response->getHeader(Header::LOCATION));
    }

    public function testWrongTokenIsRejected(): void
    {
        $security = $this->makeSecurity();
        $security->getCsrfToken()->getToken(); // создать валидный токен в хранилище

        $_POST = ['_csrf_token' => 'totally-wrong'];
        $middleware = new CsrfMiddleware($security);
        $response = $middleware->handle($this->makeRequest(),
            static fn (): Response => $this->fail('next() не должен вызываться при неверном токене'));

        self::assertNotNull($response->getHeader(Header::LOCATION));
    }

    public function testSafeRefererKeepsInternalAdminPath(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.com/admin/pages/edit?id=5';
        $security = $this->makeSecurity();
        $middleware = new CsrfMiddleware($security);

        $response = $middleware->handle($this->makeRequest(),
            static fn (): Response => Response::text('should-not-run'));

        self::assertSame('/admin/pages/edit', $response->getHeader(Header::LOCATION));
    }

    public function testExternalRefererFallsBackToAdminRoot(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.example.com/phishing-page';
        $security = $this->makeSecurity();
        $middleware = new CsrfMiddleware($security);

        $response = $middleware->handle($this->makeRequest(),
            static fn (): Response => Response::text('should-not-run'));

        self::assertSame('/admin', $response->getHeader(Header::LOCATION));
    }

    public function testNonAdminPathRefererFallsBackToAdminRoot(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.com/public/page';
        $security = $this->makeSecurity();
        $middleware = new CsrfMiddleware($security);

        $response = $middleware->handle($this->makeRequest(),
            static fn (): Response => Response::text('should-not-run'));

        self::assertSame('/admin', $response->getHeader(Header::LOCATION));
    }

    public function testGetRequestSkipsCsrfValidation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $security = $this->makeSecurity();
        $middleware = new CsrfMiddleware($security);

        $response = $middleware->handle($this->makeRequest(),
            static fn (): Response => Response::text('next-ok'));

        self::assertSame('next-ok', $this->bodyOf($response));
    }

    public function testAjaxHeaderTokenAccepted(): void
    {
        $security = $this->makeSecurity();
        $token = $security->getCsrfToken()->getToken();

        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $middleware = new CsrfMiddleware($security);

        $response = $middleware->handle($this->makeRequest(),
            static fn (): Response => Response::text('ajax-ok'));

        self::assertSame('ajax-ok', $this->bodyOf($response));
    }

    private function bodyOf(Response $response): string
    {
        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);

        return (string) $ref->getValue($response);
    }
}
