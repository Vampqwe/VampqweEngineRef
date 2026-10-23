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


namespace Core\System\Security;

/**
 * HTML-санитайзер на нативных расширениях PHP (DOM + mbstring).
 * Зависимость ezyang/htmlpurifier из проекта исключена.
 *
 * Назначение: очистка пользовательского/импортируемого HTML от XSS-векторов —
 * удаляются опасные теги, обработчики событий (on*), javascript:-URL,
 * <style>/<svg>/<math> и прочие векторы атак.
 */
class HtmlSanitizer
{
    /** @var array<string,mixed>|null Кэш загруженного конфига правил санитизации */
    private static ?array $config = null;

    /**
     * Правила санитизации вынесены в config/html-sanitizer.php
     * (значения по умолчанию сохранены как fallback на случай отсутствия файла).
     *
     * @return array<string,mixed>
     */
    private static function config(): array
    {
        if (self::$config === null) {
            $path = dirname(__DIR__, 4) . '/config/html-sanitizer.php';
            self::$config = is_file($path) ? require $path : [];
        }
        return self::$config;
    }

    /** Теги, полностью удаляемые вместе с содержимым */
    private const REMOVE_WITH_CONTENT = [
        'script', 'style', 'noscript', 'template', 'head', 'meta', 'link',
        'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form',
        'input', 'button', 'select', 'textarea', 'keygen', 'svg', 'math', 'base',
    ];

