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


namespace Core\Module\Page;

use Core\System\Config\Config;
use Core\System\Http\Request;
use Core\System\Http\Response;
use Core\System\Http\StatusCode;
use Core\System\Template\Template;

class PageController
{
    private PageService $pageService;
    private RedirectService $redirectService;
    private MenuService $menuService;
    private Template $template;
    private Config $config;

    public function __construct(
        PageService $pageService,
        RedirectService $redirectService,
        MenuService $menuService,
        Template $template,
        Config $config
    ) {
        $this->pageService = $pageService;
        $this->redirectService = $redirectService;
        $this->menuService = $menuService;
        $this->template = $template;
        $this->config = $config;
    }
    public function show(Request $request): Response
    {
        $path = $request->getPath();
        $slug = trim($path, '/');

        // Проверяем редиректы
        $redirectPath = $slug === '' ? '/' : '/' . $slug;
        $redirectResponse = $this->redirectService->checkAndRedirect($redirectPath);
        if ($redirectResponse !== null) {
            return $redirectResponse;
        }

        // Ищем страницу
        $page = $this->pageService->getPageBySlug($slug);

        if (!$page) {
            $page404 = $this->pageService->getPageBySlug('404');
            if ($page404) {
                return $this->renderPage($page404, StatusCode::NOT_FOUND);
            }
            return Response::html('<h1>404 Not Found</h1>', StatusCode::NOT_FOUND);
        }

        $this->pageService->incrementViews($page['id']);
        return $this->renderPage($page, StatusCode::OK);
    }

    private function renderPage(array $page, StatusCode $statusCode): Response
    {
        $baseUrl = rtrim($this->config->get('APP_BASE_URL', 'https://localhost'), '/');

        // Canonical: берём из БД (там уже сохранён APP_BASE_URL + slug при импорте/создании).
        // Fallback — собираем из BASE_URL + slug, если в БД пусто или относительный путь.
        $canonical = trim((string) ($page['canonical'] ?? ''));
        if ($canonical === '' || !preg_match('#^https?://#i', $canonical)) {
            $canonicalPath = $page['slug'] === '' ? '' : '/' . ltrim((string) $page['slug'], '/');
            $canonical = $baseUrl . $canonicalPath;
        }

        // Правила SEO: og:title = <title>, og:description = meta description
        $page['og_title'] = ($page['og_title'] ?? '') !== '' ? $page['og_title'] : ($page['title'] ?? '');
        $page['og_description'] = ($page['og_description'] ?? '') !== '' ? $page['og_description'] : ($page['description'] ?? '');

        $ogImageUrl = '';
        if (!empty($page['og_image'])) {
            $ogImageUrl = str_starts_with($page['og_image'], 'http')
                ? $page['og_image']
                : $baseUrl . '/' . ltrim($page['og_image'], '/');
        }

        $menuItems = $this->menuService->getVisibleItems();

        $templateName = match ($page['page_type'] ?? 'article') {
            'home'    => 'pages/home',
            '404'     => 'pages/404',
            '500'     => 'pages/404',
            'hub'     => 'pages/hub',
            'article' => 'pages/article',
            'page'    => 'pages/article',
            default   => 'pages/article',
        };

        $html = $this->template->render($templateName, [
            'page'           => $page,
            'canonical_url'  => $canonical,
            'og_image_url'   => $ogImageUrl,
            'template'       => $this->template,
            'menu_items'     => $menuItems,
        ]);

        $response = Response::html($html, $statusCode);

        // В dev-режиме не отдаём агрессивный кеш/ETag — чтобы правки в БД были видны сразу
        if (!$this->config->getBool('APP_DEBUG', false)) {
            $response
                ->setCacheControl('public', 3600)
                ->setEtag(md5(($page['updated_at'] ?? '') . $page['id']));
        }

        return $response;
    }
}