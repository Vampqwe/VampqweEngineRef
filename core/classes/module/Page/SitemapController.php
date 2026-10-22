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

/**
 * Контроллер для генерации sitemap.xml
 */
class SitemapController
{
    private SitemapService $sitemapService;
    private Config $config;

    public function __construct(SitemapService $sitemapService, Config $config)
    {
        $this->sitemapService = $sitemapService;
        $this->config = $config;
    }

public function index(Request $request): Response
{
    // Единая точка входа домена: базовый URL берётся из config.env (APP_BASE_URL).
    $baseUrl = rtrim((string)$this->config->get('APP_BASE_URL', ''), '/');
    if ($baseUrl === '') {
        // Fallback на хост текущего запроса, чтобы <loc> никогда не строился от «битого» домена.
        $scheme = $request->isSecure() ? 'https' : 'http';
        $baseUrl = $scheme . '://' . $request->getHost();
    }

    $items = $this->sitemapService->getPagesForSitemap($baseUrl);
    $xml = $this->buildXml($items);

    // ETag считается по фактическому содержимому XML — это исключает
    // отдачу устаревшей карты при совпадении MAX(updated_at) и числа страниц.
    $etag = md5($xml);

    // Корректная обработка If-None-Match (в т.ч. списков и W/ префиксов).
    $clientEtag = (string)$request->getHeader('If-None-Match', '');
    $candidate = trim(str_replace(['W/', '"'], '', $clientEtag));
    if ($candidate !== '' && hash_equals($etag, $candidate)) {
        return Response::xml('', StatusCode::NOT_MODIFIED)
            ->setEtag($etag)
            ->setHeader('Cache-Control', 'public, max-age=3600');
    }

    $response = Response::xml($xml, StatusCode::OK);

    // Sitemap должна быть доступна индексации: X-Robots-Tag: noindex убран.
    $response->setCacheControl('public', 3600);
    $response->setEtag($etag);

    $lastmodTs = $this->sitemapService->getLastUpdateTimestamp();
    if ($lastmodTs !== null) {
        $response->setLastModified($lastmodTs);
    }

    return $response;
}

    private function buildXml(array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($items as $item) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$item['loc']}</loc>\n";
            $xml .= "    <lastmod>{$item['lastmod']}</lastmod>\n";
            $xml .= "    <changefreq>{$item['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$item['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }
}