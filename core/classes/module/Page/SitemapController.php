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
use Core\System\Queue\Queue;

/**
 * Контроллер для генерации sitemap.xml
 *
 * Оптимизация под пиковый трафик: если очередь задач включена, карта
 * сайта отдаётся из предсгенерированного файла storage/sitemap/, а
 * пересборка выполняется фоновым воркером (job «sitemap.rebuild»).
 * Без очереди поведение прежнее — живая генерация по БД.
 */
class SitemapController
{
    /** Минимальный возраст файла карты, после которого запрашивается пересборка. */
    private const REFRESH_AFTER_SECONDS = 3600;

    private SitemapService $sitemapService;
    private Config $config;
    private ?SitemapGenerator $generator;
    private ?Queue $queue;

    public function __construct(
        SitemapService $sitemapService,
        Config $config,
        ?SitemapGenerator $generator = null,
        ?Queue $queue = null
    ) {
        $this->sitemapService = $sitemapService;
        $this->config = $config;
        $this->generator = $generator;
        $this->queue = $queue;
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

    // Магистральный путь: готовый файл + постановка пересборки в очередь.
    $cached = $this->getCachedXml($baseUrl);
    if ($cached !== null) {
        return $this->respond($cached['xml'], $cached['mtime'] ?: null);
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

    /**
     * Готовый файл карты сайта, если очередь активна. Свежесть обеспечивается
     * фоновой пересборкой: устаревший файл инициирует job «sitemap.rebuild».
     *
     * @return array{xml: string, mtime: int}|null
     */
    private function getCachedXml(string $baseUrl): ?array
    {
        if ($this->generator === null || $this->queue === null || !$this->queue->isEnabled()) {
            return null;
        }

        $cached = $this->generator->readCached();
        if ($cached === null) {
            return null;
        }

        if (time() - $cached['mtime'] > self::REFRESH_AFTER_SECONDS) {
            try {
                // Небольшая задержка: частые запросы не плодят дубликаты задач.
                $this->queue->push(SitemapGenerator::JOB_NAME, ['base_url' => $baseUrl], 5);
            } catch (\Throwable) {
                // Очередь недоступна — отдаём то, что есть (fail-open).
            }
        }

        return $cached;
    }

    /**
     * Ответ с ETag/Last-Modified по содержимому готового XML.
     */
    private function respond(string $xml, ?int $lastmodTs): Response
    {
        $etag = md5($xml);

        $clientEtag = trim(str_replace(['W/', '"'], '', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
        if ($clientEtag !== '' && hash_equals($etag, $clientEtag)) {
            return Response::xml('', StatusCode::NOT_MODIFIED)
                ->setEtag($etag)
                ->setHeader('Cache-Control', 'public, max-age=3600');
        }

        $response = Response::xml($xml, StatusCode::OK);
        $response->setCacheControl('public', 3600);
        $response->setEtag($etag);
        if ($lastmodTs !== null && $lastmodTs > 0) {
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