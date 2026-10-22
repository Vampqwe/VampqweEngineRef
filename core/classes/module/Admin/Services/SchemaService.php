<?php
declare(strict_types=1);

namespace Core\Module\Admin\Services;

use Core\System\DataBase\DataBase;

class SchemaService
{
    private DataBase $db;

    private const ALLOWED_SCHEMA_TYPES = [
        'WebSite', 'WebPage', 'Article', 'NewsArticle', 'BlogPosting',
        'Organization', 'LocalBusiness', 'Person',
        'Product', 'Service', 'Offer',
        'BreadcrumbList', 'FAQPage', 'HowTo',
        'Event', 'ImageObject', 'VideoObject',
        'MedicalOrganization', 'Physician', 'MedicalBusiness',
    ];

    public function __construct(DataBase $db)
    {
        $this->db = $db;
    }

    /**
     * Получить все схемы с пагинацией и данными о привязке
     */
    /**
 * Получить все схемы с пагинацией и данными о привязке
 */
public function getAllSchemas(int $page = 1, int $perPage = 20): array
{
    $offset = ($page - 1) * $perPage;

    // ВАЖНО: поле s.data обязательно — оно используется в array_map ниже
    $sql = "
        SELECT s.id, s.schema_type, s.route, s.page_id, s.data,
               p.title as page_title,
               s.priority, s.is_active, s.created_at, s.updated_at
        FROM schema_org s
        LEFT JOIN pages p ON s.page_id = p.id
        ORDER BY s.priority DESC, s.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";

    $schemas = $this->db->fetchAll($sql);
    $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM schema_org");

    return [
        'schemas' => array_map(function (array $row): array {
            $data = json_decode((string) $row['data'], true);
            return [
                'id' => (int) $row['id'],
                'schema_type' => (string) $row['schema_type'],
                'route' => $row['route'],
                'page_id' => $row['page_id'] ? (int) $row['page_id'] : null,
                'page_title' => $row['page_title'],
                'data' => is_array($data) ? $data : [],
                'priority' => (int) $row['priority'],
                'is_active' => (int) $row['is_active'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $schemas),
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => (int) ceil($total / $perPage),
    ];
}

    /**
     * Получить одну схему по ID
     */
    public function getSchema(int $id): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT id, page_id, route, schema_type, data, priority, is_active
             FROM schema_org WHERE id = :id",
            ['id' => $id]
        );

        if (!$row) return null;

        $data = json_decode((string) $row['data'], true);
        return [
            'id' => (int) $row['id'],
            'page_id' => $row['page_id'] ? (int) $row['page_id'] : null,
            'route' => $row['route'],
            'schema_type' => (string) $row['schema_type'],
            'data' => is_array($data) ? $data : [],
            'priority' => (int) $row['priority'],
            'is_active' => (int) $row['is_active'],
        ];
    }

    /**
     * Получить список всех страниц для выпадающего списка
     */
    public function getPagesForSelect(): array
    {
        return $this->db->fetchAll(
            "SELECT id, title, slug FROM pages ORDER BY title ASC"
        );
    }

    /**
     * Валидация и нормализация данных схемы
     */
    public function normalizeSchema(array $raw): array
    {
        $errors = [];
        $schemaType = trim((string) ($raw['schema_type'] ?? ''));

        if ($schemaType === '' || !in_array($schemaType, self::ALLOWED_SCHEMA_TYPES, true)) {
            $errors[] = 'Выберите корректный тип схемы из списка.';
        }

        $bindType = $raw['bind_type'] ?? 'global'; // 'global' или 'page'
        $pageId = null;
        $route = null;

        if ($bindType === 'page') {
            $pageId = (int) ($raw['page_id'] ?? 0);
            if ($pageId <= 0) {
                $errors[] = 'Выберите страницу для привязки.';
            }
        } else {
            $route = trim((string) ($raw['route'] ?? '*'));
            if ($route === '') $route = '*';
        }

        $jsonData = $raw['data'] ?? null;
        if (is_string($jsonData)) {
            $decoded = json_decode($jsonData, true);
            $jsonData = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($jsonData) || empty($jsonData)) {
            $errors[] = 'Данные схемы должны быть валидным непустым JSON-объектом.';
        } else {
            unset($jsonData['@context'], $jsonData['@type']); // Движок добавит их сам
        }

        $priority = max(0, min(1000, (int) ($raw['priority'] ?? 10)));
        $isActive = !empty($raw['is_active']) ? 1 : 0;

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'errors' => [],
            'data' => [
                'schema_type' => $schemaType,
                'page_id' => $pageId,
                'route' => $route,
                'data_json' => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'priority' => $priority,
                'is_active' => $isActive,
            ]
        ];
    }

    /**
     * Создать или обновить схему
     */
    public function saveSchema(?int $id, array $raw): array
    {
        $norm = $this->normalizeSchema($raw);
        if (!$norm['ok']) return $norm;

        $d = $norm['data'];

        try {
            if ($id === null) {
                $this->db->execute(
                    "INSERT INTO schema_org (page_id, route, schema_type, data, priority, is_active)
                     VALUES (:page_id, :route, :schema_type, :data, :priority, :is_active)",
                    [
                        'page_id' => $d['page_id'],
                        'route' => $d['route'],
                        'schema_type' => $d['schema_type'],
                        'data' => $d['data_json'],
                        'priority' => $d['priority'],
                        'is_active' => $d['is_active'],
                    ]
                );
                $id = (int) $this->db->fetchColumn("SELECT LAST_INSERT_ID()");
            } else {
                $this->db->execute(
                    "UPDATE schema_org SET page_id = :page_id, route = :route,
                     schema_type = :schema_type, data = :data, priority = :priority,
                     is_active = :is_active, updated_at = NOW() WHERE id = :id",
                    [
                        'id' => $id,
                        'page_id' => $d['page_id'],
                        'route' => $d['route'],
                        'schema_type' => $d['schema_type'],
                        'data' => $d['data_json'],
                        'priority' => $d['priority'],
                        'is_active' => $d['is_active'],
                    ]
                );
            }
            return ['ok' => true, 'errors' => [], 'id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Ошибка БД: ' . $e->getMessage()]];
        }
    }

    /**
     * Удалить схему
     */
    public function deleteSchema(int $id): array
    {
        try {
            $this->db->execute("DELETE FROM schema_org WHERE id = :id", ['id' => $id]);
            return ['ok' => true, 'errors' => []];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Ошибка удаления: ' . $e->getMessage()]];
        }
    }

    public function getAllowedTypes(): array
    {
        return self::ALLOWED_SCHEMA_TYPES;
    }
}