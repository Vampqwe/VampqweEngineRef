<?php
declare(strict_types=1);

/**
 * CLI-воркер очереди задач.
 *
 * Использование:
 *   php bin/queue-work.php                 — работать бесконечно (под supervisor)
 *   php bin/queue-work.php --once          — разобрать всё, что готово сейчас, и выйти
 *   php bin/queue-work.php --max=50        — не больше 50 задач за запуск
 *   php bin/queue-work.php --sleep=2       — пауза между итерациями в daemon-режиме (сек)
 *
 * Рекомендуемый cron (--once — безопасно при пересечении запусков, захват через flock):
 *   *\/1 * * * * /usr/bin/php /path/to/app/bin/queue-work.php --once >> /dev/null 2>&1
 *
 * Зарегистрированные задачи:
 *   sitemap.rebuild — пересборка storage/sitemap/sitemap.xml из БД.
 *   import.html     — фоновый импорт HTML-страницы (тяжёлый парсинг/картинки).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Только CLI-режим.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Core\Module\Admin\Services\ImportJobHandler;
use Core\Module\Page\SitemapGenerator;
use Core\Module\Page\SitemapService;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use Core\System\Logger\Logger;
use Core\System\Queue\JobRegistry;
use Core\System\Queue\Queue;

$options = getopt('', ['once', 'max::', 'sleep::']);
$once = isset($options['once']);
$max = isset($options['max']) ? max(1, (int) $options['max']) : 0;
$sleep = max(0, (int) ($options['sleep'] ?? 1));

$config = Config::getInstance();
$logger = new Logger($config);

$registry = new JobRegistry();

// ─── import.html ────────────────────────────────────────────────────
$registry->register(ImportJobHandler::JOB_NAME, static function (array $data): void {
    (new ImportJobHandler(Config::getInstance()))->handle($data);
});

// ─── sitemap.rebuild ────────────────────────────────────────────────
$registry->register(SitemapGenerator::JOB_NAME, static function (array $data) use ($config): void {
    $baseUrl = isset($data['base_url']) ? rtrim((string) $data['base_url'], '/') : null;
    $generator = new SitemapGenerator(new SitemapService(new DataBase($config)), $config);
    $file = $generator->rebuild($baseUrl);
    unset($file);
});

$queue = new Queue($config);

$processed = 0;
$failed = 0;

fwrite(STDOUT, sprintf(
    "Queue worker: старт (режим %s, задачи: %s)\n",
    $once ? 'once' : 'daemon',
    implode(', ', $registry->names())
));

while (true) {
    try {
        $job = $queue->reserve();
    } catch (\Throwable $e) {
        $logger->exception($e);
        fwrite(STDERR, "Ошибка чтения очереди: {$e->getMessage()}\n");
        if ($once) {
            exit(1);
        }
        sleep(max(1, $sleep));
        continue;
    }

    if ($job === null) {
        if ($once) {
            break;
        }
        sleep(max(1, $sleep));
        continue;
    }

    $name = (string) ($job['job'] ?? '');
    try {
        $registry->dispatch($name, is_array($job['data'] ?? null) ? $job['data'] : []);
        $queue->ack($job);
        $processed++;
        fwrite(STDOUT, "Выполнено: {$name} (" . ($job['id'] ?? '?') . ")\n");
    } catch (\Throwable $e) {
        $queue->nack($job, $e->getMessage());
        $failed++;
        $logger->exception($e);
        fwrite(STDERR, "Ошибка задачи {$name}: {$e->getMessage()}\n");
    }

    if ($max > 0 && ($processed + $failed) >= $max) {
        break;
    }
}

fwrite(STDOUT, "Queue worker: завершено. обработано: {$processed}, с ошибками: {$failed}\n");
exit($failed > 0 && $processed === 0 ? 1 : 0);
