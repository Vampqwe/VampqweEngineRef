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
 * Enum HTTP-методов
 */
enum Method: string
{
    case GET     = 'GET';
    case POST    = 'POST';
    case PUT     = 'PUT';
    case PATCH   = 'PATCH';
    case DELETE  = 'DELETE';
    case OPTIONS = 'OPTIONS';
    case HEAD    = 'HEAD';
    case CONNECT = 'CONNECT';
    case TRACE   = 'TRACE';

    /**
     * Метод безопасен (не изменяет состояние сервера)
     * GET, HEAD, OPTIONS, TRACE — только чтение
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * Метод идемпотентен (повторный вызов = тот же результат)
     * GET, HEAD, PUT, DELETE, OPTIONS, TRACE — идемпотентны
     * POST, PATCH — не идемпотентны
     */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * Метод может иметь тело запроса
     * POST, PUT, PATCH, DELETE, OPTIONS — могут иметь тело
     * GET, HEAD — не должны иметь тело
     */
    public function canHaveBody(): bool
    {
        return match ($this) {
            self::POST, self::PUT, self::PATCH, self::DELETE, self::OPTIONS => true,
            default => false,
        };
    }

    /**
     * Создать Method из строки
     *
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $method): self
    {
        $method = strtoupper(trim($method));

        foreach (self::cases() as $case) {
            if ($case->value === $method) {
                return $case;
            }
        }

        throw new \InvalidArgumentException("Неизвестный HTTP-метод: {$method}");
    }

    /**
     * Получить все "безопасные" методы
     *
     * @return self[]
     */
    public static function safeMethods(): array
    {
        return array_filter(self::cases(), fn(self $m) => $m->isSafe());
    }

    /**
     * Получить все "идемпотентные" методы
     *
     * @return self[]
     */
    public static function idempotentMethods(): array
    {
        return array_filter(self::cases(), fn(self $m) => $m->isIdempotent());
    }

    /**
     * Получить все методы с телом
     *
     * @return self[]
     */
    public static function methodsWithBody(): array
    {
        return array_filter(self::cases(), fn(self $m) => $m->canHaveBody());
    }

    /**
     * Проверить, является ли метод "мутацией" (изменяет данные)
     */
    public function isMutating(): bool
    {
        return !$this->isSafe();
    }
}