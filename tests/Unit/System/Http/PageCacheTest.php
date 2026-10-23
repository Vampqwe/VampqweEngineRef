<?php

declare(strict_types=1);

namespace Tests\Unit\System\Http;

use Core\System\Config\Config;
use Core\System\Http\Header;
use Core\System\Http\PageCache;
use Core\System\Http\PageCacheMiddleware;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\StatusCode;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты full-page cache: ключи, запись/чтение, TTL, инвалидация
 * и поведение middleware (HIT/MISS, обход админки, пропуск не-HTML).
 */
final class PageCacheTest extends TestCase
{
    private string $tmpRoot;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pagecache_' . uniqid('', true);
        mkdir($this->tmpRoot . '/storage', 0777, true);

        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nPAGE_CACHE_ENABLED=true\nPAGE_CACHE_TTL=60\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        $this->config = Config::init($this->tmpRoot . '/config.env');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/about';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['QUERY_STRING'] = '';
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        Config::reset();
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_HOST'], $_SERVER['QUERY_STRING'], $_SERVER['HTTP_USER_AGENT']);

        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeRequest(string $method = 'GET', string $uri = '/about'): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $_GET);
        $_SERVER['QUERY_STRING'] = parse_url($uri, PHP_URL_QUERY) ?: '';

        return new Request($this->config);
    }

    public function testDisabledByDefault(): void
    {
        file_put_contents($this->tmpRoot . '/config.env', "APP_ENV=test\n");
        Config::reset();
        $config = Config::init($this->tmpRoot . '/config.env');

        $cache = new PageCache($config);
        $this->assertFalse($cache->isEnabled());
    }

    public function testDebugModeForcesCacheOff(): void
    {
        file_put_contents($this->tmpRoot . '/config.env', "APP_DEBUG=true\nPAGE_CACHE_ENABLED=true\n");
        Config::reset();
        $config = Config::init($this->tmpRoot . '/config.env');

        $this->assertFalse((new PageCache($config))->isEnabled());
    }

    public function testBuildKeyStableAndVariantSpecific(): void
    {
        $cache = new PageCache($this->config);

        $k1 = $cache->buildKey($this->makeRequest('GET', '/about'));
        $k2 = $cache->buildKey($this->makeRequest('GET', '/about'));
        $this->assertNotNull($k1);
        $this->assertSame($k1, $k2, 'Одинаковые запросы дают одинаковый ключ');

        $this->assertNotSame($k1, $cache->buildKey($this->makeRequest('GET', '/about?x=1')));
        $this->assertNotSame($k1, $cache->buildKey($this->makeRequest('HEAD', '/about')));
    }

    public function testBuildKeyRejectsNonGetAndSessionVisitors(): void
    {
        $cache = new PageCache($this->config);

        $this->assertNull($cache->buildKey($this->makeRequest('POST', '/about')));

        $_COOKIE['PHPSESSID'] = 'abc123';
        $this->assertNull($cache->buildKey($this->makeRequest('GET', '/about')));
    }

    public function testWriteReadRoundTrip(): void
    {
        $cache = new PageCache($this->config);
        $key = $cache->buildKey($this->makeRequest('GET', '/news'));
        $this->assertNotNull($key);

        $response = Response::html('<h1>Новости</h1>');
        $this->assertTrue($cache->write($key, $response, '/news'));

        $hit = $cache->read($key);
        $this->assertNotNull($hit);
        $this->assertSame(200, $hit['status']);
        $this->assertSame('<h1>Новости</h1>', $hit['content']);
        $this->assertSame('/news', $hit['path']);
    }

    public function testExpiredEntryIsRejectedAndRemoved(): void
    {
        $cache = new PageCache($this->config);
        $key = $cache->buildKey($this->makeRequest());

        $file = $cache->getDir() . '/' . $key . '.html.meta';
        mkdir($cache->getDir(), 0777, true);
        file_put_contents($file, serialize([
            'status' => 200,
            'headers' => [],
            'content' => 'old',
            'path' => '/about',
            'created_at' => time() - 120,
            'expires_at' => time() - 60,
        ]));

        $this->assertNull($cache->read($key));
        $this->assertFileDoesNotExist($file, 'Просроченная запись удаляется при чтении');
    }

    public function testPurgeRemovesAllVariantsOfPath(): void
    {
        $cache = new PageCache($this->config);

        $home = $cache->buildKey($this->makeRequest('GET', '/'));
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ru,en;q=0.9';
        $homeRu = $cache->buildKey($this->makeRequest('GET', '/'));
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        $other = $cache->buildKey($this->makeRequest('GET', '/about'));

        $this->assertNotSame($home, $homeRu, 'Разные Accept-Language — разные варианты');

        $cache->write($home, Response::html('home'), '/');
        $cache->write($homeRu, Response::html('home ru'), '/');
        $cache->write($other, Response::html('about'), '/about');

        $this->assertSame(2, $cache->purge('/'));
        $this->assertNull($cache->read($home));
        $this->assertNull($cache->read($homeRu));
        $this->assertNotNull($cache->read($other), 'Чужие пути не затронуты');
    }

    public function testClearRemovesEverything(): void
    {
        $cache = new PageCache($this->config);
        $cache->write($cache->buildKey($this->makeRequest('GET', '/a')), Response::html('a'), '/a');
        $cache->write($cache->buildKey($this->makeRequest('GET', '/b')), Response::html('b'), '/b');

        $this->assertSame(2, $cache->clear());
        $this->assertSame(0, $cache->clear());
    }

    public function testMiddlewareServesHitOnSecondRequest(): void
    {
        $cache = new PageCache($this->config);
        $middleware = new PageCacheMiddleware($cache);

        $backendCalls = 0;
        $next = function (Request $req) use (&$backendCalls): Response {
            $backendCalls++;
            return Response::html('<h1>Hi</h1>');
        };

        $request = $this->makeRequest('GET', '/page');

        $first = $middleware->handle($request, $next);
        $this->assertSame(1, $backendCalls);
        $this->assertSame('MISS', $first->getHeader('X-Page-Cache'));

        $second = $middleware->handle($this->makeRequest('GET', '/page'), $next);
        $this->assertSame(1, $backendCalls, 'Бэкенд вызван один раз — второй ответ из кеша');
        $this->assertSame('HIT', $second->getHeader('X-Page-Cache'));
        $this->assertSame('<h1>Hi</h1>', $second->getContent());
        $this->assertSame('text/html; charset=UTF-8', $second->getHeader('Content-Type'));
    }

    public function testMiddlewareSkipsAdminAndSessionRequests(): void
    {
        $cache = new PageCache($this->config);
        $middleware = new PageCacheMiddleware($cache);

        $calls = 0;
        $next = function (Request $req) use (&$calls): Response {
            $calls++;
            return Response::html('admin');
        };

        $middleware->handle($this->makeRequest('GET', '/admin/pages'), $next);
        $middleware->handle($this->makeRequest('GET', '/admin/pages'), $next);
        $this->assertSame(2, $calls, 'Админка всегда идёт на бэкенд');

        $_COOKIE['PHPSESSID'] = 'sess';
        $middleware->handle($this->makeRequest('GET', '/page'), $next);
        $this->assertSame(3, $calls);
        unset($_COOKIE['PHPSESSID']);
    }

    public function testMutationPurgesPublicCache(): void
    {
        $cache = new PageCache($this->config);
        $middleware = new PageCacheMiddleware($cache);

        // Аноним посетитель прогрел кеш домашней.
        $home = $cache->buildKey($this->makeRequest('GET', '/'));
        $cache->write($home, Response::html('cached home'), '/');
        $this->assertNotNull($cache->read($home));

        // Мутация в админке (PUT по пути страницы) должна сбросить кеш.
        $_COOKIE['PHPSESSID'] = 'admin-sess';
        $response = $middleware->handle(
            $this->makeRequest('PUT', '/admin/api/pages/5'),
            fn(Request $r): Response => Response::json(['ok' => true])
        );
        $this->assertSame(200, $response->getStatusCode()->value);
        unset($_COOKIE['PHPSESSID']);

        // Путь мутации — /admin/api/pages/5; кеш «/» чистится только когда
        // мутация идёт по публичному пути. Проверяем публичную мутацию:
        $pub = $cache->buildKey($this->makeRequest('GET', '/contacts'));
        $cache->write($pub, Response::html('cached contacts'), '/contacts');
        $_COOKIE['PHPSESSID'] = 'x';
        $middleware->handle(
            $this->makeRequest('POST', '/contacts'),
            fn(Request $r): Response => Response::json(['ok' => true])
        );
        unset($_COOKIE['PHPSESSID']);

        $this->assertNull($cache->read($pub), 'POST-мутация сбрасывает кеш пути');
        $this->assertNull($cache->read($home), 'Мутация дополнительно сбрасывает домашнюю');
    }

    public function testNonHtmlResponseIsNotCached(): void
    {
        $cache = new PageCache($this->config);
        $middleware = new PageCacheMiddleware($cache);

        $calls = 0;
        $next = function (Request $req) use (&$calls): Response {
            $calls++;
            return Response::json(['data' => 1]);
        };

        $middleware->handle($this->makeRequest('GET', '/api/x'), $next);
        $middleware->handle($this->makeRequest('GET', '/api/x'), $next);

        $this->assertSame(2, $calls, 'JSON проходит насквозь и не кешируется');
    }

    public function testErrorResponseIsNotCachedButStaleIsServed(): void
    {
        $cache = new PageCache($this->config);
        $middleware = new PageCacheMiddleware($cache);

        // Прогрев кеша.
        $key = $cache->buildKey($this->makeRequest('GET', '/slow'));
        $cache->write($key, Response::html('good page'), '/slow');

        // Просрочим запись, но оставим файл (stale-копия).
        $file = $cache->getDir() . '/' . $key . '.html.meta';
        $data = unserialize(file_get_contents($file));
        $data['expires_at'] = time() - 10;
        file_put_contents($file, serialize($data));

        // Бэкенд падает — middleware должен отдать stale вместо исключения.
        $response = $middleware->handle(
            $this->makeRequest('GET', '/slow'),
            function (Request $r): Response {
                throw new \RuntimeException('DB down');
            }
        );

        $this->assertSame('good page', $response->getContent());
        $this->assertSame('STALE', $response->getHeader('X-Page-Cache'));
    }

    public function testBigResponsesAreRejected(): void
    {
        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_DEBUG=false\nPAGE_CACHE_ENABLED=true\nPAGE_CACHE_MAX_SIZE=2048\n"
        );
        Config::reset();
        $config = Config::init($this->tmpRoot . '/config.env');
        $cache = new PageCache($config);

        $key = hash('sha256', 'big');
        $huge = Response::html(str_repeat('x', 4096));

        $this->assertFalse($cache->write($key, $huge, '/big'));
    }
}
