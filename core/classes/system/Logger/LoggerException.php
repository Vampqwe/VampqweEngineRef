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

use RuntimeException;

/**
 * Исключение для операций логгера
 */
class LoggerException extends RuntimeException
{
    public static function invalidLevel(string $level): self
    {
        return new self("Неверный уровень логирования: {$level}");
    }

    public static function fileNotWritable(string $path): self
    {
        return new self("Файл логов недоступен для записи: {$path}");
    }

    public static function directoryCreationFailed(string $path): self
    {
        return new self("Не удалось создать директорию для логов: {$path}");
    }

    public static function writeFailed(string $message): self
    {
        return new self("Не удалось записать лог: {$message}");
    }
}