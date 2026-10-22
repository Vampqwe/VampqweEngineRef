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


namespace Core\System\Application;

use RuntimeException;
use Throwable;

/**
 * Исключение для операций приложения
 */
class ApplicationException extends RuntimeException
{
    public static function notInitialized(): self
    {
        return new self('Приложение не инициализировано. Вызовите Application::init()');
    }

    public static function alreadyInitialized(): self
    {
        return new self('Приложение уже инициализировано');
    }

    public static function basePathNotFound(string $path): self
    {
        return new self("Базовая директория не найдена: {$path}");
    }

    public static function serviceProviderFailed(
        string $provider,
        string $message,
        ?Throwable $previous = null
    ): self
    {
        return new self("Ошибка в провайдере {$provider}: {$message}", 0, $previous);
    }
}