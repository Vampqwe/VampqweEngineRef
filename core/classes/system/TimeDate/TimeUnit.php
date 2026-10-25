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

/**
 * Enum единиц времени для арифметики дат
 */
enum TimeUnit: string
{
    case Second = 'second';
    case Minute = 'minute';
    case Hour   = 'hour';
    case Day    = 'day';
    case Week   = 'week';
    case Month  = 'month';
    case Year   = 'year';

    /**
     * Получить множитель для конвертации в секунды
     */
    public function toSeconds(): int
    {
        return match ($this) {
            self::Second => 1,
            self::Minute => 60,
            self::Hour   => 3600,
            self::Day    => 86400,
            self::Week   => 604800,
            self::Month  => 2592000,
            self::Year   => 31536000,
        };
    }

    /**
     * Получить строку для DateInterval
     */
    public function toIntervalString(int $value): string
    {
        $unit = match ($this) {
            self::Second => 'S',
            self::Minute => 'M',
            self::Hour   => 'H',
            self::Day    => 'D',
            self::Week   => 'W',
            self::Month  => 'M',
            self::Year   => 'Y',
        };

        $prefix = match ($this) {
            self::Second, self::Minute, self::Hour => 'PT',
            default => 'P',
        };

        return "{$prefix}{$value}{$unit}";
    }
}