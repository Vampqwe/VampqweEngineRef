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

use Core\System\DataBase\DataBase;

/**
 * Сервис для генерации данных sitemap.xml
 */
class SitemapService
{
    private DataBase $db;

    public function __construct(DataBase $db)
    {
        $this->db = $db;
    }

    /**
     * Получить все опубликованные страницы для sitemap
     *
     * @return array<int, array{
     *     loc: string,
     *     lastmod: string,
     *     changefreq: string,
     *     priority: string
     * }>
     */
    public function getPagesForSitemap(string $baseUrl): array
    {
        $rows = $this->db->fetchAll(
            "SELECT slug, page_type, updated_at
             FROM pages
             WHERE status = 'published'
               AND page_type NOT IN ('404', '500')
             ORDER BY sort_order ASC, id ASC"
        );

        $items = [];
        foreach ($rows as $row) {
            // 1. Формируем URL с гарантированным завершающим слэшем
            $slug = ltrim((string) $row['slug'], '/');
            $loc = $baseUrl . '/' . $slug;

            // Для главной страницы (slug='') слэш уже добавлен выше
            // Для остальных — добавляем в конец, если его нет
            if ($slug !== '' && !str_ends_with($loc, '/')) {
                $loc .= '/';
            }

            // 2. Безопасная обработка updated_at (может быть NULL)
            $lastmod = !empty($row['updated_at'])
                ? date('Y-m-d', strtotime($row['updated_at']))
                : date('Y-m-d'); // fallback на текущую дату

            $items[] = [
                'loc'        => htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                'lastmod'    => $lastmod,
                'changefreq' => $this->getChangeFreq((string) ($row['page_type'] ?? 'article')),
                'priority'   => $this->getPriority((string) ($row['page_type'] ?? 'article')),
            ];
        }

        return $items;
    }

    /**
     * Максимальная дата обновления (для Last-Modified / диагностических целей).
     */
    public function getLastUpdate(): ?string
    {
        return $this->db->fetchColumn(
            "SELECT MAX(updated_at) FROM pages WHERE status = 'published'"
        );
    }

    /**
     * Unix-timestamp последней публикации (для заголовка Last-Modified).
     */
    public function getLastUpdateTimestamp(): ?int
    {
        $lastUpdate = $this->getLastUpdate();
        if (empty($lastUpdate)) {
            return null;
        }

        $ts = strtotime((string)$lastUpdate);

        return $ts !== false ? $ts : null;
    }

    /**
     * Частота обновления по типу страницы
     */
    private function getChangeFreq(string $pageType): string
    {
        return match ($pageType) {
            'home'    => 'daily',
            'hub'     => 'weekly',
            'article' => 'monthly',
            'page'    => 'monthly',
            default   => 'monthly',
        };
    }

    /**
     * Приоритет страницы
     */
    private function getPriority(string $pageType): string
    {
        return match ($pageType) {
            'home'    => '1.0',
            'hub'     => '0.8',
            'article' => '0.6',
            'page'    => '0.5',
            default   => '0.5',
        };
    }
}