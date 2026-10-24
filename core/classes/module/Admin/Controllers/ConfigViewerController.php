<?php
declare(strict_types=1);

namespace Core\Module\Admin\Controllers;

use Core\Module\Admin\Services\ConfigFileParser;
use Core\Module\Admin\Services\AdminService;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Template\Template;
use Core\System\Security\SecurityManager;
use Core\System\Http\StatusCode;
use Core\System\Session\SessionManager;

/**
 * Контроллер просмотра и редактирования config.env.
 * Доступ ТОЛЬКО для супер-администратора: основная защита —
 * SuperAdminMiddleware в стеке AdminMiddleware; здесь additionally
 * defence-in-depth (проверка флага is_super из сессии).
 */
class ConfigViewerController
{
    private SessionManager $session;

    public function __construct(
        private ConfigFileParser $parser,
        private AdminService $adminService,
        private Template $template,
        private SecurityManager $security,
        ?SessionManager $session = null
    ) {
        $this->session = $session ?? new SessionManager();
    }

    public function index(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return Response::text('Forbidden', StatusCode::FORBIDDEN);
        }

        try {
            $data = $this->parser->parse();
        } catch (\Throwable $e) {
            error_log('ConfigViewerController ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $data = [
                'sections' => [],
                'meta' => [
                    'error' => 'Ошибка парсера: ' . $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ];
        }

        $flash = $this->adminService->getFlash();

        $html = $this->template->render('admin/config/index', [
            'title' => 'Редактор config.env',
            'data' => $data,
            'flash' => $flash,
            'type_labels' => self::TYPE_LABELS,
            'csrf_token' => $this->security->getCsrfToken()->getToken(),
            'csrf_meta' => $this->security->getCsrfToken()->getMetaTag(),
        ]);

        return Response::html($html);
    }

    public function save(Request $request): Response
    {
        if (!$this->isSuperAdmin()) {
            return Response::text('Forbidden', StatusCode::FORBIDDEN);
        }

        $changes = [];
        $uncomment = [];
        $post = $request->allPost();
        foreach ($post as $key => $value) {
            if (str_starts_with($key, 'cfg_')) {
                $changes[substr($key, 4)] = (string) $value;
            } elseif (str_starts_with($key, 'uncmt_')) {
                $uncomment[] = substr($key, 6);
            }
        }

        $result = $this->parser->saveChanges($changes, $uncomment);

        if (!$result['ok']) {
            $this->adminService->setFlash('error', implode('; ', $result['errors']));
        } else {
            $this->adminService->setFlash('success', "Сохранено изменений: {$result['changed']}");
        }

        return Response::redirect('/admin/config');
    }

    /**
     * Defence-in-depth: проверяем флаг is_super из сессии (устанавливается
     * только AuthController при входе), а не сравнение email через Config —
     * Config::get уязвим к poison-ключам, и такой путь позволял обычному
     * админу попасть в редактор config.env.
     */
    private function isSuperAdmin(): bool
    {
        if (!$this->session->isAdminAuthenticated()) {
            return false;
        }
        $admin = $this->session->adminUser() ?? [];
        return ($admin['is_super'] ?? false) === true;
    }

    private const TYPE_LABELS = [
        'bool' => 'логическое',
        'empty' => 'пусто',
        'int' => 'число',
        'url' => 'URL',
        'email' => 'email',
        'ip' => 'IP/CIDR',
        'hash' => 'хеш',
        'password_hash' => 'bcrypt',
        'sensitive' => 'секрет',
        'text' => 'текст',
    ];
}