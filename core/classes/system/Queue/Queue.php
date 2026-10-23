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
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or use is strictly prohibited.
 */

namespace Core\System\Queue;

use Core\System\Config\Config;

/**
 * Лёгкая файловая очередь задач (без внешних зависимостей).
 *
 * Назначение: вынос тяжёлых операций (генерация sitemap, импорт HTML,
 * массовые пересчёты) из веб-процесса в фоновый воркер (`bin/queue-work.php`),
 * чтобы рост посещаемости не упирался в таймауты HTTP-запросов.
 *
 * Устройство:
 *  - storage/queue/{waiting,reserved,failed}/ — каталоги состояний;
 *  - каждая задача — JSON-файл с атомарной записью (tmp + rename),
 *    поэтому конкурентные producer/consumer не видят полуреализованных файлов;
 *  - захват задачи воркером — через flock(LOCK_EX | LOCK_NB): две копии
 *    воркера могут работать параллельно без дублирования обработки;
 *  - ретраи: неудачная задача возвращается в waiting с отложенным
 *    available_at до исчерпания attempts; после — переносится в failed.
 *
 * Формат payload: ['job' => строка-имя зарегистрированного обработчика,
 *                   'data' => произвольный массив].
 */
class Queue
{
    private string $basePath;
    private int $defaultTries;
    private int $retryAfterSeconds;
    private bool $enabled;

    public function __construct(Config $config)
    {
        // getStoragePath уже возвращает абсолютный путь от корня проекта.
        $this->basePath = rtrim($config->getStoragePath('queue'), '/\\');

        $this->defaultTries = max(1, $config->getInt('QUEUE_DEFAULT_TRIES', 3));
        $this->retryAfterSeconds = max(1, $config->getInt('QUEUE_RETRY_AFTER_SECONDS', 60));
        $this->enabled = $config->getBool('QUEUE_ENABLED', true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Поставить задачу в очередь. Возвращает id задачи.
     */
    public function push(string $job, array $data = [], int $delaySeconds = 0, ?int $tries = null): string
    {
        $this->ensureDirectories();

        $id = bin2hex(random_bytes(8)) . '-' . dechex(time());
        $payload = [
            'id'           => $id,
            'job'          => $job,
            'data'         => $data,
            'attempts'     => 0,
            'max_tries'    => max(1, $tries ?? $this->defaultTries),
            'available_at' => time() + max(0, $delaySeconds),
            'created_at'   => time(),
        ];

        $this->atomicWrite($this->path('waiting', $id), (string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $id;
    }

    /**
     * Взять следующую готовую задачу (или null, если очередь пуста).
     *
     * @return array<string,mixed>|null
     */
    public function reserve(): ?array
    {
        $this->ensureDirectories();

        $now = time();
        foreach ($this->listFiles($this->dir('waiting')) as $file) {
            $handle = @fopen($file, 'r+');
            if ($handle === false) {
                continue;
            }
            // Non-blocking: если файл уже обрабатывается другим воркером — пропускаем.
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }

            clearstatcache(true, $file);
            $raw = stream_get_contents($handle);
            $payload = is_string($raw) ? json_decode($raw, true) : null;

            if (!is_array($payload) || (int) ($payload['available_at'] ?? 0) > $now) {
                flock($handle, LOCK_UN);
                fclose($handle);
                continue;
            }

            $payload['reserved_at'] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            return $payload;
        }

        return null;
    }

    /**
     * Отметить задачу выполненной.
     */
    public function ack(array $payload): void
    {
        $id = (string) ($payload['id'] ?? '');
        if ($id !== '') {
            @unlink($this->path('waiting', $id));
        }
    }

    /**
     * Задача не выполнена: ретрай (возврат в waiting с задержкой) либо
     * перенос в failed при исчерпании попыток.
     */
    public function nack(array $payload, string $error): void
    {
        $id = (string) ($payload['id'] ?? '');
        if ($id === '') {
            return;
        }
        $file = $this->path('waiting', $id);

        $attempts = (int) ($payload['attempts'] ?? 0) + 1;
        $maxTries = max(1, (int) ($payload['max_tries'] ?? $this->defaultTries));

        if ($attempts >= $maxTries) {
            $payload['attempts'] = $attempts;
            $payload['failed_at'] = time();
            $payload['error'] = mb_substr($error, 0, 2000);
            $this->atomicWrite($this->path('failed', $id), (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            @unlink($file);
            return;
        }

        $payload['attempts'] = $attempts;
        $payload['error'] = mb_substr($error, 0, 2000);
        $payload['available_at'] = time() + $this->retryAfterSeconds;
        unset($payload['reserved_at']);
        $this->atomicWrite($file, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Число задач в указанном состоянии.
     */
    public function size(string $state = 'waiting'): int
    {
        return count($this->listFiles($this->dir($state)));
    }

    /**
     * @return string[] абсолютные пути файлов состояния
     */
    public function listFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = glob($directory . '/*.json');

        return $files === false ? [] : $files;
    }

    public function dir(string $state): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $state;
    }

    private function path(string $state, string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $id) ?? '';

        return $this->dir($state) . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function ensureDirectories(): void
    {
        foreach (['waiting', 'reserved', 'failed'] as $state) {
            $dir = $this->dir($state);
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
        }
    }

    private function atomicWrite(string $file, string $contents): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $contents);
        @chmod($tmp, 0660);
        rename($tmp, $file);
    }
}
