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

use Core\Module\Admin\Services\BatchImportService;
use Core\Module\Admin\Services\HtmlImportParser;
use Core\Module\Admin\Services\ImportJobHandler;
use Core\Module\Admin\Services\ImportService;
use Core\Module\Page\PageService;
use Core\Module\Admin\Services\AdminService;
use Core\Module\Admin\Services\PageImportWriter;
use Core\System\Application\Application;
use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use Core\System\Http\Request;
use Core\System\Http\Response;
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
    private PageImportWriter $writer;
    private BatchImportService $batch;

    /** Ключ предпросмотра импорта в сессии */
    private const PREVIEW_KEY = 'import_preview';

    /** Ключ отчёта пакетного импорта в сессии (scan → confirm) */
    private const BATCH_SCAN_KEY = 'import_batch_scan';

    public function __construct(
        HtmlImportParser $parser,
        PageService $pageService,
        AdminService $adminService,
        Template $template,
        Config $config,
        private SecurityManager $security,
        ?SessionManager $session = null,
        ?PageImportWriter $writer = null,
        ?BatchImportService $batch = null
    ) {
        $this->parser = $parser;
        $this->pageService = $pageService;
        $this->adminService = $adminService;
        $this->template = $template;
        $this->config = $config;
        $this->session = $session ?? new SessionManager($config);
        // Единая точка записи импорта — тот же код, что использует воркер
        // очереди (ImportJobHandler), поэтому поведение sync/async не расходится.
        // В веб-контексте провайдер передаёт готовый синглтон из DI; при его
        // отсутствии собираем writer напрямую (обратная совместимость).
        $this->writer = $writer ?? new PageImportWriter(
            $parser,
            $pageService,
            $config,
            $this->resolveImportService()
        );
        // Пакетный импорт из папки storage: тот же parser/writer/PageService,
        // поэтому правила записи полностью совпадают с одиночным импортом.
        $this->batch = $batch ?? new BatchImportService($config, $parser, $pageService, $this->writer);
    }

    /**
     * ImportService для fallback-сборки writer: из DI-контейнера приложения,
     * иначе — напрямую через новое соединение БД (только если контейнер не инициализирован).
     */
    private function resolveImportService(): ?ImportService
    {
        try {
            return Application::getInstance()->getContainer()->get(ImportService::class);
        } catch (\Throwable) {
            try {
                return new ImportService(new DataBase($this->config));
            } catch (\Throwable) {
                return null; // прежний резервный путь без ImportService
            }
        }
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

        // Фоновый режим: сохраняем загруженный файл во временное хранилище
        // очереди и запоминаем путь в данных предпросмотра — confirm()
        // положит его в job вместо тяжёлой синхронной обработки.
        $handler = new ImportJobHandler($this->config);
        if ($handler->isQueueEnabled()) {
            $staged = $handler->stageUpload((string) $file['tmp_name']);
            if ($staged !== null) {
                $parsed['import_file'] = $staged;
            }
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

        // Фоновый режим (QUEUE_ENABLED=true): тяжёлые шаги — скачивание
        // картинок, запись блоков/стилей — выполняет воркер bin/queue-work.php.
        // Веб-процесс лишь кладёт сохранённый во время upload() файл в очередь.
        $queued = $this->dispatchBackgroundImport($parsed);
        if ($queued !== null) {
            $this->session->remove(self::PREVIEW_KEY);
            $this->adminService->setFlash(
                'success',
                'Импорт принят в фоновую обработку (задача ' . $queued . '). Страница появится в списке как черновик.'
            );
            return Response::redirect('/admin/import');
        }

        if ($this->pageService->pageExistsBySlug((string) $parsed['slug'])) {
            $this->adminService->setFlash('error', 'Страница с slug "' . $parsed['slug'] . '" уже существует.');
            (new ImportJobHandler($this->config))->forgetStagedFile();
            $this->session->remove(self::PREVIEW_KEY);
            return Response::redirect('/admin/import');
        }

        // Запись импорта — единая точка PageImportWriter (тот же код, что в
        // воркере ImportJobHandler): сборка записи pages, колоночные блоки
        // config/import-parser.php, rows-наборы (schema_org и др.), стили.
        try {
            $report = $this->writer->write($parsed);
        } catch (\Throwable $e) {
            $this->adminService->setFlash('error', 'Импорт не выполнен: ' . $e->getMessage());
            return Response::redirect('/admin/import');
        }

        foreach ($report['warnings'] as $warning) {
            $this->adminService->setFlash('warning', (string) $warning);
        }

        $this->session->remove(self::PREVIEW_KEY);
        $this->adminService->setFlash('success', 'Страница импортирована! ID: ' . $report['page_id']);

        return Response::redirect('/admin/pages/' . $report['page_id'] . '/edit');
    }

    /**
     * Поставить импорт в очередь, если фоновый режим включён и временный
     * файл доступен. Возвращает id задачи или null (очередь выключена /
     * файл потерян — работаем синхронно по-старому).
     */
    private function dispatchBackgroundImport(array $parsed): ?string
    {
        $handler = new ImportJobHandler($this->config);
        if (!$handler->isQueueEnabled()) {
            return null;
        }

        $file = (string) ($parsed['import_file'] ?? '');
        if ($file === '' || !is_file($file)) {
            return null;
        }

        $user = $this->session->adminUser();

        return $handler->dispatch($file, (int) ($user['id'] ?? 0));
    }

    // ─── Пакетный импорт из папки storage ────────────────────────────────

    /**
     * GET /admin/import/batch — выбор папки и запуск сканирования.
     */
    public function batch(Request $request): Response
    {
        $folder = trim((string) $request->input('folder', ''));

        $scan = null;
        if ($folder !== '') {
            $scan = $this->batch->scan($folder);
            $this->session->set(self::BATCH_SCAN_KEY, $scan);
        } else {
            $this->session->remove(self::BATCH_SCAN_KEY);
        }

        $html = $this->template->render('admin/import/batch', [
            'title' => 'Пакетный импорт страниц',
            'flash' => $this->adminService->getFlash(),
            'folders' => $this->batch->listFolders(),
            'folder' => $folder,
            'scan' => $scan,
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta'  => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    /**
     * POST /admin/import/batch-scan — «Просканировать»: короткий отчёт о
     * том, какие страницы папки можно импортировать, а какие нет.
     */
    public function batchScan(Request $request): Response
    {
        if (!$request->isPost()) {
            return Response::redirect('/admin/import/batch');
        }

        $folder = trim((string) $request->post('folder', ''));
        if ($folder === '') {
            $this->adminService->setFlash('error', 'Не выбрана папка в storage.');

            return Response::redirect('/admin/import/batch');
        }

        $scan = $this->batch->scan($folder);
        $this->session->set(self::BATCH_SCAN_KEY, $scan);

        $html = $this->template->render('admin/import/batch', [
            'title' => 'Пакетный импорт страниц',
            'flash' => $this->adminService->getFlash(),
            'folders' => $this->batch->listFolders(),
            'folder' => $folder,
            'scan' => $scan,
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta'  => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    /**
     * POST /admin/import/batch-confirm — «Жму ОК»: импорт всех годных
     * файлов по уже реализованным правилам (PageImportWriter), успешно
     * залитые страницы переносятся в {folder}/added.
     */
    public function batchConfirm(Request $request): Response
    {
        if (!$request->isPost()) {
            return Response::redirect('/admin/import/batch');
        }

        $scan = $this->session->get(self::BATCH_SCAN_KEY);
        $folder = trim((string) ((is_array($scan) ? ($scan['folder'] ?? '') : '')
            ?: (string) $request->input('folder', '')));

        if (!is_array($scan) || $folder === ''
            || BatchImportService::isValidFolderName($folder) === false) {
            $this->adminService->setFlash('error', 'Нет данных сканирования. Выполните сканирование заново.');

            return Response::redirect('/admin/import/batch');
        }

        $result = $this->batch->run($folder);
        $this->session->remove(self::BATCH_SCAN_KEY);

        if ($result['imported'] > 0) {
            $this->adminService->setFlash(
                'success',
                'Импортировано страниц: ' . $result['imported']
                    . ($result['moved'] > 0 ? ', перенесено в ' . $folder . '/added: ' . $result['moved'] : '')
            );
        }
        foreach (array_slice($result['errors'], 0, 10) as $error) {
            $this->adminService->setFlash('error', (string) $error);
        }
        if ($result['imported'] === 0 && $result['failed'] === 0) {
            $this->adminService->setFlash('warning', 'В папке «' . $folder . '» нет файлов, пригодных для импорта.');
        }

        return Response::redirect('/admin/import/batch?folder=' . rawurlencode($folder));
    }
}
