<?php

declare(strict_types=1);

namespace Tests\Unit\System\Queue;

use Core\System\Config\Config;
use Core\System\Queue\JobRegistry;
use Core\System\Queue\Queue;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты файловой очереди задач: push/reserve/ack/nack, ретраи,
 * задержки (delay) и реестр обработчиков.
 */
final class QueueTest extends TestCase
{
    private string $tmpRoot;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/queue_' . uniqid('', true);
        mkdir($this->tmpRoot . '/logs', 0777, true);
        mkdir($this->tmpRoot . '/storage', 0777, true);

        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=test\n"
            . "SECURITY_ENFORCE_CONFIG_PERMS=false\nAPP_ROOT_PATH=\"{$this->tmpRoot}\"\n"
            . "QUEUE_ENABLED=true\nQUEUE_DEFAULT_TRIES=2\nQUEUE_RETRY_AFTER_SECONDS=1\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        Config::init($this->tmpRoot . '/config.env');
        $this->config = Config::getInstance();
    }

    protected function tearDown(): void
    {
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

    public function testPushReserveAckLifecycle(): void
    {
        $queue = new Queue($this->config);

        $id = $queue->push('demo.job', ['x' => 1]);
        self::assertNotSame('', $id);
        self::assertSame(1, $queue->size('waiting'));

        $job = $queue->reserve();
        self::assertNotNull($job);
        self::assertSame($id, $job['id']);
        self::assertSame('demo.job', $job['job']);
        self::assertSame(['x' => 1], $job['data']);

        $queue->ack($job);
        self::assertSame(0, $queue->size('waiting'));
        self::assertNull($queue->reserve());
    }

    public function testDelayMakesJobUnavailable(): void
    {
        $queue = new Queue($this->config);
        $queue->push('demo.job', [], 3600);

        self::assertSame(1, $queue->size('waiting'));
        self::assertNull($queue->reserve(), 'Отложенная задача не должна резервироваться');
    }

    public function testNackRetriesThenMovesToFailed(): void
    {
        $queue = new Queue($this->config); // max tries = 2
        $queue->push('demo.job', ['a' => 'b']);

        $job = $queue->reserve();
        self::assertNotNull($job);
        $queue->nack($job, 'первый сбой');

        // Ретрай отложен на QUEUE_RETRY_AFTER_SECONDS=1 — сразу не берём.
        self::assertNull($queue->reserve());
        sleep(1);

        $job2 = $queue->reserve();
        self::assertNotNull($job2, 'После задержки задача снова доступна');
        self::assertSame(1, $job2['attempts']);

        $queue->nack($job2, 'второй сбой');
        self::assertSame(0, $queue->size('waiting'));
        self::assertSame(1, $queue->size('failed'), 'Исчерпаны попытки → failed');

        $failedFiles = $queue->listFiles($queue->dir('failed'));
        $payload = json_decode((string) file_get_contents($failedFiles[0]), true);
        self::assertSame('второй сбой', $payload['error']);
    }

    public function testJobRegistryDispatchAndUnknownJob(): void
    {
        $registry = new JobRegistry();
        $called = null;
        $registry->register('demo.job', static function (array $data) use (&$called): void {
            $called = $data;
        });

        self::assertTrue($registry->has('demo.job'));
        self::assertSame(['demo.job'], $registry->names());

        $registry->dispatch('demo.job', ['k' => 'v']);
        self::assertSame(['k' => 'v'], $called);

        $this->expectException(\RuntimeException::class);
        $registry->dispatch('no.such.job', []);
    }
}
