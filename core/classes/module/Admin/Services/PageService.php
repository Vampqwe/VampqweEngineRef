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

    // ═══════════════════════════════════════════════════════════════
    // Schema.org — CRUD для схем, привязанных к странице
    // ═══════════════════════════════════════════════════════════════

    /** Жёсткий лимит схем на одну страницу (защита от раздувания). */
    private const MAX_SCHEMAS_PER_PAGE = 50;

    /**
     * Whitelist разрешённых типов schema.org.
     *
     * Ограничиваем список, чтобы в БД не попали произвольные строки
     * (защита от stored-XSS через JSON-LD и от мусорных типов).
     * Список покрывает основные сценарии: сайт, организация, статья, товар, услуга.
     */
    private const ALLOWED_SCHEMA_TYPES = [
        'WebSite', 'WebPage', 'Article', 'NewsArticle', 'BlogPosting',
        'Organization', 'LocalBusiness', 'Person',
        'Product', 'Service', 'Offer',
        'BreadcrumbList', 'FAQPage', 'HowTo',
        'Event', 'ImageObject', 'VideoObject',
        'MedicalOrganization', 'Physician', 'MedicalBusiness',
    ];

    /**
     * Получить все схемы, привязанные к странице.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSchemasForPage(int $pageId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, schema_type, data, priority, is_active, created_at, updated_at
             FROM schema_org
             WHERE page_id = :page_id AND route IS NULL
             ORDER BY priority DESC, id ASC",
            ['page_id' => $pageId]
        );

        return array_map(function (array $row): array {
            $data = json_decode((string) $row['data'], true);
            return [
                'id'          => (int) $row['id'],
                'schema_type' => (string) $row['schema_type'],
                'data'        => is_array($data) ? $data : [],
                'priority'    => (int) $row['priority'],
                'is_active'   => (int) $row['is_active'],
                'created_at'  => (string) $row['created_at'],
                'updated_at'  => (string) $row['updated_at'],
            ];
        }, $rows);
    }

    /**
     * Получить одну схему по ID.
     * Возвращает null, если схемы нет или она не привязана к указанной странице
     * (защита от подмены page_id — нельзя редактировать чужие схемы).
     */
    public function getSchema(int $schemaId, int $pageId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT id, page_id, schema_type, data, priority, is_active
             FROM schema_org
             WHERE id = :id AND page_id = :page_id",
            ['id' => $schemaId, 'page_id' => $pageId]
        );

        if ($row === null) {
            return null;
        }

        $data = json_decode((string) $row['data'], true);
        return [
            'id'          => (int) $row['id'],
            'page_id'     => (int) $row['page_id'],
            'schema_type' => (string) $row['schema_type'],
            'data'        => is_array($data) ? $data : [],
            'priority'    => (int) $row['priority'],
            'is_active'   => (int) $row['is_active'],
        ];
    }

    /**
     * Занятые типы схем на странице.
     *
     * В схеме БД стоит UNIQUE KEY uk_page_schema (page_id, schema_type),
     * поэтому на странице не может быть двух схем одного типа. Возвращаем
     * карту type => schema_id, чтобы UI мог заблокировать уже занятые
     * опции, а сервис — вернуть внятную ошибку вместо SQLException.
     *
     * @return array<string, int>
     */
    public function getTakenSchemaTypes(int $pageId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, schema_type FROM schema_org
             WHERE page_id = :page_id AND route IS NULL",
            ['page_id' => $pageId]
        );

        $taken = [];
        foreach ($rows as $row) {
            $taken[(string) $row['schema_type']] = (int) $row['id'];
        }

        return $taken;
    }

    /**
     * Валидация и нормализация данных схемы.
     *
     * @param array<string, mixed> $raw
     * @return array{ok: bool, errors: string[], data?: array<string, mixed>}
     */
    public function normalizeSchema(array $raw): array
    {
        $errors = [];

        $schemaType = trim((string) ($raw['schema_type'] ?? ''));
        if ($schemaType === '') {
            $errors[] = 'Тип схемы обязателен.';
        } elseif (!in_array($schemaType, self::ALLOWED_SCHEMA_TYPES, true)) {
            $errors[] = 'Недопустимый тип схемы: ' . $schemaType
                . '. Разрешённые: ' . implode(', ', self::ALLOWED_SCHEMA_TYPES);
        }

        $data = $raw['data'] ?? null;
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($data)) {
            $errors[] = 'Данные схемы должны быть валидным JSON-объектом.';
        } elseif ($data === []) {
            $errors[] = 'Данные схемы не могут быть пустыми.';
        } else {
            // Защита от внедрения @context/@type извне — они всегда задаются движком
            unset($data['@context'], $data['@type']);
        }

        $priority = (int) ($raw['priority'] ?? 10);
        if ($priority < 0 || $priority > 1000) {
            $errors[] = 'Приоритет должен быть в диапазоне 0–1000.';
        }

        $isActive = !empty($raw['is_active']) ? 1 : 0;

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok'     => true,
            'errors' => [],
            'data'   => [
                'schema_type' => $schemaType,
                'data'        => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'priority'    => $priority,
                'is_active'   => $isActive,
            ],
        ];
    }

    /**
     * Проверить, не занят ли тип схемы другой схемой этой же страницы.
     *
     * @param int|null $excludeId ID обновляемой схемы (исключается из проверки)
     */
    private function assertSchemaTypeFree(int $pageId, string $schemaType, ?int $excludeId = null): ?string
    {
        $sql = "SELECT id FROM schema_org
                WHERE page_id = :page_id AND schema_type = :type AND route IS NULL";
        $params = ['page_id' => $pageId, 'type' => $schemaType];

        if ($excludeId !== null) {
            $sql .= " AND id <> :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $existing = $this->db->fetchOne($sql, $params);

        if ($existing !== null) {
            return sprintf(
                'Схема типа «%s» уже привязана к этой странице (ID %d). '
                . 'На одну страницу допускается не более одной схемы каждого типа — '
                . 'отредактируйте существующую или выберите другой тип.',
                $schemaType,
                (int) $existing['id']
            );
        }

        return null;
    }

    /**
     * Создать схему для страницы.
     *
     * @return array{ok: bool, errors: string[], schema?: array<string, mixed>}
     */
    public function createSchemaForPage(int $pageId, array $raw): array
    {
        $page = $this->getPage($pageId);
        if ($page === null) {
            return ['ok' => false, 'errors' => ['Страница не найдена.']];
        }

        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM schema_org WHERE page_id = :page_id AND route IS NULL",
            ['page_id' => $pageId]
        );
        if ($count >= self::MAX_SCHEMAS_PER_PAGE) {
            return ['ok' => false, 'errors' => [
                "Превышен лимит схем на страницу (максимум " . self::MAX_SCHEMAS_PER_PAGE . ")."
            ]];
        }

        $norm = $this->normalizeSchema($raw);
        if (!$norm['ok']) {
            return $norm;
        }

        $data = $norm['data'];

        $typeError = $this->assertSchemaTypeFree($pageId, $data['schema_type']);
        if ($typeError !== null) {
            return ['ok' => false, 'errors' => [$typeError]];
        }

        try {
            $this->db->execute(
                "INSERT INTO schema_org (page_id, route, schema_type, data, priority, is_active)
                 VALUES (:page_id, NULL, :schema_type, :data, :priority, :is_active)",
                [
                    'page_id'     => $pageId,
                    'schema_type' => $data['schema_type'],
                    'data'        => $data['data'],
                    'priority'    => $data['priority'],
                    'is_active'   => $data['is_active'],
                ]
            );

            $id = (int) $this->db->fetchColumn("SELECT LAST_INSERT_ID()");
            return [
                'ok'     => true,
                'errors' => [],
                'schema' => $this->getSchema($id, $pageId),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Ошибка создания схемы: ' . $e->getMessage()]];
        }
    }

    /**
     * Обновить схему.
     *
     * @return array{ok: bool, errors: string[], schema?: array<string, mixed>}
     */
    public function updateSchema(int $schemaId, int $pageId, array $raw): array
    {
        $existing = $this->getSchema($schemaId, $pageId);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['Схема не найдена.']];
        }

        $norm = $this->normalizeSchema($raw);
        if (!$norm['ok']) {
            return $norm;
        }

        $data = $norm['data'];

        $typeError = $this->assertSchemaTypeFree($pageId, $data['schema_type'], $schemaId);
        if ($typeError !== null) {
            return ['ok' => false, 'errors' => [$typeError]];
        }

        try {
            $this->db->execute(
                "UPDATE schema_org
                 SET schema_type = :schema_type, data = :data,
                     priority = :priority, is_active = :is_active,
                     updated_at = NOW()
                 WHERE id = :id AND page_id = :page_id",
                [
                    'id'          => $schemaId,
                    'page_id'     => $pageId,
                    'schema_type' => $data['schema_type'],
                    'data'        => $data['data'],
                    'priority'    => $data['priority'],
                    'is_active'   => $data['is_active'],
                ]
            );

            $updated = $this->getSchema($schemaId, $pageId);
            if ($updated === null) {
                return ['ok' => false, 'errors' => ['Схема не найдена после обновления.']];
            }

            return [
                'ok'     => true,
                'errors' => [],
                'schema' => $updated,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Ошибка обновления схемы: ' . $e->getMessage()]];
        }
    }

    /**
     * Удалить схему.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public function deleteSchema(int $schemaId, int $pageId): array
    {
        $existing = $this->getSchema($schemaId, $pageId);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['Схема не найдена.']];
        }

        try {
            $this->db->execute(
                "DELETE FROM schema_org WHERE id = :id AND page_id = :page_id",
                ['id' => $schemaId, 'page_id' => $pageId]
            );
            return ['ok' => true, 'errors' => []];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Ошибка удаления схемы: ' . $e->getMessage()]];
        }
    }

    /**
     * Получить список разрешённых типов схем (для UI).
     *
     * @return string[]
     */
    public function getAllowedSchemaTypes(): array
    {
        return self::ALLOWED_SCHEMA_TYPES;
    }
}