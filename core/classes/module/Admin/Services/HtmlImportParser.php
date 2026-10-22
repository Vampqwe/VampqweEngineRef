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
use DOMDocument;
use DOMXPath;

class HtmlImportParser
{
    private Config $config;

    /** @var array<string,mixed> Правила импорта из config/import-parser.php */
    private array $rules;
    private string $basePath;
    private string $uploadDir;
    /** Промежуточный файл стилей: storage/neutral-style.css */
    private string $stylesFile;
    private array $downloadedImages = [];
    private string $extractedStyles = '';
    private array $warnings = [];
    /** Кэш картинок original => local (в т.ч. неудачные попытки => null) */
    private array $imageCache = [];


    public function __construct(Config $config)
    {
        $this->config = $config;

        // Единая точка управления правилами парсинга — config/import-parser.php
        $rulesFile = dirname(__DIR__, 4) . '/config/import-parser.php';
        $this->rules = is_file($rulesFile) ? (array) require $rulesFile : [];

        // Получаем реальный путь к storage (с правильным регистром)
        // Корень проекта — каноническая настройка APP_ROOT_PATH (единый резолвер,
        // см. Config::getStoragePath()). storage/ всегда вычисляется от корня,
        // устаревший FS_BASE_PATH для пути больше не используется.
        $configBasePath = $config->getStoragePath();
        $realBasePath = realpath($configBasePath);

        if ($realBasePath === false) {
            throw new \RuntimeException(
                "storage/ не существует: {$configBasePath}"
                . ' (проверьте APP_ROOT_PATH в config.env)');
        }

        $this->basePath = $realBasePath;
        $this->uploadDir = $this->basePath . DIRECTORY_SEPARATOR
            . trim((string) ($this->rules['upload_dir'] ?? 'uploads/import'), '/');
        // Стили импортируемых страниц складываются в промежуточный файл стилей
        $this->stylesFile = $this->basePath . DIRECTORY_SEPARATOR
            . trim((string) ($this->rules['styles_file'] ?? 'neutral-style.css'), '/');

        // Создаём директории
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Валидация загруженного файла до парсинга.
     * Возвращает список ошибок (пустой массив = файл валиден).
     */
    public function validateUpload(array $file): array
    {
        $errors = [];

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Файл не загружен или произошла ошибка загрузки';
            return $errors;
        }

        $maxFileSize = (int) ($this->rules['max_file_size'] ?? 20 * 1024 * 1024);
        if ($file['size'] > $maxFileSize) {
            $errors[] = sprintf('Файл слишком большой (максимум %d МБ)', (int) round($maxFileSize / 1048576));
        }

        $allowedExtensions = (array) ($this->rules['allowed_extensions'] ?? ['html', 'htm']);
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Допустимы только файлы: .' . implode(', .', $allowedExtensions);
        }

