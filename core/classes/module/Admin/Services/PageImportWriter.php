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
use Core\System\Security\HtmlSanitizer;
use Core\System\Security\PathResolver;

/**
 * Единая точка записи результата импорта HTML в таблицы БД.
 *
 * Извлекается из дублирующихся веток ImportController::confirm() и
 * ImportJobHandler::handle(): сборка записи страницы, дополнительные
 * колоночные блоки, rows-наборы (schema_org и др.). Оба пути — веб
 * (синхронный confirm) и воркер очереди — вызывают один и тот же код,
 * поэтому поведение импорта не может разойтись при правках.
 *
 * Контракт: write() НИКОГДА не бросает исключений — ошибки возвращаются
 * в отчёте (consumers решают, показывать ли flash или уведомление).
 * Валидация slug «уже существует» остаётся у потребителей (разные UX).
 */
class PageImportWriter
{
    public function __construct(
        private HtmlImportParser $parser,
        private PageService $pageService,
        private Config $config,
        private ?ImportService $importService = null
    ) {
    }

    /**
     * Записать импортированную страницу и все сконфигурированные блоки.
     *
     * @param array<string,mixed> $parsed результат HtmlImportParser::parse()
     * @return array{
     *     page_id: int,
     *     row_reports: array<string,array{inserted:int,skipped:int,errors:string[]}>,
     *     warnings: string[],
     *     styles_ok: bool
     * }
     */
    public function write(array $parsed): array
    {
        $warnings = [];

        $record = $this->buildPageRecord($parsed);
        $pageId = $this->pageService->createPage($record);

        $rowReports = $this->writeRowSets($parsed, $pageId, $warnings);

        $stylesOk = true;
        if (!empty($parsed['styles'])) {
            $stylesOk = $this->parser->commitStyles((string) $parsed['styles']);
        }

        return [
            'page_id' => $pageId,
            'row_reports' => $rowReports,
            'warnings' => $warnings,
            'styles_ok' => $stylesOk,
        ];
    }

    /**
     * Собрать запись таблицы pages из результата парсинга: базовые поля +
     * дополнительные колоночные блоки config/import-parser.php (только
     * target table=pages, колонки уже валидированы парсером).
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public function buildPageRecord(array $parsed): array
    {
        $baseUrl = rtrim((string) $this->config->get('APP_BASE_URL', ''), '/');
        $slug = (string) ($parsed['slug'] ?? '');
        $canonical = $baseUrl . '/' . ltrim($slug, '/');

        // JS-скрипты: дефолтный скрипт из config/page-content.php + извлечённые со страницы
        $pageCfg = require PathResolver::resolveConfigFile('page-content.php');
        $defaultScript = (string) ($pageCfg['default_script'] ?? '/assets/js/main.js');
        $scripts = (array) ($parsed['scripts'] ?? []);
        if ($defaultScript !== '' && !in_array($defaultScript, $scripts, true)) {
            array_unshift($scripts, $defaultScript);
        }

        $mainMeta = (string) ($parsed['blocks']['main_content']['meta']['marker'] ?? '');
        $rawSlice = $mainMeta === 'raw_slice';

        $record = [
            'title' => $parsed['title'] ?? '',
            'description' => $parsed['description'] ?? '',
            'slug' => $slug,
            // Контент импорта прогоняется через тот же санитайзер, что и ручное
            // сохранение страниц (config/page-content.php: content_mode) —
            // импорт чужого HTML не должен приносить stored-XSS в БД.
            // Побитный импорт (content_raw): точный срез исходника — байт-в-байт.
            'content' => $rawSlice
                ? (string) ($parsed['blocks']['main_content']['value'] ?? $parsed['content'] ?? '')
                : $this->prepareContent(
                    (string) ($parsed['content'] ?? ''),
                    $mainMeta === '' // fallback-нормализованный контент — без снятия обёртки повторно
                ),
            'status' => 'draft',
            'page_type' => 'article',
            'meta_robots' => 'index, follow',
            'canonical' => $canonical,
            'og_title' => $parsed['title'] ?? '',
            'og_description' => $parsed['description'] ?? '',
            'scripts' => json_encode(array_values($scripts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $selfFields = array_fill_keys(array_keys($record), true);
        foreach ($this->parser->getActiveBlocks() as $blockName => $block) {
            $target = (array) ($block['target'] ?? []);
            if ((string) ($target['mode'] ?? 'column') !== 'column') {
                continue; // rows-наборы пишет writeRowSets(); json_array уже в $record
            }
            if ((string) ($target['table'] ?? 'pages') !== 'pages') {
                continue; // createPage() принимает фиксированный набор полей этой таблицы
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
                    : $this->prepareContent((string) $value, false);
            } elseif (!is_string($value)) {
                $value = (string) $value;
            }
            $record[$column] = $value;
        }

        return $record;
    }

    /**
     * Обработка контента перед сохранением: снятие служебной обёртки и
     * санитайзинг (config/page-content.php: content_mode/max_content_length).
     * Побитный raw-срез проходит сюда только когда это разрешено конфигом
     * import-parser ('content_raw' => true) — иначе обрабатывается обычно.
     */
    public function prepareContent(string $html, bool $rawSliceCandidate = false): string
    {
        if ($rawSliceCandidate) {
            $rulesFile = PathResolver::resolveConfigFile('import-parser.php');
            $rules = is_file($rulesFile) ? (array) require $rulesFile : [];
            if (($rules['content_raw'] ?? false) === true) {
                return $html;
            }
        }

        return $this->sanitizeContent($this->parser->stripDocumentWrapper($html));
    }

