<?php

declare(strict_types=1);

namespace Tests\Unit\System\Security;

use Core\System\Security\PathSanitizer;
use Core\System\Security\SecurityException;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты PathSanitizer: защита от path traversal (LFI) — критично
 * для шаблонизатора и файловой системы движка.
 */
final class PathSanitizerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = realpath(sys_get_temp_dir()) . '/pathsan_' . uniqid('', true);
        mkdir($this->base . '/views', 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->base . '/views');
        @rmdir($this->base);
        parent::tearDown();
    }

    public function testRelativePathResolvedInsideBase(): void
    {
        $sanitizer = new PathSanitizer($this->base);
        $result = $sanitizer->sanitize('views/layout.html');

        self::assertStringStartsWith($this->base, $result);
        self::assertTrue($sanitizer->isInsideBasePath($result));
    }

    public function testTraversalOutsideBaseThrows(): void
    {
        $sanitizer = new PathSanitizer($this->base);

        $this->expectException(SecurityException::class);
        $sanitizer->sanitize('../../etc/passwd');
    }

    public function testAbsoluteOutsideBaseThrows(): void
    {
        $sanitizer = new PathSanitizer($this->base, true);

        $this->expectException(SecurityException::class);
        $sanitizer->sanitize('/etc/passwd');
    }

    public function testNullByteStripped(): void
    {
        $sanitizer = new PathSanitizer($this->base);
        $result = $sanitizer->sanitize("views/layout.html\0.php");

        self::assertStringNotContainsString("\0", $result);
        self::assertStringEndsWith('.html.php', $result);
    }

    public function testAbsolutePathInsideBaseReturnedAsIs(): void
    {
        $sanitizer = new PathSanitizer($this->base);
        $inside = $this->base . '/views/layout.html';

        self::assertSame($inside, $sanitizer->sanitize($inside));
    }

    public function testEnforceDisabledAllowsOutsideAbsolute(): void
    {
        $sanitizer = new PathSanitizer($this->base, false);
        $result = $sanitizer->sanitize('/etc/passwd');

        self::assertSame('/etc/passwd', $result);
    }

    public function testIsAbsolutePathDetection(): void
    {
        $sanitizer = new PathSanitizer($this->base);

        self::assertTrue($sanitizer->isAbsolutePath('/etc/passwd'));
        self::assertFalse($sanitizer->isAbsolutePath('views/layout.html'));
    }

    public function testGetBasePathReturnsNormalized(): void
    {
        $sanitizer = new PathSanitizer($this->base . '/');

        self::assertSame($this->base, $sanitizer->getBasePath());
    }
}
