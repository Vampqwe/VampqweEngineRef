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
use Core\System\Map\Map;

/**
 * Сервис для работы с меню из БД
 */
class MenuService
{
    private DataBase $db;

    public function __construct(DataBase $db)
    {
        $this->db = $db;
    }

    /**
     * Получить все видимые пункты меню (плоский список)
     *
     * @return array<int, array>
     */
    public function getVisibleItems(): array
    {
        $items = $this->db->fetchAll(
            "SELECT * FROM menu_items 
             WHERE is_visible = 1 
             ORDER BY sort_order ASC, id ASC"
        );

        return array_map(function (array $item): array {
            $item['url'] = $this->safeUrl((string) ($item['url'] ?? ''));
            return $item;
        }, $items);
    }

    /**
     * Получить меню в виде дерева
     *
     * @return array<int, array{item: array, children: array}>
     */
    public function getTree(): array
    {
        $items = $this->getVisibleItems();
        $grouped = [];
        $roots = [];

        // Группируем по parent_id
        foreach ($items as $item) {
            $parentId = $item['parent_id'] ?? null;
            if (!isset($grouped[$parentId])) {
                $grouped[$parentId] = [];
            }
            $grouped[$parentId][] = $item;
        }

        // Рекурсивно строим дерево
        $buildTree = function (?int $parentId) use (&$buildTree, $grouped): array {
            $children = [];
            $items = $grouped[$parentId] ?? [];

            foreach ($items as $item) {
                $children[] = [
                    'item'     => $item,
                    'children' => $buildTree($item['id']),
                ];
            }

            return $children;
        };

        return $buildTree(null);
    }

    /**
     * Получить HTML меню
     */
    public function renderHtml(string $ulClass = 'menu', string $liClass = 'menu-item'): string
    {
        $tree = $this->getTree();

        if (empty($tree)) {
            return '';
        }

        $renderLevel = function (array $items, int $depth) use (&$renderLevel, $ulClass, $liClass): string {
            if (empty($items)) {
                return '';
            }

            $safeUlClass = htmlspecialchars($ulClass, ENT_QUOTES, 'UTF-8');
            $html = "<ul class=\"{$safeUlClass}\" data-depth=\"{$depth}\">";

            foreach ($items as $node) {
                $item = $node['item'];
                $children = $node['children'];

                $url = htmlspecialchars($this->safeUrl((string) ($item['url'] ?? '')), ENT_QUOTES, 'UTF-8');
                $title = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                $cssClass = $liClass;

                if (!empty($item['css_class'])) {
                    $cssClass .= ' ' . $item['css_class'];
                }

                if (!empty($children)) {
                    $cssClass .= ' has-children';
                }

                $cssClass = htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8');
                $target = !empty($item['is_external']) ? ' target="_blank" rel="noopener noreferrer"' : '';
                $iconText = htmlspecialchars((string) ($item['icon'] ?? ''), ENT_QUOTES, 'UTF-8');
                $icon = $iconText !== '' ? "<span class=\"menu-icon\">{$iconText}</span> " : '';

                $html .= "<li class=\"{$cssClass}\">";

                if ($url !== '' && $url !== '#') {
                    $html .= "<a href=\"{$url}\"{$target}>{$icon}{$title}</a>";
                } else {
                    $html .= "<span class=\"menu-title\">{$icon}{$title}</span>";
                }

                if (!empty($children)) {
                    $html .= $renderLevel($children, $depth + 1);
                }

                $html .= "</li>";
            }

            $html .= "</ul>";
            return $html;
        };

        return $renderLevel($tree, 0);
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return '#';
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)
            ? $url
            : '#';
    }
}