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


namespace Core\Module\Auth\Middleware;

use Core\System\Http\Middleware;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\StatusCode;
use Core\System\Session\SessionManager;

/**
 * Проверяет, что пользователь является супер-админом
 * Используется для маршрутов, доступных только супер-админу.
 * Единая точка доступа к сессии — через SessionManager (без прямых $_SESSION).
 */
class SuperAdminMiddleware implements Middleware
{
    private SessionManager $session;

    public function __construct(?SessionManager $session = null)
    {
        $this->session = $session ?? new SessionManager();
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->session->isAdminAuthenticated()) {
            $this->session->set('redirect_after_login', $request->getPath());
            return Response::redirect('/admin/login');
        }

        $user = $this->session->adminUser() ?? [];
        if (!($user['is_super'] ?? false)) {
            return Response::text('Доступ запрещён. Требуется роль супер-администратора.', StatusCode::FORBIDDEN);
        }

        return $next($request);
    }
}