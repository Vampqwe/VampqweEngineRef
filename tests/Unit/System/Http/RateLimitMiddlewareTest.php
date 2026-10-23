<?php

declare(strict_types=1);

namespace Tests\Unit\System\Http;

use Core\System\Config\Config;
use Core\System\Http\RateLimitMiddleware;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\StatusCode;
use Core\System\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты глобального rate-limit middleware: пропуск под лимитом,
 * 429 поверх лимита, исключения путей и fail-open при сбое хранилища.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private string $tmpRoot;
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;

        $this->tmpRoot = sys_get_temp_dir() . '/rlmw_' . uniqid('', true);
        mkdir($this->tmpRoot . '/logs', 0777, true);
        mkdir($this->tmpRoot . '/storage', 0777, true);

        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=test\n"
            . "SECURITY_ENFORCE_CONFIG_PERMS=false\nAPP_ROOT_PATH=\"{$this->tmpRoot}\"\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        Config::init($this->tmpRoot . '/config.env');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['REQUEST_URI'] = '/some-page';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_ACCEPT']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Config::reset();
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function middleware(int $max = 3): RateLimitMiddleware
    {
        $limiter = new RateLimiter(
            $this->tmpRoot . '/storage/rate',
            $max,
            60,
            true
        );

        return new RateLimitMiddleware($limiter);
    }

    public function testPassesUnderLimit(): void
    {
        $mw = $this->middleware(3);
        $next = static fn(Request $r): Response => Response::html('ok');

        for ($i = 0; $i < 3; $i++) {
            $response = $mw->handle(new Request(Config::getInstance()), $next);
            self::assertSame(StatusCode::OK, $response->getStatusCode());
        }
    }

    public function testReturns429OverLimit(): void
    {
        $mw = $this->middleware(2);
        $next = static fn(Request $r): Response => Response::html('ok');

        $mw->handle(new Request(Config::getInstance()), $next);
        $mw->handle(new Request(Config::getInstance()), $next);
        $response = $mw->handle(new Request(Config::getInstance()), $next);

        self::assertSame(StatusCode::TOO_MANY_REQUESTS, $response->getStatusCode());
    }

    public function testExemptPathsAreNotCounted(): void
    {
        $mw = $this->middleware(1);
        $next = static fn(Request $r): Response => Response::html('ok');

        $_SERVER['REQUEST_URI'] = '/admin/import';
        $request = new Request(Config::getInstance());

        // Много запросов в админку — все проходят, лимит их не считает.
        for ($i = 0; $i < 5; $i++) {
            $response = $mw->handle($request, $next);
            self::assertSame(StatusCode::OK, $response->getStatusCode());
        }
    }

    public function testFailOpenWhenStorageBroken(): void
    {
        // Хранилище лимитера — недоступный путь: attempt() бросает,
        // middleware обязан пропустить запрос (fail-open), а не уронить сайт.
        $limiter = new RateLimiter('/proc/impossible-dir-' . uniqid(), 1, 60, true);
        $mw = new RateLimitMiddleware($limiter);
        $next = static fn(Request $r): Response => Response::html('ok');

        $response = $mw->handle(new Request(Config::getInstance()), $next);
        self::assertSame(StatusCode::OK, $response->getStatusCode());
    }
}
