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

namespace Tests\Unit\Module\Admin;

use Core\Module\Admin\Services\RedirectAdminService;
use Core\System\Config\Config;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Module\Admin\Fakes\FakeRedirectDb;

/**
 * Тесты CRUD-сервиса редиректов админки: валидация путей, защита от
 * open-redirect, нормализация, UNIQUE по source, лимит записей.
 */
final class RedirectAdminServiceTest extends TestCase
{
    private FakeRedirectDb $db;
    private RedirectAdminService $service;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Изолированный config.env в tmp — как в остальных тестах движка.
        $this->tmpRoot = sys_get_temp_dir() . '/redirects_' . uniqid('', true);
        mkdir($this->tmpRoot, 0777, true);
        file_put_contents(
            $this->tmpRoot . '/config.env',
            "APP_ENV=test\nAPP_DEBUG=false\nDB_DRIVER=mysql\n"
            . 'APP_ROOT_PATH=' . $this->tmpRoot . "\n"
        );
        chmod($this->tmpRoot . '/config.env', 0640);

        Config::reset();
        $config = Config::init($this->tmpRoot . '/config.env');

        $this->db = new FakeRedirectDb($config);
        $this->service = new RedirectAdminService($this->db);
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
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testCreateValidRelativeRedirect(): void
    {
        $result = $this->service->create('/old-page', '/new-page', 301, true, 'переезд');

        self::assertTrue($result['ok'], json_encode($result));
        self::assertArrayHasKey('id', $result);
        $row = $this->service->find((int) $result['id']);
        self::assertSame('/old-page', $row['source']);
        self::assertSame('/new-page', $row['destination']);
        self::assertSame(301, (int) $row['status_code']);
    }

    public function testNormalizeCollapsesSlashesAndTrailingSlash(): void
    {
        $result = $this->service->create('/a//b/', '/c//d/', 302);

        self::assertTrue($result['ok']);
        $row = $this->service->find((int) $result['id']);
        self::assertSame('/a/b', $row['source']);
        self::assertSame('/c/d', $row['destination']);
    }

    public function testRejectsAbsoluteProtocolSourceOpenRedirect(): void
    {
        foreach (['https://evil.example/x', 'javascript:alert(1)', '//evil.example', 'C:/windows', 'not-a-path'] as $bad) {
            $result = $this->service->create($bad, '/ok', 301);
            self::assertFalse($result['ok'], "must reject source: {$bad}");
        }
    }

    public function testDestinationAcceptsOnlyRelativeOrHttpUrl(): void
    {
        self::assertTrue($this->service->create('/a', 'https://example.com/b', 301)['ok']);
        self::assertFalse($this->service->create('/c', 'ftp://example.com/b', 301)['ok']);
        self::assertFalse($this->service->create('/d', 'javascript:alert(1)', 301)['ok']);
    }

    public function testRejectsSelfLoop(): void
    {
        $result = $this->service->create('/same', '/same', 301);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('цикл', $result['error'] ?? '');
    }

    public function testRejectsUnsupportedStatusCode(): void
    {
        foreach ([300, 307, 308, 200] as $code) {
            self::assertFalse($this->service->create('/x' . $code, '/y', $code)['ok'], "code {$code} must be rejected");
        }
    }

    public function testDuplicateSourceRejected(): void
    {
        self::assertTrue($this->service->create('/dup', '/to1', 301)['ok']);
        $second = $this->service->create('/dup', '/to2', 301);
        self::assertFalse($second['ok']);
        self::assertStringContainsString('уже существует', $second['error'] ?? '');
    }

    public function testUpdateChangesRowAndDuplicateConflicts(): void
    {
        $created = $this->service->create('/u1', '/v1', 301);
        $this->service->create('/u2', '/v2', 301);

        $ok = $this->service->update((int) $created['id'], '/u1-new', '/v1-new', 302, false, 'обновлен');
        self::assertTrue($ok['ok'], json_encode($ok));
        $row = $this->service->find((int) $created['id']);
        self::assertSame('/u1-new', $row['source']);
        self::assertSame(302, (int) $row['status_code']);
        self::assertSame(0, (int) $row['is_active']);

        // коллизия с существующим source другого редиректа
        $conflict = $this->service->update((int) $created['id'], '/u2', '/zzz', 301, true);
        self::assertFalse($conflict['ok']);

        // несуществующий id
        self::assertFalse($this->service->update(99999, '/q', '/w', 301, true)['ok']);
    }

    public function testDeleteAndResetHits(): void
    {
        $created = $this->service->create('/del', '/dst', 301);
        $id = (int) $created['id'];

        self::assertTrue($this->service->resetHits($id));
        self::assertFalse($this->service->resetHits(99999));

        self::assertTrue($this->service->delete($id));
        self::assertFalse($this->service->delete($id));
        self::assertNull($this->service->find($id));
    }

    public function testListSearchPaginationAndLikeEscaping(): void
    {
        $this->service->create('/news-2024', '/a', 301);
        $this->service->create('/news-2025', '/b', 301);
        $this->service->create('/sale%off', '/c', 301);

        $all = $this->service->list();
        self::assertSame(3, $all['total']);
        self::assertSame('/news-2024', $all['items'][0]['source'], 'сортировка по source');

        $found = $this->service->list('news');
        self::assertSame(2, $found['total']);

        // спецсимволы LIKE трактуются буквально: '%' не должен матчить всё
        $literal = $this->service->list('%');
        self::assertSame(1, $literal['total']);
        self::assertSame('/sale%off', $literal['items'][0]['source']);

        $page2 = $this->service->list('', 2, 2);
        self::assertCount(1, $page2['items']);
    }

    public function testMaxRedirectsLimit(): void
    {
        $ref = new \ReflectionClass(RedirectAdminService::class);
        $max = (int) $ref->getConstant('MAX_REDIRECTS');
        for ($i = 0; $i < $max; $i++) {
            $this->db->seed($i + 1, '/seed-' . $i, '/to');
        }

        $result = $this->service->create('/overflow', '/to', 301);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('лимит', $result['error'] ?? '');
    }

    public function testNoteTrimmedTo255AndBlankBecomesNull(): void
    {
        $long = str_repeat('н', 300);
        $created = $this->service->create('/n1', '/n2', 301, true, $long);
        self::assertSame(255, mb_strlen((string) $this->service->find((int) $created['id'])['note']));

        $blank = $this->service->create('/n3', '/n4', 301, true, '   ');
        self::assertNull($this->service->find((int) $blank['id'])['note']);
    }
}
