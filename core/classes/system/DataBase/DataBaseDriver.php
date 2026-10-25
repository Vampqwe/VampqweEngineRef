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


namespace Core\System\DataBase;

/**
 * Enum поддерживаемых драйверов БД
 *
 * ПОЛИТИКА ДВИЖКА: рабочее приложение поддерживает ТОЛЬКО MySQL/MariaDB.
 * PostgreSQL и SQLite оставлены как дополнительные (неактивные) драйверы —
 * они не могут быть выбраны для боевого подключения (см. fromString()).
 */
enum DataBaseDriver: string
{
    case MySQL      = 'mysql';
    /** @deprecated Неактивный дополнительный драйвер. Для работы движка не используется. */
    case PostgreSQL = 'pgsql';
    /** @deprecated Неактивный дополнительный драйвер. Для работы движка не используется. */
    case SQLite     = 'sqlite';

    /**
     * Драйвер, разрешённый для работы приложения.
     */
    public static function active(): self
    {
        return self::MySQL;
    }

    /**
     * Разрешён ли драйвер для боевого подключения к БД.
     * SQLite/PostgreSQL — только как зарезервированные неактивные драйверы.
     */
    public static function isAllowedForRuntime(self $driver): bool
    {
        return $driver === self::MySQL;
    }

    /**
     * Получить DSN-строку для драйвера
     *
     * @param array<string, string> $params
     */
    public function buildDsn(array $params): string
    {
        return match ($this) {
            self::MySQL => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s%s',
                $params['host'],
                $params['port'],
                $params['name'],
                $params['charset'],
                isset($params['connect_timeout'])
                    ? ';connect_timeout=' . rawurlencode((string) $params['connect_timeout'])
                    : ''
            ),
            self::PostgreSQL => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $params['host'],
                $params['port'],
                $params['name']
            ),
            self::SQLite => sprintf(
                'sqlite:%s',
                $params['path'] ?? ':memory:'
            ),
        };
    }

    /**
     * Определить драйвер из строки.
     *
     * ПОЛИТИКА: для работы приложения разрешён только MySQL/MariaDB.
     * Алиасы sqlite/pgsql распознаются, но возвращают ошибку «драйвер
     * неактивен» — использовать их как боевое подключение нельзя.
     *
     * @param bool $lenient если true (только для диагностических целей),
     *                      возвращает распознанный неактивный драйвер без ошибки
     */
    public static function fromString(string $driver, bool $lenient = false): self
    {
        $driver = strtolower(trim($driver));

        return match ($driver) {
            'mysql', 'mariadb' => self::MySQL,
            'pgsql', 'postgres', 'postgresql' => $lenient
                ? self::PostgreSQL
                : throw DataBaseException::inactiveDriver('pgsql'),
            'sqlite', 'sqlite3' => $lenient
                ? self::SQLite
                : throw DataBaseException::inactiveDriver('sqlite'),
            default => throw DataBaseException::unsupportedDriver($driver),
        };
    }

    /**
     * Проверить, является ли драйвер MySQL-совместимым (включая MariaDB)
     */
    public function isMySqlCompatible(): bool
    {
        return $this === self::MySQL;
    }
}