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

use RuntimeException;

/**
 * Исключение для операций с базой данных
 */
class DataBaseException extends RuntimeException
{
    public static function connectionFailed(string $message): self
    {
        return new self("Не удалось подключиться к БД: {$message}");
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Неподдерживаемый драйвер БД: {$driver}");
    }

    /**
     * Драйвер существует в enum, но отключён политикой движка
     * (рабочее приложение работает только с MySQL).
     */
    public static function inactiveDriver(string $driver): self
    {
        return new self(
            "Драйвер БД «{$driver}» неактивен: движок работает только с MySQL/MariaDB "
            . "(укажите DB_DRIVER=mysql в config.env)"
        );
    }

    public static function queryFailed(string $sql, string $message): self
    {
        return new self("Ошибка выполнения запроса [{$sql}]: {$message}");
    }

    public static function transactionFailed(string $message): self
    {
        return new self("Ошибка транзакции: {$message}");
    }

    public static function notConnected(): self
    {
        return new self('Нет активного подключения к БД');
    }
}