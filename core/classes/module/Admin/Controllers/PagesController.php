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

use Core\Module\Admin\Services\PageService;
use Core\Module\Admin\Services\AdminService;
use Core\System\Config\Config;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Security\HtmlSanitizer;
use Core\System\Security\SecurityManager;
use Core\System\Template\Template;
use Core\System\Http\StatusCode;

class PagesController
{
    private PageService $pageService;
    private AdminService $adminService;
    private Template $template;

    public function __construct(
        PageService $pageService,
        AdminService $adminService,
        Template $template,
        private SecurityManager $security
    ) {
        $this->pageService = $pageService;
        $this->adminService = $adminService;
        $this->template = $template;
    }

    /**
     * Реальный CSRF-токен для форм (create/edit/list).
     *
     * Ранее в render передавался пустой csrf_token — поля формы были пусты,
     * и CsrfMiddleware отклонял бы любой корректно отправленный POST.
     */
    private function csrfToken(): string
    {
        return $this->security->getCsrfToken()->getToken();
    }

    /**
     * Обработка контента страницы перед сохранением.
     *
     * Режим управляется конфигом config/page-content.php ('content_mode'):
     *  - 'sanitize' (по умолчанию): HtmlSanitizer::clean() — закрывает
     *    stored-XSS от редакторов; скрипты/iframe/онатут-обработчики вырезаются;
     *  - 'raw': сохранять как есть (только полностью доверенное окружение).
     * Дополнительно урезается до max_content_length (защита от раздувания БД).
     */
    private function sanitizeContent(string $html): string
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
        $page = max(1, (int) $request->input('page', 1));
        // Размер страницы: запрос (?per_page=) опционален, иначе — из конфига.
        $perPage = $request->input('per_page');
        $data = $this->pageService->getPages($page, $perPage !== null ? (int) $perPage : null);
        $flash = $this->adminService->getFlash();

        $html = $this->template->render('admin/pages/list', [
            'title' => 'Страницы',
            'pages' => $data['pages'],
            'pagination' => [
                'page' => $data['page'],
                'totalPages' => $data['totalPages'],
                'total' => $data['total'],
            ],
            'flash' => $flash,
            'csrf_token' => $this->csrfToken(),
        ]);

