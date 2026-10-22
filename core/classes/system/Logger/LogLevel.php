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


namespace Core\System\Logger;

/**
 * Enum уровней логирования (совместим с PSR-3)
 */
enum LogLevel: string
{
    case Debug     = 'DEBUG';
    case Info      = 'INFO';
    case Notice    = 'NOTICE';
    case Warning   = 'WARNING';
    case Error     = 'ERROR';
    case Critical  = 'CRITICAL';
    case Alert     = 'ALERT';
    case Emergency = 'EMERGENCY';
    case Dump      = 'DUMP';

    public function priority(): int
    {
        return match ($this) {
            self::Debug     => 100,
            self::Dump      => 150,
            self::Info      => 200,
            self::Notice    => 250,
            self::Warning   => 300,
            self::Error     => 400,
            self::Critical  => 500,
            self::Alert     => 550,
            self::Emergency => 600,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Debug     => "\033[37m",
            self::Dump      => "\033[36m",
            self::Info      => "\033[32m",
            self::Notice    => "\033[34m",
            self::Warning   => "\033[33m",
            self::Error     => "\033[31m",
            self::Critical  => "\033[35m",
            self::Alert     => "\033[1;31m",
            self::Emergency => "\033[1;41m",
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Debug     => '🔍',
            self::Dump      => '📦',
            self::Info      => 'ℹ️ ',
            self::Notice    => '📝',
            self::Warning   => '⚠️ ',
            self::Error     => '❌',
            self::Critical  => '🔥',
            self::Alert     => '🚨',
            self::Emergency => '💥',
        };
    }

    public static function fromString(string $level): self
    {
        $level = strtoupper(trim($level));

        foreach (self::cases() as $case) {
            if ($case->value === $level) {
                return $case;
            }
        }

        throw LoggerException::invalidLevel($level);
    }
}