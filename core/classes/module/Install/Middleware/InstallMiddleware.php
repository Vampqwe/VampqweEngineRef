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


namespace Core\Module\Install\Middleware;

use Core\Module\Install\Services\InstallService;
use Core\System\Http\Middleware;
use Core\System\Http\Request;
use Core\System\Http\Response;

/**
 * Middleware мастера установки (защита от повторной установки).
 *
 * Порядок выполнения в группе /admin: InstallAccessMiddleware →
 * InstallMiddleware → AuthMiddleware. Сначала доступ к мастеру
 * проверяется по ключу активации/сессии администратора, затем здесь —
 * корректность перехода между шагами.
 */
class InstallMiddleware implements Middleware
{
    public function __construct(private readonly InstallService $installService)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Полный признак (метка + схема БД через InstalledGuard): если метка
        // есть, а базы нет — это НЕ «установлено», мастер должен быть доступен
        // для починки/переустановки.
        $isInstalled = $this->installService->isFullyInstalled();
        $isInstallRoute = str_starts_with($request->getPath(), '/admin/install');

        // Если сайт НЕ установлен и мы НЕ на маршрутах установки → редирект на установку
        if (!$isInstalled && !$isInstallRoute) {
            return Response::redirect('/admin/install');
        }

        // Если сайт УЖЕ установлен и GET-запрос к /admin/install → редирект на дашборд.
        // POST-запросы к установленному сайту блокирует InstallAccessMiddleware (403).
        if ($isInstalled && $isInstallRoute && $request->isGet()) {
            return Response::redirect('/admin/');
        }

        return $next($request);
    }
}
