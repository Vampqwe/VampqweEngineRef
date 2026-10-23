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

use Core\Module\Auth\Middleware\AuthMiddleware;
use Core\Module\Install\Middleware\InstallMiddleware;
use Core\Module\Install\Services\InstallService;
use Core\System\Http\CsrfMiddleware;
use Core\System\Http\Middleware;
use Core\System\Http\MiddlewareStack;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Security\SecurityManager;

/**
 * Композитный middleware группы /admin.
 *
 * Исправлена критическая ошибка старой реализации: каждый из middleware
 * вызывал $next независимо, в результате финальный обработчик (контроллер)
 * выполнялся ДВАЖДЫ, а результат install-проверки терялся. Теперь цепочка
 * собирается через MiddlewareStack и обрабатывается строго последовательно:
 * InstallMiddleware → AuthMiddleware → CsrfMiddleware → AdminCspMiddleware → контроллер.
 */
class AdminMiddleware implements Middleware
{
    private MiddlewareStack $stack;

    public function __construct(InstallService $installService, SecurityManager $security)
    {
        $this->stack = (new MiddlewareStack())
            ->push(new InstallMiddleware($installService))
            ->push(new AuthMiddleware())
            ->push(new CsrfMiddleware($security))
            ->push(new AdminCspMiddleware());
    }

    public function handle(Request $request, callable $next): Response
    {
        return $this->stack->process($request, $next);
    }
}