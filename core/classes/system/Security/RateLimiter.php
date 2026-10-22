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


namespace Core\System\Security;

/**
 * Ограничение частоты запросов (Rate Limiting)
 * Использует файловое хранилище
 */
class RateLimiter
{
    // Минимальный интервал между прогонами уборки, секунд.
    private const CLEANUP_INTERVAL_SECONDS = 300;

    private string $storagePath;
    private int $maxAttempts;
    private int $windowSeconds;
    private bool $enabled;

    public function __construct(
        string $storagePath,
        int $maxAttempts = 60,
        int $windowSeconds = 60,
        bool $enabled = true
    ) {
        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->enabled = $enabled;
    }

    /**
     * Попытаться выполнить действие
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function attempt(string $key): array
    {
        if (!$this->enabled) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        $this->ensureStorageDirectory();
        // Уборка протухших файлов — по времени последнего обращения, а не
        // per-process счётчиком (который при php-fpm почти никогда не
        // доходит до CLEANUP_FREQUENCY и расточительно вызывается в воркере).
        $this->maybeCleanup();

    $storageLock = $this->acquireStorageLock(LOCK_SH);
    try {
        $file = $this->getFilePath($key);
        $handle = fopen($file, 'c+');
        // Файлы-счётчики создаются на 0640: только владелец и группа веб-сервера,
        // без всемирного чтения (umask по умолчанию дал бы 666/644).
        @chmod($file, 0640);
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл rate limit: {$file}");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException("Не удалось заблокировать файл rate limit: {$file}");
        }

        try {
            $now = time();
            $attempts = $this->readAttemptsFromHandle($handle);
            $attempts = array_values(array_filter(
                $attempts,
                fn(int $timestamp) => ($now - $timestamp) < $this->windowSeconds
            ));

            if (count($attempts) >= $this->maxAttempts) {
                $oldest = min($attempts);
                $retryAfter = $this->windowSeconds - ($now - $oldest);
                $this->writeAttemptsToHandle($handle, $attempts);

                return [
                    'allowed'     => false,
                    'remaining'   => 0,
                    'retry_after' => max(1, $retryAfter),
                ];
            }

            $attempts[] = $now;
            $this->writeAttemptsToHandle($handle, $attempts);
            return [
                'allowed'     => true,
                'remaining'   => max(0, $this->maxAttempts - count($attempts)),
                'retry_after' => 0,
            ];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    } finally {
        $this->releaseStorageLock($storageLock);
    }
}

    /**
     * Сбросить лимит для ключа
     */
    public function clear(string $key): void
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Получить ключ для IP-адреса
     */
    public static function keyForIp(?string $ip = null): string
    {
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return 'ip:' . hash('sha256', $ip);
    }

    // ─── Приватные методы ───

    /**
     * Получить путь к файлу хранилища
     */
    private function getFilePath(string $key): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.rl';
    }

    /**
     * Получить список попыток из файла
     *
     * @return int[]
     */
    private function getAttempts(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось прочитать файл rate limit: {$file}");
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new \RuntimeException("Не удалось заблокировать файл rate limit: {$file}");
        }

        try {
            return $this->readAttemptsFromHandle($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Прочитать сериализованные попытки при уже взятой блокировке файла.
     *
     * @param resource $handle
     * @return int[]
     */
    private function readAttemptsFromHandle($handle): array
    {
        rewind($handle);
        $content = stream_get_contents($handle);
        if ($content === false) {
            throw new \RuntimeException('Не удалось прочитать данные rate limit');
        }

        if ($content === '') {
            return [];
        }

        try {
            $attempts = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Повреждены данные rate limit', 0, $e);
        }

        if (!is_array($attempts)) {
            throw new \RuntimeException('Некорректный формат данных rate limit');
        }

        foreach ($attempts as $timestamp) {
            if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
                throw new \RuntimeException('Некорректная отметка времени rate limit');
            }
        }

        return array_map('intval', array_values($attempts));
    }

    /**
     * Перезаписать счётчик при уже взятой эксклюзивной блокировке.
     *
     * @param resource $handle
     * @param int[] $attempts
     */
    private function writeAttemptsToHandle($handle, array $attempts): void
    {
        $content = json_encode(array_values($attempts), JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
            throw new \RuntimeException('Не удалось записать данные rate limit');
        }
    }

    private function ensureStorageDirectory(): void
    {
        if (is_dir($this->storagePath)) {
            return;
        }

        // 0750 вместо унаследованного umask: каталог с файлами-счётчиками
        // не должен читаться другими пользователями хоста (перечисление IP,
        // подделка состояния лимитов локальным процессом).
        if (!@mkdir($this->storagePath, 0750, true) && !is_dir($this->storagePath)) {
            throw new \RuntimeException("Не удалось создать каталог rate limit: {$this->storagePath}");
        }
        @chmod($this->storagePath, 0750);
    }

    /**
     * Запускает cleanupExpired не чаще одного раза в CLEANUP_INTERVAL_SECONDS.
     * Отметка хранится во временном файле-маркере — решение переживает
     * завершение запроса и корректно работает при php-fpm (несколько
     * процессов видят одну и ту же отметку).
     */
    private function maybeCleanup(): void
    {
        $marker = $this->storagePath . DIRECTORY_SEPARATOR . '.last_cleanup';
        $now = time();
        $last = @filemtime($marker);
        if ($last !== false && $now - $last < self::CLEANUP_INTERVAL_SECONDS) {
            return;
        }
        // Атомарное создание/обновление маркера: только один процесс побеждает.
        if (@touch($marker) === false) {
            return;
        }
        clearstatcache(true, $marker);
        $this->cleanupExpired();
    }

    private function cleanupExpired(): void
    {
        $storageLock = $this->acquireStorageLock(LOCK_EX);
        try {
            $files = glob($this->storagePath . DIRECTORY_SEPARATOR . '*.rl');
            if ($files === false) {
                return;
            }

            $now = time();
            foreach ($files as $file) {
                $modifiedAt = filemtime($file);
                if ($modifiedAt !== false && $now - $modifiedAt > $this->windowSeconds * 2) {
                    @unlink($file);
                }
            }
        } finally {
            $this->releaseStorageLock($storageLock);
        }
    }

    /**
     * Блокировка каталога не даёт уборщику удалить файл, открытый активной попыткой.
     *
     * @return resource
     */
    private function acquireStorageLock(int $operation)
    {
        $lockFile = $this->storagePath . DIRECTORY_SEPARATOR . '.rate-limiter.lock';
        $handle = fopen($lockFile, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть блокировку rate limit: {$lockFile}");
        }

        if (!flock($handle, $operation)) {
            fclose($handle);
            throw new \RuntimeException("Не удалось захватить блокировку rate limit: {$lockFile}");
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseStorageLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}