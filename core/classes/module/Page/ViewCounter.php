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

namespace Core\Module\Page;

use Core\System\Config\Config;
use Core\System\DataBase\DataBase;

/**
 * Асинхронный счётчик просмотров страниц.
 *
 * Проблема, которую решает: синхронный «UPDATE pages SET views_count = ...»
 * на каждый GET блокирует строку страницы и создаёт гонку обновлений на
 * горячей строке — при росте трафика это упирается в row-lock contention,
 * а боты раздувают счётчик. Здесь же просмотр за запрос превращается в
 * дешёвую запись в буфер (Redis при наличии, иначе — файл в storage),
 * а в БД значения списываются пакетно одним UPDATE ... IN (flush).
 *
 * Конфиг (config/page-content.php, секция 'views'):
 *  - count_enabled    : учитывать ли просмотры вообще;
 *  - throttle_seconds : один засчитанный просмотр на страницу+посетителя
 *                       за окно (маркер в Redis/сессии);
 *  - async            : true (по умолчанию) — буферизовать и списывать
 *                       пакетно; false — прежнее прямое обновление БД;
 *  - flush_threshold  : списывать буфер, когда накоплено N инкрементов;
 *  - buffer_ttl       : TTL ключей буфера в Redis, сек;
 *  - max_keys_per_flush / file_max_pages : защита от раздувания одного
 *                       flush-запроса.
 *
 * Надёжность: все сбои хранилища/БД проглатываются с error_log — счётчик
 * не должен ронять выдачу страницы (fail-open).
 */
class ViewCounter
{
    /** Ключи-счётчики в Redis: prefix + pageId. */
    private const REDIS_PREFIX = 'viewcounter:';

    /** Флаг «в этом процессе уже запланирован shutdown-flush». */
    private bool $shutdownScheduled = false;

    /** @var array<string,mixed>|null Переопределение секции views (для тестов). */
    private ?array $viewsOverride;

    /** @var array<string,mixed>|null кэш прочитанной секции views. */
    private ?array $viewsCached = null;

    /** @var \Redis|null|false кэш подключения Redis (false — недоступен). */
    private mixed $redisInstance = null;

    /**
     * @param array<string,mixed>|null $viewsOverride переопределение секции
     *        'views' из config/page-content.php (используется тестами).
     */
    public function __construct(
        private readonly DataBase $db,
        private readonly Config $config,
        ?array $viewsOverride = null
    ) {
        $this->viewsOverride = $viewsOverride;
    }

    /**
     * Засчитать просмотр страницы. Вызывается из PageService::incrementViews().
     */
    public function record(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        $cfg = $this->viewsConfig();

        if (($cfg['count_enabled'] ?? true) !== true) {
            return;
        }

        if (!$this->passThrottle($pageId, $cfg)) {
            return;
        }

        // Режим совместимости: прежний синхронный UPDATE на каждый просмотр.
        if (($cfg['async'] ?? true) !== true) {
            $this->applyToDb([$pageId => 1], $cfg);
            return;
        }

        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $ttl = max(60, (int) ($cfg['buffer_ttl'] ?? 86400));
                $redis->incr(self::REDIS_PREFIX . $pageId);
                $redis->expire(self::REDIS_PREFIX . $pageId, $ttl);
                $pending = (int) $redis->incr(self::REDIS_PREFIX . 'pending');
                $redis->expire(self::REDIS_PREFIX . 'pending', $ttl);
            } catch (\Throwable $e) {
                error_log("ViewCounter redis write failed: {$e->getMessage()}");
                return; // просмотр потерян, но страница отдана
            }

