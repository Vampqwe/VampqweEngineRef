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


namespace Core\System\Http;

use Core\System\Application\EngineState;
use Core\System\Application\InstalledGuard;

/**
 * Глобальный шлюз установки.
 *
 * Пропускает дальше только запросы, когда движок установлен
 * (есть storage/.installed И таблицы в БД) либо когда запрос
 * идёт на маршруты мастера установки / страницы-заглушки / health-проб.
 *
 * Это страхует сайт от фатальных ошибок вида
 * "SQLSTATE[42S02]: Table 'xxx.redirects' doesn't exist",
 * которые возникали при удалённом .installed и пустой БД:
 * вместо падения пользователь получает аккуратную страницу
 * с инструкцией перейти в админ-панель и выполнить установку.
 *
 * Различает два разных «нехороших» состояния:
 *  - NotInstalled   → заглушка «Движок не установлен» + ссылка на мастер;
 *  - DbUnreachable  → служебная страница «База временно недоступна»
 *                     (установка НЕ сломана, переустановка не требуется).
 */
class InstallGateMiddleware implements Middleware
{
    public function __construct(
        private readonly InstalledGuard $guard,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->getPath();

        // Внутренний путь заглушки: рендерим её напрямую, не выпуская
        // запрос к маршрутизатору/БД. Раньше '/not-installed' был в
        // whitelist без соответствующего маршрута → запрос уходил в
        // fallback (PageController) и падал на БД / давал 404.
        if ($path === '/not-installed') {
            return NotInstalledPage::response();
        }

        // Маршруты установки и health-пробы всегда доступны.
        if ($this->guard->isWhitelistedPath($path)) {
            return $next($request);
        }

        $state = $this->guard->state();

        if ($state === EngineState::Installed) {
            return $next($request);
        }

        // Движок установлен, но БД недоступна: это деградация сервиса,
        // а не приглашение переустанавливать движок.
        if ($state === EngineState::DbUnreachable) {
            return $this->dbUnavailableResponse($path);
        }

        return $this->notInstalledResponse($path);
    }

    private function notInstalledResponse(string $path): Response
    {
        if (str_starts_with($path, '/api/')) {
            return Response::json([
                'status'  => 'error',
                'code'    => 'engine_not_installed',
                'message' => 'Движок не установлен. Перейдите в админ панель и выполните установку.',
                'install_url' => '/admin/install',
            ], StatusCode::SERVICE_UNAVAILABLE)
                ->setHeader('X-Engine-State', EngineState::NotInstalled->value);
        }

        return NotInstalledPage::response();
    }

    private function dbUnavailableResponse(string $path): Response
    {
        if (str_starts_with($path, '/api/')) {
            return Response::json([
                'status'  => 'error',
                'code'    => 'database_unavailable',
                'message' => 'Сервис временно перегружен: нет соединения с базой данных. Установка не повреждена, повторите запрос позже.',
            ], StatusCode::SERVICE_UNAVAILABLE)
                ->setHeader('Retry-After', '30')
                ->setHeader('X-Engine-State', EngineState::DbUnreachable->value);
        }

        return DbUnavailablePage::response();
    }
}
