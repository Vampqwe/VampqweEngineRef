<?php
declare(strict_types=1);

/**
 * CLI-утилита: списать буфер асинхронного счётчика просмотров в БД.
 *
 * Использование:
 *   php bin/views-flush.php          — забрать накопленные дельты из буфера
 *                                      (Redis либо storage/view_counter.json)
 *                                      и применить их одним UPDATE к таблице pages.
 *
 * Рекомендуемый cron (страховка от затишья трафика — веб-процессы списывают
 * буфер по порогу сами, но при тишине дельты могут лежать долго):
 *   *\/5 * * * * /usr/bin/php /path/to/app/bin/views-flush.php >> /dev/null 2>&1
 *
 * Надёжность: flush идемпотентен (буфер обнуляется до записи в БД), поэтому
 * параллельный запуск из cron и веб-процесса не удваивает счётчик.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Только CLI-режим.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Core\Module\Page\ViewCounter;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;

try {
    $config = Config::getInstance();
    $counter = new ViewCounter(new DataBase($config), $config);

    $updated = $counter->flush();

    fwrite(STDOUT, "ViewCounter flush: обновлено страниц: {$updated}\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Ошибка списания счётчика: {$e->getMessage()}\n");
    exit(1);
}
