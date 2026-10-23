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

namespace Tests\Unit\Module\Page;

use Core\Module\Page\ViewCounter;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use PHPUnit\Framework\TestCase;

/**
 * Тесты асинхронного счётчика просмотров (файловый бэкенд: Redis в CI
 * недоступен, REDIS_HOST пуст — выбор бэкенда прозрачен для вызывающего кода).
 */
final class ViewCounterTest extends TestCase
{
    private string $tmpRoot;
    private Config $config;

    /** @var list<array{sql:string,params:array<string,mixed>}> */
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/viewcounter_' . uniqid('', true);
        mkdir($this->tmpRoot . '/storage', 0777, true);

        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_ALLOWED_HOSTS=example.com\n"
            . "APP_ROOT_PATH=" . $this->tmpRoot . "\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        $this->config = Config::init($this->tmpRoot . '/config.env');

        $this->executed = [];
        $_SESSION = [];
        ViewCounter::$forceSessionContextForTests = false;
    }

    protected function tearDown(): void
    {
        ViewCounter::$forceSessionContextForTests = false;
        Config::reset();
        $_SESSION = [];
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function testRecordWritesToBufferNotDatabase(): void
    {
        $counter = $this->makeCounter(['throttle_seconds' => 0]);

        $counter->record(7);
        $counter->record(7);
        $counter->record(9);

        // Асинхронный режим: ни одного UPDATE на каждый просмотр…
        self::assertSame([], $this->executed, 'record() must not touch DB directly');
        // …дельты накопились в буфере.
        self::assertSame(['7' => 2, '9' => 1], $this->bufferContents());
    }

    public function testFlushAppliesBatchedUpdateOnce(): void
    {
        $counter = $this->makeCounter(['throttle_seconds' => 0]);

        $counter->record(3);
        $counter->record(3);
        $counter->record(5);

        $this->executed = [];
        $updated = $counter->flush();

        self::assertGreaterThan(0, $updated, 'flush must issue an UPDATE');
        self::assertCount(1, $this->executed, 'one batched UPDATE per flush');
        self::assertStringContainsString('CASE id', $this->executed[0]['sql']);
        self::assertStringContainsString('WHERE id IN', $this->executed[0]['sql']);
        // Сортировка id: :id0=3 (+2), :id1=5 (+1)
        self::assertSame(3, $this->executed[0]['params']['id0']);
        self::assertSame(2, $this->executed[0]['params']['inc0']);
        self::assertSame(5, $this->executed[0]['params']['id1']);
        self::assertSame(1, $this->executed[0]['params']['inc1']);

        // Буфер обнулён — повторный flush идемпотентен.
        self::assertSame([], $this->bufferContents());
        $this->executed = [];
        self::assertSame(0, $counter->flush());
        self::assertSame([], $this->executed);
    }

    public function testFlushSinglePageUsesSimpleUpdate(): void
    {
        $counter = $this->makeCounter(['throttle_seconds' => 0]);
        $counter->record(42);

        $this->executed = [];
        $updated = $counter->flush();

        self::assertGreaterThan(0, $updated);
        self::assertStringNotContainsString('CASE', $this->executed[0]['sql']);
        self::assertStringContainsString('views_count + :inc', $this->executed[0]['sql']);
        self::assertSame(42, $this->executed[0]['params']['id0']);
        self::assertSame(1, $this->executed[0]['params']['inc']);
    }

    public function testCountDisabledSkipsEverything(): void
    {
        $counter = $this->makeCounter([
            'count_enabled' => false,
            'throttle_seconds' => 0,
        ]);

        $counter->record(1);

        self::assertSame([], $this->executed);
        self::assertSame([], $this->bufferContents());
    }

    public function testThrottleSuppressesSecondViewWithinWindow(): void
    {
        ViewCounter::$forceSessionContextForTests = true;
        $counter = $this->makeCounter(['throttle_seconds' => 300]);

        $counter->record(11);
        $counter->record(11); // второй раз в окне троттлинга — игнор

        self::assertSame(['11' => 1], $this->bufferContents());

        // Просмотр другой страницы троттлингом не ограничен.
        $counter->record(12);
        self::assertSame(['11' => 1, '12' => 1], $this->bufferContents());
    }

    public function testSyncModeFallsBackToDirectUpdate(): void
    {
        $counter = $this->makeCounter([
            'async' => false,
            'throttle_seconds' => 0,
        ]);

        $counter->record(8);

        self::assertCount(1, $this->executed);
        self::assertStringContainsString('WHERE id = :id0', $this->executed[0]['sql']);
        self::assertSame(8, $this->executed[0]['params']['id0']);
        self::assertSame(1, $this->executed[0]['params']['inc']);
        self::assertSame([], $this->bufferContents(), 'sync mode must not buffer');
    }

    public function testInvalidPageIdIgnored(): void
    {
        $counter = $this->makeCounter(['throttle_seconds' => 0]);
        $counter->record(0);
        $counter->record(-5);

        self::assertSame([], $this->executed);
        self::assertSame([], $this->bufferContents());
    }

    public function testHasPendingReflectsBufferState(): void
    {
        $counter = $this->makeCounter(['throttle_seconds' => 0]);

        self::assertFalse($counter->hasPending());
        $counter->record(1);
        self::assertTrue($counter->hasPending());
        $counter->flush();
        self::assertFalse($counter->hasPending());
    }

    public function testMaxKeysPerFlushCapsBatch(): void
    {
        $counter = $this->makeCounter([
            'throttle_seconds' => 0,
            'max_keys_per_flush' => 2,
        ]);

        for ($id = 1; $id <= 5; $id++) {
            $counter->record($id);
        }

        $this->executed = [];
        $counter->flush();

        // Один UPDATE, но не более 2 id в пачке.
        self::assertCount(1, $this->executed);
        self::assertArrayHasKey('id1', $this->executed[0]['params']);
        self::assertArrayNotHasKey('id2', $this->executed[0]['params']);
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $viewsOverrides
     */
    private function makeCounter(array $viewsOverrides = []): ViewCounter
    {
        return new ViewCounter($this->makeDbStub(), $this->config, $viewsOverrides);
    }

    private function makeDbStub(): DataBase
    {
        $executed = &$this->executed;

        return new class ($this->config, $executed) extends DataBase {
            /** @param list<array{sql:string,params:array<string,mixed>}> $executed */
            public function __construct(Config $config, private array &$executed)
            {
                parent::__construct($config);
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->executed[] = ['sql' => $sql, 'params' => $params];
                return str_contains($sql, 'UPDATE') ? 1 : 0;
            }
        };
    }

    private function bufferPath(): string
    {
        return $this->config->getStoragePath('view_counter.json');
    }

    /**
     * @return array<string,int>
     */
    private function bufferContents(): array
    {
        $raw = @file_get_contents($this->bufferPath());
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
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
