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


use Core\System\Application\Application;
use Core\System\DataBase\DataBase;
use Core\System\Logger\Logger;
use Core\System\Security\CsrfToken;
use Core\System\Security\RateLimiter;
use Core\System\Security\SecurityManager;
use Core\System\TimeDate\TimeDate;
use Core\System\Validator\Validator;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Template\Template;
use Core\System\Error\ErrorHandler;

if (!function_exists('error_handler')) {
    function error_handler(): ErrorHandler
    {
        return app(ErrorHandler::class);
    }
}

if (!function_exists('request')) {
    /**
     * Получить текущий HTTP-запрос
     */
    function request(): Request
    {
        return app(Request::class);
    }
}

if (!function_exists('response')) {
    /**
     * Создать HTTP-ответ
     */
    function response(string $content = '', int $statusCode = 200): Response
    {
        $status = \Core\System\Http\StatusCode::from($statusCode);
        return new Response($content, $status);
    }
}

if (!function_exists('json')) {
    /**
     * Создать JSON-ответ
     */
    function json(mixed $data, int $statusCode = 200): Response
    {
        return Response::json($data, \Core\System\Http\StatusCode::from($statusCode));
    }
}

if (!function_exists('redirect')) {
    /**
     * Создать редирект
     */
    function redirect(string $url, int $statusCode = 302): Response
    {
        return Response::redirect($url, \Core\System\Http\StatusCode::from($statusCode));
    }
}

if (!function_exists('download')) {
    /**
     * Отправить файл на скачивание
     */
    function download(string $filePath, ?string $fileName = null): Response
    {
        return Response::download($filePath, $fileName);
    }
}

if (!function_exists('abort')) {
    /**
     * Выбросить HTTP-исключение
     */
    function abort(int $code, string $message = ''): never
    {
        throw \Core\System\Http\HttpException::from(new \Core\System\Http\StatusCode($code), $message);
    }
}

if (!function_exists('input')) {
    /**
     * Получить параметр из запроса
     */
    function input(string $key, mixed $default = null): mixed
    {
        return request()->input($key, $default);
    }
}

if (!function_exists('query')) {
    /**
     * Получить параметр из query string
     */
    function query(string $key, mixed $default = null): mixed
    {
        return request()->query($key, $default);
    }
}

if (!function_exists('post')) {
    /**
     * Получить параметр из POST
     */
    function post(string $key, mixed $default = null): mixed
    {
        return request()->post($key, $default);
    }
}

if (!function_exists('header')) {
    /**
     * Получить заголовок запроса
     */
    function header(string $name, ?string $default = null): ?string
    {
        return request()->getHeader($name, $default);
    }
}

if (!function_exists('cookie')) {
    /**
     * Получить куку
     */
    function cookie(string $name, mixed $default = null): mixed
    {
        return request()->cookie($name, $default);
    }
}
if (!function_exists('app')) {
    /**
     * @template T of object
     * @param class-string<T>|null $abstract
     * @return Application|T
     */
    function app(?string $abstract = null): mixed
    {
        $app = Application::getInstance();

        if ($abstract === null) {
            return $app;
        }

        return $app->make($abstract);
    }
}

if (!function_exists('config')) {
    function config(string $key, ?string $default = null): ?string
    {
        return app()->getConfig()->get($key, $default);
    }
}

if (!function_exists('logger')) {
    function logger(): Logger
    {
        return app(Logger::class);
    }
}

if (!function_exists('db')) {
    function db(): DataBase
    {
        return app(DataBase::class);
    }
}

if (!function_exists('now')) {
    function now(): TimeDate
    {
        return TimeDate::now(app()->getConfig());
    }
}

if (!function_exists('validator')) {
    /**
     * @param array<string, mixed> $data
     */
    function validator(array $data = []): Validator
    {
        return Validator::make($data);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return app()->path($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return app()->storagePath($path);
    }
}

if (!function_exists('logs_path')) {
    function logs_path(string $path = ''): string
    {
        return app()->logsPath($path);
    }
}

// ─── Security helpers ───

if (!function_exists('security')) {
    function security(): SecurityManager
    {
        return app(SecurityManager::class);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(SecurityManager::class)->getCsrfToken()->getToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return app(SecurityManager::class)->getCsrfToken()->getField();
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string
    {
        return app(SecurityManager::class)->getCsrfToken()->getMetaTag();
    }
}

if (!function_exists('validate_csrf')) {
    function validate_csrf(?string $token = null): bool
    {
        return app(SecurityManager::class)->getCsrfToken()->validate($token);
    }
}

if (!function_exists('rate_limit')) {
    /**
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    function rate_limit(string $key): array
    {
        return app(SecurityManager::class)->getRateLimiter()->attempt($key);
    }
}

if (!function_exists('sanitize_path')) {
    function sanitize_path(string $path): string
    {
        return app(SecurityManager::class)->sanitizePath($path);
    }
}

if (!function_exists('audit')) {
    function audit(): \Core\System\Security\AuditLogger
    {
        return app(SecurityManager::class)->getAuditLogger();
    }
}

if (!function_exists('mask_sensitive')) {
    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    function mask_sensitive(array $data): array
    {
        return app(SecurityManager::class)->maskSensitive($data);
    }
}

if (!function_exists('template')) {
    function template(): Template
    {
        return app(Template::class);
    }
}

if (!function_exists('view')) {
    function view(string $name, array $data = []): string
    {
        return app(Template::class)->render($name, $data);
    }
}

if (!function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return app(Template::class)->getCspNonce();
    }
}