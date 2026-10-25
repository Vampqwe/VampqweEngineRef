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
use Core\System\Http\StatusCode;
use Core\System\Security\SecurityManager;
use Core\System\Session\SessionManager;

/**
 * Middleware доступа к мастеру установки.
 *
 * Закрывает критические уязвимости мастера:
 *  1. Мастер больше не доступен анонимно: доступ разрешён только
 *     авторизованному администратору (супер-админу или пользователю
 *     с ролью admin/super_admin) Либо держателю одноразового ключа
 *     активации (?key=... либо заголовок X-Install-Key). Ключ нужен
 *     для первоначальной установки, когда учётных записей в БД ещё нет.
 *  2. POST-запросы проходят обязательную CSRF-проверку.
 *  3. Попытки доступа без полномочий ограничиваются rate limiter'ом,
 *     чтобы исключить брутфорс ключа активации.
 */
class InstallAccessMiddleware implements Middleware
{
    private const RATE_LIMIT_KEY_PREFIX = 'admin:install:';
    private const KEY_HEADER = 'X-Install-Key';
    /** TTL подтверждения ключа в сессии (секунды). Без TTL украденная до
     *  подтверждения cookie давала бы бессрочный доступ к мастеру. */
    private const SESSION_VERIFY_TTL = 1800;
    private const SESSION_VERIFY_TIME_KEY = 'install_key_verified_at';

    public function __construct(
        private readonly InstallService $installService,
        private readonly SecurityManager $security,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Уже установленный сайт: мастер закрыт полностью.
        // (Исключение — GET-редиректы оставлены InstallMiddleware,
        //  сюда доходят только POST, которые раньше позволяли
        //  перезаписать живой config.env и БД.)
        // ВАЖНО: используется детерминированная метка .installed, а НЕ
        // полный guard-признак: при недоступной БД на пустом сервере
        // guard вернул бы «установлен» и навсегда запер мастер (403)
        // даже для первичной установки.
        if ($this->installService->isInstalled()) {
            return $this->forbidden('Установка уже выполнена. Мастер установки недоступен.');
        }

        // Авторизованный администратор может работать с мастером без ключа.
        if ($this->isAdminSessionAuthorized()) {
            return $this->handleCsrf($request, $next);
        }

        // Ключ уже был подтверждён ранее в этой сессии браузера —
        // пропускаем дальше без ключа и без расходования rate limit.
        if ($this->isInstallVerifiedInSession()) {
            return $this->handleCsrf($request, $next);
        }

        // Иначе требуется корректный ключ активации.
        if (!$this->isValidActivationKey($request)) {
            $this->audit('install_access_denied', $request);

            return $this->forbidden(
                'Мастер установки защищён. Для доступа передайте ключ активации '
                . '(параметр ?key=... или заголовок ' . self::KEY_HEADER . '), '
                . 'либо войдите как администратор.'
            );
        }

        // Ключ подтверждён: запоминаем факт прохода в PHP-сессии браузера
        // (session_id — не угадываемый идентификатор из cookie). Дальше
        // мастер работает по сессии без ключа в URL. Это решает проблему
        // хостингов за балансировщиком/CDN, где REMOTE_ADDR меняется от
        // запроса к запросу и любая привязка к IP ненадёжна.
        $this->markInstallVerified();

        // Если ключ пришёл в URL (?key=...) — редиректим на тот же путь без
        // параметра, чтобы секрет не остался в истории браузера, логах
        // веб-сервера и Referer-заголовках внешних ресурсов.
        $keyInQuery = trim((string) $request->query('key', '')) !== '';
        if ($keyInQuery && $request->isSafe()) {
            $location = $request->getPath();
            $query = $request->allQuery();
            unset($query['key']);
            if ($query !== []) {
                $location .= '?' . http_build_query($query);
            }

            return Response::redirect($location);
        }

        return $this->handleCsrf($request, $next);
    }

    /**
     * Был ли когда-либо подтверждён ключ активации в текущей сессии браузера.
     * Флаг действует ограниченное время (SESSION_VERIFY_TTL) — по истечении
     * TTL ключ требуется снова.
     */
    private function isInstallVerifiedInSession(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        if ($this->session()->get('install_key_verified') !== true) {
            return false;
        }

        $verifiedAt = (int) $this->session()->get(self::SESSION_VERIFY_TIME_KEY, 0);
        if ($verifiedAt <= 0 || (time() - $verifiedAt) > self::SESSION_VERIFY_TTL) {
            // TTL истёк (или флаг записан старой версией без метки времени) —
            // снимаем полномочие и требуем повторный ввод ключа.
            $this->session()->remove('install_key_verified');
            $this->session()->remove(self::SESSION_VERIFY_TIME_KEY);

            return false;
        }

        return true;
    }

    /**
     * Обязательная CSRF-проверка для всех изменяющих запросов.
     */
    private function handleCsrf(Request $request, callable $next): Response
    {
        if (!$request->isSafe()) {
            try {
                $this->security->validateCsrf($request->input('_csrf_token', ''));
            } catch (\Throwable) {
                $this->audit('install_csrf_failed', $request);

                return $this->forbidden('Недействительный CSRF-токен. Обновите страницу мастера и повторите действие.');
            }
        }

        return $next($request);
    }

    /**
     * Запись security-события в аудит-лог (с маскированием чувствительных данных).
     */
    private function audit(string $event, Request $request): void
    {
        try {
            $this->security->getAuditLogger()->logAction(
                $event,
                'install_wizard',
                null,
                ['ip' => $request->getIp(), 'path' => $request->getPath()]
            );
        } catch (\Throwable) {
            // Аудит не должен ломать защиту — молча пропускаем.
        }
    }

    /** Единая точка доступа к сессии модуля (без прямых обращений к $_SESSION). */
    private function session(): SessionManager
    {
        static $manager = null;
        return $manager ??= new SessionManager();
    }

    private function markInstallVerified(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->session()->set('install_key_verified', true);
            $this->session()->set(self::SESSION_VERIFY_TIME_KEY, time());
        }
    }

    private function isAdminSessionAuthorized(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        /** @var array{role?: string, is_super?: bool}|null $user */
        $user = $this->session()->adminUser();
        if ($user === null) {
            return false;
        }

        return ($user['is_super'] ?? false) === true
            || in_array($user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    private function isValidActivationKey(Request $request): bool
    {
        // Rate limit против брутфорса ключа (по IP).
        $rateLimit = $this->security->attempt(self::RATE_LIMIT_KEY_PREFIX . $request->getIp());
        if (!$rateLimit['allowed']) {
            return false;
        }

        $provided = trim((string) $request->query('key', ''));
        if ($provided === '') {
            $provided = trim((string) $request->getHeader(self::KEY_HEADER, ''));
        }

        if ($provided === '') {
            return false;
        }

        // Вариант 1: открытый ключ в config.env (первоначальная установка).
        $expected = $this->installService->getActivationKey();
        if ($expected !== null && hash_equals($expected, $provided)) {
            return true;
        }

        // Вариант 2: хэш ключа в storage/.install_key (смена ключа после установки).
        return $this->installService->verifyStoredActivationKey($provided);
    }

    private function forbidden(string $message): Response
    {
        return Response::text($message, StatusCode::FORBIDDEN);
    }
}
