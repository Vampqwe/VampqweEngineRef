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

namespace Core\Module\Admin\Services;

use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use Core\System\Queue\Queue;
use Core\System\Security\HtmlSanitizer;

/**
 * Фоновое выполнение импорта HTML (job «import.html»).
 *
 * Тяжёлые шаги — парсинг чужого HTML, скачивание картинок, запись
 * блоков и стилей — вынесены из веб-процесса: контроллер лишь кладёт
 * путь к временному файлу в очередь и сразу отвечает администратору.
 *
 * Payload задачи хранит только примитивы (путь, slug, user id), а не
 * сериализованные объекты — безопасно и переживает релизы.
 */
class ImportJobHandler
{
    public const JOB_NAME = 'import.html';

    /** Ключ хранения результата парсинга в сессии администратора. */
    public const SESSION_KEY = 'import_preview';

    /** Срок жизни временного файла импорта (старые файлы удаляются воркером). */
    private const TMP_TTL_SECONDS = 86400;

    public function __construct(private Config $config, private ?Queue $queue = null)
    {
        $this->queue ??= new Queue($config);
    }

    /**
     * Поставить импорт в очередь (вызывается из веб-процесса).
     * Возвращает id задачи или null, если очередь выключена (QUEUE_ENABLED=false).
     */
    public function dispatch(string $file, int $userId): ?string
    {
        if (!$this->queue->isEnabled()) {
            return null;
        }

        return $this->queue->push(self::JOB_NAME, [
            'file' => $file,
            'user_id' => $userId,
        ]);
    }

    /**
     * Фоновый режим включён (QUEUE_ENABLED=true)?
     */
    public function isQueueEnabled(): bool
    {
        return $this->queue->isEnabled();
    }