        return $errors;
    }

    public function parse(string $html): array
    {
        $this->downloadedImages = [];
        $this->extractedStyles = '';
        $this->warnings = [];
        $this->imageCache = [];

        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        // Валидация описаний блоков из конфига: битый блок не должен ронять импорт —
        // он пропускается с предупреждением.
        $blocks = (array) ($this->rules['blocks'] ?? []);
        if ($blocks === []) {
            $blocks = $this->defaultBlocks();
        }
        foreach ($blocks as $name => $block) {
            if (!is_array($block)) {
                unset($blocks[$name]);
                continue;
            }
            $check = self::validateBlockDefinition((string) $name, $block);
            if (!$check['ok']) {
                $this->warnings[] = (string) $check['error'] . ' — блок пропущен';
                unset($blocks[$name]);
            }
        }

        // Глобальные <style> всего документа уносятся в neutral-style.css до извлечения
        // контентных блоков; HTML-разметка и все классы остаются без изменений.
        $this->collectStyles($xpath);
        $this->removeStyleTags($doc, $xpath);

        $blockResults = [];   // имя блока => ['value'|'rows', 'found', ...]
        $values = [];         // колонка => скалярное значение для pages
        $rowSets = [];        // table => список строк для вставки (mode=rows)

        foreach ($blocks as $name => $block) {
            if (!is_array($block)) {
                continue;
            }
            $result = $this->extractBlock((string) $name, $block, $html, $doc, $xpath);
            $blockResults[(string) $name] = $result;

            if (($result['found'] ?? false) !== true && !empty($block['required'])) {
                $this->warnings[] = "Обязательный блок «{$name}» не найден в документе";
            }
        }

        // Сборка значений по target-описаниям из конфига блоков
        foreach ($blocks as $name => $block) {
            $result = $blockResults[$name] ?? null;
            if ($result === null) {
                continue;
            }
            $target = (array) ($block['target'] ?? []);
            $mode = (string) ($target['mode'] ?? 'column');

            if ($mode === 'rows') {
                $table = (string) ($target['table'] ?? '');
                if ($table !== '') {
                    $rowSets[$table] = array_merge($rowSets[$table] ?? [], $result['rows'] ?? []);
                }
                continue;
            }

            $column = (string) ($target['column'] ?? '');
            if ($column === '') {
                continue; // блок только для предпросмотра (без записи)
            }

            $value = $result['value'] ?? null;
            if ($mode === 'json_array') {
                $list = is_array($value) ? array_values($value) : [];
                if ($list === [] && isset($block['default'])) {
                    $list = (array) $block['default'];
                }
                $values[$column] = json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                if ((!is_string($value) || $value === '') && isset($block['default'])) {
                    $value = (string) $block['default'];
                }
                $values[$column] = $value;
            }
        }

        // Специальные пост-источники: slug может генерироваться из title
        if (isset($values['slug']) && (!is_string($values['slug']) || $values['slug'] === '')) {
            unset($values['slug']);
        }
        if (!isset($values['slug'])) {
            $slug = $this->generateSlug((string) ($values['title'] ?? ''));
            if ($slug === '') {
                $slug = 'import-' . substr(md5(((string) ($values['title'] ?? '')) . microtime()), 0, 8);
            }
            $values['slug'] = $slug;
        }

        // Совместимость со старшим контрактом ImportController/preview.html
        $schemas = $rowSets['schema_org'] ?? [];
        $scriptsRaw = $values['scripts'] ?? null;
        $scripts = is_string($scriptsRaw) ? (array) json_decode($scriptsRaw, true) : [];
        if ($scripts === []) {
            $scripts = ['/assets/js/main.js'];
        }

        return [
            'title' => (string) ($values['title'] ?? ''),
            'description' => (string) ($values['description'] ?? ''),
            'slug' => (string) ($values['slug'] ?? ''),
            'content' => (string) ($values['content'] ?? ''),
            'schemas' => $schemas,
            'scripts' => $scripts,
            'styles' => $this->extractedStyles,
            'images_count' => count($this->downloadedImages),
            'styles_extracted' => strlen($this->extractedStyles),
            'downloaded_images' => $this->downloadedImages,
            'warnings' => $this->warnings,
            // Новые поля: полный отчёт по блокам и готовые к записи данные
            'blocks' => $blockResults,
            'values' => $values,
            'row_sets' => $rowSets,
        ];
    }

    /**
     * Публичный доступ к правилам импорта (config/import-parser.php) —
     * для отчёта предпросмотра в админке.
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Описания target ('table', 'mode', 'column', 'fields') активных блоков —
     * для generic-записи в БД на этапе подтверждения импорта.
     *
     * @return array<string,array>
     */
    public function getBlockTargets(): array
    {
        $targets = [];
        foreach ($this->getActiveBlocks() as $name => $block) {
            $target = (array) ($block['target'] ?? []);
            if ($target !== []) {
                $targets[(string) $name] = $target;
            }
        }

        return $targets;
    }

    /**
     * Активный профиль блоков: из конфига (валидный) либо встроенный по умолчанию.
     *
     * @return array<string,array>
     */
    public function getActiveBlocks(): array
    {
        $blocks = (array) ($this->rules['blocks'] ?? []);
        if ($blocks === []) {
            return $this->defaultBlocks();
        }

        $valid = [];
        foreach ($blocks as $name => $block) {
            if (!is_array($block)) {
                continue;
            }
            $check = self::validateBlockDefinition((string) $name, $block);
            if ($check['ok']) {
                $valid[$name] = $block;
            }
        }

        return $valid;
    }

    /**
     * Валидация и нормализация описания блока из конфига.
     * Возвращает ['ok' => bool, 'error' => string|null, 'block' => array].
     */
    public static function validateBlockDefinition(string $name, array $block): array
    {
        $error = null;

        if (preg_match('/[^a-z0-9_]/i', $name) === 1) {
            $error = "Имя блока «{$name}»: допускаются только буквы, цифры и подчёркивание";
        } else {
            $type = (string) ($block['type'] ?? '');
            $knownTypes = ['marker', 'xpath', 'meta', 'schema', 'script', 'title'];

            if (!empty($block['source'])) {
                if ($block['source'] !== 'slug_from_title') {
                    $error = "Блок «{$name}»: неизвестный source «{$block['source']}»";
                }
            } elseif (!in_array($type, $knownTypes, true)) {
                $error = "Блок «{$name}»: неизвестный type «{$type}» (допустимы: " . implode(', ', $knownTypes) . ')';
            } elseif ($type === 'marker' && empty($block['start'])) {
                $error = "Блок «{$name}»: для marker-блока обязателен 'start'";
            } elseif ($type === 'xpath' && empty($block['expression'])) {
                $error = "Блок «{$name}»: для xpath-блока обязательно 'expression'";
            }

            $target = $block['target'] ?? null;
            if ($error === null && $target !== null) {
                if (!is_array($target)) {
                    $error = "Блок «{$name}»: target должен быть массивом";
                } else {
                    $mode = (string) ($target['mode'] ?? 'column');
                    if (!in_array($mode, ['column', 'json_array', 'rows'], true)) {
                        $error = "Блок «{$name}»: неизвестный target.mode «{$mode}»";
                    } elseif (($mode === 'column' || $mode === 'json_array') && empty($target['column'])) {
                        $error = "Блок «{$name}»: в target отсутствует 'column'";
                    } elseif ($mode === 'rows' && (empty($target['table']) || empty($target['fields']))) {
                        $error = "Блок «{$name}»: для rows-режима нужны target.table и target.fields";
                    }
                    foreach (['table', 'column'] as $k) {
                        if (isset($target[$k]) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $target[$k]) !== 1) {
                            $error = "Блок «{$name}»: некорректное имя {$k} в target: «{$target[$k]}»";
                            break;
                        }
                    }
                }
            }
        }

        return ['ok' => $error === null, 'error' => $error, 'block' => $block];
    }

    /**
     * Профиль блоков по умолчанию — ровно прежнее жёстко зашитое поведение
     * (для конфигов без секции 'blocks').
     */
    private function defaultBlocks(): array
    {
        return [
            'title' => [
                'type' => 'title',
                'target' => ['table' => 'pages', 'column' => 'title'],
                'required' => true,
            ],
            'description' => [
                'type' => 'meta',
                'expression' => 'description',
                'fallback_meta' => ['property' => 'og:description'],
                'target' => ['table' => 'pages', 'column' => 'description'],
            ],
            'main_content' => [
                'type' => 'marker',
                'start' => '<!-- ОСНОВНОЙ КОНТЕНТ -->',
                'end' => '<!-- FOOTER -->',
                'strip_wrapper' => true,
                'verify_integrity' => true,
                'download_images' => true,
                'target' => ['table' => 'pages', 'column' => 'content'],
            ],
            'schema' => [
                'type' => 'schema',
                'target' => [
                    'table' => 'schema_org',
                    'mode' => 'rows',
                    'fields' => ['schema_type' => '{schema_type}', 'data' => '{json}', 'priority' => 10],
                ],
            ],
            'scripts' => [
                'type' => 'script',
                'target' => ['table' => 'pages', 'column' => 'scripts', 'mode' => 'json_array'],
                'default' => ['/assets/js/main.js'],
            ],
        ];
    }

    /**
     * Извлечение одного блока согласно его описанию из конфига.
     *
     * @return array{found:bool, value:mixed, rows:array, meta:array}
     */
    private function extractBlock(string $name, array $block, string $sourceHtml, DOMDocument $doc, DOMXPath $xpath): array
    {
        $type = (string) ($block['type'] ?? 'marker');
        $result = ['found' => false, 'value' => null, 'rows' => [], 'meta' => []];

        switch ($type) {
            case 'title':
                $value = $this->extractTitle($xpath);
                $result['found'] = $value !== '';
                $result['value'] = $value;
                break;

            case 'meta':
                $value = $this->extractMetaByBlock($xpath, $block);
                $result['found'] = $value !== '';
                $result['value'] = $value;
                break;

            case 'xpath':
                $expr = (string) ($block['expression'] ?? '');
                $nodes = $expr !== '' ? @$xpath->query($expr) : false;
                if ($nodes === false) {
                    $this->warnings[] = "Блок «{$name}»: некорректное XPath-выражение: {$expr}";
                    break;
                }
                $html = '';
                foreach ($nodes as $node) {
                    foreach ($node->childNodes as $child) {
                        $html .= $doc->saveHTML($child);
                    }
                }
                $result['found'] = trim($html) !== '';
                $result['value'] = $html;
                $result['meta']['nodes'] = $nodes->length;
                break;

            case 'marker':
                [$startPos, $endPos] = $this->findMarkerPositionsForBlock($sourceHtml, $block, $name);
                if ($startPos !== null) {
                    $raw = $endPos === null
                        ? substr($sourceHtml, $startPos)
                        : substr($sourceHtml, $startPos, $endPos - $startPos);

                    if ($endPos === null) {
                        $this->warnings[] = "Блок «{$name}»: закрывающий маркер не найден, берём до конца документа";
                        $footerPos = stripos($raw, '<footer');
                        if ($footerPos !== false) {
                            $raw = substr($raw, 0, $footerPos);
                        }
                    }

                    // Отбрасываем закрывающие теги контейнеров, попавшие в начало среза
                    $raw = preg_replace('~^\s*</(main|div|section|article|body|html)\s*>~iu', '', $raw, 1) ?? $raw;

                    $result['value'] = $this->normalizeFragment($raw, $block, $name);
                    $result['found'] = trim(strip_tags($result['value'])) !== '' || trim($result['value']) !== '';
                    $result['meta']['marker'] = 'exact';
                } elseif (!empty($block['fallback_xpath'])) {
                    $frag = $this->extractByFallbackXpath($sourceHtml, (string) $block['fallback_xpath'], $block, $name);
                    if ($frag !== null) {
                        $result['value'] = $frag;
                        $result['found'] = trim($frag) !== '';
                        $result['meta']['marker'] = 'fallback_xpath';
                    }
                }
                break;

            case 'schema':
                $result['rows'] = $this->extractSchema($xpath);
                $result['found'] = $result['rows'] !== [];
                $result['value'] = $result['rows'];
                break;

            case 'script':
                $result['value'] = $this->extractScripts($xpath);
                $result['found'] = $result['value'] !== [];
                break;

            default:
                $this->warnings[] = "Блок «{$name}»: неизвестный тип «{$type}», блок пропущен";
        }

        // Транформация строкового значения
        if (is_string($result['value'] ?? null) && !empty($block['transform'])) {
            $result['value'] = $this->applyTransform($result['value'], (string) $block['transform']);
        }

        return $result;
    }

    /**
     * Публичное извлечение текста блока по XPath (для детального отчёта
     * предпросмотра в админке — сравнение с исходным HTML).
     *
     * @return array<string,string> имя блока => plain-текст ('' если не найден)
     */
    public function blocksPlainText(string $html): array
    {
        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        $result = [];
        foreach ($this->getActiveBlocks() as $name => $block) {
            $type = (string) ($block['type'] ?? '');
            $text = '';
            switch ($type) {
                case 'title':
                    $text = $this->extractTitle($xpath);
                    break;
                case 'meta':
                    $text = $this->extractMetaByBlock($xpath, $block);
                    break;
                case 'marker':
                case 'xpath':
                    [$value] = $this->extractBlockValueForReport((string) $name, $block, $html, $doc, $xpath);
                    $text = is_string($value) ? $this->plainText($value) : '';
                    break;
            }
            $result[(string) $name] = $text;
        }

        return $result;
    }

    /**
     * Значение блока только для отчёта (без скачивания картинок и записи стилей).
     *
     * @return array{0:mixed, 1:bool} [value, found]
     */
    private function extractBlockValueForReport(string $name, array $block, string $sourceHtml, DOMDocument $doc, DOMXPath $xpath): array
    {
        $type = (string) ($block['type'] ?? 'marker');

        if ($type === 'xpath') {
            $expr = (string) ($block['expression'] ?? '');
            $nodes = $expr !== '' ? @$xpath->query($expr) : false;
            $html = '';
            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    foreach ($node->childNodes as $child) {
                        $html .= $doc->saveHTML($child);
                    }
                }
            }
            return [$html, trim($html) !== ''];
        }

        // marker (и прочие типы здесь не нужны)
        [$startPos, $endPos] = $this->findMarkerPositionsForBlock($sourceHtml, $block, $name);
        if ($startPos !== null) {
            $raw = $endPos === null
                ? substr($sourceHtml, $startPos)
                : substr($sourceHtml, $startPos, $endPos - $startPos);
            return [$raw, trim($raw) !== ''];
        }

        if (!empty($block['fallback_xpath'])) {
            $expr = (string) $block['fallback_xpath'];
            $nodes = @$xpath->query($expr);
            if ($nodes !== false && $nodes->length > 0) {
                $html = '';
                foreach ($nodes as $node) {
                    foreach ($node->childNodes as $child) {
                        $html .= $doc->saveHTML($child);
                    }
                }
                return [$html, trim($html) !== ''];
            }
        }

        return [null, false];
    }

    private function applyTransform(string $value, string $transform): string
    {
        return match ($transform) {
            'trim' => trim($value),
            'lowercase' => mb_strtolower(trim($value), 'UTF-8'),
            'strip_tags' => strip_tags($value),
            'plain_text' => $this->plainText($value),
            'slug' => $this->generateSlug($value),
            default => $value,
        };
    }

    /**
     * Meta-значение по блоку: name или property + опциональный fallback_meta.
     */
    private function extractMetaByBlock(DOMXPath $xpath, array $block): string
    {
        $expr = (string) ($block['expression'] ?? '');
        if ($expr !== '') {
            $nodes = $xpath->query('//meta[@name="' . $expr . '"]/@content');
            if ($nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
            $nodes = $xpath->query('//meta[@property="' . $expr . '"]/@content');
            if ($nodes->length > 0) {
                return trim($nodes->item(0)->textContent);
            }
        }

        foreach ((array) ($block['fallback_meta'] ?? []) as $fb) {
            if (!is_array($fb)) {
                continue;
            }
            if (isset($fb['name'])) {
                $nodes = $xpath->query('//meta[@name="' . $fb['name'] . '"]/@content');
                if ($nodes->length > 0) {
                    return trim($nodes->item(0)->textContent);
                }
            }
            if (isset($fb['property'])) {
                $nodes = $xpath->query('//meta[@property="' . $fb['property'] . '"]/@content');
                if ($nodes->length > 0) {
                    return trim($nodes->item(0)->textContent);
                }
            }
        }

        return '';
    }

    /**
     * Нормализация raw-фрагмента блока: отдельный DOM (починка «битого» HTML),
     * опционально — сбор <style>, скачивание картинок, снятие служебной обёртки,
     * контроль целостности текста.
     */
    private function normalizeFragment(string $raw, array $block, string $name): string
    {
        $fragDoc = new DOMDocument();
        libxml_use_internal_errors(true);
        $fragDoc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'
                . $raw . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $fragXp = new DOMXPath($fragDoc);

        if (!empty($block['collect_styles'])) {
            $this->collectStyles($fragXp);
            $this->removeStyleTags($fragDoc, $fragXp);
        }

        $html = $this->extractInnerBody($fragDoc);

        if (!empty($block['strip_wrapper'])) {
            $html = $this->stripDocumentWrapper($html);
        }

        if (!empty($block['verify_integrity'])) {
            $this->verifyContentIntegrity($html, $raw);
        }

        if (!empty($block['download_images'])) {
            $html = $this->downloadAndReplaceImages($html);
        }

        return $html;
    }

    /**
     * Fallback-извлечение блока по XPath (//main, //article и т.п.) из исходника.
     */
    private function extractByFallbackXpath(string $sourceHtml, string $expr, array $block, string $name): ?string
    {
        $doc = $this->loadHtml($sourceHtml);
        $xp = new DOMXPath($doc);
        $nodes = @$xp->query($expr);
        if ($nodes === false || $nodes->length === 0) {
            $this->warnings[] = "Блок «{$name}»: маркеры и fallback-узлы не найдены";
            return null;
        }

        $this->collectStyles($xp);
        $this->removeStyleTags($doc, $xp);

        $html = '';
        foreach ($nodes as $node) {
            foreach ($node->childNodes as $child) {
                $html .= $doc->saveHTML($child);
            }
        }

        return $this->normalizeFragment($html, $block, $name);
    }

    /**
     * Позиции маркеров конкретного блока в исходном HTML.
     * 'start'/'end' — либо точное содержимое комментария ('<!-- nav -->',
     * сравнивается регистронезависимо и с гибкими пробелами), либо PCRE
     * ('~...~' или '/.../'). При неудаче подключаются глобальные 'marker_aliases'.
     *
     * @return array{0:int|null, 1:int|null}
     */
    private function findMarkerPositionsForBlock(string $html, array $block, string $name): array
    {
        $start = $this->findMarkerOffset($html, $block['start'] ?? null, 'start');
        if ($start === null) {
            return [null, null];
        }

        // Поиск end ведём строго ПОСЛЕ конца start-маркера: повторное совпадение
        // с самим start невозможно в силу позиции, а содержимое блока между
        // маркерами легитимно и токен-проверке не подлежит (иначе имя start
        // «nav» ложно блокировало бы корректный end «end nav»).
        $tailOffset = $start;

        $end = $this->findMarkerOffset(substr($html, $tailOffset), $block['end'] ?? null, 'end');

        return [$start, $end === null ? null : $tailOffset + $end];
    }

    /**
     * Ядра маркеров в нижнем регистре (все буквы/цифры/подчёркивания по порядку).
     *
     * @return string[]
     */
    private function markerTokens(mixed $marker): array
    {
        $tokens = [];
        $items = is_array($marker) ? $marker : [$marker];
        foreach ($items as $m) {
            if (!is_string($m)) {
                continue;
            }
            $raw = trim($m);
            if ($raw === '' || $this->looksLikePcre($raw)) {
                continue;
            }
            $core = strtolower((string) preg_replace('~[^A-Za-z0-9_]~u', '', $raw));
            if ($core !== '') {
                $tokens[] = $core;
            }
        }

        return $tokens;
    }

    /**
     * Строит список PCRE-паттернов для литерального маркера (или готовой PCRE).
     *
     * @return string[]
     */
    private function markerPatterns(mixed $marker): array
    {
        if ($marker === null || $marker === '') {
            return [];
        }

        $patterns = [];
        if (is_string($marker)) {
            if ($this->looksLikePcre($marker)) {
                // Готовая PCRE из конфига
                $patterns[] = $marker;
            } else {
                // Литеральный маркер: комментарий <!-- ... --> или произвольный
                // разделитель вида <---nav---> / <-- nav --> (регистронезависимо,
                // гибкие пробелы между словами).
                $raw = trim($marker);
                if (preg_match('~^<!--.*-->$~s', $raw) === 1) {
                    // Комментарий: снимаем обрамление '<!--' ... '-->', внутри — имя маркера.
                    $inner = trim((string) preg_replace('~^<!--\s*|\s*-->$~', '', $raw));
                    // 'ОСНОВНОЙ КОНТЕНТ' и т.п.: одинарные пробелы → гибкие \s*
                    $flexible = str_replace(' ', '\\s*', preg_quote($inner, '~'));
                    $patterns[] = '~<!--[<\\s-]*\\s*' . $flexible . '\\s*[>\\s-]*-->~iu';
                } else {
                    // Произвольный литеральный разделитель: '<---nav--->', '<-- nav -->'.
                    // 1) Точное совпадение (регистронезависимо, гибкие пробелы).
                    $flex = str_replace(' ', '\\s*', preg_quote($raw, '~'));
                    $patterns[] = '~' . $flex . '~iu';
                    // 2) Гибкие границы: ядро = буквы/цифры по порядку,
                    //    всё остальное (<, -, !, пробелы) — [<>\s!-]*.
                    $core = preg_quote((string) preg_replace('~[^A-Za-z0-9_]~u', '', $raw), '~');
                    $coreFlex = str_replace(' ', '\\s*', $core);
                    $loose = '[<>\\s!-]*' . $coreFlex . '[<>\\s!-]*';
                    $patterns[] = '~<' . $loose . '>~iu';
                    $patterns[] = '~<!--' . $loose . '-->~iu';
                }
            }
        } elseif (is_array($marker)) {
            foreach ($marker as $m) {
                if (is_string($m)) {
                    $patterns = array_merge($patterns, $this->markerPatterns($m));
                }
            }
        }

        return $patterns;
    }

    /**
     * Ищет одно вхождение маркера. Возвращает смещение КОНЦА найденного
     * маркера (int) или null.
     *
     * @param string[]|null $forbidden Токены (ядро маркера в
     *        нижнем регистре), которые нельзя принимать за найденный маркер —
     *        защита от совпадения end-паттерна с тем же самым start-маркером.
     */
    private function findMarkerOffset(string $html, mixed $marker, string $side, ?array $forbidden = null): ?int
    {
        if ($marker === null || $marker === '') {
            return null;
        }

        $patterns = $this->markerPatterns($marker);

        // Собственные регулярки маркера → глобальные псевдонимы из конфига
        foreach ((array) ($this->rules['marker_aliases'][$side] ?? []) as $alias) {
            if (is_string($alias)) {
                $patterns[] = $alias;
            }
        }

        foreach ($patterns as $p) {
            // Перебор всех вхождений: первое может оказаться самим start-маркером
            // (или его вариацией из псевдонимов) — такие совпадения пропускаем.
            $offset = 0;
            while (@preg_match($p, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $matchStart = (int) $m[0][1];
                $matchEnd = $matchStart + strlen($m[0][0]);
                // Защита проверяет только промежуток МЕЖДУ позицией поиска и
                // совпадением: если там есть буквы другого (запрещённого) ядра,
                // совпадение относится к иному маркеру — пропускаем ищем дальше.
                // Важно: не весь префикс строки — иначе собственное имя
                // start-маркера («nav») ложно блокирует корректный end («end nav»).
                if ($forbidden !== null
                    && $this->isForbiddenMatch(substr($html, $offset, $matchStart - $offset), $forbidden)) {
                    $offset = max($matchStart + 1, $offset + 1);
                    continue;
                }

                return $matchEnd;
            }
        }

        return null;
    }

    /**
     * Проверка: не «приняли» ли мы за маркер другой маркер (например, тот же
     * самый start при поиске end). Между позициями поиска и совпадением не
     * должно быть букв/цифр другого запрещённого ядра.
     */
    private function isForbiddenMatch(string $between, array $forbidden): bool
    {
        $letters = strtolower((string) preg_replace('~[^a-z0-9_]~', '', $between));
        if ($letters === '') {
            return false;
        }
        foreach ($forbidden as $token) {
            if (str_contains($letters, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Эвристика: похожа ли строка конфига на готовое PCRE-выражение
     * с одинаковым открывающим и закрывающим ограничителем в начале и конце.
     */
    private function looksLikePcre(string $s): bool
    {
        $s = trim($s);
        if (strlen($s) < 3) {
            return false;
        }
        // Только симметричные ASCII-ограничители без вложенных пар: /.../ ~...~ #...% !...!
        foreach (['/', '~', '#', '%', '!'] as $d) {
            if ($s[0] === $d && strpbrk(substr($s, 1), '/~#%!') !== false) {
                // закрывающий ограничитель должен быть тем же символом
                $closePos = strrpos($s, $d);
                if ($closePos > 0) {
                    $tail = substr($s, $closePos + 1);
                    if ($tail === '' || preg_match('/^[imsxuADSUJn]+$/', $tail) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);

        // Обёртка с явным объявлением кодировки: libxml иначе интерпретирует байты как latin1,
        // а сериализация в saveHTML() превращает кириллицу в numeric entities.
        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'
            . $html . '</body></html>';

        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $doc;
    }

    private function extractTitle(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//title');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        $h1 = $xpath->query('//h1');
        if ($h1->length > 0) {
            return trim($h1->item(0)->textContent);
        }

        return '';
    }

    /**
     * Собирает CSS из тегов <style> в памяти (без записи на диск).
     */
    private function collectStyles(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//style');
        if ($nodes->length === 0) {
            return;
        }

        foreach ($nodes as $node) {
            $css = trim($node->textContent);
            if (!empty($css)) {
                $this->extractedStyles .= $css . "\n";
            }
        }
    }

    /**
     * Удаляет теги <style> из DOM. Классы и остальной HTML не трогаются.
     */
    private function removeStyleTags(DOMDocument $doc, DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//style');
        $list = [];
        foreach ($nodes as $node) {
            $list[] = $node;
        }
        foreach ($list as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Запись извлечённых стилей в промежуточный neutral-style.css с дедупликацией.
     * Повторяющиеся правила (одинаковый селектор) заменяются новыми значениями.
     * Вызывается только на этапе confirm (чтобы предпросмотр не имел побочных эффектов).
     */
    public function commitStyles(string $css): bool
    {
        $css = trim($css);
        if ($css === '') {
            return true;
        }

        $existingRules = [];
        if (is_file($this->stylesFile)) {
            $existingCss = (string)file_get_contents($this->stylesFile);
            $existingRules = $this->parseCssRules($existingCss);
        }

        $newRules = $this->parseCssRules($css);

        // Дедупликация: одинаковые селекторы заменяем (последнее значение побеждает),
        // новые селекторы дописываем в конец
        $merged = $existingRules;
        foreach ($newRules as $selector => $body) {
            $merged[$selector] = $body;
        }

        $out = "/* neutral-style.css — стили, извлечённые импортом страниц (" . date('Y-m-d H:i:s') . ") */\n\n";
        foreach ($merged as $selector => $body) {
            $out .= $selector . " {" . $body . "}\n";
        }

        return file_put_contents($this->stylesFile, $out, LOCK_EX) !== false;
    }

    /**
     * Разбивает CSS на карту «селектор => тело правила» (поверхностный парсер без учёта @media).
     */
    private function parseCssRules(string $css): array
    {
        $rules = [];
        // Убираем комментарии
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $selector = preg_replace('/\s+/', ' ', trim($m[1]));
                $body = trim($m[2]);
                if ($selector !== '' && $body !== '') {
                    $rules[$selector] = $body;
                }
            }
        }

        return $rules;
    }

    /**
     * Извлекает пути JS-скриптов страницы. Всегда содержит дефолтный /assets/js/main.js.
     */
    private function extractScripts(DOMXPath $xpath): array
    {
        $scripts = ['/assets/js/main.js'];

        $nodes = $xpath->query('//script[@src]');
        foreach ($nodes as $node) {
            $src = trim($node->getAttribute('src'));
            if ($src === '') {
                continue;
            }

            // Пропускаем внешние библиотеки/аналитику/JSON-LD-подобные src
            if (preg_match('~(googleapis|gstatic|analytics|gtm|recaptcha|facebook|connect\.facebook)~i', $src)) {
                continue;
            }

            // Локальные относительные пути нормализуем к /assets/...
            if (!preg_match('~^(/|https?://)~', $src)) {
                $src = '/assets/js/' . ltrim($src, './');
            }

            if (!in_array($src, $scripts, true)) {
                $scripts[] = $src;
            }
        }

        return $scripts;
    }

    /**
     * Извлечь Schema.org JSON-LD из страницы.
     *
     * Перед записью схема нормализуется под текущий сайт:
     *  - все поля "url" заменяются на APP_BASE_URL (хостинг из конфига);
     *  - все поля "name" заменяются на APP_NAME;
     *  - "dateModified" и "datePublished" устанавливаются в дату импорта.
     */
    private function extractSchema(DOMXPath $xpath): array
    {
        $schemas = [];
        $nodes = $xpath->query('//script[@type="application/ld+json"]');
        $now = date('Y-m-d');

        foreach ($nodes as $node) {
            $json = trim($node->textContent);
            if (empty($json)) {
                continue;
            }

            $data = json_decode($json, true);
            // Валидная схема: объект с @type ИЛИ объект с @graph (несколько схем в одном скрипте)
            $isValid = json_last_error() === JSON_ERROR_NONE
                && is_array($data)
                && (isset($data['@type']) || isset($data['@graph']));
            if ($isValid) {
                if (isset($data['@type'])) {
                    $type = is_array($data['@type']) ? implode(',', $data['@type']) : (string)$data['@type'];
                } elseif (isset($data['@graph'])) {
                    $types = [];
                    foreach ((array)$data['@graph'] as $item) {
                        if (isset($item['@type'])) {
                            $types[] = is_array($item['@type']) ? implode(',', $item['@type']) : (string)$item['@type'];
                        }
                    }
                    $type = implode(',', array_unique($types)) ?: 'WebPage';
                } else {
                    $type = 'WebPage';
                }
                $data = $this->normalizeSchemaUrls($data);
                $data = $this->normalizeSchemaNames($data);
                $data = $this->setSchemaDates($data, $now);
                $schemas[] = [
                    'schema_type' => $type,
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'priority' => 10, // Стандартный приоритет
                ];
            } else {
                $this->warnings[] = 'Найден невалидный JSON в schema.org скрипте';
            }
        }

        return $schemas;
    }

    /**
     * Рекурсивно установить dateModified / datePublished в дату импорта.
     * Если поле отсутствовало — оно добавляется к объекту схемы.
     */
    private function setSchemaDates(array $node, string $date): array
    {
        $isObject = isset($node['@type']) || array_keys($node) !== range(0, count($node) - 1);
        if ($isObject) {
            $node['dateModified'] = $date;
            $node['datePublished'] = $date;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->setSchemaDates($value, $date);
            }
        }

        return $node;
    }

    /**
     * Рекурсивно заменить строковые поля "name" на APP_NAME.
     * Правило: решение о замене принимается по СОБСТВЕННОМУ @type каждого
     * узла-объекта, а не наследуется от родителя. Вложенный объект без своего
     * @type (напр. {"provider":{"@type":"Organization",...}} внутри Service)
     * сохраняет свой тип и подлежит замене; white-list действует только на
     * узлы, которые сами имеют @type из NAME_KEEP_TYPES (ListItem, Service…).
     */
    private function normalizeSchemaNames(array $node): array
    {
        $appName = trim((string)$this->config->get('APP_NAME', ''));
        if ($appName === '') {
            return $node;
        }

        // Определяем @type ТЕКУЩЕГО узла (строка или массив типов).
        $keepName = false;
        if (isset($node['@type'])) {
            $types = array_map('strval', is_array($node['@type']) ? $node['@type'] : [$node['@type']]);
            foreach ($types as $type) {
                // Поддержка полностью квалифицированных IRIs вида https://schema.org/ListItem
                $slashPos = strrpos($type, '/');
                $short = $slashPos === false ? $type : substr($type, $slashPos + 1);
                if (in_array($short, (array) ($this->rules['name_keep_types'] ?? []), true)) {
                    $keepName = true;
                    break;
                }
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->normalizeSchemaNames($value);
            } elseif ($key === 'name' && is_string($value) && !$keepName) {
                $node[$key] = $appName;
            }
        }

        return $node;
    }

    /**
     * Рекурсивно заменить все поля "url" на базовый URL сайта (APP_BASE_URL).
     */
    private function normalizeSchemaUrls(array $node): array
    {
        $baseUrl = rtrim((string)$this->config->get('APP_BASE_URL', ''), '/');
        if ($baseUrl === '') {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->normalizeSchemaUrls($value);
            } elseif ($key === 'url') {
                $node[$key] = $baseUrl . '/';
            }
        }

        return $node;
    }

    /**
     * Возвращает внутреннее содержимое <body> документа без служебной обёртки
     * (<!DOCTYPE html><html><head>...</head><body> ... </body></html>).
     */
    private function extractInnerBody(DOMDocument $doc): string
    {
        $bodyList = $doc->getElementsByTagName('body');
        if ($bodyList->length === 0) {
            return trim((string)$doc->saveHTML());
        }

        $body = $bodyList->item(0);
        $inner = '';
        foreach ($body->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }

    /**
     * Страховка: срезает служебную HTML-обёртку, которая не должна попадать в контент.
     * Убирает ведущие <!DOCTYPE ...>, <html ...>, <head>...</head>, <body ...>
     * и закрывающие </body>, </html> — как в конце строки, так и сразу после
     * обёртки (вариант «<!DOCTYPE...><html><head>..</head><body> </body></html>КОНТЕНТ»,
     * который даёт loadHTML() при пустом/вынесенном содержимом <body>).
     */
    public function stripDocumentWrapper(string $content): string
    {
        $patterns = [
            '~^\s*<!DOCTYPE[^>]*>\s*~i',
            '~^\s*<html(?:\s[^>]*)?>\s*~i',
            '~^\s*<head(?:\s[^>]*)?>.*?</head>\s*~is',
            // Пустой "body-хвост" обёртки целиком: <body> </body></html> (пробел/перепад
            // между тегами допустим). ВАЖНО: этот шаблон должен стоять ДО одиночного
            // '<body>', иначе он съест только открывающий тег и хвост </body></html>
            // повиснет в начале контента.
            '~^\s*<body(?:\s[^>]*)?>\s*</body>\s*</html>\s*~i',
            '~^\s*<body(?:\s[^>]*)?>\s*~i',
            // Закрывающий хвост документа
            '~\s*</body>\s*</html>\s*$~i',
            '~\s*</body>\s*$~i',
            '~\s*</html>\s*$~i',
        ];

        do {
            $before = $content;
            foreach ($patterns as $p) {
                $content = preg_replace($p, '', $content, 1) ?? $content;
            }
            $content = trim($content);
        } while ($content !== $before);

        return $content;
    }


    /**
     * Проверка целостности: текстовое содержимое извлечённого HTML
     * должно совпадать с текстовым содержимым raw-среза исходника.
     */
    private function verifyContentIntegrity(string $extracted, string $rawFragment): void
    {
        $textExtracted = $this->plainText($extracted);
        $textRaw       = $this->plainText($rawFragment);

        if ($textRaw !== '' && $textExtracted !== $textRaw) {
            $this->warnings[] = 'Внимание: текст извлечённого контента отличается от исходного ('
                . mb_strlen($textRaw, 'UTF-8') . ' → ' . mb_strlen($textExtracted, 'UTF-8') . ' симв.)';
        }
    }

    private function plainText(string $html): string
    {
        $noComments = preg_replace('/<!--.*?-->/su', '', $html) ?? $html;
        $text = strip_tags($noComments);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function downloadAndReplaceImages(string $html): string
    {
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) === 0) {
            return $html;
        }

        foreach (array_unique($matches[1]) as $src) {
            if (strpos($src, 'data:') === 0 || strpos($src, '/storage/uploads/import/') === 0) {
                continue;
            }

            if (strpos($src, 'http') !== 0) {
                continue;
            }

            // Кэш: одна и та же картинка скачивается один раз
            if (array_key_exists($src, $this->imageCache)) {
                $localPath = $this->imageCache[$src];
            } else {
                $localPath = $this->downloadImage($src);
                $this->imageCache[$src] = $localPath;
            }

            if ($localPath !== null) {
                $this->downloadedImages[] = [
                    'original' => $src,
                    'local' => $localPath,
                ];
                $html = str_replace($src, $localPath, $html);
            }
        }

        return $html;
    }

    /**
     * Защита от SSRF: разрешаем только http/https на публичные хосты.
     */
    private function isSafeImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $ip = gethostbyname($parts['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // Блокируем приватные/петлбэк-диапазоны
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function downloadImage(string $url): ?string
    {
        try {
            if (!$this->isSafeImageUrl($url)) {
                $this->warnings[] = 'Пропущен небезопасный URL картинки: ' . $url;
                return null;
            }

            $context = stream_context_create([
                'http' => [
                    'timeout' => (int) ($this->rules['image_timeout_s'] ?? 10),
                    'max_redirects' => (int) ($this->rules['image_max_redirects'] ?? 3),
                    'user_agent' => 'VampqweEngine Importer/1.0',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $imageData = @file_get_contents($url, false, $context);
            if ($imageData === false || strlen($imageData) < 100) {
                return null;
            }

            if (strlen($imageData) > (int) ($this->rules['max_image_size'] ?? 10 * 1024 * 1024)) {
                $this->warnings[] = 'Картинка слишком большая, пропущена: ' . $url;
                return null;
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imageData);
            $extMap = [
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                'image/jpeg' => 'jpg',
            ];
            $extension = $extMap[$mimeType] ?? 'jpg';

            $filename = md5($url . microtime(true)) . '.' . $extension;
            $destPath = $this->uploadDir . DIRECTORY_SEPARATOR . $filename;

            // Используем file_put_contents напрямую
            file_put_contents($destPath, $imageData);

            return '/storage/uploads/import/' . $filename;
        } catch (\Throwable $e) {
            $this->warnings[] = 'Ошибка загрузки картинки: ' . $e->getMessage();
            return null;
        }
    }

    private function generateSlug(string $title): string
    {
        if (function_exists('transliterator_transliterate')) {
            $slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
        } else {
            // Транслитерация кириллицы без intl-расширения
            $slug = mb_strtolower($this->transliterateCyrllic($title), 'UTF-8');
        }

        $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Простейшая транслитерация кириллицы (fallback без intl).
     */
    private function transliterateCyrllic(string $text): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        return strtr(mb_strtolower($text, 'UTF-8'), $map);
    }
}