    /**
     * Sanitize/raw-режим контента по config/page-content.php.
     */
    public function sanitizeContent(string $html): string
    {
        $cfg = require PathResolver::resolveConfigFile('page-content.php');
        $mode = (string) ($cfg['content_mode'] ?? 'sanitize');
        $maxLen = (int) ($cfg['max_content_length'] ?? 2_000_000);

        if ($maxLen > 0) {
            $html = mb_substr($html, 0, $maxLen);
        }

        return $mode === 'raw' ? $html : HtmlSanitizer::clean($html);
    }

    /**
     * Generic-запись блоков mode='rows' (schema_org, menu_items и любые
     * другие из config/import-parser.php) в произвольные таблицы.
     *
     * @param array<string,mixed> $parsed
     * @param string[] $warnings накопитель предупреждений (по ссылкам)
     * @return array<string,array{inserted:int,skipped:int,errors:string[]}>
     */
    private function writeRowSets(array $parsed, int $pageId, array &$warnings): array
    {
        $reports = [];
        $rowSets = (array) ($parsed['row_sets'] ?? []);
        if ($rowSets === []) {
            return $reports;
        }

        if ($this->importService === null) {
            // Резервный путь без ImportService — прежнее поведение (schemas)
            if (!empty($parsed['schemas'])) {
                $this->pageService->addSchemaForPage($pageId, (array) $parsed['schemas']);
            }

            return $reports;
        }

        $targets = $this->parser->getBlockTargets();
        foreach ($rowSets as $table => $rows) {
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
                $warnings[] = "Блоки для таблицы «{$table}» не описаны в конфиге — пропущено "
                    . count($rows) . ' строк';
                continue;
            }

            try {
                $report = $this->importService->writeRows(
                    (string) $table,
                    $rows,
                    $fields,
                    ['page_id' => $pageId]
                );
            } catch (\Throwable $e) {
                // ошибка блока не должна отменять созданный импорт
                $report = ['inserted' => 0, 'skipped' => count($rows), 'errors' => [$e->getMessage()]];
            }
            $reports[(string) $table] = $report;
            foreach ($report['errors'] as $err) {
                $warnings[] = (string) $err;
            }
        }

        return $reports;
    }
}
