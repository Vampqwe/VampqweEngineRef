<?php

declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * Правила HTML-санитизации (модуль «Система безопасности»).
 * Управляют тем, какой HTML разрешён в контенте страниц после очистки
 * Core\System\Security\HtmlSanitizer::clean() / cleanStrict().
 */

return [

    // Разрешённые теги базового режима clean().
    'allowed_tags' => [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'hr', 'div', 'span',
        'strong', 'em', 'u', 'del', 'ins', 'small', 'sub', 'sup', 'b', 'i',
        'a', 'ul', 'ol', 'li',
        'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'caption', 'colgroup', 'col',
        'blockquote', 'pre', 'code', 'figure', 'figcaption',
    ],

    // Валидные атрибуты по тегам. Ключ '*' — глобальные атрибуты всех тегов.
    'allowed_attributes' => [
        '*'          => ['class', 'style', 'id', 'title'],
        'a'          => ['href', 'target', 'rel'],
        'img'        => ['src', 'alt', 'width', 'height'],
        'blockquote' => ['cite'],
        'td'         => ['colspan', 'rowspan'],
        'th'         => ['colspan', 'rowspan', 'scope'],
        'col'        => ['span'],
        'colgroup'   => ['span'],
        'ol'         => ['start', 'reversed', 'type'],
        'li'         => ['value'],
    ],

    // Набор тегов строгого режима cleanStrict() (короткие текстовые поля).
    'strict_allowed_tags' => ['p', 'br', 'strong', 'em', 'a'],

    // Атрибуты строгого режима (по тегам).
    'strict_allowed_attributes' => [
        'a' => ['href', 'title'],
    ],

    // Схемы URL, запрещённые в href/src/cite и прочих URL-атрибутах.
    'dangerous_url_schemes' => ['javascript', 'vbscript', 'data', 'file'],
];
