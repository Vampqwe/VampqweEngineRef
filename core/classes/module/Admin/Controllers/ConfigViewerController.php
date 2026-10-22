<?php
declare(strict_types=1);

namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\ConfigFileParser;
use Core\Module\Admin\Services\AdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Template\Template;
use Core\System\Security\SecurityManager;

/**
 * Контроллер просмотра и редактирования config.env.
 * Доступ ТОЛЬКО для супер-администратора.
 */
class ConfigViewerController
{
    public function __construct(
        private ConfigFileParser $parser,
        private AdminService $adminService,
        private Template $template,
        private SecurityManager $security
    ) {}

    public function index(Request $request): Response
{
    error_log('ConfigViewerController::index() START');

    try {
        error_log('ConfigViewerController: parser = ' . get_class($this->parser));
        error_log('ConfigViewerController: calling parse()');

        $data = $this->parser->parse();

        error_log('ConfigViewerController: parse() returned sections: ' . count($data['sections'] ?? []));
    } catch (\Throwable $e) {
        error_log('ConfigViewerController ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $data = [
            'sections' => [],
            'meta' => [
                'error' => 'Ошибка парсера: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]
        ];
    }

    $flash = $this->adminService->getFlash();

    $html = $this->template->render('admin/config/index', [
        'title' => 'Редактор config.env',
        'data' => $data,
        'flash' => $flash,
        'csrf_token' => $this->security->getCsrfToken()->getToken(),
        'csrf_meta' => $this->security->getCsrfToken()->getMetaTag(),
    ]);

    return Response::html($html);
}

    public function save(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            $this->adminService->setFlash('error', 'Доступ запрещён.');
            return Response::redirect('/admin/config');
        }

        $changes = [];
        $post = $request->allPost();
        foreach ($post as $key => $value) {
            if (str_starts_with($key, 'cfg_')) {
                $realKey = substr($key, 4);
                $changes[$realKey] = (string) $value;
            }
        }

        $result = $this->parser->saveChanges($changes);

        if (!$result['ok']) {
            $this->adminService->setFlash('error', implode('; ', $result['errors']));
        } else {
            $this->adminService->setFlash('success', "Сохранено изменений: {$result['changed']}");
        }

        return Response::redirect('/admin/config');
    }

    private function isSuperAdmin(): bool
    {
        $session = $_SESSION ?? [];
        $admin = $session['admin_user'] ?? null;
        if (!$admin) return false;
        $config = \Core\System\Config\Config::getInstance();
        $superEmail = $config->get('SUPER_ADMIN_EMAIL', '');
        return $superEmail !== '' && strcasecmp((string) ($admin['email'] ?? ''), $superEmail) === 0;
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'bool' => 'логическое',
            'int' => 'число',
            'url' => 'URL',
            'email' => 'email',
            'ip' => 'IP/CIDR',
            'hash' => 'хеш',
            'password_hash' => 'bcrypt',
            'sensitive' => 'секрет',
            'text' => 'текст',
            default => $type,
        };
    }
}