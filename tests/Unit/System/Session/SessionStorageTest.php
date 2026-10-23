<?php

declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
 *
 * Данный код является интеллектуальной собственностью автора.
 * Любое копирование, распространение, модификация или использование
 * без письменного разрешения правообладателя строго запрещено.
 */

namespace Tests\Unit\System\Session;

use Core\System\Config\Config;
use Core\System\Session\FileSessionStorage;
use Core\System\Session\RedisSessionStorage;
use Core\System\Session\SessionStorageFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Тесты двухрежимного хранилища сессий (SESSION_DRIVER=files|redis).
 *
 * Redis в CI недоступен, поэтому проверяется: выбор драйвера из конфига,
 * поведение файлового хранилища и корректные ошибки конфигурации/подключения.
 */
final class SessionStorageTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/sessions_' . uniqid('', true);
        mkdir($this->tmpRoot . '/sessions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        Config::reset();
        parent::tearDown();
    }

    private function makeConfig(string $extraEnv = ''): Config
    {
        $envFile = $this->tmpRoot . '/config.env';
        file_put_contents(
            $envFile,
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_ALLOWED_HOSTS=example.com\n"
            . "APP_ROOT_PATH=" . $this->tmpRoot . "\n" . $extraEnv
        );
        chmod($envFile, 0640);

        Config::reset();

        return Config::init($envFile);
    }

    public function testDefaultDriverIsFiles(): void
    {
        $factory = new SessionStorageFactory($this->makeConfig());

        self::assertTrue($factory->isFilesDriver());
        self::assertInstanceOf(FileSessionStorage::class, $factory->make());
        self::assertSame('files', $factory->make()->getDriverName());
    }

    public function testUnknownDriverThrows(): void
    {
        $factory = new SessionStorageFactory($this->makeConfig("SESSION_DRIVER=memcached\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Неизвестный SESSION_DRIVER/');
        $factory->make();
    }

    public function testRedisDriverWithoutServerThrowsConnectionError(): void
    {
        // Порт 1 заведомо закрыт — конструктор должен упасть с внятной ошибкой.
        $factory = new SessionStorageFactory($this->makeConfig(
            "SESSION_DRIVER=redis\nSESSION_REDIS_HOST=127.0.0.1\nSESSION_REDIS_PORT=1\nSESSION_REDIS_TIMEOUT=0.5\n"
        ));

        if (!class_exists(\Redis::class)) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/phpredis/');
        } else {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Не удалось подключиться к Redis/');
        }

        $factory->make();
    }

    public function testMakeHandlerRejectsFilesDriver(): void
    {
        $factory = new SessionStorageFactory($this->makeConfig());

        $this->expectException(RuntimeException::class);
        $factory->makeHandler();
    }

    public function testFileStorageRoundtripAndValidation(): void
    {
        $storage = new FileSessionStorage($this->tmpRoot . '/sessions');

        $storage->write('abc123XY', 'admin_user|s:1:"1";', 1800);
        self::assertTrue($storage->exists('abc123XY'));
        self::assertSame('admin_user|s:1:"1";', $storage->read('abc123XY'));

        $storage->destroy('abc123XY');
        self::assertFalse($storage->exists('abc123XY'));
        self::assertNull($storage->read('abc123XY'));
    }

    public function testFileStorageRejectsDirectoryTraversalIds(): void
    {
        $storage = new FileSessionStorage($this->tmpRoot . '/sessions');

        // Идентификаторы с путевыми символами недопустимы — ни чтение, ни запись
        // не должны выходить за каталог хранения.
        foreach (['../evil', '../../etc/passwd', '', 'a', "sess\ninject"] as $badId) {
            self::assertFalse($storage->exists($badId), 'exists() for id: ' . $badId);
            self::assertNull($storage->read($badId), 'read() for id: ' . $badId);
            $storage->write($badId, 'x', 60);
        }

        self::assertSame([], glob($this->tmpRoot . '/sessions/sess_*'), 'no stray files written');
    }

    public function testRedisStorageRequiresExtensionOrServer(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/phpredis/');
            new RedisSessionStorage([]);

            return;
        }

        $this->expectException(RuntimeException::class);
        new RedisSessionStorage(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]);
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
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
