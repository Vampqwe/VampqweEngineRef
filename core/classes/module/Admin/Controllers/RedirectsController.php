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

use Core\Module\Admin\Services\AdminService;
use Core\Module\Admin\Services\RedirectAdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\StatusCode;
use Core\System\Security\SecurityManager;
use Core\System\Session\SessionManager;
use Core\System\Template\Template;

/**
 * Управление редиректами: GET /admin/redirects (+ CRUD POST).
 *
 * Защита по правилам движка:
 *  - основной контур — стек маршрута /admin (InstallMiddleware →
 *    AuthMiddleware → CsrfMiddleware → AdminCspMiddleware);
 *  - defence-in-depth — проверка роли супер-админа в самом контроллере
 *    (как в SettingsController): прямой вызов мимо middleware не должен
 *    обходить проверку прав;
 *  - CSRF-токены берутся из SecurityManager и попадают в шаблон,
 *    POST-запросы валидируются CsrfMiddleware до входа в контроллер;
 *  - вывод в шаблоне экранируется htmlspecialchars, данные в БД — только
 *    prepared statements (см. RedirectAdminService).
 */
class RedirectsController
{
    public function __construct(
        private RedirectAdminService $redirectService,
        private AdminService $adminService,
        private Template $template,
        private SecurityManager $security,
        private ?SessionManager $session = null,
    ) {
        $this->session = $session ?? new SessionManager();
    }

    /**
     * Defence-in-depth: роль супер-админа по флагу is_super из сессии
     * (не по сравнению email), как в SettingsController.
     */
    private function isSuperAdmin(): bool
    {
        if (!$this->session->isAdminAuthenticated()) {
            return false;
        }
        $user = $this->session->adminUser() ?? [];
        return ($user['is_super'] ?? false) === true;
    }

    /** 403 без раскрытия деталей роли. */
    private function forbidden(): Response
    {
        return Response::text('Forbidden', StatusCode::FORBIDDEN);
    }

    /** GET /admin/redirects — список + форма создания. */
    public function index(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $list = $this->redirectService->list($search, $page);

        $html = $this->template->render('admin/redirects/index', [
            'title' => 'Редиректы',
            'items' => $list['items'],
            'total' => $list['total'],
            'page' => $list['page'],
            'per_page' => $list['per_page'],
            'search' => $search,
            'flash' => $this->adminService->getFlash(),
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta' => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    /** GET /admin/redirects/{id}/edit — форма редактирования. */
    public function edit(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

        $id = (int) $request->getAttribute('id');
        $redirect = $this->redirectService->find($id);
        if ($redirect === null) {
            $this->adminService->setFlash('error', 'Редирект не найден.');
            return Response::redirect('/admin/redirects');
        }

        $html = $this->template->render('admin/redirects/edit', [
            'title' => 'Редактирование редиректа',
            'redirect' => $redirect,
            'flash' => $this->adminService->getFlash(),
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta' => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    /** POST /admin/redirects/store — создать. */
    public function store(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

        $result = $this->redirectService->create(
            (string) $request->post('source', ''),
            (string) $request->post('destination', ''),
            (int) $request->post('status_code', 301),
            $request->post('is_active') !== null,
            (string) $request->post('note', '')
        );

        $this->finish($result, 'Редирект создан.');
        return Response::redirect('/admin/redirects');
    }

    /** POST /admin/redirects/{id}/update — обновить. */
    public function update(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

        $id = (int) $request->getAttribute('id');
        $result = $this->redirectService->update(
            $id,
            (string) $request->post('source', ''),
            (string) $request->post('destination', ''),
            (int) $request->post('status_code', 301),
            $request->post('is_active') !== null,
            (string) $request->post('note', '')
        );

        $this->finish($result, 'Редирект обновлён.');
        return Response::redirect($result['ok'] ? '/admin/redirects' : "/admin/redirects/{$id}/edit");
    }

    /** POST /admin/redirects/{id}/delete — удалить. */
    public function destroy(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

        $id = (int) $request->getAttribute('id');
        if ($this->redirectService->delete($id)) {
            $this->adminService->setFlash('success', 'Редирект удалён.');
        } else {
            $this->adminService->setFlash('error', 'Редирект не найден.');
        }

        return Response::redirect('/admin/redirects');
    }

    /** POST /admin/redirects/{id}/reset-hits — сброс счётчика. */
    public function resetHits(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

        $id = (int) $request->getAttribute('id');
        if ($this->redirectService->resetHits($id)) {
            $this->adminService->setFlash('success', 'Счётчик срабатываний сброшен.');
        } else {
            $this->adminService->setFlash('error', 'Редирект не найден.');
        }

        return Response::redirect('/admin/redirects');
    }

    /**
     * @param array{ok: bool, error?: string} $result
     */
    private function finish(array $result, string $successMessage): void
    {
        if ($result['ok']) {
            $this->adminService->setFlash('success', $successMessage);
        } else {
            $this->adminService->setFlash('error', $result['error'] ?? 'Ошибка сохранения.');
        }
    }
}
