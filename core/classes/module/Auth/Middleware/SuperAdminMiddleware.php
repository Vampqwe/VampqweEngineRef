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
            // Визуальная страница 403 в стиле админки (без утечки деталей роли).
            return Response::html(
                '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>403 — Доступ запрещён</title>'
                . '<link rel="stylesheet" href="/assets/css/admin.css"></head><body>'
                . '<div style="display:flex;align-items:center;justify-content:center;'
                . 'min-height:100vh;font-family:system-ui,sans-serif;text-align:center;padding:1rem">'
                . '<div><h1 style="font-size:4rem;margin:0;color:#dc2626">403</h1>'
                . '<p style="font-size:1.25rem;margin:.5rem 0">Доступ запрещён</p>'
                . '<p style="color:#64748b">Эта страница доступна только супер-администратору.</p>'
                . '<p style="margin-top:1.5rem"><a href="/admin/" '
                . 'style="color:#2563eb;text-decoration:none">&larr; Вернуться в админ-панель</a></p>'
                . '</div></div></body></html>',
                StatusCode::FORBIDDEN
            );
        }

        return $next($request);
    }
}