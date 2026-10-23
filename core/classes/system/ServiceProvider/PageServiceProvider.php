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

use Core\Module\Page\MenuService;
use Core\Module\Page\PageService;
use Core\Module\Page\RedirectService;
use Core\Module\Page\SitemapService;

/**
 * Провайдер для страниц, меню и редиректов
 */
class PageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $db = $this->container->get(\Core\System\DataBase\DataBase::class);

        $this->container->singleton(PageService::class, fn() =>
            new PageService($db)
        );

        $this->container->singleton(RedirectService::class, fn() =>
            new RedirectService($db)
        );

        $this->container->singleton(MenuService::class, fn() =>
            new MenuService($db)
        );
		
		// ─── НОВОЕ ───
		$this->container->singleton(SitemapService::class, fn() =>
			new SitemapService($db)
		);
    }

    public function boot(): void
    {
        // Ничего не требуется
    }
}