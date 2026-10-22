<?php
declare(strict_types=1);

namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\AdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Session\SessionManager;
use Core\System\Template\Template;
use Core\System\Security\SecurityManager;

class DashboardController
{
    public function __construct(
        private AdminService $adminService,
        private Template $template,
        private SecurityManager $security,          // 3-й аргумент: обязательный
        private ?SessionManager $session = null     // 4-й аргумент: опциональный
    ) {
        // Если SessionManager не передан через DI, создаём fallback (на всякий случай)
        if ($this->session === null) {
            $this->session = new SessionManager();
        }
    }

    public function index(Request $request): Response
    {
        $stats = $this->adminService->getDashboardStats();
        $recentPages = $this->adminService->getRecentPages(5);
        $recentActivity = $this->adminService->getRecentActivity(4);
        $flash = $this->adminService->getFlash();
        $user = $this->session->adminUser();

        $html = $this->template->render('admin/pages/dashboard', [
            'title' => 'Дашборд',
            'stats' => $stats,
            'recentPages' => $recentPages,
            'recentActivity' => $recentActivity,
            'flash' => $flash,
            'user' => $user,
            'csrf_token' => $this->security->getCsrfToken()->getToken(), // ← Это починит кнопку "Выйти"
        ]);

        return Response::html($html);
    }
}