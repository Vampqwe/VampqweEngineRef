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


namespace Core\System\Template;

use RuntimeException;

class TemplateException extends RuntimeException
{
    public static function notFound(string $template): self
    {
        return new self("Шаблон не найден: {$template}");
    }

    public static function compilationFailed(string $template, string $error): self
    {
        return new self("Ошибка компиляции шаблона '{$template}': {$error}");
    }

    public static function invalidSyntax(string $template, string $line): self
    {
        return new self("Неверный синтаксис в шаблоне '{$template}' на строке: {$line}");
    }

    public static function pathTraversal(string $path): self
    {
        return new self("Обнаружена попытка Path Traversal: {$path}");
    }

    public static function nestedTooDeep(int $depth, int $max): self
    {
        return new self("Превышена максимальная глубина вложенности: {$depth} (максимум {$max})");
    }
}