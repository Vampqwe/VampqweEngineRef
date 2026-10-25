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
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Session\SessionManager;
use Core\System\Template\Template;

class DashboardController
{
    private AdminService $adminService;
    private Template $template;
    private SessionManager $session;

    public function __construct(
        AdminService $adminService,
        Template $template,
        ?SessionManager $session = null
    ) {
        $this->adminService = $adminService;
        $this->template = $template;
        $this->session = $session ?? new SessionManager();
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
        ]);

        return Response::html($html);
    }
}