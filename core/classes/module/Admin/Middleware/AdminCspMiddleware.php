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


namespace Core\Module\Admin\Middleware;

use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\Middleware;

/**
 * Middleware для ослабления CSP в админ-панели.
 * Разрешает inline-стили и скрипты для работы WYSIWYG-редакторов.
 */
class AdminCspMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Переопределяем CSP для админки
        $csp = "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: blob:; "
             . "font-src 'self' data:;";

        $response->setHeader('Content-Security-Policy', $csp);

        return $response;
    }
}