    /** Разрешённые теги (переопределяются конфигом html-sanitizer.php) */
    private const ALLOWED_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'hr', 'div', 'span',
        'strong', 'em', 'u', 'del', 'ins', 'small', 'sub', 'sup', 'b', 'i',
        'a', 'ul', 'ol', 'li',
        'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'blockquote', 'pre', 'code', 'figure', 'figcaption',
    ];

    /** Строгий набор тегов для cleanStrict() */
    private const STRICT_ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'a'];

    /** Глобальные атрибуты, разрешённые у всех тегов */
    private const GLOBAL_ATTRS = ['class', 'style', 'id', 'title'];

    /** Опасные схемы в URL-атрибутах */
    private const DANGEROUS_URL_SCHEMES = ['javascript', 'vbscript', 'data', 'file'];

    /** Атрибуты, содержащие URL (проверяются на схему) */
    private const URL_ATTRS = ['href', 'src', 'cite', 'background', 'action', 'formaction', 'xlink:href'];

    /** Опасные конструкции внутри style (CSS-векторы: expression(), url(javascript:...), @import) */
    private const DANGEROUS_CSS_PATTERNS = [
        '/expression\s*\(/i',
        '/url\(["\']?\s*(javascript|vbscript|data)\s*:/i',
        '/@import/i',
        '/behavior\s*:/i',
        '/-moz-binding/i',
    ];

    public static function clean(string $html): string
    {
        $cfg = self::config();
        return self::purify(
            $html,
            (array) ($cfg['allowed_tags'] ?? self::ALLOWED_TAGS),
            self::allowedAttributes()
        );
    }

    public static function cleanStrict(string $html): string
    {
        // Совместимо со старым поведением: HTML.Allowed = 'p,br,strong,em,a[href|title]'
        $cfg = self::config();
        return self::purify(
            $html,
            (array) ($cfg['strict_allowed_tags'] ?? self::STRICT_ALLOWED_TAGS),
            (array) ($cfg['strict_allowed_attributes'] ?? ['a' => ['href', 'title']])
        );
    }

    public static function stripTags(string $html): string
    {
        return strip_tags($html);
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Парсит HTML через DOM, вырезает опасное и возвращает innerBody.
     *
     * @param string[]             $allowedTags    список разрешённых тегов в нижнем регистре
     * @param array<string,string> $allowedAttrsMap карта tag => список атрибутов (спец. ключ '*' — глобальные)
     */
    private static function purify(string $html, array $allowedTags, array $allowedAttrsMap): string
    {
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        // XML-декларация задаёт кодировку входа; DOCTYPE включает HTML-режим парсинга
        $doc->loadHTML(
            '<?xml encoding=UTF-8><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return '';
        }

        $allowedFlip = array_flip($allowedTags);

        // 1. Удаляем опасные теги вместе с содержимым
        self::removeDangerousElements($body, self::REMOVE_WITH_CONTENT);

        // 2. Фильтруем атрибуты у оставшихся элементов
        self::filterElements($body, $allowedAttrsMap);

        // 3. Распаковываем оставшиеся неразрешённые теги (текст сохраняется)
        self::unwrapDisallowed($body, $allowedFlip);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Рекурсивно удаляет указанные теги вместе с их поддеревьями.
     *
     * @param string[] $tagNames
     */
    private static function removeDangerousElements(\DOMElement $root, array $tagNames): void
    {
        $xpath = new \DOMXPath($root->ownerDocument);
        $query = implode(' | ', array_map(
            static fn(string $tag): string => './/' . $tag,
            $tagNames
        ));
        $nodes = $xpath->query($query, $root);
        if ($nodes === false) {
            return;
        }
        foreach (iterator_to_array($nodes) as $node) {
            /** @var \DOMNode $node */
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Проходит по дереву (снимок NodeList — итерация безопасна при мутациях)
     * и очищает атрибуты у всех элементов.
     *
     * @param array<string,string[]> $allowedAttrsMap
     */
    private static function filterElements(\DOMElement $root, array $allowedAttrsMap): void
    {
        $global = $allowedAttrsMap['*'] ?? [];

        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            /** @var \DOMElement $el */
            $tag = strtolower($el->tagName);
            $permitted = array_merge($global, $allowedAttrsMap[$tag] ?? []);

            foreach (iterator_to_array($el->attributes) as $attr) {
                /** @var \DOMAttr $attr */
                $name = strtolower($attr->nodeName);
                $value = (string) ($attr->nodeValue ?? '');

                // Любые on*-обработчики запрещены всегда
                if (str_starts_with($name, 'on')) {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }

                // Атрибуты с пространствами имён (xlink:, xml: и т.п.), кроме явно разрешённых
                if (str_contains($name, ':') && !in_array($name, $permitted, true)) {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }

                if (!in_array($name, $permitted, true)) {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }

                if (in_array($name, self::URL_ATTRS, true) && !self::isSafeUrl($value)) {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }

                if ($name === 'style' && self::isDangerousCss($value)) {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }
            }

            // Специфичная пост-обработка
            if ($tag === 'a') {
                self::normalizeLink($el);
            } elseif ($tag === 'img' && !$el->hasAttribute('src')) {
                $el->parentNode?->removeChild($el);
            }
        }
    }

    /**
     * Заменяет неразрешённые теги на их содержимое (текст не теряется).
     *
     * @param array<string,int> $allowedFlip
     */
    private static function unwrapDisallowed(\DOMElement $root, array $allowedFlip): void
    {
        $xpath = new \DOMXPath($root->ownerDocument);
        $nodes = $xpath->query('.//*', $root);
        if ($nodes === false) {
            return;
        }
        foreach (iterator_to_array($nodes) as $node) {
            /** @var \DOMElement $node */
            $parent = $node->parentNode;
            if (!$parent instanceof \DOMElement) {
                continue;
            }
            if (!isset($allowedFlip[strtolower($node->tagName)])) {
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
            }
        }
    }

    /** Валидные URL-атрибуты каждого тега (переопределяются конфигом) */
    private static function allowedAttributes(): array
    {
        $cfg = self::config();
        if (isset($cfg['allowed_attributes']) && is_array($cfg['allowed_attributes'])) {
            return $cfg['allowed_attributes'];
        }

        $map = ['*' => self::GLOBAL_ATTRS];
        foreach (self::ALLOWED_TAGS as $tag) {
            $map[$tag] = [];
        }
        $map['a'] = ['href', 'target', 'rel'];
        $map['img'] = ['src', 'alt', 'width', 'height'];
        $map['blockquote'] = ['cite'];
        $map['td'] = ['colspan', 'rowspan'];
        $map['th'] = ['colspan', 'rowspan', 'scope'];
        $map['col'] = ['span'];
        $map['colgroup'] = ['span'];
        $map['ol'] = ['start', 'reversed', 'type'];
        $map['li'] = ['value'];

        return $map;
    }

    /**
     * Проверяет URL на опасные схемы.
     * Учитывает обфускацию: пробелы ("java script:"), управляющие символы ("java\tscript:"),
     * HTML-сущности в схеме ("JaVaScRiPt&NewLine;pt:", "&colon;" вместо ":") и
     * percent-encoding ("%6aavascript:").
     */
    private static function isSafeUrl(string $url): bool
    {
        // Сущности декодируем ДО удаления пробелов: &Tab;/&#9; внутри слова «javascript»
        // после декодирования превращаются в разделитель и тоже удаляются
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Убираем управляющие символы и пробелы (атаки вида "java\tscript:" / "j a v a script:")
        $stripped = preg_replace('/[\x00-\x20\x7f]+/', '', $decoded) ?? '';
        // Double-decode: "&amp;lt;" -> "&lt;" -> "<" (защита от двойного экранирования в схеме)
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lower = mb_strtolower(rawurldecode($stripped));

        $schemes = (array) (self::config()['dangerous_url_schemes'] ?? self::DANGEROUS_URL_SCHEMES);

        // Опасная схема может стоять не в начале: "anything:javascript:alert(1)"
        if (preg_match('/(?:^|[a-z][a-z0-9+.-]*:)\s*(' . implode('|', $schemes) . ')\s*:/', $lower)) {
            return false;
        }

        // Прочее (http, https, mailto, tel, относительные пути, #якорь) — ок
        return true;
    }

    private static function isDangerousCss(string $style): bool
    {
        $normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $style) ?? '';
        foreach (self::DANGEROUS_CSS_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }
        return false;
    }

    /** target=_blank дополняется rel=noopener noreferrer; <a> без href распаковывается в текст */
    private static function normalizeLink(\DOMElement $a): void
    {
        if (!$a->hasAttribute('href')) {
            $parent = $a->parentNode;
            if ($parent instanceof \DOMNode) {
                while ($a->firstChild !== null) {
                    $parent->insertBefore($a->firstChild, $a);
                }
                $parent->removeChild($a);
            }
            return;
        }

        $target = strtolower($a->getAttribute('target'));
        if ($target === '_blank') {
            $a->setAttribute('rel', 'noopener noreferrer');
        } elseif ($target !== '' && $target !== '_self') {
            $a->removeAttribute('target');
        }
    }
}