        return Response::html($html);
    }

    public function create(Request $request): Response
    {
        $authors = $this->pageService->getAuthors();
        $flash = $this->adminService->getFlash();

        $html = $this->template->render('admin/pages/edit', [
            'title' => 'Новая страница',
            'page' => null,
            'authors' => $authors,
            'flash' => $flash,
            'csrf_token' => $this->csrfToken(),
            'csrf_meta' => $this->security->getCsrfToken()->getMetaTag(),
            // Schema.org: для новой страницы (page_id ещё нет) передаём пустые массивы.
            // UI покажет: "Схемы можно добавить после сохранения страницы".
            'schemas'      => [],
            'schema_types' => $this->pageService->getAllowedSchemaTypes(),
            'schema_taken' => [],
        ]);

        return Response::html($html);
    }

    public function store(Request $request): Response
    {
        if (!$request->isPost()) {
            return Response::redirect('/admin/pages');
        }

        $data = [
            'title' => strip_tags(trim($request->input('title', ''))),
            'description' => trim($request->input('description', '')),
            'keywords' => trim($request->input('keywords', '')),
            'canonical' => trim($request->input('canonical', '')),
            'og_title' => strip_tags(trim($request->input('og_title', ''))),
            'og_description' => trim($request->input('og_description', '')),
            'meta_robots' => $request->input('meta_robots', 'index, follow'),
            'og_image' => trim($request->input('og_image', '')),
            'reading_time' => $request->input('reading_time') ? (int) $request->input('reading_time') : null,
            'author_id' => $request->input('author_id') ? (int) $request->input('author_id') : null,
            'slug' => trim($request->input('slug', '')),
            'content' => $this->sanitizeContent((string) $request->input('content', '')),
            'scripts' => $request->input('scripts') ? json_encode(explode(',', $request->input('scripts'))) : null,
            'page_type' => $request->input('page_type', 'article'),
            'parent_id' => $request->input('parent_id') ? (int) $request->input('parent_id') : null,
            'status' => $request->input('status', 'draft'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_in_menu' => (int) $request->input('is_in_menu', 0),
        ];

        if (empty($data['slug'])) {
            $data['slug'] = $this->pageService->generateSlug($data['title']);
        }

        if (empty($data['title']) || empty($data['slug'])) {
            $this->adminService->setFlash('error', 'Заголовок и slug обязательны');
            return Response::redirect('/admin/pages/new');
        }

        $id = $this->pageService->createPage($data);
        $this->adminService->setFlash('success', 'Страница создана');

        return Response::redirect('/admin/pages/' . $id . '/edit');
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->getAttribute('id');
        $page = $this->pageService->getPage($id);

        if (!$page) {
            $this->adminService->setFlash('error', 'Страница не найдена');
            return Response::redirect('/admin/pages');
        }

        $authors = $this->pageService->getAuthors();
        $flash = $this->adminService->getFlash();

        $html = $this->template->render('admin/pages/edit', [
            'title' => 'Редактирование: ' . $page['title'],
            'page' => $page,
            'authors' => $authors,
            'flash' => $flash,
            'csrf_token' => $this->csrfToken(),
            'csrf_meta' => $this->security->getCsrfToken()->getMetaTag(),
            // Schema.org: привязанные к странице схемы + whitelist типов + занятые типы.
            'schemas'      => $this->pageService->getSchemasForPage($id),
            'schema_types' => $this->pageService->getAllowedSchemaTypes(),
            'schema_taken' => $this->pageService->getTakenSchemaTypes($id),
        ]);

        return Response::html($html);
    }

    public function update(Request $request): Response
    {
        if (!$request->isPost()) {
            return Response::redirect('/admin/pages');
        }

        $id = (int) $request->getAttribute('id');
        $page = $this->pageService->getPage($id);

        if (!$page) {
            $this->adminService->setFlash('error', 'Страница не найдена');
            return Response::redirect('/admin/pages');
        }

        $data = [
            'title' => strip_tags(trim($request->input('title', ''))),
            'description' => trim($request->input('description', '')),
            'keywords' => trim($request->input('keywords', '')),
            'canonical' => trim($request->input('canonical', '')),
            'og_title' => strip_tags(trim($request->input('og_title', ''))),
            'og_description' => trim($request->input('og_description', '')),
            'meta_robots' => $request->input('meta_robots', 'index, follow'),
            'og_image' => trim($request->input('og_image', '')),
            'reading_time' => $request->input('reading_time') ? (int) $request->input('reading_time') : null,
            'author_id' => $request->input('author_id') ? (int) $request->input('author_id') : null,
            'slug' => trim($request->input('slug', '')),
            'content' => $this->sanitizeContent((string) $request->input('content', '')),
            'scripts' => $request->input('scripts') ? json_encode(explode(',', $request->input('scripts'))) : null,
            'page_type' => $request->input('page_type', 'article'),
            'parent_id' => $request->input('parent_id') ? (int) $request->input('parent_id') : null,
            'status' => $request->input('status', 'draft'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_in_menu' => (int) $request->input('is_in_menu', 0),
        ];

        if (empty($data['title']) || empty($data['slug'])) {
            $this->adminService->setFlash('error', 'Заголовок и slug обязательны');
            return Response::redirect('/admin/pages/' . $id . '/edit');
        }

        $this->pageService->updatePage($id, $data);
        $this->adminService->setFlash('success', 'Страница обновлена');

        return Response::redirect('/admin/pages/' . $id . '/edit');
    }

    public function destroy(Request $request): Response
    {
        if (!$request->isPost()) {
            return Response::redirect('/admin/pages');
        }

        $id = (int) $request->getAttribute('id');
        $page = $this->pageService->getPage($id);

        if (!$page) {
            $this->adminService->setFlash('error', 'Страница не найдена');
            return Response::redirect('/admin/pages');
        }

        $this->pageService->deletePage($id);
        $this->adminService->setFlash('success', 'Страница удалена');

        return Response::redirect('/admin/pages');
    }

        // ═══════════════════════════════════════════════════════════════
    // Schema.org — AJAX CRUD для схем, привязанных к странице
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /admin/pages/{id}/schemas — список схем страницы.
     *
     * Отдаёт сами схемы, разрешённые типы и уже занятые типы, чтобы
     * UI сразу заблокировал недоступные опции (UNIQUE KEY page_id+type).
     */
    public function schemas(Request $request): Response
    {
        $pageId = (int) $request->getAttribute('id');
        $page = $this->pageService->getPage($pageId);
        if ($page === null) {
            return $this->jsonError('Страница не найдена.', StatusCode::NOT_FOUND);
        }

        return Response::json([
            'ok'            => true,
            'schemas'       => $this->pageService->getSchemasForPage($pageId),
            'allowed_types' => $this->pageService->getAllowedSchemaTypes(),
            'taken_types'   => $this->pageService->getTakenSchemaTypes($pageId),
        ]);
    }

    /**
     * POST /admin/pages/{id}/schemas — создать схему.
     */
    public function schemaStore(Request $request): Response
    {
        $pageId = (int) $request->getAttribute('id');
        $payload = $this->readSchemaPayload($request);
        $result = $this->pageService->createSchemaForPage($pageId, $payload);

        return $this->schemaResponse($result, $pageId, 'Схема создана.');
    }

    /**
     * POST /admin/pages/{id}/schemas/{schemaId} — обновить схему.
     */
    public function schemaUpdate(Request $request): Response
    {
        $pageId = (int) $request->getAttribute('id');
        $schemaId = (int) $request->getAttribute('schemaId');
        $payload = $this->readSchemaPayload($request);
        $result = $this->pageService->updateSchema($schemaId, $pageId, $payload);

        return $this->schemaResponse($result, $pageId, 'Схема обновлена.');
    }

    /**
     * POST /admin/pages/{id}/schemas/{schemaId}/delete — удалить схему.
     */
    public function schemaDelete(Request $request): Response
    {
        $pageId = (int) $request->getAttribute('id');
        $schemaId = (int) $request->getAttribute('schemaId');
        $result = $this->pageService->deleteSchema($schemaId, $pageId);

        if (!$result['ok']) {
            return $this->jsonError(implode(' ', $result['errors']), StatusCode::BAD_REQUEST);
        }

        $this->security->getAuditLogger()->logAction('page_schema.delete', 'schema', (string) $schemaId, [
            'page_id' => $pageId,
        ]);

        return Response::json([
            'ok'          => true,
            'message'     => 'Схема удалена.',
            'taken_types' => $this->pageService->getTakenSchemaTypes($pageId),
        ]);
    }

    /**
     * Прочитать payload схемы из запроса.
     *
     * Поддерживает и JSON-тело (fetch + Content-Type: application/json),
     * и обычную форму — на случай деградации JS.
     *
     * @return array<string, mixed>
     */
    private function readSchemaPayload(Request $request): array
    {
        $raw = $request->isJson() ? $request->getParsedBody() : $request->allPost();
        if (!is_array($raw)) {
            $raw = [];
        }

        // data может прийти и как объект (JSON), и как строка (форма) —
        // нормализацию и валидацию JSON делает сервис.
        return [
            'schema_type' => (string) ($raw['schema_type'] ?? ''),
            'data'        => $raw['data'] ?? null,
            'priority'    => $raw['priority'] ?? 10,
            'is_active'   => $raw['is_active'] ?? 1,
        ];
    }

    /**
     * Единый формат ответа для create/update схемы + аудит-запись.
     *
     * @param array{ok: bool, errors: string[], schema?: array<string, mixed>} $result
     */
    private function schemaResponse(array $result, int $pageId, string $okMessage): Response
    {
        if (!$result['ok']) {
            return $this->jsonError(implode(' ', $result['errors']), StatusCode::BAD_REQUEST);
        }

        // Безопасное извлечение схемы (защита от warning при null)
        $schema = $result['schema'] ?? null;
        if ($schema === null) {
            return $this->jsonError('Схема не найдена после сохранения.', StatusCode::INTERNAL_SERVER_ERROR);
        }

        $this->security->getAuditLogger()->logAction('page_schema.save', 'schema', (string) ($schema['id'] ?? ''), [
            'page_id'     => $pageId,
            'schema_type' => $schema['schema_type'] ?? null,
        ]);

        return Response::json([
            'ok'          => true,
            'message'     => $okMessage,
            'schema'      => $schema,
            'taken_types' => $this->pageService->getTakenSchemaTypes($pageId),
        ]);
    }

    /**
     * JSON-ответ об ошибке с корректным статусом.
     */
    private function jsonError(string $message, StatusCode $status): Response
    {
        return Response::json([
            'ok'     => false,
            'errors' => [$message],
        ], $status);
    }
}