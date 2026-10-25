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


namespace Core\System\FileSystem;

/**
 * Enum типа файловой сущности
 */
enum FileSystemType: string
{
    case File      = 'file';
    case Directory = 'directory';
    case Link      = 'link';
    case Unknown   = 'unknown';

    /**
     * Определить тип по реальному пути
     */
    public static function detect(string $path): self
    {
        if (!file_exists($path)) {
            return self::Unknown;
        }

        if (is_link($path)) {
            return self::Link;
        }

        if (is_dir($path)) {
            return self::Directory;
        }

        if (is_file($path)) {
            return self::File;
        }

        return self::Unknown;
    }
}