    /**
     * Удалить временный файл job'а из сессии (если он там есть).
     */
    public function forgetStagedFile(): void
    {
        $file = (string) ($_SESSION[self::SESSION_KEY]['import_file'] ?? '');
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Каталог временных файлов загрузок: storage/import/tmp/.
     */
    public function getTmpDir(): string
    {
        return $this->config->getStoragePath('import') . DIRECTORY_SEPARATOR . 'tmp';
    }

    /**
     * Сохранить загруженный файл во временный хранилище очереди.
     * Возвращает абсолютный путь либо null при неудаче.
     */
    public function stageUpload(string $tmpName): ?string
    {
        $dir = $this->getTmpDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $target = $dir . DIRECTORY_SEPARATOR . 'import-' . bin2hex(random_bytes(8)) . '.html';
        // move_uploaded_file не работает из CLI-контекста тестов — используем rename как fallback.
        $moved = @move_uploaded_file($tmpName, $target) || @rename($tmpName, $target);
        if (!$moved) {
            return null;
        }
        @chmod($target, 0660);

        return $target;
    }

    /**
     * Обработчик job «import.html». Бросок исключения трактуется очередью
     * как неудача (ретрай/failed решает Queue).
     *
     * @param array<string,mixed> $data
     */
    public function handle(array $data): void
    {
        $file = (string) ($data['file'] ?? '');
        $userId = (int) ($data['user_id'] ?? 0);

        try {
            if ($file === '' || !is_file($file)) {
                throw new \RuntimeException('Файл импорта не найден (возможно, уже обработан).');
            }

            $html = file_get_contents($file);
            if ($html === false || $html === '') {
                throw new \RuntimeException('Файл импорта пуст или не читается.');
            }

            $db = new DataBase($this->config);
            $parser = new HtmlImportParser($this->config);

            $parsed = $parser->parse($html);

            $pageService = new PageService($db);
            if ($pageService->pageExistsBySlug((string) $parsed['slug'])) {
                throw new \RuntimeException(
                    'Страница с slug «' . $parsed['slug'] . '» уже существует.'
                );
            }

            $baseUrl = rtrim((string) $this->config->get('APP_BASE_URL', ''), '/');
            $canonical = $baseUrl . '/' . ltrim((string) $parsed['slug'], '/');

            $pageCfg = require dirname(__DIR__, 5) . '/config/page-content.php';
            $defaultScript = (string) ($pageCfg['default_script'] ?? '/assets/js/main.js');
            $scripts = $parsed['scripts'] ?? [];
            if ($defaultScript !== '' && !in_array($defaultScript, $scripts, true)) {
                array_unshift($scripts, $defaultScript);
            }

            // Побитный импорт: если парсер вернул точный срез исходника
            // (content_raw=true), сохраняем его байт-в-байт — без обёртки и санитизации.
            // Точный срез из парсера: raw-режим или fallback (маркеры не найдены —
            // нормализованный контент, тогда обычная обработка).
            $mainMarker = (string) ($parsed['blocks']['main_content']['meta']['marker'] ?? '');
            $rawSlice = $mainMarker === 'raw_slice';
            $fallbackNorm = $mainMarker === '';

            $record = [
                'title' => $parsed['title'],
                'description' => $parsed['description'],
                'slug' => $parsed['slug'],
                'content' => $rawSlice
                    ? (string) ($parsed['blocks']['main_content']['value'] ?? $parsed['content'])
                    : $this->sanitizeContent($fallbackNorm
                        ? (string) $parsed['content']
                        : (string) $parser->stripDocumentWrapper((string) $parsed['content'])),
                'status' => 'draft',
                'page_type' => 'article',
                'meta_robots' => 'index, follow',
                'canonical' => $canonical,
                'og_title' => $parsed['title'],
                'og_description' => $parsed['description'],
                'scripts' => json_encode(array_values($scripts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];

            // Дополнительные колоночные блоки (config/import-parser.php) — та же
            // логика, что в ImportController::confirm().
            $selfFields = array_fill_keys(array_keys($record), true);
            foreach ($parser->getActiveBlocks() as $blockName => $block) {
                $target = (array) ($block['target'] ?? []);
                if ((string) ($target['mode'] ?? 'column') !== 'column') {
                    continue;
                }
                if ((string) ($target['table'] ?? 'pages') !== 'pages') {
                    continue;
                }
                $column = (string) ($target['column'] ?? '');
                if ($column === '' || isset($selfFields[$column])) {
                    continue;
                }
                $value = $parsed['values'][$column] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if ($column === 'content') {
                    $isRaw = ((string) ($parsed['blocks'][$blockName]['meta']['marker'] ?? '')) === 'raw_slice';
                    $value = $isRaw
                        ? (string) ($parsed['blocks'][$blockName]['value'] ?? $value)
                        : $this->sanitizeContent((string) $parser->stripDocumentWrapper((string) $value));
                } elseif (!is_string($value)) {
                    $value = (string) $value;
                }
                $record[$column] = $value;
            }

            $id = $pageService->createPage($record);

            // rows-блоки (schema_org, menu_items и др.)
            $importService = new ImportService($db);
            $targets = $parser->getBlockTargets();
            foreach ((array) ($parsed['row_sets'] ?? []) as $table => $rows) {
                if (!is_array($rows) || $rows === []) {
                    continue;
                }
                $fields = null;
                foreach ($targets as $target) {
                    if (($target['mode'] ?? 'column') === 'rows'
                        && (string) ($target['table'] ?? '') === (string) $table) {
                        $fields = (array) ($target['fields'] ?? []);
                        break;
                    }
                }
                if ($fields === null || $fields === []) {
                    continue; // блок не описан в конфиге — молча пропускаем (как flash-warning в вебе)
                }
                try {
                    $importService->writeRows((string) $table, $rows, $fields, ['page_id' => $id]);
                } catch (\Throwable) {
                    // ошибка блока не должна отменять созданный импорт
                }
            }

            if (!empty($parsed['styles'])) {
                $parser->commitStyles($parsed['styles']);
            }

            $this->notify($userId, 'success', 'Страница импортирована! ID: ' . $id . ' (фоновая обработка)');
        } catch (\Throwable $e) {
            $this->notify($userId, 'error', 'Импорт не выполнен: ' . $e->getMessage());
            throw $e; // Queue решит ретрай/failed
        } finally {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Удалить осиротевшие временные файлы старше TTL (запускается воркером).
     */
    public function pruneStaleUploads(): int
    {
        $dir = $this->getTmpDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $deadline = time() - self::TMP_TTL_SECONDS;
        foreach ((array) glob($dir . '/import-*.html') as $file) {
            if (is_string($file) && (int) @filemtime($file) < $deadline) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function sanitizeContent(string $html): string
    {
        $cfg = require dirname(__DIR__, 5) . '/config/page-content.php';
        $mode = (string) ($cfg['content_mode'] ?? 'sanitize');
        $maxLen = (int) ($cfg['max_content_length'] ?? 2_000_000);

        if ($maxLen > 0) {
            $html = mb_substr($html, 0, $maxLen);
        }

        return $mode === 'raw' ? $html : HtmlSanitizer::clean($html);
    }

    /**
     * Уведомление администратору о результате фоновой задачи.
     *
     * Воркер работает вне HTTP-сессии, а прямо писать в чужую сессию
     * небезопасно и несовместимо с драйвером redis. Поэтому результат
     * складывается в простой JSON-файл storage/import/notifications.json
     * (ключ — user id). Админ-панель может показать его при следующем
     * визите; до интеграции UI уведомления остаются в этом файле.
     */
    private function notify(int $userId, string $type, string $message): void
    {
        if ($userId <= 0) {
            return;
        }

        $dir = $this->config->getStoragePath('import');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'notifications.json';

        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        $data = '';
        while (($chunk = fread($handle, 65536)) !== false) {
            $data .= $chunk;
        }
        $store = json_decode((string) $data, true);
        if (!is_array($store)) {
            $store = [];
        }

        $entry = [
            'type' => $type,
            'message' => $message,
            'at' => date('Y-m-d H:i:s'),
        ];
        $store[(string) $userId][] = $entry;
        // Не копить бесконечно: храним последние 20 уведомлений на пользователя.
        $store[(string) $userId] = array_slice($store[(string) $userId], -20);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite(
            $handle,
            (string) json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
