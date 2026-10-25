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


namespace Core\System\ServiceProvider;

use Core\System\Security\HtmlSanitizer;

class HtmlSanitizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрируем HtmlSanitizer как singleton в контейнере
        $this->container->singleton(HtmlSanitizer::class, fn() => new HtmlSanitizer());
    }

    public function boot(): void
    {
        // Ничего не нужно инициализировать
    }
}