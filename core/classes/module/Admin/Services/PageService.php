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


namespace Core\Module\Admin\Services;

use Core\System\Config\Config;
use Core\System\DataBase\DataBase;
use PDO;

/**
 * Админский сервис страниц. Наследует базовые операции чтения по slug
 * (getPageBySlug / pageExistsBySlug) из модуля Page — единый источник
 * логики для фронтенда и админки.
 */
class PageService extends \Core\Module\Page\PageService
{
    /** Значение по умолчанию, если ADMIN_PER_PAGE не задан в конфиге. */
    private const DEFAULT_PER_PAGE = 20;

    /** Жёсткая верхняя граница размера страницы (защита от DoS через ?per_page=). */
    private const MAX_PER_PAGE = 100;

    /**
     * Получить все страницы с пагинацией
     */
    public function getPages(int $page = 1, ?int $perPage = null): array
    {
        if ($perPage === null) {
            // Размер страницы управляется конфигом (config.env: ADMIN_PER_PAGE)
            $perPage = Config::getInstance()->getInt('ADMIN_PER_PAGE', self::DEFAULT_PER_PAGE);
        }
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        
        $pages = $this->db->fetchAll(
            "SELECT p.*, a.name as author_name 
             FROM pages p 
             LEFT JOIN authors a ON p.author_id = a.id 
             ORDER BY p.updated_at DESC 
             LIMIT {$perPage} OFFSET {$offset}"
        );

        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM pages");

        return [
            'pages' => $pages,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Получить страницу по ID (для админки)
     */
    public function getPage(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT p.*, a.name as author_name 
             FROM pages p 
             LEFT JOIN authors a ON p.author_id = a.id 
             WHERE p.id = :id",
            ['id' => $id]
        );
    }

    /**
     * Создать страницу
     */
    public function createPage(array $data): int
    {
        $this->db->execute(
            "INSERT INTO pages (title, description, keywords, canonical, og_title, og_description, 
             meta_robots, og_image, reading_time, views_count, author_id, slug, content, scripts, 
             page_type, parent_id, status, sort_order, is_in_menu, published_at) 
             VALUES (:title, :description, :keywords, :canonical, :og_title, :og_description, 
             :meta_robots, :og_image, :reading_time, :views_count, :author_id, :slug, :content, 
             :scripts, :page_type, :parent_id, :status, :sort_order, :is_in_menu, :published_at)",
            [
                'title' => $data['title'],
                'description' => $data['description'],
                'keywords' => $data['keywords'] ?? null,
                'canonical' => $data['canonical'],
                'og_title' => $data['og_title'],
                'og_description' => $data['og_description'],
                'meta_robots' => $data['meta_robots'] ?? 'index, follow',
                'og_image' => $data['og_image'] ?? null,
                'reading_time' => $data['reading_time'] ?? null,
                'views_count' => 0,
                'author_id' => $data['author_id'] ?? null,
                'slug' => $data['slug'],
                'content' => $data['content'],
                'scripts' => $data['scripts'] ?? null,
                'page_type' => $data['page_type'] ?? 'article',
                'parent_id' => $data['parent_id'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'sort_order' => $data['sort_order'] ?? 0,
                'is_in_menu' => $data['is_in_menu'] ?? 0,
                'published_at' => $data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
            ]
        );

        return (int) $this->db->fetchColumn("SELECT LAST_INSERT_ID()");
    }

    /**
     * Обновить страницу
     */
    public function updatePage(int $id, array $data): int
    {
        $status = $data['status'] ?? 'draft';

        // Собираем явно, чтобы не тянуть в параметры лишние/несовпадающие ключи из $data
        $params = [
            'title' => $data['title'],
            'description' => $data['description'],
            'keywords' => $data['keywords'] ?? null,
            'canonical' => $data['canonical'] ?? null,
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'meta_robots' => $data['meta_robots'] ?? 'index, follow',
            'og_image' => $data['og_image'] ?? null,
            'reading_time' => $data['reading_time'] ?? null,
            'author_id' => $data['author_id'] ?? null,
            'slug' => $data['slug'],
            'content' => $data['content'],
            'scripts' => $data['scripts'] ?? null,
            'page_type' => $data['page_type'] ?? 'article',
            'parent_id' => $data['parent_id'] ?? null,
            'status' => $status,
            'status_check' => $status,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_in_menu' => $data['is_in_menu'] ?? 0,
            'id' => $id,
        ];

        return $this->db->execute(
            "UPDATE pages SET
             title = :title, description = :description, keywords = :keywords,
             canonical = :canonical, og_title = :og_title, og_description = :og_description,
             meta_robots = :meta_robots, og_image = :og_image, reading_time = :reading_time,
             author_id = :author_id, slug = :slug, content = :content, scripts = :scripts,
             page_type = :page_type, parent_id = :parent_id, status = :status,
             sort_order = :sort_order, is_in_menu = :is_in_menu,
             published_at = CASE WHEN :status_check = 'published' AND published_at IS NULL THEN NOW() ELSE published_at END,
             updated_at = NOW()
             WHERE id = :id",
            $params
        );
    }

    /**
     * Удалить страницу
     */
    public function deletePage(int $id): int
    {
        return $this->db->execute("DELETE FROM pages WHERE id = :id", ['id' => $id]);
    }

    /**
     * Получить всех авторов для выпадающего списка
     */
    public function getAuthors(): array
    {
        return $this->db->fetchAll("SELECT id, name FROM authors WHERE is_active = 1 ORDER BY name");
    }

    /**
     * Генерация slug из заголовка
     */
    public function generateSlug(string $title): string
    {
        $slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
	
	    /**
     * Добавить Schema.org разметку для страницы
     */
    public function addSchemaForPage(int $pageId, array $schemas): void
    {
        foreach ($schemas as $schema) {
            $this->db->execute(
                "INSERT INTO schema_org (page_id, route, schema_type, data, priority, is_active) 
                 VALUES (:page_id, :route, :schema_type, :data, :priority, 1)",
                [
                    'page_id' => $pageId,
                    'route' => null, // Привязка по page_id приоритетнее
                    'schema_type' => $schema['schema_type'],
                    'data' => $schema['data'],
                    'priority' => $schema['priority'] ?? 10,
                ]
            );
        }
    }
}