<?php
declare(strict_types=1);

namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\SettingsService;
use Core\Module\Admin\Services\AdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Template\Template;
use Core\System\Security\SecurityManager;

/**
 * Контроллер управления настройками движка.
 * Доступ только для супер-администратора (через SuperAdminMiddleware).
 */
class SettingsController
{
    public function __construct(
        private SettingsService $settingsService,
        private AdminService $adminService,
        private Template $template,
        private SecurityManager $security,
    ) {}

    /**
     * GET /admin/settings — показать форму настроек
     */
    public function index(Request $request): Response
    {
        $sections = $this->settingsService->getSettingsGrouped();
        $flash = $this->adminService->getFlash();

        $html = $this->template->render('admin/settings/index', [
            'title' => 'Настройки сайта',
            'sections' => $sections,
            'flash' => $flash,
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta' => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    /**
     * POST /admin/settings — сохранить настройки
     */
    public function update(Request $request): Response
    {
        $data = $request->allPost();
        $result = $this->settingsService->saveSettings($data);

        if (!$result['ok']) {
            $this->adminService->setFlash('error', implode(' ', $result['errors']));
            return Response::redirect('/admin/settings');
        }

        if ($result['changed'] === 0) {
            $this->adminService->setFlash('info', 'Изменений не обнаружено.');
        } else {
            $this->adminService->setFlash('success', "Настройки сохранены. Изменено параметров: {$result['changed']}.");
        }

        return Response::redirect('/admin/settings');
    }

        /**
     * POST /admin/settings/rebuild-seed — пересоздать seed-данные
     */
    public function rebuildSeed(Request $request): Response
    {
        $result = $this->settingsService->rebuildSeedData();

        if (!$result['ok']) {
            $this->adminService->setFlash('error', implode(' ', $result['errors']));
        } else {
            $msg = "Seed-данные обновлены. ";
            $parts = [];
            if ($result['updated']['schemas'] > 0) $parts[] = "схем: {$result['updated']['schemas']}";
            if ($result['updated']['pages'] > 0) $parts[] = "страниц: {$result['updated']['pages']}";
            if ($result['updated']['authors'] > 0) $parts[] = "авторов: {$result['updated']['authors']}";
            if (!empty($parts)) {
                $msg .= "Изменено: " . implode(', ', $parts) . ".";
            } else {
                $msg .= "Изменений не обнаружено (все данные уже актуальны).";
            }
            $this->adminService->setFlash('success', $msg);
        }

        return Response::redirect('/admin/settings');
    }
}