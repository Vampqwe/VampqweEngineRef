<?php

declare(strict_types=1);

namespace Tests\Unit\System\Http;

use PHPUnit\Framework\TestCase;

/**
 * Стиль-гейт (задача №1 «единый стиль»): код ядра и тестов должен использовать
 * только пробельную индентацию, без табов в начале строк и хвостовых пробелов.
 * Дублирует grep-шаг из CI локально (переносимо: PCRE2 \h недоступен в PHP<7.3,
 * используем [[:space:]]).
 */
final class HeaderStyleGateTest extends TestCase
{
    public function testNoTabIndentationInCore(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if (preg_match('/^[[:space:]]*\t/', $line)) {
                    $offenders[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Найдены табы в индентации: ' . implode(', ', array_slice($offenders, 0, 20)));
    }

    public function testNoTrailingWhitespaceInCore(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if (preg_match('/[ \t]+$/', $line)) {
                    $offenders[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Найдены хвостовые пробелы: ' . implode(', ', array_slice($offenders, 0, 20)));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 4);
        $files = [];

        foreach (['core/classes', 'tests'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }

        return $files;
    }
}
