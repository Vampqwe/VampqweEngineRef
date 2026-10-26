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

namespace Core\System\Parser;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Парсер HTML-документов: загрузка «битого» HTML в DOM с корректной
 * кодировкой, извлечение фрагментов по маркерам комментариев и XPath,
 * сбор <style>/<script>/JSON-LD, снятие служебной обёртки документа.
 *
 * Чистый разбор без побочных эффектов (никогда не пишет на диск и не
 * скачивает изображения): побочные шаги выполняет потребитель —
 * например адаптер Core\Module\Admin\Services\HtmlImportParser.
 *
 * Правила извлечения передаются конструктором (секция 'blocks' файла
 * config/import-parser.php); при пустом массиве включается встроенный
 * профиль блоков по умолчанию.
 */
class HtmlParser extends AbstractParser
{
    /** @var string[] Разрешённые описания source в блоках конфига */
    private const KNOWN_SOURCES = ['slug_from_title', 'slug_from_canonical'];

    /** @var string[] Разрешённые типы блоков */
    public const KNOWN_BLOCK_TYPES = ['marker', 'xpath', 'meta', 'schema', 'script', 'title'];

    /** @var array<string,array> Активный профиль блоков (валидированный) */
    protected array $blocks = [];

    /** Картинки, «найденные» прогоном (для контракта отчёта; сам парсер их не качает) */
    private array $foundImages = [];

    /** @var array<string,bool> Уже отмеченные src картинок (дедуп прогона) */
    private array $seenImages = [];

    /** @var array<string,string> Схемы JSON-LD 'raw' => нормализованная запись */
    private array $schemaRecords = [];

    public function __construct(array $rules = [])
    {
        parent::__construct($rules);

        // Валидный профиль блоков: из правил либо встроенный по умолчанию.
        $this->blocks = $this->buildActiveBlocks();
    }

    public function format(): string
    {
        return 'html';
    }

