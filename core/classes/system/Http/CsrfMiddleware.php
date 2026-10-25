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

use Core\Module\Admin\Services\AdminService;
use Core\System\Security\SecurityManager;

/**
 * Единая CSRF-защита для всех небезопасных запросов (POST/PUT/PATCH/DELETE).
 *
 * Подключается к группам маршрутов (см. Router::group()), чтобы проверка
 * выполнялась в одном месте, а не дублировалась в каждом контроллере.
 * Токен берётся из поля `_csrf_token` (форма) либо заголовка `X-CSRF-Token`.
 */
class CsrfMiddleware implements Middleware
{
    public function __construct(
        private SecurityManager $security,
        private ?AdminService $flash = null
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$request->isSafe()) {
            // Токен принимается из поля формы (_csrf_token) либо AJAX-заголовка
            // X-CSRF-Token. Пустой/отсутствующий токен считается невалидным;
            // CsrfToken::validate() бросает SecurityException — перехватываем его
            // здесь, чтобы вернуть единый UX (flash + редирект), а не HTTP 500.
            $headerToken = $request->getHeader(Header::X_CSRF_TOKEN);
            $token = trim((string) ($request->input('_csrf_token', '') ?: ($headerToken ?? '')));

            $valid = false;
            if ($token !== '') {
                try {
                    $valid = $this->security->validateCsrf($token);
                } catch (\Throwable) {
                    $valid = false;
                }
            }

            if (!$valid) {
                // Единый UX с контроллерами админки: flash + редирект назад.
                $this->flash?->setFlash(
                    'error',
                    'Защита от подделки запроса: токен отсутствует или устарел. Повторите действие.'
                );
                return Response::redirect($this->safeReferer((string) $request->getReferer()));
            }
        }

        return $next($request);
    }

    /**
     * Куда возвращать пользователя после CSRF-ошибки: только на внутренний
     * GET-адрес из Referer (белый список префикса /admin), иначе — на дашборд.
     * Это сохраняет flash-сообщение на той странице, откуда был отправлен
     * запрос, и исключает redirect open-redirect по внешнему Referer.
     */
    private function safeReferer(string $referer): string
    {
        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($path, '/admin')) {
                return $path;
            }
        }

        return '/admin';
    }
}
