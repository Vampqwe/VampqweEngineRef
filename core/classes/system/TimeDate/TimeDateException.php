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


namespace Core\System\TimeDate;

use RuntimeException;

/**
 * Исключение для операций с датой и временем
 */
class TimeDateException extends RuntimeException
{
    public static function invalidFormat(string $date, string $format): self
    {
        return new self("Неверный формат даты '{$date}' для формата '{$format}'");
    }

    public static function invalidTimezone(string $timezone): self
    {
        return new self("Неверный часовой пояс: {$timezone}");
    }

    public static function invalidDate(string $date): self
    {
        return new self("Некорректная дата: {$date}");
    }
}