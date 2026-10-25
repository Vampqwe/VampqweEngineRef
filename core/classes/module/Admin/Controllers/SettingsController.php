<?php
declare(strict_types=1);

namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\SettingsService;
use Core\Module\Admin\Services\AdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Template\Template;
use Core\System\Security\SecurityManager;
use Core\System\Session\SessionManager;

/**
 * Контроллер управления настройками движка.
 * Доступ только для супер-администратора: основная защита —
 * SuperAdminMiddleware в стеке AdminMiddleware; здесь additionally
 * defence-in-depth (метод isSuperAdmin()), чтобы прямая инстанциация
 * контроллера вне маршрута /admin не обходила проверку роли.
 */
class SettingsController
{
    public function __construct(
        private SettingsService $settingsService,
        private AdminService $adminService,
        private Template $template,
        private SecurityManager $security,
        private ?SessionManager $session = null,
    ) {
        $this->session = $session ?? new SessionManager();
    }

    /**
     * Defence-in-depth: роль супер-админа берётся из флага is_super,
     * который устанавливается только AuthController при входе (сессия),
     * а не из сравнивания email (Config::get уязвим к poison-ключам).
     */
    private function isSuperAdmin(): bool
    {
        if (!$this->session->isAdminAuthenticated()) {
            return false;
        }
        $user = $this->session->adminUser() ?? [];
        return ($user['is_super'] ?? false) === true;
    }

    /**
     * Закрыть доступ без раскрытия деталей: 403 Forbidden.
     */
    private function forbidden(): Response
    {
        return Response::text('Forbidden', \Core\System\Http\StatusCode::FORBIDDEN);
    }

    /**
     * GET /admin/settings — показать форму настроек
     */
    public function index(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

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
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

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
        if (!$this->isSuperAdmin()) {
            return $this->forbidden();
        }

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