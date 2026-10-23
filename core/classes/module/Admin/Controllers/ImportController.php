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


namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\HtmlImportParser;
use Core\Module\Admin\Services\ImportService;
use Core\Module\Page\PageService;
use Core\Module\Admin\Services\AdminService;
use Core\System\Config\Config;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Security\HtmlSanitizer;
use Core\System\Security\SecurityManager;
use Core\System\Session\SessionManager;
use Core\System\Template\Template;

class ImportController
{
    private HtmlImportParser $parser;
    private PageService $pageService;
    private AdminService $adminService;
    private Template $template;
    private Config $config;
    private SessionManager $session;

    /** Ключ предпросмотра импорта в сессии */
    private const PREVIEW_KEY = 'import_preview';

    public function __construct(
        HtmlImportParser $parser,
        PageService $pageService,
        AdminService $adminService,
        Template $template,
        Config $config,
        private SecurityManager $security,
        ?SessionManager $session = null,
        private ?ImportService $importService = null
    ) {
        $this->parser = $parser;
        $this->pageService = $pageService;
        $this->adminService = $adminService;
        $this->template = $template;
        $this->config = $config;
        $this->session = $session ?? new SessionManager($config);
    }

    /**
     * Обработка импортированного контента перед сохранением.
     * Режим берётся из config/page-content.php ('content_mode'), как и в
     * PagesController: 'sanitize' — HtmlSanitizer::clean(), 'raw' — без изменений.
     */
    private function sanitizeImportedContent(string $html): string
    {
        $cfg = require dirname(__DIR__, 4) . '/config/page-content.php';
        $mode = (string) ($cfg['content_mode'] ?? 'sanitize');
        $maxLen = (int) ($cfg['max_content_length'] ?? 2_000_000);

        if ($maxLen > 0) {
            $html = mb_substr($html, 0, $maxLen);
        }

        return $mode === 'raw' ? $html : HtmlSanitizer::clean($html);
    }

