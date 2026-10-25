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

use Core\System\DataBase\DataBase;
use Core\System\Security\HtmlSanitizer;

/**
 * Сервис административного редактора меню.
 *
 * Отвечает за чтение полного дерева пунктов меню (включая скрытые),
 * валидацию полей и пакетное сохранение структуры, полученной из
 * drag-and-drop редактора (порядок, вложенность, видимость).
 */
class MenuAdminService
{
    private DataBase $db;

    public function __construct(DataBase $db)
    {
        $this->db = $db;
    }

    /**
     * Все пункты меню, включая скрытые (для админ-редактора)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllItems(): array
    {
        return $this->db->fetchAll(
            "SELECT id, parent_id, title, url, page_id, sort_order, is_visible,
                    is_external, css_class, icon, note
             FROM menu_items
             ORDER BY sort_order ASC, id ASC"
        );
    }

    /**
     * Опубликованные страницы (подсказки ссылок в редакторе)
     *
     * @return array<int, array{id: int|string, title: string, canonical: string}>
     */
    public function getPageSuggestions(): array
    {
        return $this->db->fetchAll(
            "SELECT id, title, canonical
             FROM pages
             WHERE status = 'published'
             ORDER BY title ASC
             LIMIT 500"
        );
    }

    /**
     * Дерево меню для UI: плоский список с вычисленной глубиной.
     * Защищён от циклов в parent_id (битые данные не уронят админку).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTreeForUi(): array
    {
        $items = $this->getAllItems();

        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item['id']] = $item;
        }

        $depthCache = [];

        $getDepth = function (int $id) use (&$getDepth, &$depthCache, $byId): int {
            if (isset($depthCache[$id])) {
                return $depthCache[$id];
            }
            // Помечаем «в процессе», чтобы разорвать цикл
            $depthCache[$id] = 0;

            $parentId = isset($byId[$id]) ? ($byId[$id]['parent_id'] ?? null) : null;
            $parentId = $parentId !== null ? (int) $parentId : null;

            $depth = 0;
            if ($parentId !== null && isset($byId[$parentId]) && !isset($depthCache[$parentId])) {
                $depth = $getDepth($parentId) + 1;
            } elseif ($parentId !== null && isset($byId[$parentId])) {
                // Цикл — считаем элементом верхнего уровня
                $depth = 0;
            }

            $depthCache[$id] = $depth;
            return $depth;
        };

        $result = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $parentId = $item['parent_id'] !== null ? (int) $item['parent_id'] : null;

            // Осиротевший родитель (удалён) — поднимаем наверх
            if ($parentId !== null && !isset($byId[$parentId])) {
                $parentId = null;
            }

            $result[] = [
                'id'          => $id,
                'parent_id'   => $parentId,
                'title'       => (string) $item['title'],
                'url'         => (string) ($item['url'] ?? ''),
                'page_id'     => $item['page_id'] !== null ? (int) $item['page_id'] : null,
                'sort_order'  => (int) $item['sort_order'],
                'is_visible'  => (int) $item['is_visible'],
                'is_external' => (int) $item['is_external'],
                'css_class'   => (string) ($item['css_class'] ?? ''),
                'icon'        => (string) ($item['icon'] ?? ''),
                'note'        => (string) ($item['note'] ?? ''),
                'depth'       => $getDepth($id),
            ];
        }

        return $result;
    }

    /**
     * Валидация и нормализация данных пункта меню
     *
     * @param array<string, mixed> $raw
     * @return array{ok: bool, errors: array<string>, data?: array<string, mixed>}
     */
    public function normalizeItem(array $raw): array
    {
        $errors = [];

        $title = trim((string) ($raw['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            $errors[] = 'Название обязательно (до 255 символов).';
        }

        $url = trim((string) ($raw['url'] ?? ''));
        if ($url !== '' && $url !== '#') {
            // Запрещённые URL-схемы (javascript:, data:, vbscript: и т.п.)
            // проверяются ПЕРВЫМИ и отдельно от формата: иначе подшаблон
            // протокола может «поглотить» опасную схему.
            if (mb_strlen($url) > 500) {
                $errors[] = 'URL слишком длинный (максимум 500 символов).';
            } elseif (preg_match('#^\s*(javascript|data|vbscript|file)\s*:#i', $url)) {
                $errors[] = 'Запрещённая схема URL.';
            } elseif (!preg_match('#^(https?://|/)#i', $url)) {
                $errors[] = 'URL должен начинаться с «/» или «http(s)://».';
            }
        }

        $icon = trim((string) ($raw['icon'] ?? ''));
        if (mb_strlen($icon) > 50) {
            $errors[] = 'Иконка слишком длинная (максимум 50 символов).';
        }

        $cssClass = trim((string) ($raw['css_class'] ?? ''));
        if (mb_strlen($cssClass) > 100) {
            $errors[] = 'CSS-класс слишком длинный (максимум 100 символов).';
        } elseif ($cssClass !== '' && !preg_match('/^[A-Za-z0-9_\- ]*$/', $cssClass)) {
            // Whitelist: значение подставляется в атрибут class на фронтенде —
            // кавычки, угловые скобки и прочие символы отклоняются.
            $errors[] = 'CSS-класс содержит недопустимые символы (разрешены буквы, цифры, «_», «-» и пробел).';
        }

        $note = trim((string) ($raw['note'] ?? ''));
        if (mb_strlen($note) > 255) {
            $errors[] = 'Заметка слишком длинная (максимум 255 символов).';
        }

        $pageId = ($raw['page_id'] ?? null);
        $pageId = ($pageId === '' || $pageId === null) ? null : (int) $pageId;
        if ($pageId !== null && $pageId <= 0) {
            $pageId = null;
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Defense-in-depth: экранируем текстовые поля перед записью в БД.
        // Вывод (MenuService::renderHtml) экранирует и раньше, но сырые
        // HTML-фрагменты больше не хранятся в базе.
        $title  = HtmlSanitizer::escape($title);
        $icon   = $icon !== '' ? HtmlSanitizer::escape($icon) : '';
        $note   = $note !== '' ? HtmlSanitizer::escape($note) : '';

        return [
            'ok'     => true,
            'errors' => [],
            'data'   => [
                'title'       => $title,
                'url'         => $url,
                'page_id'     => $pageId,
                'is_visible'  => !empty($raw['is_visible']) ? 1 : 0,
                'is_external' => !empty($raw['is_external']) ? 1 : 0,
                'css_class'   => $cssClass !== '' ? $cssClass : null,
                'icon'        => $icon !== '' ? $icon : null,
                'note'        => $note !== '' ? $note : null,
            ],
        ];
    }

    /**
     * Создать пункт меню
     */
    public function createItem(array $raw): array
    {
        $norm = $this->normalizeItem($raw);
        if (!$norm['ok']) {
            return $norm;
        }

        $data = $norm['data'];
        $maxSort = (int) $this->db->fetchColumn("SELECT COALESCE(MAX(sort_order), 0) FROM menu_items");

        $sql = "INSERT INTO menu_items
                    (parent_id, title, url, page_id, sort_order, is_visible,
                     is_external, css_class, icon, note)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $this->db->insert($sql, [
            $data['title'],
            $data['url'],
            $data['page_id'],
            $maxSort + 1,
            $data['is_visible'],
            $data['is_external'],
            $data['css_class'],
            $data['icon'],
            $data['note'],
        ]);

        $id = (int) $this->db->getConnection()->lastInsertId();

        return [
            'ok'        => true,
            'errors'    => [],
            'item'      => $this->findItemForUi($id),
            'createdAt' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Обновить поля существующего пункта (структура — через saveStructure)
     */
    public function updateItem(int $id, array $raw): array
    {
        $existing = $this->db->fetchOne("SELECT id FROM menu_items WHERE id = ?", [$id]);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['Пункт меню не найден.']];
        }

        $norm = $this->normalizeItem($raw);
        if (!$norm['ok']) {
            return $norm;
        }

        $data = $norm['data'];

        $this->db->execute(
            "UPDATE menu_items
             SET title = ?, url = ?, page_id = ?, is_visible = ?, is_external = ?,
                 css_class = ?, icon = ?, note = ?
             WHERE id = ?",
            [
                $data['title'],
                $data['url'],
                $data['page_id'],
                $data['is_visible'],
                $data['is_external'],
                $data['css_class'],
                $data['icon'],
                $data['note'],
                $id,
            ]
        );

        return [
            'ok'        => true,
            'errors'    => [],
            'item'      => $this->findItemForUi($id),
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Удалить пункт вместе со всеми потомками (FK ON DELETE CASCADE)
     */
    public function deleteItem(int $id): array
    {
        $row = $this->db->fetchOne("SELECT title FROM menu_items WHERE id = ?", [$id]);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['Пункт меню не найден.']];
        }

        $deleted = $this->db->execute("DELETE FROM menu_items WHERE id = ?", [$id]);

        return [
            'ok'      => true,
            'errors'  => [],
            'title'   => (string) $row['title'],
            'deleted' => $deleted,
        ];
    }

    /**
     * Пакетное сохранение структуры, присланной drag-and-drop редактором.
     *
     * Ожидаемый элемент: ['id' => int, 'parent_id' => int|null]
     * Порядок элементов массива = новый порядок отображения (sort_order 1..N).
     *
     * @param array<int, array<string, mixed>> $structure
     */
    public function saveStructure(array $structure): array
    {
        // Собираем известные ID из БД
        $knownIds = [];
        foreach ($this->db->fetchAll("SELECT id FROM menu_items") as $row) {
            $knownIds[(int) $row['id']] = true;
        }

        $seen = [];
        $parsed = [];
        foreach ($structure as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) {
                return ['ok' => false, 'errors' => ['Некорректный формат структуры.']];
            }
            $id = (int) $entry['id'];
            if (!isset($knownIds[$id])) {
                return ['ok' => false, 'errors' => ["Неизвестный ID пункта: {$id}."]];
            }
            if (isset($seen[$id])) {
                return ['ok' => false, 'errors' => ["Дублирование пункта #{$id} в структуре."]];
            }
            $seen[$id] = true;

            $parentId = $entry['parent_id'] ?? null;
            $parentId = ($parentId === '' || $parentId === null) ? null : (int) $parentId;
            if ($parentId !== null && $parentId === $id) {
                return ['ok' => false, 'errors' => ["Пункт #{$id} не может быть родителем самого себя."]];
            }
            if ($parentId !== null && !isset($knownIds[$parentId])) {
                return ['ok' => false, 'errors' => ["Неизвестный родительский ID: {$parentId}."]];
            }

            $parsed[] = ['id' => $id, 'parent_id' => $parentId];
        }

        // Проверка на циклы: идём вверх по цепочке родителей, максимум N шагов
        $parentMap = [];
        foreach ($parsed as $p) {
            $parentMap[$p['id']] = $p['parent_id'];
        }
        $limit = count($parsed) + 1;
        foreach ($parentMap as $startId => $parentId) {
            $hops = 0;
            $cursor = $parentId;
            while ($cursor !== null) {
                if ($cursor === $startId || ++$hops > $limit) {
                    return ['ok' => false, 'errors' => ['Обнаружена циклическая вложенность в структуре меню.']];
                }
                $cursor = $parentMap[$cursor] ?? null;
            }
        }

        // Всё корректно — пишем в транзакции
        $this->db->beginTransaction();
        try {
            foreach ($parsed as $i => $p) {
                $this->db->execute(
                    "UPDATE menu_items SET parent_id = ?, sort_order = ? WHERE id = ?",
                    [$p['parent_id'], $i + 1, $p['id']]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'errors' => ['Ошибка сохранения структуры: ' . $e->getMessage()]];
        }

        return [
            'ok'        => true,
            'errors'    => [],
            'count'     => count($parsed),
            'savedAt'   => date('Y-m-d H:i:s'),
            'structure' => $parsed,
        ];
    }

    /**
     * Пункт в формате для UI (с глубиной) по ID
     *
     * @return array<string, mixed>|null
     */
    public function findItemForUi(int $id): ?array
    {
        foreach ($this->getTreeForUi() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }
}
