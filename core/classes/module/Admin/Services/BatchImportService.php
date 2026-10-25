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

namespace Core\Module\Admin\Services;

use Core\System\Config\Config;
use Core\System\Security\PathResolver;

/**
 * Пакетный импорт HTML-страниц из папки внутри storage.
 *
 * Пользователь закидывает .html/.htm файлы в подкаталог storage (например
 * storage/pages), админ выбирает папку — сервис сканирует её, прогоняет
 * каждый файл через HtmlImportParser::parse() и формирует короткий отчёт:
 * что можно импортировать, а что нет (битый файл, нет slug/title, slug уже
 * занят другой страницей). После подтверждения («жму ОК») импорт выполняется
 * по всем уже реализованным правилам — единой точкой PageImportWriter
 * (та же транзакция pages + блоки + schema_org, что и для одиночного
 * импорта), а успешно импортированные файлы переносятся в подпапку added
 * выбранной директории.
 *
 * Безопасность:
 *  - имена папок — только безопасные идентификаторы ([A-Za-z0-9_-]+), без
 *    разделителей — выход за пределы storage невозможен;
 *  - сканируются только файлы с разрешёнными расширениями, симлинги
 *    пропускаются; размер файла ограничен max_file_size из конфига парсера;
 *  - никакого выполнения содержимого файлов — только разбор DOM.
 */
class BatchImportService
{
    /** Подпапка, куда переносятся успешно импортированные файлы. */
    public const ADDED_DIR = 'added';

    /** Максимум файлов в одном пакете (защита от таймаута веб-процесса). */
    private const MAX_FILES_PER_BATCH = 500;

    public function __construct(
        private Config $config,
        private HtmlImportParser $parser,
        private PageService $pageService,
        private PageImportWriter $writer
    ) {
    }

    /**
     * Абсолютный путь корня storage (без завершающего разделителя).
     */
    public function getStorageRoot(): string
    {
        return rtrim(PathResolver::resolveStorageDir($this->config->getRootPath()), '/\\');
    }

    /**
     * Список подпапок storage (кроме служебных и added), доступных для импорта.
     *
     * @return array<int,array{name:string,files:int}>
     */
    public function listFolders(): array
    {
        $root = $this->getStorageRoot();
        if (!is_dir($root)) {
            return [];
        }

        $result = [];
        foreach (scandir($root) ?: [] as $name) {
            if (!in_array($name, ['.', '..'], true)
                && self::isValidFolderName($name)
                && !self::isReservedFolder($name)) {
                $dir = $root . DIRECTORY_SEPARATOR . $name;
                if (is_dir($dir) && !is_link($dir)) {
                    $result[] = ['name' => $name, 'files' => count($this->scanFiles($dir))];
                }
            }
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $result;
    }

    /**
     * Безопасное имя папки: только латиница/цифры/дефис/подчёркивание.
     * Разделители путей запрещены — traversal исключён на уровне синтаксиса.
     */
    public static function isValidFolderName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1;
    }

    /** Служебные каталоги storage, недоступные как источники импорта. */
    private static function isReservedFolder(string $name): bool
    {
        return in_array(strtolower($name), [
            'cache', 'logs', 'log', 'queue', 'sessions', 'tmp', 'framework',
            self::ADDED_DIR,
        ], true);
    }

    /**
     * Относительный путь папки по имени; '' если имя недопустимо/резервное.
     */
    public function resolveRelativeDir(string $folder): string
    {
        if (!self::isValidFolderName($folder) || self::isReservedFolder($folder)) {
            return '';
        }

        return trim((string) $this->config->get('STORAGE_DIR', 'storage'), '/\\')
            . '/' . $folder;
    }

    /**
     * Проверить пригодность папки для отчёта/импорта.
     *
     * @return array{ok:bool,error?:string,path?:string}
     */
    public function checkFolder(string $folder): array
    {
        if (!self::isValidFolderName($folder)) {
            return ['ok' => false, 'error' => 'Недопустимое имя папки (разрешены буквы, цифры, «-», «_»).'];
        }
        if (self::isReservedFolder($folder)) {
            return ['ok' => false, 'error' => 'Папка «' . $folder . '» является служебной.'];
        }

        $dir = $this->getStorageRoot() . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($dir)) {
            return ['ok' => false, 'error' => 'Папка не найдена: storage/' . $folder];
        }