    public function index(Request $request): Response
    {
        $flash = $this->adminService->getFlash();

        $html = $this->template->render('admin/import/index', [
            'title' => 'Импорт страниц',
            'flash' => $flash,
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta'  => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    public function upload(Request $request): Response
    {
        if (!$request->isPost()) {
            return Response::redirect('/admin/import');
        }

        $file = $_FILES['html_file'] ?? null;

        if (!$file) {
            $this->adminService->setFlash('error', 'Файл не выбран');
            return Response::redirect('/admin/import');
        }

        // Валидация файла (размер, расширение, ошибки загрузки)
        $errors = $this->parser->validateUpload($file);
        if ($errors !== []) {
            $this->adminService->setFlash('error', implode('. ', $errors));
            return Response::redirect('/admin/import');
        }

        $html = file_get_contents($file['tmp_name']);

        if (empty($html)) {
            $this->adminService->setFlash('error', 'Файл пуст или не удалось прочитать');
            return Response::redirect('/admin/import');
        }

        try {
            $parsed = $this->parser->parse($html);
        } catch (\Exception $e) {
            $this->adminService->setFlash('error', 'Ошибка парсинга: ' . $e->getMessage());
            return Response::redirect('/admin/import');
        }

        $exists = $this->pageService->pageExistsBySlug($parsed['slug']);

        // Детальный отчёт по блокам для предпросмотра: plain-текст каждого
        // блока из исходного HTML (без скачивания картинок/записи стилей).
        $blockTexts = [];
        try {
            $blockTexts = $this->parser->blocksPlainText($html);
        } catch (\Throwable) {
            // отчёт не критичен для импорта
        }

        $this->session->set(self::PREVIEW_KEY, $parsed);

        $html = $this->template->render('admin/import/preview', [
            'title' => 'Предпросмотр импорта',
            'parsed' => $parsed,
            'existing' => $exists,
            'blocks_config' => $this->parser->getActiveBlocks(),
            'block_texts' => $blockTexts,
            'flash' => $this->adminService->getFlash(),
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta'  => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    public function confirm(Request $request): Response
    {
        if (!$request->isPost()) {
            return Response::redirect('/admin/import');
        }

        $parsed = $this->session->get(self::PREVIEW_KEY);

        if (!$parsed) {
            $this->adminService->setFlash('error', 'Нет данных для импорта. Загрузите файл заново.');
            return Response::redirect('/admin/import');
        }

        $exists = $this->pageService->pageExistsBySlug($parsed['slug']);

        if ($exists) {
            $this->adminService->setFlash('error', 'Страница с slug "' . $parsed['slug'] . '" уже существует.');
            $this->session->remove(self::PREVIEW_KEY);
            return Response::redirect('/admin/import');
        }

        // canonical = APP_BASE_URL + slug из БД
        $baseUrl = rtrim((string)$this->config->get('APP_BASE_URL', ''), '/');
        $canonical = $baseUrl . '/' . ltrim($parsed['slug'], '/');

        // JS-скрипты: дефолтный скрипт из config/page-content.php + извлечённые со страницы
        $pageCfg = require dirname(__DIR__, 4) . '/config/page-content.php';
        $defaultScript = (string) ($pageCfg['default_script'] ?? '/assets/js/main.js');
        $scripts = $parsed['scripts'] ?? [];
        if ($defaultScript !== '' && !in_array($defaultScript, $scripts, true)) {
            array_unshift($scripts, $defaultScript);
        }

        $data = [
            'title' => $parsed['title'],
            'description' => $parsed['description'],
            'slug' => $parsed['slug'],
            // Контент импорта прогоняется через тот же санитайзер, что и ручное
            // сохранение страниц (config/page-content.php: content_mode) —
            // импорт чужого HTML не должен приносить stored-XSS в БД.
            'content' => $this->sanitizeImportedContent($this->parser->stripDocumentWrapper((string)$parsed['content'])),
            'status' => 'draft',
            'page_type' => 'article',
            'meta_robots' => 'index, follow',
            'canonical' => $canonical,
            'og_title' => $parsed['title'],
            'og_description' => $parsed['description'],
            'scripts' => json_encode(array_values($scripts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        // Конфигурируемые блоки из config/import-parser.php ('blocks'):
        // колоночные target'ы таблицы pages — дополнительные поля записи
        // (title/description/content/scripts и т.п.). Значения идут только
        // через подготовленные запросы PageService; имена колонок
        // предварительно валидированы парсером ([A-Za-z_][A-Za-z0-9_]*).
        $selfFields = array_fill_keys(array_keys($data), true);
        $extraColumns = [];
        foreach ($this->parser->getActiveBlocks() as $name => $block) {
            $target = (array) ($block['target'] ?? []);
            $mode = (string) ($target['mode'] ?? 'column');
            if ($mode === 'rows') {
                continue; // строковые наборы пишут ImportService (ниже)
            }
            $table = (string) ($target['table'] ?? 'pages');
            $column = (string) ($target['column'] ?? '');
            // Разрешаем дополнительные колонки только целевой таблицы pages:
            // createPage() принимает фиксированный набор полей этой таблицы.
            if ($table !== 'pages' || $column === '' || isset($selfFields[$column])) {
                continue;
            }
            $value = $parsed['values'][$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($column === 'content') {
                $value = $this->sanitizeImportedContent($this->parser->stripDocumentWrapper((string) $value));
            } elseif (!is_string($value)) {
                $value = (string) $value;
            }
            $extraColumns[$column] = $value;
        }
        if ($extraColumns !== []) {
            $data = array_merge($data, $extraColumns);
        }

        $id = $this->pageService->createPage($data);

        // Generic-запись блоков mode='rows' в произвольные таблицы
        // (schema_org, menu_items и любые другие из config/import-parser.php).
        // Schema.org сохраняется тем же путём: его rows-набор содержит
        // те же schema_type/data/priority, что и прежний addSchemaForPage().
        $rowReports = [];
        if ($this->importService !== null) {
            $targets = $this->parser->getBlockTargets();
            foreach ((array) ($parsed['row_sets'] ?? []) as $table => $rows) {
                if ($rows === [] || !is_array($rows)) {
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
                    $this->adminService->setFlash(
                        'warning',
                        "Блоки для таблицы «{$table}» не описаны в конфиге — пропущено " . count($rows) . ' строк'
                    );
                    continue;
                }

                $context = ['page_id' => $id];
                try {
                    $report = $this->importService->writeRows((string) $table, $rows, $fields, $context);
                } catch (\Throwable $e) {
                    $report = ['inserted' => 0, 'skipped' => count($rows), 'errors' => [$e->getMessage()]];
                }
                $rowReports[(string) $table] = $report;

                foreach ($report['errors'] as $err) {
                    $this->adminService->setFlash('warning', (string) $err);
                }
            }
        } elseif (!empty($parsed['schemas'])) {
            // Резервный путь без ImportService — прежнее поведение
            $this->pageService->addSchemaForPage($id, $parsed['schemas']);
        }

        // Стили из <style>-блоков пишутся в neutral-style.css только после подтверждения импорта
        if (!empty($parsed['styles'])) {
            if (!$this->parser->commitStyles($parsed['styles'])) {
                $this->adminService->setFlash('warning', 'Не удалось записать стили в storage/neutral-style.css');
            }
        }

        $this->session->remove(self::PREVIEW_KEY);

        $this->adminService->setFlash('success', 'Страница импортирована! ID: ' . $id);

        return Response::redirect('/admin/pages/' . $id . '/edit');
    }
}