    /**
     * Разобрать HTML и вернуть полный контракт импорта:
     * title/description/slug/content + блоки, значения колонок, rows-наборы,
     * стили, скрипты, предупреждения.
     *
     * @param string|array<string,mixed> $content содержимое документа либо
     *        массив из ParserInterface::parseFile() (ключ 'content').
     */
    public function parse(string|array $content): array
    {
        if (is_array($content)) {
            $content = (string) ($content['content'] ?? '');
        }

        $this->resetRunState();
        $this->foundImages = [];
        $this->seenImages = [];
        $this->schemaRecords = [];

        // ⚠️ ПОБИТНЫЙ СРЕЗ ВЫПОЛНЯЕТСЯ ПО ОРИГИНАЛУ (без CRLF-нормализации):
        // normalizeInput() приводит переносы к \n, и срез, снятый с
        // нормализованной копии, байт-в-байт НЕ равен фрагменту исходного
        // файла. Поэтому позиции маркеров для raw-среза ищем в оригинале,
        // а нормализованная версия используется только для DOM-блоков
        // (title/meta/xpath/schema/script) — им переносы не важны.
        $originalContent = is_array($content) ? '' : $content;

        $sourceHtml = $this->normalizeInput($content);

        $doc = $this->loadHtml($sourceHtml);
        $xpath = new DOMXPath($doc);

        // ⚠️ МЕХАНИКА КОПИРОВАНИЯ СТИЛЕЙ ИЗ СТРАНИЦ ПРИ ИМПОРТЕ УДАЛЕНА:
        // теги <style> больше НЕ извлекаются из HTML и НЕ вырезаются из
        // контента. Страница сохраняется ровно с теми стилями, которые в
        // ней есть (per-page стили подключаются через meta/layouts страницы).

        $blockResults = [];   // имя блока => ['value'|'rows', 'found', 'meta']
        $values = [];         // колонка => скалярное значение для pages
        $rowSets = [];        // table => список строк (mode=rows)

        foreach ($this->blocks as $name => $block) {
            $result = $this->extractBlock((string) $name, $block, $sourceHtml, $doc, $xpath);
            $blockResults[(string) $name] = $result;

            if (($result['found'] ?? false) !== true && !empty($block['required'])) {
                $this->addWarning("Обязательный блок «{$name}» не найден в документе");
            }
        }

        // Сборка значений по target-описаниям из конфига блоков
        foreach ($this->blocks as $name => $block) {
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
                // Псевдо-колонка (column: false) или блок без колонки —
                // значение доступно writer'у по имени блока в $values.
                $column = ($target['column'] ?? null) === false ? (string) $name : '';
            }
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

        // slug может генерироваться из title (slug_from_title) либо
        // вырезаться из path canonical-ссылки исходника (slug_from_canonical).
        if (isset($values['slug']) && (!is_string($values['slug']) || $values['slug'] === '')) {
            unset($values['slug']);
        }
        if (!isset($values['slug'])) {
            $slug = '';
            foreach ($this->blocks as $block) {
                if (!is_array($block) || (string) ($block['source'] ?? '') !== 'slug_from_canonical') {
                    continue;
                }
                $canonicalSrc = (string) ($values['canonical'] ?? '');
                if ($canonicalSrc === '') {
                    $target = (array) ($block['target'] ?? []);
                    $column = (string) ($target['column'] ?? '');
                    $canonicalSrc = $column !== '' ? (string) ($values[$column] ?? '') : '';
                }
                $slug = self::slugFromCanonicalUrl($canonicalSrc);
                break;
            }
            if ($slug === '') {
                $slug = $this->generateSlug((string) ($values['title'] ?? ''));
            }
            if ($slug === '') {
                $slug = 'import-' . substr(md5(((string) ($values['title'] ?? '')) . microtime()), 0, 8);
            }
            $values['slug'] = $slug;
        }

        // Побитный режим ('content_raw' => true): content = ТОЧНЫЙ срез
        // исходного HTML между маркерами контентного блока (включая сами
        // комментарии), без DOM-нормализации и обёрток.
        // ⚠️ ПРАВИЛО ДОВЕРЕННОГО ИМПОРТА — НЕ ТРОГАТЬ: на этом уровне цепочки
        // из контента не вырезается ничего (ни <style>, ни картинок, ни
        // «мусорных» тегов). Любая санитизация/обрезка ниже по потоку должна
        // обходить raw_slice стороной (см. PageImportWriter::prepareContent).
        if (($this->rules['content_raw'] ?? false) === true) {
            $rawSlice = $this->rawMarkerSlice($originalContent, $this->blocks);
            if ($rawSlice !== null) {
                $values['content'] = $rawSlice;
                $blockResults['main_content']['value'] = $rawSlice;
                $blockResults['main_content']['found'] = trim($rawSlice) !== '';
                $blockResults['main_content']['meta']['marker'] = 'raw_slice';
            } else {
                // Маркеры не найдены в оригинале — последний шанс: срез по
                // нормализованной копии (например, массив-вход без оригинала).
                $rawSlice = $originalContent === ''
                    ? $this->rawMarkerSlice($sourceHtml, $this->blocks)
                    : null;
                if ($rawSlice !== null) {
                    $this->addWarning('Побитный режим: маркеры не найдены в оригинале — '
                        . 'срез взят по нормализованной копии (переносы \\r\\n приведены к \\n).');
                    $values['content'] = $rawSlice;
                    $blockResults['main_content']['value'] = $rawSlice;
                    $blockResults['main_content']['found'] = trim($rawSlice) !== '';
                    $blockResults['main_content']['meta']['marker'] = 'raw_slice';
                } else {
                    $this->addWarning('Побитный режим (content_raw=true): маркеры основного'
                        . ' контента не найдены — сохранён нормализованный вариант.');
                }
            }
        }

        // Совместимость с контрактом ImportController/preview.html
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
            // ⚠️ Механика копирования стилей из страниц при импорте УДАЛЕНА:
            // <style> остаются в контенте страницы как есть; ключи 'styles'
            // и 'styles_extracted' сохранены только для совместности контракта.
            'styles' => '',
            'images_count' => count($this->foundImages),
            'styles_extracted' => 0,
            'downloaded_images' => $this->foundImages,
            'warnings' => $this->warnings,
            'blocks' => $blockResults,
            'values' => $values,
            'row_sets' => $rowSets,
        ];
    }

    /**
     * Валидный активный профиль блоков (конфиг либо встроенный по умолчанию).
     * Некорректные описания отбрасываются с предупреждением.
     *
     * @return array<string,array>
     */
    public function getActiveBlocks(): array
    {
        return $this->blocks;
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
        foreach ($this->blocks as $name => $block) {
            $target = (array) ($block['target'] ?? []);
            if ($target !== []) {
                $targets[(string) $name] = $target;
            }
        }

        return $targets;
    }

    /**
     * Публичный извлечение текста каждого активного блока из HTML (для
     * детального отчёта предпросмотра в админке). Без побочных эффектов.
     *
     * @return array<string,string> имя блока => plain-текст ('' если не найден)
     */
    public function blocksPlainText(string $html): array
    {
        $sourceHtml = $this->normalizeInput($html);
        $doc = $this->loadHtml($sourceHtml);
        $xpath = new DOMXPath($doc);

        $result = [];
        $sourceParsed = null; // ленивый кэш parse() для source-блоков отчёта
        foreach ($this->blocks as $name => $block) {
            $type = (string) ($block['type'] ?? '');
            $text = '';
            switch ($type) {
                case 'title':
                    $text = $this->extractTitle($xpath);
                    break;
                case 'meta':
                    $text = $this->extractMetaByBlock($xpath, $block);
                    break;
                // source-блоки (slug_from_canonical и т.п.) не извлекаются
                // напрямую: их значение вычисляется в конце parse() из
                // других блоков — для отчёта считаем parse() один раз.
                case '':
                    if ((string) ($block['source'] ?? '') !== '') {
                        $sourceParsed ??= $this->parse($html);
                        $text = (string) ($sourceParsed['values'][(string) $name] ?? '');
                    }
                    break;
                case 'marker':
                case 'xpath':
                    [$value] = $this->extractBlockValueForReport((string) $name, $block, $sourceHtml, $doc, $xpath);
                    $text = is_string($value) ? $this->plainText($value) : '';
                    break;
            }
            $result[(string) $name] = $text;
        }

        return $result;
    }

    /**
     * Валидация и нормализация описания блока из конфига.
     * Возвращает ['ok' => bool, 'error' => string|null, 'block' => array].
     *
     * @param array<string,mixed> $block
     * @return array{ok:bool,error:?string,block:array}
     */
    public static function validateBlockDefinition(string $name, array $block): array
    {
        $error = null;

        if (preg_match('/[^a-z0-9_]/i', $name) === 1) {
            $error = "Имя блока «{$name}»: допускаются только буквы, цифры и подчёркивание";
        } else {
            $type = (string) ($block['type'] ?? '');

            if (!empty($block['source'])) {
                if (!in_array((string) $block['source'], self::KNOWN_SOURCES, true)) {
                    $error = "Блок «{$name}»: неизвестный source «{$block['source']}»";
                }
            } elseif (!in_array($type, self::KNOWN_BLOCK_TYPES, true)) {
                $error = "Блок «{$name}»: неизвестный type «{$type}» (допустимы: " . implode(', ', self::KNOWN_BLOCK_TYPES) . ')';
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
                    // Псевдо-колонка: значение блока не пишется напрямую в
                    // колонку pages, а используется кодом записи (writer) по
                    // имени блока — например canonical_mode='source' берёт
                    // URL из блока 'canonical', а slug вырезается из его path.
                    $isPseudo = ($target['column'] ?? null) === false;
                    if (!in_array($mode, ['column', 'json_array', 'rows'], true)) {
                        $error = "Блок «{$name}»: неизвестный target.mode «{$mode}»";
                    } elseif ($mode === 'rows' && (empty($target['table']) || empty($target['fields']))) {
                        $error = "Блок «{$name}»: для rows-режима нужны target.table и target.fields";
                    } elseif (($mode === 'column' || $mode === 'json_array') && !$isPseudo && empty($target['column'])) {
                        $error = "Блок «{$name}»: в target отсутствует 'column'";
                    }
                    foreach (['table', 'column'] as $k) {
                        // column === false — псевдо-колонка (см. выше), не проверяется
                        if ($k === 'column' && ($target[$k] ?? null) === false) {
                            continue;
                        }
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
     * Профиль блоков по умолчанию — исторически жёстко зашитое поведение
     * (для конфигов без секции 'blocks').
     *
     * @return array<string,array>
     */
    public static function defaultBlocks(): array
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
                    // Плейсхолдеры = колонки строк extractSchema(): data, не json.
                    'fields' => ['schema_type' => '{schema_type}', 'data' => '{data}', 'priority' => 10],
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
     * Снимает служебную HTML-обёртку <!DOCTYPE/html/head/body> со строки.
     */
    public function stripDocumentWrapper(string $content): string
    {
        $patterns = [
            '~^\s*<!DOCTYPE[^>]*>\s*~i',
            '~^\s*<html(?:\s[^>]*)?>\s*~i',
            '~^\s*<head(?:\s[^>]*)?>.*?</head>\s*~is',
            // Пустой "body-хвост" обёртки целиком: <body> </body></html>.
            // ВАЖНО: шаблон должен стоять ДО одиночного '<body>', иначе он
            // съест только открывающий тег и хвост </body></html> повиснет
            // в начале контента.
            '~^\s*<body(?:\s[^>]*)?>\s*</body>\s*</html>\s*~i',
            '~^\s*<body(?:\s[^>]*)?>\s*~i',
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
     * Загрузка HTML в DOM с явным объявлением UTF-8 (libxml иначе трактует
     * байты как latin1 и превращает кириллицу в numeric entities).
     */
    protected function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);

        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'
            . $html . '</body></html>';

        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $doc;
    }

    // ──────────────────────────────── Внутренняя логика ────────────────────────────────

    /**
     * @return array<string,array>
     */
    protected function buildActiveBlocks(): array
    {
        $blocks = (array) ($this->rules['blocks'] ?? []);
        if ($blocks === []) {
            return self::defaultBlocks();
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
     * Актуализация профиля блоков по текущим правилам (вызывается дочерними
     * классами после изменения $rules — например, при тестовой подмене секции
     * 'blocks'). Повторно валидирует описания и отмечает профиль переопределённым.
     */
    protected function rebuildActiveBlocks(): void
    {
        $this->blocks = $this->buildActiveBlocks();
    }

    /**
     * Точный побитный срез исходного HTML между маркерами контентного блока
     * (первый marker-блок с колонкой target 'content', иначе 'main_content').
     * Включает оба комментария-маркера; без какой-либо нормализации.
     */
    private function rawMarkerSlice(string $html, array $blocks): ?string
    {
        $block = null;
        foreach ($blocks as $name => $b) {
            if (!is_array($b) || (string) ($b['type'] ?? '') !== 'marker') {
                continue;
            }
            $target = (array) ($b['target'] ?? []);
            $column = (string) ($target['column'] ?? '');
            if ($column === 'content' || (string) $name === 'main_content') {
                $block = $b;
                break;
            }
        }

        if ($block === null || empty($block['start'])) {
            return null;
        }

        [$startPos, $endPos] = $this->findMarkerPositionsForBlock($html, $block, 'main_content');
        if ($startPos === null) {
            return null;
        }

        // Смещение начала start-маркера: нужно включить сам комментарий в срез
        $patterns = $this->markerPatterns($block['start']);
        $sliceStart = $startPos;
        foreach ($patterns as $p) {
            if (@preg_match($p, $html, $m, PREG_OFFSET_CAPTURE, 0) === 1) {
                $candidate = (int) $m[0][1];
                if ($candidate + strlen($m[0][0]) === $startPos) {
                    $sliceStart = $candidate;
                    break;
                }
            }
        }

        $slice = $endPos === null
            ? substr($html, $sliceStart)
            : substr($html, $sliceStart, $endPos - $sliceStart);

        // ⚠️ ПРАВИЛО ДОВЕРЕННОГО ИМПОРТА — НЕ ТРОГАТЬ !!!
        // Контент сохраняется ПОБИТНО: никакого trim(), никакого
        // trimUnclosedTail(), никаких regex-вырезаний хвостов/обёрток.
        // Срез байт-в-байт равен исходнику между маркерами (включая сами
        // маркеры). Обрезка «мусора» допускается только явным правилом
        // блока ('trim'/'transform') в конфиге — по умолчанию ничего
        // не удаляется. Правило рассчитано на доверенных лиц.

        // Самопроверка побитности: срез обязан быть точной подстрокой
        // переданного исходника. Если это не так (например, позиции
        // посчитаны по другой копии файла) — побитный режим отказывается
        // отдавать нормализованный/смещённый вариант: вернём null, и
        // вызывающий код честно предупредит о деградации, а не молча
        // испортит контент в БД.
        if ($slice !== '' && strpos($html, $slice) === false) {
            return null;
        }

        return $slice;
    }

    /**
     * Отсекает хвост незакрытого блока: всё начиная с первого <footer>.
     */
    private function trimUnclosedTail(string $slice): string
    {
        $footerPos = stripos($slice, '<footer');
        if ($footerPos !== false) {
            $slice = substr($slice, 0, $footerPos);
        }

        return $slice;
    }

    /**
     * Извлечение одного блока согласно его описанию из конфига.
     *
     * @param array<string,mixed> $block
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
                    $this->addWarning("Блок «{$name}»: некорректное XPath-выражение: {$expr}");
                    break;
                }
                // Режим 'raw': outerHTML найденных узлов без DOM-нормализации — побитно.
                if ((string) ($block['mode'] ?? '') === 'raw') {
                    $outer = '';
                    foreach ($nodes as $node) {
                        $outer .= $doc->saveHTML($node);
                    }
                    $result['found'] = trim($outer) !== '';
                    $result['value'] = $outer;
                    $result['meta']['nodes'] = $nodes->length;
                    $result['meta']['marker'] = 'raw';
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
                        $this->addWarning("Блок «{$name}»: закрывающий маркер не найден, берём до конца документа");
                        $raw = $this->trimUnclosedTail((string) $raw);
                    }

                    // Отбрасываем закрывающие теги контейнеров, попавшие в начало среза
                    $raw = preg_replace('~^\s*</(main|div|section|article|body|html)\s*>~iu', '', $raw, 1) ?? $raw;

                    // Режим 'raw': байт-в-байт из исходника — без DOM-нормализации.
                    if ((string) ($block['mode'] ?? '') === 'raw') {
                        $result['value'] = trim($raw);
                        $result['found'] = $result['value'] !== '';
                        $result['meta']['marker'] = 'raw';
                        break;
                    }

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
                $this->addWarning("Блок «{$name}»: неизвестный тип «{$type}», блок пропущен");
        }

        // Трансформация строкового значения
        if (is_string($result['value'] ?? null) && !empty($block['transform'])) {
            $result['value'] = $this->applyTransform($result['value'], (string) $block['transform']);
        }

        return $result;
    }

    /**
     * Значение блока только для отчёта (без нормализационных побочных шагов).
     *
     * @param array<string,mixed> $block
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

        // marker: побитный срез исходника между маркерами (тот же механизм,
        // что и при разборе, но без нормализации DOM).
        [$startPos, $endPos] = $this->findMarkerPositionsForBlock($sourceHtml, $block, $name);
        if ($startPos !== null) {
            $raw = $endPos === null
                ? substr($sourceHtml, $startPos)
                : substr($sourceHtml, $startPos, $endPos - $startPos);
            if ($endPos === null) {
                $raw = $this->trimUnclosedTail((string) $raw);
            }
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
     *
     * @param array<string,mixed> $block
     */
    private function extractMetaByBlock(DOMXPath $xpath, array $block): string
    {
        $expr = (string) ($block['expression'] ?? '');
        if ($expr !== '') {
            $value = $this->matchMetaNode($xpath, $expr);
            if ($value !== null) {
                return $value;
            }
        }

        foreach ((array) ($block['fallback_meta'] ?? []) as $fb) {
            if (!is_array($fb)) {
                continue;
            }
            $fbName = (string) ($fb['name'] ?? $fb['property'] ?? '');
            if ($fbName === '') {
                continue;
            }
            $value = $this->matchMetaNode($xpath, $fbName);
            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Поиск значения мета-тега по имени: <meta name="X" content>,
     * <meta property="X" content> или <link rel="X" href> (для link —
     * извлекается href). Возвращает null, если ничего не найдено.
     */
    private function matchMetaNode(DOMXPath $xpath, string $name): ?string
    {
        $queries = [
            '//meta[@name="' . $name . '"]/@content',
            '//meta[@property="' . $name . '"]/@content',
            '//link[@rel="' . $name . '"]/@href',
        ];
        foreach ($queries as $q) {
            $nodes = @$xpath->query($q);
            if ($nodes !== false && $nodes->length > 0) {
                $value = trim($nodes->item(0)->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Slug из канонического URL: берётся путь после домена, срезаются
     * ведущие/замыкающие слеши, строка запроса и якорь.
     * Пример: https://site.by/uslugi/zabor-krovi/ -> 'uslugi/zabor-krovi'.
     */
    public static function slugFromCanonicalUrl(string $canonical): string
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return '';
        }
        $path = parse_url($canonical, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            // Относительный canonical без схемы — считаем его путём как есть
            $path = $canonical;
        }
        $path = (string) preg_replace('~/+$~', '', $path);
        $path = (string) preg_replace('~^/+~', '', $path);

        return trim($path);
    }

    /**
     * Нормализация raw-фрагмента блока: отдельный DOM (починка «битого» HTML),
     * опционально — сбор <style>, отметка внешних картинок (без скачивания),
     * снятие служебной обёртки, контроль целостности текста.
     *
     * @param array<string,mixed> $block
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

        // ⚠️ Сбор/вырезание <style> из блока УДАЛЕН: контент страницы
        // сохраняется со своими стилями как есть.

        $html = $this->extractInnerBody($fragDoc);

        if (!empty($block['strip_wrapper'])) {
            $html = $this->stripDocumentWrapper($html);
        }

        if (!empty($block['verify_integrity'])) {
            $this->verifyContentIntegrity($html, $raw);
        }

        if (!empty($block['download_images'])) {
            $html = $this->markExternalImages($html);
        }

        return $html;
    }

    /**
     * Fallback-извлечение блока по XPath (//main, //article и т.п.) из исходника.
     *
     * @param array<string,mixed> $block
     */
    private function extractByFallbackXpath(string $sourceHtml, string $expr, array $block, string $name): ?string
    {
        $doc = $this->loadHtml($sourceHtml);
        $xp = new DOMXPath($doc);
        $nodes = @$xp->query($expr);
        if ($nodes === false || $nodes->length === 0) {
            $this->addWarning("Блок «{$name}»: маркеры и fallback-узлы не найдены");
            return null;
        }

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
     * @param array<string,mixed> $block
     * @return array{0:int|null, 1:int|null}
     */
    private function findMarkerPositionsForBlock(string $html, array $block, string $name): array
    {
        $start = $this->findMarkerOffset($html, $block['start'] ?? null, 'start');
        if ($start === null) {
            return [null, null];
        }

        // Ядро start-маркера запрещено при поиске end: иначе end-паттерн
        // может ложно совпасть с самим start (или его вариацией из псевдонимов).
        $forbidden = $this->markerTokens($block['start'] ?? null);

        // Поиск end ведём строго ПОСЛЕ конца start-маркера.
        $tailOffset = $start;

        $end = $this->findMarkerOffset(substr($html, $tailOffset), $block['end'] ?? null, 'end', $forbidden);

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
                $raw = trim($marker);
                if (preg_match('~^<!--.*-->$~s', $raw) === 1) {
                    // Комментарий: снимаем обрамление, внутри — имя маркера.
                    $inner = trim((string) preg_replace('~^<!--\s*|\s*-->$~', '', $raw));
                    $flexible = str_replace(' ', '\\s*', preg_quote($inner, '~'));
                    $patterns[] = '~<!--[<\\s-]*\\s*' . $flexible . '\\s*[>\\s-]*-->~iu';
                } else {
                    // Произвольный литеральный разделитель: '<---nav--->', '<-- nav -->'.
                    $flex = str_replace(' ', '\\s*', preg_quote($raw, '~'));
                    $patterns[] = '~' . $flex . '~iu';
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
     * @param string[]|null $forbidden Токены (ядро маркера в нижнем регистре),
     *        которые нельзя принимать за найденный маркер — защита от
     *        совпадения end-паттерна с тем же самым start-маркером.
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
                // совпадение относится к иному маркеру — пропускаем и ищем дальше.
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
     * с одинаковым ограничителем в начале и конце.
     */
    private function looksLikePcre(string $s): bool
    {
        $s = trim($s);
        if (strlen($s) < 3) {
            return false;
        }
        foreach (['/', '~', '#', '%', '!'] as $d) {
            if ($s[0] === $d && strpbrk(substr($s, 1), '/~#%!') !== false) {
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

            // Пропускаем внешние библиотеки/аналитику
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
     * Извлечь Schema.org JSON-LD из страницы. Перед записью схема
     * нормализуется под текущий сайт (правила 'site_name'/'base_url'/
     * 'name_keep_types' в правилах парсера):
     *  - все поля "url" заменяются на базовый URL;
     *  - все поля "name" заменяются на имя сайта (кроме white-list типов);
     *  - "dateModified"/"datePublished" устанавливаются в дату импорта.
     *
     * @return array<int,array{schema_type:string,data:string,priority:int}>
     */
    private function extractSchema(DOMXPath $xpath): array
    {
        $schemas = [];
        $nodes = $xpath->query('//script[@type="application/ld+json"]');
        $now = date('Y-m-d');

        foreach ($nodes as $node) {
            $json = trim($node->textContent);
            if ($json === '') {
                continue;
            }

            $data = json_decode($json, true);
            // Валидная схема: объект с @type ИЛИ объект с @graph (несколько схем в одном скрипте)
            $isValid = json_last_error() === JSON_ERROR_NONE
                && is_array($data)
                && (isset($data['@type']) || isset($data['@graph']));
            if (!$isValid) {
                $this->addWarning('Найден невалидный JSON в schema.org скрипте');
                continue;
            }

            if (isset($data['@type'])) {
                $type = is_array($data['@type']) ? implode(',', $data['@type']) : (string) $data['@type'];
            } elseif (isset($data['@graph'])) {
                $types = [];
                foreach ((array) $data['@graph'] as $item) {
                    if (isset($item['@type'])) {
                        $types[] = is_array($item['@type']) ? implode(',', $item['@type']) : (string) $item['@type'];
                    }
                }
                $type = implode(',', array_unique($types)) ?: 'WebPage';
            } else {
                $type = 'WebPage';
            }

            $data = $this->normalizeSchemaUrls($data);
            $data = $this->normalizeSchemaNames($data);
            $data = $this->setSchemaDates($data, $now);

            $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // Дедупликация одинаковых записей внутри прогона
            if ($raw !== false && !isset($this->schemaRecords[$raw])) {
                $this->schemaRecords[$raw] = $raw;
                $schemas[] = [
                    'schema_type' => $type,
                    'data' => $raw,
                    'priority' => 10, // Стандартный приоритет
                ];
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
     * Рекурсивно заменить строковые поля "name" на имя сайта (правило 'site_name').
     * Правило: решение о замене принимается по СОБСТВЕННОМУ @type каждого
     * узла-объекта, а не наследуется от родителя. White-list ('name_keep_types')
     * действует только на узлы, которые сами имеют @type из списка (ListItem, Service…).
     */
    private function normalizeSchemaNames(array $node): array
    {
        $appName = trim((string) ($this->rules['site_name'] ?? ''));
        if ($appName === '') {
            return $node;
        }

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
     * Рекурсивно заменить все поля "url" на базовый URL (правило 'base_url').
     */
    private function normalizeSchemaUrls(array $node): array
    {
        $baseUrl = rtrim((string) ($this->rules['base_url'] ?? ''), '/');
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
     * Внутреннее содержимое <body> документа без служебной обёртки.
     */
    private function extractInnerBody(DOMDocument $doc): string
    {
        $bodyList = $doc->getElementsByTagName('body');
        if ($bodyList->length === 0) {
            return trim((string) $doc->saveHTML());
        }

        $body = $bodyList->item(0);
        $inner = '';
        foreach ($body->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }

    /**
     * Проверка целостности: текстовое содержимое извлечённого HTML
     * должно совпадать с текстовым содержимым raw-среза исходника.
     */
    private function verifyContentIntegrity(string $extracted, string $rawFragment): void
    {
        $textExtracted = $this->plainText($extracted);
        $textRaw = $this->plainText($rawFragment);

        if ($textRaw !== '' && $textExtracted !== $textRaw) {
            $this->addWarning('Внимание: текст извлечённого контента отличается от исходного ('
                . mb_strlen($textRaw, 'UTF-8') . ' → ' . mb_strlen($textExtracted, 'UTF-8') . ' симв.)');
        }
    }

    public function plainText(string $html): string
    {
        $noComments = preg_replace('/<!--.*?-->/su', '', $html) ?? $html;
        $text = strip_tags($noComments);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Отметка внешних http(s)-картинок в отчёте (чистый парсер НЕ скачивает
     * файлы — это шаг адаптера импорта). src остаётся нетронутым.
     */
    private function markExternalImages(string $html): string
    {
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) === 0) {
            return $html;
        }

        foreach (array_unique($matches[1]) as $src) {
            if (str_starts_with($src, 'data:') || str_starts_with($src, '/storage/uploads/import/')) {
                continue;
            }
            if (!str_starts_with($src, 'http')) {
                continue;
            }
            if (!isset($this->seenImages[$src])) {
                $this->seenImages[$src] = true;
                $this->foundImages[] = ['original' => $src, 'local' => null];
            }
        }

        return $html;
    }

    /**
     * Транслитерация заголовка в slug (intl при наличии, иначе fallback-карта).
     */
    public function generateSlug(string $title): string
    {
        if (function_exists('transliterator_transliterate')) {
            $slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
        } else {
            $slug = mb_strtolower($this->transliterateCyrillic($title), 'UTF-8');
        }

        $slug = (string) preg_replace('/[^a-z0-9\s\-]/', '', (string) $slug);
        $slug = (string) preg_replace('/[\s]+/', '-', $slug);
        $slug = (string) preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Простейшая транслитерация кириллицы (fallback без intl).
     */
    public function transliterateCyrillic(string $text): string
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