            if ($pending >= max(1, (int) ($cfg['flush_threshold'] ?? 50))) {
                $this->scheduleFlush($redis, $cfg);
            }
            return;
        }

        $this->fileRecord($pageId, $cfg);
    }

    /**
     * Списать накопленный буфер в БД (одним UPDATE ... IN на пачку).
     * Идемпотентен: списанные счётчики обнуляются до применения UPDATE,
     * поэтому параллельные flush не удваивают значения.
     *
     * @return int количество обновленных строк pages
     */
    public function flush(): int
    {
        $cfg = $this->viewsConfig();
        $updated = 0;

        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $keys = $redis->keys(self::REDIS_PREFIX . '*');
                $delta = [];
                foreach ((array) $keys as $key) {
                    if (!preg_match('/^' . preg_quote(self::REDIS_PREFIX, '/') . '(\d+)$/', (string) $key, $m)) {
                        continue;
                    }
                    $val = (int) $redis->get($key);
                    if ($val > 0) {
                        // Get-and-clear: кто первым забрал значение, тот и пишет в БД.
                        $delta[(int) $m[1]] = $val;
                        $redis->set($key, 0);
                    }
                }
                $redis->del(self::REDIS_PREFIX . 'pending');
                $updated = $this->applyToDb($this->capDelta($delta, $cfg), $cfg);
            } catch (\Throwable $e) {
                error_log("ViewCounter redis flush failed: {$e->getMessage()}");
            }
            return $updated;
        }

        $path = $this->bufferFilePath();
        $fh = @fopen($path, 'c+b');
        if ($fh === false) {
            return 0;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return 0;
        }

        try {
            rewind($fh);
            $raw = stream_get_contents($fh);
            // Обнуляем буфер ДО записи в БД — параллельные flush не удвоят счётчик.
            ftruncate($fh, 0);
            rewind($fh);
            fflush($fh);

            $delta = json_decode((string) $raw, true);
            if (!is_array($delta) || $delta === []) {
                return 0;
            }
            $updated = $this->applyToDb($this->capDelta($delta, $cfg), $cfg);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $updated;
    }

    /**
     * Есть ли активный буфер (для диагностики/админ-панели).
     */
    public function hasPending(): bool
    {
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                return (int) $redis->get(self::REDIS_PREFIX . 'pending') > 0;
            } catch (\Throwable) {
                return false;
            }
        }

        $size = @filesize($this->bufferFilePath());
        return $size !== false && $size > 2; // '{}' и пустой файл не считаем
    }

    /**
     * Путь к файловому буферу (используется также CLI-скриптом cron-списания).
     */
    public static function bufferPathFor(Config $config): string
    {
        return $config->getStoragePath('view_counter.json');
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function viewsConfig(): array
    {
        if ($this->viewsCached !== null) {
            return $this->viewsCached;
        }

        if ($this->viewsOverride !== null) {
            return $this->viewsCached = $this->viewsOverride;
        }

        $path = dirname(__DIR__, 5) . '/config/page-content.php';
        $full = is_file($path) ? (array) require $path : [];
        return $this->viewsCached = (array) ($full['views'] ?? []);
    }

    /**
     * Троттлинг «страница + посетитель». Маркер живёт в Redis (перезаписью
     * по TTL) либо в сессии; без активной сессии просмотр засчитывается
     * как обычно (fail-open для UX).
     *
     * @param array<string,mixed> $cfg
     */
    private function passThrottle(int $pageId, array $cfg): bool
    {
        $throttle = max(0, (int) ($cfg['throttle_seconds'] ?? 300));
        // В CLI (cron-скрипт списания) троттлинг не применяется.
        if ($throttle <= 0 || (PHP_SAPI === 'cli' && !self::$forceSessionContextForTests)) {
            return true;
        }

        $redis = $this->redis();
        if ($redis !== null) {
            try {
                // setnx с TTL: если ключ поставлен — просмотр новый.
                $ok = $redis->set(
                    self::REDIS_PREFIX . "t:{$pageId}:" . $this->visitorKey(),
                    1,
                    ['nx', 'ex' => $throttle]
                );
                return $ok !== false;
            } catch (\Throwable) {
                // падаем в сессионный вариант
            }
        }

        // Маркер живёт в активной сессии посетителя; без сессии просмотр
        // засчитывается как обычно (fail-open для UX, защита от ботов — на
        // уровне Redis-маркера, когда он доступен).
        if (!$this->hasSessionContext()) {
            return true;
        }

        $markerKey = self::sessionMarkerKey($pageId);
        $marker = isset($_SESSION[$markerKey]) ? (int) $_SESSION[$markerKey] : 0;
        if (time() - $marker < $throttle) {
            return false;
        }
        $_SESSION[$markerKey] = time();
        return true;
    }

    /**
     * Есть ли контекст «активной сессии» для троттлинга. В CLI-тестах
     * session_status() всегда NONE, поэтому предусмотрен явный флаг.
     */
    public static bool $forceSessionContextForTests = false;

    private function hasSessionContext(): bool
    {
        if (self::$forceSessionContextForTests) {
            return true;
        }
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Ключ маркера троттлинга в сессии (общий для ViewCounter и fallback-пути
     * PageService, чтобы режимы не сбрасывали друг друга).
     */
    public static function sessionMarkerKey(int $pageId): string
    {
        return 'viewed_page_' . $pageId;
    }

    private function visitorKey(): string
    {
        $sid = session_id();
        if (is_string($sid) && $sid !== '') {
            return hash('xxh128', $sid);
        }
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0';
        return hash('xxh128', $ip);
    }

    /**
     * @param array<int,int> $delta
     * @param array<string,mixed> $cfg
     */
    private function capDelta(array $delta, array $cfg): array
    {
        $max = max(1, (int) ($cfg['max_keys_per_flush'] ?? 500));
        if (count($delta) > $max) {
            $delta = array_slice($delta, 0, $max, true);
        }
        return $delta;
    }

    /**
     * Пакетное применение дельты к БД: один UPDATE со CASE на всю пачку.
     *
     * @param array<int,int> $delta pageId => +N
     * @param array<string,mixed> $cfg
     */
    private function applyToDb(array $delta, array $cfg): int
    {
        if ($delta === []) {
            return 0;
        }

        $ids = array_map('intval', array_keys($delta));
        sort($ids); // детерминированный порядок -> нет взаимных блокировок

        try {
            $placeholders = [];
            $params = [];
            foreach ($ids as $i => $id) {
                $placeholders[] = ':id' . $i;
                $params['id' . $i] = $id;
            }

            if (count($ids) === 1) {
                $sql = "UPDATE pages SET views_count = views_count + :inc WHERE id = :id0";
                $params['inc'] = max(1, (int) reset($delta));
                return $this->db->execute($sql, $params);
            }

            // Один UPDATE на пачку вместо N отдельных — меньше транзакций,
            // короче удержание row-locks.
            $cases = '';
            foreach ($ids as $i => $id) {
                $cases .= 'WHEN :id' . $i . ' THEN :inc' . $i . ' ';
                $params['inc' . $i] = max(1, (int) $delta[$id]);
            }
            $sql = "UPDATE pages SET views_count = views_count + CASE id {$cases}END "
                 . "WHERE id IN (" . implode(',', $placeholders) . ")";

            return $this->db->execute($sql, $params);
        } catch (\Throwable $e) {
            error_log("ViewCounter flush to DB failed: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Запланировать списание буфера в конец текущего запроса: heavy UPDATE
     * не задерживает отдачу HTML. При исчерпании лимита процессов порождать
     * background-процесс нельзя, поэтому используется register_shutdown_function.
     */
    private function scheduleFlush(\Redis $redis, array $cfg): void
    {
        if ($this->shutdownScheduled) {
            return;
        }
        // Гасим лавину: следующий процесс начнёт flush только после паузы.
        try {
            $cooldown = max(1, (int) ($cfg['flush_cooldown_seconds'] ?? 10));
            if (!$redis->set(self::REDIS_PREFIX . 'flushing', 1, ['nx', 'ex' => $cooldown])) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $this->shutdownScheduled = true;
        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (\Throwable $e) {
                error_log("ViewCounter shutdown flush failed: {$e->getMessage()}");
            }
        });
    }

    /**
     * Файловый fallback: JSON-карта {pageId: +N} c эксклюзивным локом.
     *
     * @param array<string,mixed> $cfg
     */
    private function fileRecord(int $pageId, array $cfg): void
    {
        $path = $this->bufferFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            error_log("ViewCounter: cannot create buffer dir {$dir}");
            return;
        }

        $fh = @fopen($path, 'c+b');
        if ($fh === false) {
            return;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return;
        }

        try {
            rewind($fh);
            $raw = stream_get_contents($fh);
            $delta = json_decode((string) $raw, true);
            if (!is_array($delta)) {
                $delta = [];
            }

            $delta[$pageId] = (int) ($delta[$pageId] ?? 0) + 1;

            $fileMax = max(1, (int) ($cfg['file_max_pages'] ?? 1000));
            if (count($delta) > $fileMax) {
                $delta = array_slice($delta, 0, $fileMax, true);
            }

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($delta));
            fflush($fh);

            $threshold = max(1, (int) ($cfg['flush_threshold'] ?? 50));
            $total = array_sum($delta);
            if ($total >= $threshold) {
                $this->scheduleFileFlush($cfg);
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function scheduleFileFlush(array $cfg): void
    {
        if ($this->shutdownScheduled) {
            return;
        }

        $cooldown = max(1, (int) ($cfg['flush_cooldown_seconds'] ?? 10));
        $lockPath = $this->bufferFilePath() . '.flushing';
        $lh = @fopen($lockPath, 'c+b');
        if ($lh === false) {
            return;
        }
        // Неблокирующий лок: если другой процесс уже списывает — выходим.
        if (!flock($lh, LOCK_EX | LOCK_NB)) {
            fclose($lh);
            return;
        }

        $this->shutdownScheduled = true;
        register_shutdown_function(function () use ($lh): void {
            try {
                $this->flush();
            } catch (\Throwable $e) {
                error_log("ViewCounter shutdown flush failed: {$e->getMessage()}");
            } finally {
                @flock($lh, LOCK_UN);
                @fclose($lh);
            }
        });
    }

    private function bufferFilePath(): string
    {
        $override = trim((string) $this->config->get('VIEW_COUNTER_FILE', ''));
        if ($override !== '') {
            return $this->isAbsolutePath($override)
                ? $override
                : $this->config->resolvePath($override);
        }
        return self::bufferPathFor($this->config);
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1);
    }

    /**
     * Redis, если расширение и конфигурация доступны; иначе null (файл-fallback).
     */
    private function redis(): ?\Redis
    {
        if ($this->redisInstance !== null) {
            return $this->redisInstance === false ? null : $this->redisInstance;
        }

        if (!class_exists(\Redis::class)) {
            $this->redisInstance = false;
            return null;
        }

        // Тот же источник настроек, что и для full-page cache/сессий.
        $host = trim((string) $this->config->get('REDIS_HOST', ''));
        if ($host === '') {
            $this->redisInstance = false;
            return null;
        }

        try {
            $r = new \Redis();
            $port = $this->config->getInt('REDIS_PORT', 6379);
            $timeout = 0.5; // короткий таймаут: медленный Redis не тормозит выдачу
            $connected = @$r->connect($host, $port > 0 ? $port : 6379, $timeout);
            if (!$connected) {
                $this->redisInstance = false;
                return null;
            }
            $password = trim((string) $this->config->get('REDIS_PASSWORD', ''));
            if ($password !== '') {
                $r->auth($password);
            }
            $db = max(0, $this->config->getInt('REDIS_DB', 0));
            if ($db > 0) {
                $r->select($db);
            }
            $prefix = trim((string) $this->config->get('REDIS_PREFIX', ''));
            if ($prefix !== '') {
                $r->setOption(\Redis::OPT_PREFIX, $prefix);
            }
            $this->redisInstance = $r;
        } catch (\Throwable $e) {
            error_log("ViewCounter redis unavailable: {$e->getMessage()}");
            $this->redisInstance = false;
            return null;
        }

        return $r;
    }
}
