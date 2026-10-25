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

/**
 * Enum HTTP-статус кодов
 */
enum StatusCode: int
{
    // 1xx Informational
    case CONTINUE            = 100;
    case SWITCHING_PROTOCOLS = 101;
    case PROCESSING          = 102;

    // 2xx Success
    case OK                  = 200;
    case CREATED             = 201;
    case ACCEPTED            = 202;
    case NO_CONTENT          = 204;
    case RESET_CONTENT       = 205;
    case PARTIAL_CONTENT     = 206;

    // 3xx Redirection
    case MOVED_PERMANENTLY   = 301;
    case FOUND               = 302;
    case SEE_OTHER           = 303;
    case NOT_MODIFIED        = 304;
    case TEMPORARY_REDIRECT  = 307;
    case PERMANENT_REDIRECT  = 308;

    // 4xx Client Error
    case BAD_REQUEST         = 400;
    case UNAUTHORIZED        = 401;
    case FORBIDDEN           = 403;
    case NOT_FOUND           = 404;
    case METHOD_NOT_ALLOWED  = 405;
    case NOT_ACCEPTABLE      = 406;
    case CONFLICT            = 409;
    case GONE                = 410;
    case TOO_LONG            = 414;
    case TOO_MANY_REQUESTS   = 429;

    // 5xx Server Error
    case INTERNAL_ERROR      = 500;
    case NOT_IMPLEMENTED     = 501;
    case BAD_GATEWAY         = 502;
    case SERVICE_UNAVAILABLE = 503;
    case GATEWAY_TIMEOUT     = 504;

    /**
     * Является ли код успешным (2xx)
     */
    public function isSuccessful(): bool
    {
        return $this->value >= 200 && $this->value < 300;
    }

    /**
     * Является ли код редиректом (3xx)
     */
    public function isRedirect(): bool
    {
        return $this->value >= 300 && $this->value < 400;
    }

    /**
     * Является ли код ошибкой клиента (4xx)
     */
    public function isClientError(): bool
    {
        return $this->value >= 400 && $this->value < 500;
    }

    /**
     * Является ли код ошибкой сервера (5xx)
     */
    public function isServerError(): bool
    {
        return $this->value >= 500 && $this->value < 600;
    }

    /**
     * Является ли код ошибкой (4xx или 5xx)
     */
    public function isError(): bool
    {
        return $this->isClientError() || $this->isServerError();
    }

    /**
     * Получить стандартное описание статуса
     */
    public function reasonPhrase(): string
    {
        return match ($this) {
            self::CONTINUE            => 'Continue',
            self::SWITCHING_PROTOCOLS => 'Switching Protocols',
            self::PROCESSING          => 'Processing',
            self::OK                  => 'OK',
            self::CREATED             => 'Created',
            self::ACCEPTED            => 'Accepted',
            self::NO_CONTENT          => 'No Content',
            self::RESET_CONTENT       => 'Reset Content',
            self::PARTIAL_CONTENT     => 'Partial Content',
            self::MOVED_PERMANENTLY   => 'Moved Permanently',
            self::FOUND               => 'Found',
            self::SEE_OTHER           => 'See Other',
            self::NOT_MODIFIED        => 'Not Modified',
            self::TEMPORARY_REDIRECT  => 'Temporary Redirect',
            self::PERMANENT_REDIRECT  => 'Permanent Redirect',
            self::BAD_REQUEST         => 'Bad Request',
            self::UNAUTHORIZED        => 'Unauthorized',
            self::FORBIDDEN           => 'Forbidden',
            self::NOT_FOUND           => 'Not Found',
            self::METHOD_NOT_ALLOWED  => 'Method Not Allowed',
            self::NOT_ACCEPTABLE      => 'Not Acceptable',
            self::CONFLICT            => 'Conflict',
            self::GONE                => 'Gone',
            self::TOO_LONG            => 'Request-URI Too Long',
            self::TOO_MANY_REQUESTS   => 'Too Many Requests',
            self::INTERNAL_ERROR      => 'Internal Server Error',
            self::NOT_IMPLEMENTED     => 'Not Implemented',
            self::BAD_GATEWAY         => 'Bad Gateway',
            self::SERVICE_UNAVAILABLE => 'Service Unavailable',
            self::GATEWAY_TIMEOUT     => 'Gateway Timeout',
        };
    }
}