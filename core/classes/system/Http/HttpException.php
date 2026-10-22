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

use RuntimeException;

/**
 * Исключение для HTTP-операций
 */
class HttpException extends RuntimeException
{
    private StatusCode $statusCode;

    public function __construct(StatusCode $statusCode, string $message = '', ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message ?: $statusCode->reasonPhrase(), $statusCode->value, $previous);
    }

    public function getStatusCode(): StatusCode
    {
        return $this->statusCode;
    }

    /**
     * Создать исключение для 400 Bad Request
     */
    public static function badRequest(string $message = 'Bad Request'): self
    {
        return new self(StatusCode::BAD_REQUEST, $message);
    }

    /**
     * Создать исключение для 401 Unauthorized
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(StatusCode::UNAUTHORIZED, $message);
    }

    /**
     * Создать исключение для 403 Forbidden
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(StatusCode::FORBIDDEN, $message);
    }

    /**
     * Создать исключение для 404 Not Found
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(StatusCode::NOT_FOUND, $message);
    }

    /**
     * Создать исключение для 405 Method Not Allowed
     */
    public static function methodNotAllowed(string $message = 'Method Not Allowed'): self
    {
        return new self(StatusCode::METHOD_NOT_ALLOWED, $message);
    }

    /**
     * Создать исключение для 429 Too Many Requests
     */
    public static function tooManyRequests(string $message = 'Too Many Requests'): self
    {
        return new self(StatusCode::TOO_MANY_REQUESTS, $message);
    }

    /**
     * Создать исключение для 500 Internal Server Error
     */
    public static function internalError(string $message = 'Internal Server Error'): self
    {
        return new self(StatusCode::INTERNAL_ERROR, $message);
    }
}