        return ['ok' => true, 'path' => $this->resolveRelativeDir($folder)];
    }

    /**
     * Короткий отчёт по папке: по каждому файлу — статус пригодности.
     *
     * Статусы: ok | parse_error | no_slug | no_title | exists | too_large | unreadable.
     *
     * @return array{
     *   folder:string, path:string, total:int, importable:int,
     *   items:array<int,array{file:string,status:string,title:string,slug:string,schemas:int,reason:string}>
     * }
     */
    public function scan(string $folder): array
    {
        $check = $this->checkFolder($folder);
        if ($check['ok'] !== true) {
            return [
                'folder' => $folder,
                'path' => '',
                'total' => 0,
                'importable' => 0,
                'items' => [['file' => '', 'status' => 'no_files', 'title' => '', 'slug' => '',
                    'schemas' => 0, 'reason' => (string) ($check['error'] ?? 'Папка недоступна')]],
            ];
        }

        $dir = $this->getStorageRoot() . DIRECTORY_SEPARATOR . $folder;
        $files = $this->scanFiles($dir);

        $items = [];
        $importable = 0;

        foreach ($files as $entry) {
            $items[] = $this->inspectFile($entry['abs']);
            if ($items[count($items) - 1]['status'] === 'ok') {
                $importable++;
            }
        }

        // Дедуп slug внутри самого пакета: первый годный файл остаётся ok,
        // остальные помечаются exists (в БД их импортировать нельзя повторно).
        $seenSlugs = [];
        foreach ($items as &$item) {
            if ($item['status'] !== 'ok' || $item['slug'] === '') {
                continue;
            }
            if (isset($seenSlugs[$item['slug']])) {
                $item['status'] = 'exists';
                $item['reason'] = 'Дубликат slug внутри пакета (' . $seenSlugs[$item['slug']] . ')';
                $importable--;
            } else {
                $seenSlugs[$item['slug']] = $item['file'];
            }
        }
        unset($item);

        return [
            'folder' => $folder,
            'path' => (string) $check['path'],
            'total' => count($files),
            'importable' => max(0, $importable),
            'items' => $items,
        ];
    }

    /**
     * Импортировать все годные файлы папки (по правилам одиночного импорта:
     * PageImportWriter — та же транзакция и блоки config/import-parser.php).
     * Успешно импортированные файлы переносятся в storage/{folder}/added.
     *
     * Каждый файл изолирован: сбой одного не прерывает пакет (транзакция
     * откатывается на уровне одной страницы — writer бросает исключение
     * после rollback, частичного состояния в БД не остаётся).
     *
     * @return array{
     *   folder:string, imported:int, failed:int, skipped:int, moved:int,
     *   errors:array<int,string>, details:array<int,array<string,mixed>>
     * }
     */
    public function run(string $folder): array
    {
        $report = $this->scan($folder);

        $result = [
            'folder' => $folder,
            'imported' => 0,
            'failed' => 0,
            'skipped' => $report['total'] - $report['importable'],
            'moved' => 0,
            'errors' => [],
            'details' => [],
        ];

        if ($report['path'] === '' || $report['importable'] === 0) {
            return $result;
        }

        $dir = $this->getStorageRoot() . DIRECTORY_SEPARATOR . $folder;
        $maxSize = (int) ($this->parser->getRules()['max_file_size'] ?? 0);

        foreach ($report['items'] as $item) {
            if ($item['status'] !== 'ok') {
                continue;
            }

            $abs = $dir . DIRECTORY_SEPARATOR . $item['file'];

            try {
                $html = @file_get_contents($abs);
                if ($html === false || $html === '') {
                    throw new \RuntimeException('Файл пуст или не читается.');
                }
                if ($maxSize > 0 && strlen($html) > $maxSize) {
                    throw new \RuntimeException('Размер файла превышает лимит импорта.');
                }

                $parsed = $this->parser->parse($html);
                $slug = (string) ($parsed['slug'] ?? '');
                if ($slug === '') {
                    throw new \RuntimeException('Не удалось определить slug страницы.');
                }
                if ($this->pageService->pageExistsBySlug($slug)) {
                    throw new \RuntimeException('Страница со slug «' . $slug . '» уже существует.');
                }

                $writeReport = $this->writer->write($parsed);
                $pageId = (int) $writeReport['page_id'];

                // Перенос исходника в added — только после успешной записи.
                $moved = $this->moveToAdded($dir, $item['file']);

                $result['imported']++;
                if ($moved !== '') {
                    $result['moved']++;
                }
                $result['details'][] = [
                    'file' => $item['file'],
                    'page_id' => $pageId,
                    'slug' => $slug,
                    'moved' => $moved !== '' ? self::ADDED_DIR . '/' . basename($moved) : '',
                    'warnings' => $writeReport['warnings'] ?? [],
                ];
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $item['file'] . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Файлы папки (не рекурсивно, только разрешённые расширения, без симлингов).
     *
     * @return array<int,array{name:string,abs:string,size:int}>
     */
    private function scanFiles(string $dir): array
    {
        $allowed = array_map(
            static fn ($e): string => strtolower((string) $e),
            (array) ($this->parser->getRules()['allowed_extensions'] ?? ['html', 'htm'])
        );

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '' || $name[0] === '.') {
                continue; // скрытые файлы и . / ..
            }
            $abs = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($abs) || is_link($abs)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($allowed !== [] && !in_array($ext, $allowed, true)) {
                continue;
            }
            $files[] = ['name' => $name, 'abs' => $abs, 'size' => (int) (@filesize($abs) ?: 0)];
            if (count($files) >= self::MAX_FILES_PER_BATCH) {
                break;
            }
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $files;
    }

    /**
     * Полная проверка одного файла без побочных эффектов (только parse()).
     *
     * @return array{file:string,status:string,title:string,slug:string,schemas:int,reason:string}
     */
    private function inspectFile(string $abs): array
    {
        $name = basename($abs);
        $item = ['file' => $name, 'status' => 'ok', 'title' => '', 'slug' => '', 'schemas' => 0, 'reason' => ''];

        $size = (int) (@filesize($abs) ?: 0);
        $maxSize = (int) ($this->parser->getRules()['max_file_size'] ?? 0);
        if ($maxSize > 0 && $size > $maxSize) {
            $item['status'] = 'too_large';
            $item['reason'] = 'Размер больше лимита ' . round($maxSize / 1048576, 1) . ' МБ';

            return $item;
        }

        $html = @file_get_contents($abs);
        if ($html === false || $html === '') {
            $item['status'] = 'unreadable';
            $item['reason'] = 'Файл пуст или не читается';

            return $item;
        }

        try {
            $parsed = $this->parser->parse($html);
        } catch (\Throwable $e) {
            $item['status'] = 'parse_error';
            $item['reason'] = 'Ошибка парсинга: ' . $e->getMessage();

            return $item;
        }

        $item['title'] = mb_substr((string) ($parsed['title'] ?? ''), 0, 120);
        $item['slug'] = (string) ($parsed['slug'] ?? '');
        $item['schemas'] = count((array) ($parsed['schemas'] ?? []));

        if ($item['slug'] === '') {
            $item['status'] = 'no_slug';
            $item['reason'] = 'Не определён slug (нет canonical/<h1>/title)';

            return $item;
        }
        if ($item['title'] === '') {
            $item['status'] = 'no_title';
            $item['reason'] = 'Не найден заголовок страницы';

            return $item;
        }
        if ($this->pageService->pageExistsBySlug($item['slug'])) {
            $item['status'] = 'exists';
            $item['reason'] = 'Страница со slug уже существует в БД';

            return $item;
        }

        return $item;
    }

    /**
     * Перенести файл в storage/{folder}/added (с уникализацией имени при
     * коллизии). Возвращает новый абсолютный путь или '' при неудаче —
     * страница уже в БД, перенос не критичен.
     */
    private function moveToAdded(string $dir, string $fileName): string
    {
        $addedDir = $dir . DIRECTORY_SEPARATOR . self::ADDED_DIR;
        if (!is_dir($addedDir) && !@mkdir($addedDir, 0775, true) && !is_dir($addedDir)) {
            return '';
        }

        $target = $addedDir . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($target)) {
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $base = pathinfo($fileName, PATHINFO_FILENAME);
            $target = $addedDir . DIRECTORY_SEPARATOR
                . $base . '-' . date('YmdHis') . '-' . substr(md5($fileName . microtime(true)), 0, 6)
                . ($ext !== '' ? '.' . $ext : '');
        }

        return @rename($dir . DIRECTORY_SEPARATOR . $fileName, $target) ? $target : '';
    }
}
