<?php

declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
 *
 * Данный код является интеллектуальной собственностью автора.
 * Любое копирование, распространение, модификация или использование
 * без письменного разрешения правообладателя строго запрещено.
 *
 * This software is proprietary and confidential.
 * Unauthorized copying, distribution, or use is strictly prohibited.
 */

namespace Core\System\Http;

use Core\System\Security\RateLimiter;

/**
 * Глобальный rate-limit middleware.
 *
 * Опирается на существующее хранилище Core\System\Security\RateLimiter
 * (файловые счётчики с flock), но применяет лимит ко ВСЕМ HTTP-запросам,
 * а не только к точечным местам (логин, установщик).
 *
 * Поведение:
 *  - ключ: ip + нормализованный путь (разные секции сайта не «отравляют»
 *    друг друга одним всплеском); опционально добавляется user id;
 *  - при превышении лимита — 429 Too Many Requests с заголовками
 *    Retry-After / X-RateLimit-Limit / X-RateLimit-Remaining;
 *  - HTML-запросы получают короткую страницу, API (.json) — JSON;
 *  - fail-open: сбой хранилища лимитера НЕ должен ронять сайт —
 *    запрос пропускается дальше (защита от превращения безопасности
 *    в отказоустойчивость).
 */
class RateLimitMiddleware implements Middleware
{
    private const EXEMPT_PREFIXES = ['/admin', '/install'];

    public function __construct(
        private RateLimiter $limiter,
        private bool $includeUserId = false
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Точечные лимиты (логин, установщик) уже применяются внутри
        // своих контроллеров/провайдеров — глобально админку и мастера
        // установки не считаем, чтобы не мешать легитимной работе.
        if ($this->isExempt($request->getPath())) {
            return $next($request);
        }

        $key = 'global:' . $this->buildKey($request);

        try {
            $result = $this->limiter->attempt($key);
        } catch (\Throwable) {
            // fail-open: проблемы хранилища лимитера не должны валить сайт.
            return $next($request);
        }

        if (!($result['allowed'] ?? true)) {
            return $this->tooManyRequests($result);
        }

        $response = $next($request);

        // Информативные заголовки для хорошо себя ведущих клиентов
        // (полезно для парсеров и отладки). Remaining может быть большим
        // (PHP_INT_MAX при выключенном лимитере) — пишем разумный максимум.
        $remaining = min(max((int) ($result['remaining'] ?? 0), 0), 1_000_000);
        $limit = (int) ($result['limit'] ?? 0);
        if ($limit > 0) {
            $response->setHeader('X-RateLimit-Limit', (string) $limit);
        }
        $response->setHeader('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }

    private function isExempt(string $path): bool
    {
        $normalized = rtrim($path, '/') ?: '/';
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ключ окна лимита: ip + путь (+ user id при включённой опции).
     */
    private function buildKey(Request $request): string
    {
        $path = strtolower(rtrim(rawurldecode($request->getPath()), '/')) ?: '/';

        $key = $request->getIp() . '|' . $path;

        if ($this->includeUserId) {
            $uid = session_status() === PHP_SESSION_ACTIVE
                ? ($_SESSION['user_id'] ?? null)
                : null;
            if ($uid !== null) {
                $key .= '|u' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $uid);
            }
        }

        // Сам ключ используется как имя файла в хранилище RateLimiter —
        // берём хэш, чтобы избежать проблем с длиной/спецсимволами.
        return hash('sha256', $key);
    }

    private function tooManyRequests(array $result): Response
    {
        $retryAfter = max(1, (int) ($result['retry_after'] ?? 60));

        $body = "Слишком много запросов. Повторите попытку позже.\n";
        $contentType = 'text/plain; charset=UTF-8';

        if (!$this->wantsHtml()) {
            $body = json_encode(
                ['error' => 'rate_limited', 'retry_after' => $retryAfter],
                JSON_UNESCAPED_UNICODE
            ) ?: '{"error":"rate_limited"}';
            $contentType = 'application/json; charset=UTF-8';
        }

        $response = new Response($body, StatusCode::TOO_MANY_REQUESTS, [
            'Content-Type' => $contentType,
            'Retry-After'  => (string) $retryAfter,
            'Cache-Control' => 'no-store',
        ]);
        $response->setHeader('X-RateLimit-Remaining', '0');

        return $response;
    }

    /**
     * HTML-клиент (браузер) или программный (curl/api)? Смотрим Accept
     * текущего запроса через globals — Request передаётся в handle(), но
     * метод вызывается и из тестов без объекта запроса в 429-ветке,
     * поэтому читаем $_SERVER напрямую.
     */
    private function wantsHtml(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if ($accept === '') {
            return true; // без Accept считаем браузером (безопаснее по UX)
        }
        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml');
    }
}
