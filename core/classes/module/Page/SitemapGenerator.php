<?php
declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
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

/**
 * Генерация sitemap.xml в файл (storage/sitemap/sitemap.xml).
 *
 * Тяжёлая выборка всех опубликованных страниц выполняется фоновым
 * воркером очереди (job «sitemap.rebuild»), а веб-контроллер отдаёт
 * готовый файл — пиковый трафик не упирается в SQL на каждый запрос.
 */
class SitemapGenerator
{
    public const JOB_NAME = 'sitemap.rebuild';

    private SitemapService $service;
    private Config $config;

    public function __construct(SitemapService $service, Config $config)
    {
        $this->service = $service;
        $this->config = $config;
    }

    /**
     * Путь файла предсгенерированной карты сайта.
     */
    public function getFilePath(): string
    {
        return $this->config->getStoragePath('sitemap') . DIRECTORY_SEPARATOR . 'sitemap.xml';
    }

    /**
     * Построить XML по текущему содержимому БД.
     */
    public function buildXml(string $baseUrl): string
    {
        $items = $this->service->getPagesForSitemap($baseUrl);

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

    /**
     * Пересобрать файл карты сайта. Возвращает путь к записанному файлу.
     *
     * Запись атомарная (tmp + rename), поэтому читатель никогда не увидит
     * полуреализованный XML.
     */
    public function rebuild(?string $baseUrl = null): string
    {
        if ($baseUrl === null) {
            $baseUrl = rtrim((string) $this->config->get('APP_BASE_URL', ''), '/');
        }
        if ($baseUrl === '') {
            throw new \RuntimeException(
                'Не задан APP_BASE_URL: sitemap.rebuild требует единого канонического домена.'
            );
        }

        $xml = $this->buildXml($baseUrl);
        $file = $this->getFilePath();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог: {$dir}");
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $xml) === false) {
            throw new \RuntimeException("Не удалось записать временный файл: {$tmp}");
        }
        @chmod($tmp, 0664);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Не удалось заменить файл: {$file}");
        }

        clearstatcache(true, $file);

        return $file;
    }

    /**
     * Прочитать готовый файл карты сайта (null — если ещё не сгенерирован).
     */
    public function readCached(): ?array
    {
        $file = $this->getFilePath();
        if (!is_file($file)) {
            return null;
        }
        $xml = file_get_contents($file);
        if ($xml === false || $xml === '') {
            return null;
        }

        return [
            'xml'  => $xml,
            'mtime' => (int) (filemtime($file) ?: 0),
        ];
    }
}
