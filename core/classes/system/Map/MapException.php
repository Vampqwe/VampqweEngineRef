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


namespace Core\System\Map;

use RuntimeException;

/**
 * Исключение для операций с Map
 */
class MapException extends RuntimeException
{
    public static function keyNotFound(string|int $key): self
    {
        return new self("Ключ не найден: {$key}");
    }

    public static function emptyCollection(string $operation): self
    {
        return new self("Нельзя выполнить операцию '{$operation}' на пустой коллекции");
    }

    public static function invalidCallback(string $message): self
    {
        return new self("Неверный callback: {$message}");
    }
}