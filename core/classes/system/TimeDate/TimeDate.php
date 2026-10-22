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

use Core\System\Config\Config;
use DateTimeImmutable;
use DateTimeZone;
use DateInterval;
use Throwable;

/**
 * Класс для работы с датой и временем.
 * Использует композицию с DateTimeImmutable.
 */
class TimeDate
{
    private DateTimeImmutable $dateTime;
    private Config $config;

    public function __construct(DateTimeImmutable $dateTime, Config $config)
    {
        $this->dateTime = $dateTime;
        $this->config = $config;
    }

    // ─── Статические конструкторы ───

    public static function now(Config $config): self
    {
        return new self(new DateTimeImmutable('now', self::timezoneFromConfig($config)), $config);
    }

    public static function fromString(string $dateString, Config $config, ?string $format = null): self
    {
        try {
            $timezone = self::timezoneFromConfig($config);

            if ($format !== null) {
                $dateTime = DateTimeImmutable::createFromFormat($format, $dateString, $timezone);

                if ($dateTime === false) {
                    throw TimeDateException::invalidFormat($dateString, $format);
                }
            } else {
                $dateTime = new DateTimeImmutable($dateString, $timezone);
            }

            return new self($dateTime, $config);

        } catch (Throwable $e) {
            if ($e instanceof TimeDateException) {
                throw $e;
            }
            throw TimeDateException::invalidDate($dateString);
        }
    }

    public static function fromTimestamp(int $timestamp, Config $config): self
    {
        $dateTime = (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::timezoneFromConfig($config));
        return new self($dateTime, $config);
    }

    public static function fromComponents(
        int $year,
        int $month,
        int $day,
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        Config $config
    ): self {
        $dateString = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $year, $month, $day, $hour, $minute, $second
        );

        return self::fromString($dateString, $config);
    }

    public static function fromDateTime(DateTimeImmutable $dateTime, Config $config): self
    {
        return new self($dateTime, $config);
    }

    // ─── Форматирование ───

    public function format(?string $format = null): string
    {
        $format = $format ?? $this->config->get('DATETIME_FORMAT', DateFormat::DateTime->value);
        return $this->dateTime->format($format);
    }

    public function toIso(): string
    {
        return $this->dateTime->format(DateFormat::ISO8601->value);
    }

    public function toDateString(): string
    {
        return $this->dateTime->format($this->config->get('DATE_FORMAT', DateFormat::Date->value));
    }

    public function toTimeString(): string
    {
        return $this->dateTime->format($this->config->get('TIME_FORMAT', DateFormat::Time->value));
    }

    public function toTimestamp(): int
    {
        return $this->dateTime->getTimestamp();
    }

    public function toDateTime(): DateTimeImmutable
    {
        return $this->dateTime;
    }

    // ─── Компоненты даты ───

    public function getYear(): int
    {
        return (int) $this->dateTime->format('Y');
    }

    public function getMonth(): int
    {
        return (int) $this->dateTime->format('m');
    }

    public function getDay(): int
    {
        return (int) $this->dateTime->format('d');
    }

    public function getHour(): int
    {
        return (int) $this->dateTime->format('H');
    }

    public function getMinute(): int
    {
        return (int) $this->dateTime->format('i');
    }

    public function getSecond(): int
    {
        return (int) $this->dateTime->format('s');
    }

    public function getDayOfWeek(): int
    {
        return (int) $this->dateTime->format('w');
    }

    public function getDayOfYear(): int
    {
        return (int) $this->dateTime->format('z') + 1;
    }

    public function getWeekOfYear(): int
    {
        return (int) $this->dateTime->format('W');
    }

    public function isLeapYear(): bool
    {
        return $this->dateTime->format('L') === '1';
    }

    public function getDaysInMonth(): int
    {
        return (int) $this->dateTime->format('t');
    }

    // ─── Арифметика дат ───

    public function add(int $value, TimeUnit $unit): self
    {
        $intervalString = $unit->toIntervalString($value);
        $interval = new DateInterval($intervalString);
        $newDateTime = $this->dateTime->add($interval);

        return new self($newDateTime, $this->config);
    }

    public function sub(int $value, TimeUnit $unit): self
    {
        $intervalString = $unit->toIntervalString($value);
        $interval = new DateInterval($intervalString);
        $newDateTime = $this->dateTime->sub($interval);

        return new self($newDateTime, $this->config);
    }

    public function diff(TimeDate $other, TimeUnit $unit = TimeUnit::Second): int
    {
        $diffSeconds = $this->dateTime->getTimestamp() - $other->dateTime->getTimestamp();
        return (int) ($diffSeconds / $unit->toSeconds());
    }

    // ─── Сравнение ───

    public function isBefore(TimeDate $other): bool
    {
        return $this->dateTime < $other->dateTime;
    }

    public function isAfter(TimeDate $other): bool
    {
        return $this->dateTime > $other->dateTime;
    }

    public function equals(TimeDate $other): bool
    {
        return $this->dateTime == $other->dateTime;
    }

    public function isBetween(TimeDate $start, TimeDate $end): bool
    {
        return $this->dateTime >= $start->dateTime && $this->dateTime <= $end->dateTime;
    }

    public function isToday(): bool
    {
        $today = self::now($this->config);
        return $this->toDateString() === $today->toDateString();
    }

    public function isYesterday(): bool
    {
        $yesterday = self::now($this->config)->sub(1, TimeUnit::Day);
        return $this->toDateString() === $yesterday->toDateString();
    }

    public function isTomorrow(): bool
    {
        $tomorrow = self::now($this->config)->add(1, TimeUnit::Day);
        return $this->toDateString() === $tomorrow->toDateString();
    }

    // ─── Модификаторы ───

    public function startOfDay(): self
    {
        return self::fromComponents(
            $this->getYear(),
            $this->getMonth(),
            $this->getDay(),
            0, 0, 0,
            $this->config
        );
    }

    public function endOfDay(): self
    {
        return self::fromComponents(
            $this->getYear(),
            $this->getMonth(),
            $this->getDay(),
            23, 59, 59,
            $this->config
        );
    }

    public function startOfMonth(): self
    {
        return self::fromComponents(
            $this->getYear(),
            $this->getMonth(),
            1,
            0, 0, 0,
            $this->config
        );
    }

    public function endOfMonth(): self
    {
        return self::fromComponents(
            $this->getYear(),
            $this->getMonth(),
            $this->getDaysInMonth(),
            23, 59, 59,
            $this->config
        );
    }

    public function startOfYear(): self
    {
        return self::fromComponents(
            $this->getYear(),
            1,
            1,
            0, 0, 0,
            $this->config
        );
    }

    public function endOfYear(): self
    {
        return self::fromComponents(
            $this->getYear(),
            12,
            31,
            23, 59, 59,
            $this->config
        );
    }

    public function setTimezone(string $timezone): self
    {
        try {
            $tz = new DateTimeZone($timezone);
            $newDateTime = $this->dateTime->setTimezone($tz);
            return new self($newDateTime, $this->config);
        } catch (Throwable $e) {
            throw TimeDateException::invalidTimezone($timezone);
        }
    }

    public function getTimezone(): string
    {
        return $this->dateTime->getTimezone()->getName();
    }

    // ─── Приватные методы ───

    private static function timezoneFromConfig(Config $config): DateTimeZone
    {
        $timezone = $config->get('TIMEZONE', 'UTC');

        try {
            return new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw TimeDateException::invalidTimezone($timezone);
        }
    }

    public function __toString(): string
    {
        return $this->format();
    }
}