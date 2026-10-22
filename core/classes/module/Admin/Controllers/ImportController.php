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
        ?SessionManager $session = null
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

        $this->session->set(self::PREVIEW_KEY, $parsed);

        $html = $this->template->render('admin/import/preview', [
            'title' => 'Предпросмотр импорта',
            'parsed' => $parsed,
            'existing' => $exists,
            'flash' => $this->adminService->getFlash(),
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
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

        $id = $this->pageService->createPage($data);

        // СОХРАНЯЕМ SCHEMA.ORG РАЗМЕТКУ (привязка к странице согласно архитектуре БД)
        if (!empty($parsed['schemas'])) {
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
