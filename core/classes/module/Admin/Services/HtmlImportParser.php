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
use Core\Module\File\File;
use Core\Module\Directory\Directory;
use DOMDocument;
use DOMXPath;

class HtmlImportParser
{
    private Config $config;

    /** @var array<string,mixed> Правила импорта из config/import-parser.php */
    private array $rules;
    private string $basePath;
    private string $uploadDir;
    /** Промежуточный файл стилей: storage/neutral-style.css */
    private string $stylesFile;
    private array $downloadedImages = [];
    private string $extractedStyles = '';
    private array $warnings = [];
    /** Кэш картинок original => local (в т.ч. неудачные попытки => null) */
    private array $imageCache = [];
	
	
    public function __construct(Config $config)
    {
        $this->config = $config;

        // Единая точка управления правилами парсинга — config/import-parser.php
        $rulesFile = dirname(__DIR__, 4) . '/config/import-parser.php';
        $this->rules = is_file($rulesFile) ? (array) require $rulesFile : [];
        
        // Получаем реальный путь к storage (с правильным регистром)
        // Корень проекта — каноническая настройка APP_ROOT_PATH (единый резолвер,
        // см. Config::getStoragePath()). storage/ всегда вычисляется от корня,
        // устаревший FS_BASE_PATH для пути больше не используется.
        $configBasePath = $config->getStoragePath();
        $realBasePath = realpath($configBasePath);
        
        if ($realBasePath === false) {
            throw new \RuntimeException(
                "storage/ не существует: {$configBasePath}"
                . ' (проверьте APP_ROOT_PATH в config.env)');
        }
        
        $this->basePath = $realBasePath;
        $this->uploadDir = $this->basePath . DIRECTORY_SEPARATOR
            . trim((string) ($this->rules['upload_dir'] ?? 'uploads/import'), '/');
        // Стили импортируемых страниц складываются в промежуточный файл стилей
        $this->stylesFile = $this->basePath . DIRECTORY_SEPARATOR
            . trim((string) ($this->rules['styles_file'] ?? 'neutral-style.css'), '/');

        // Создаём директории
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Валидация загруженного файла до парсинга.
     * Возвращает список ошибок (пустой массив = файл валиден).
     */
    public function validateUpload(array $file): array
    {
        $errors = [];

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Файл не загружен или произошла ошибка загрузки';
            return $errors;
        }

        $maxFileSize = (int) ($this->rules['max_file_size'] ?? 20 * 1024 * 1024);
        if ($file['size'] > $maxFileSize) {
            $errors[] = sprintf('Файл слишком большой (максимум %d МБ)', (int) round($maxFileSize / 1048576));
        }

        $allowedExtensions = (array) ($this->rules['allowed_extensions'] ?? ['html', 'htm']);
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Допустимы только файлы: .' . implode(', .', $allowedExtensions);
        }

        return $errors;
    }

    public function parse(string $html): array
    {
        $this->downloadedImages = [];
        $this->extractedStyles = '';
        $this->warnings = [];
        $this->imageCache = [];

        $doc = $this->loadHtml($html);
        $xpath = new DOMXPath($doc);

        $title = $this->extractTitle($xpath);
        $description = $this->extractDescription($xpath);
        // 1. Извлекаем содержимое <style>-блоков (запись в neutral-style.css отложена до confirm)
        $this->collectStyles($xpath);
        $schemas = $this->extractSchema($xpath);
        // 2. Удаляем теги <style> из документа — HTML-разметка и все классы остаются без изменений
        $this->removeStyleTags($doc, $xpath);
        // 3. Извлекаем контент: инлайн-стили (style="...") и классы сохраняются как есть
        $content = $this->extractContent($html, $doc, $xpath);
        // Страховка: служебная обёртка <!DOCTYPE html><html><head>...<body>, которую
        // мы сами добавляем для корректной обработки кодировки в loadHTML(), никогда
        // не должна попадать в итоговый контент страницы.
        $content = $this->stripDocumentWrapper($content);
        // 4. Скачиваем внешние картинки и заменяем ссылки на локальные пути
        $content = $this->downloadAndReplaceImages($content);
        // 5. Собираем пути JS-скриптов (по умолчанию /assets/js/main.js)
        $scripts = $this->extractScripts($xpath);

        $slug = $this->generateSlug($title);
        if (empty($slug)) {
            $slug = 'import-' . substr(md5($title . microtime()), 0, 8);
        }

        return [
            'title' => $title,
            'description' => $description,
            'slug' => $slug,
            'content' => $content,
            'schemas' => $schemas,
            'scripts' => $scripts,
            'styles' => $this->extractedStyles,
            'images_count' => count($this->downloadedImages),
            'styles_extracted' => strlen($this->extractedStyles),
            'downloaded_images' => $this->downloadedImages,
            'warnings' => $this->warnings,
        ];
    }

    private function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);

        // Обёртка с явным объявлением кодировки: libxml иначе интерпретирует байты как latin1,
        // а сериализация в saveHTML() превращает кириллицу в numeric entities.
        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'
            . $html . '</body></html>';

        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $doc;
    }

    private function extractTitle(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//title');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        $h1 = $xpath->query('//h1');
        if ($h1->length > 0) {
            return trim($h1->item(0)->textContent);
        }

        return '';
    }

    private function extractDescription(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//meta[@name="description"]/@content');
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        $og = $xpath->query('//meta[@property="og:description"]/@content');
        if ($og->length > 0) {
            return trim($og->item(0)->textContent);
        }

        return '';
    }

    /**
     * Собирает CSS из тегов <style> в памяти (без записи на диск).
     */
    private function collectStyles(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//style');
        if ($nodes->length === 0) {
            return;
        }

        foreach ($nodes as $node) {
            $css = trim($node->textContent);
            if (!empty($css)) {
                $this->extractedStyles .= $css . "\n";
            }
        }
    }

    /**
     * Удаляет теги <style> из DOM. Классы и остальной HTML не трогаются.
     */
    private function removeStyleTags(DOMDocument $doc, DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//style');
        $list = [];
        foreach ($nodes as $node) {
            $list[] = $node;
        }
        foreach ($list as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Запись извлечённых стилей в промежуточный neutral-style.css с дедупликацией.
     * Повторяющиеся правила (одинаковый селектор) заменяются новыми значениями.
     * Вызывается только на этапе confirm (чтобы предпросмотр не имел побочных эффектов).
     */
    public function commitStyles(string $css): bool
    {
        $css = trim($css);
        if ($css === '') {
            return true;
        }

        $existingRules = [];
        if (is_file($this->stylesFile)) {
            $existingCss = (string)file_get_contents($this->stylesFile);
            $existingRules = $this->parseCssRules($existingCss);
        }

        $newRules = $this->parseCssRules($css);

        // Дедупликация: одинаковые селекторы заменяем (последнее значение побеждает),
        // новые селекторы дописываем в конец
        $merged = $existingRules;
        foreach ($newRules as $selector => $body) {
            $merged[$selector] = $body;
        }

        $out = "/* neutral-style.css — стили, извлечённые импортом страниц (" . date('Y-m-d H:i:s') . ") */\n\n";
        foreach ($merged as $selector => $body) {
            $out .= $selector . " {" . $body . "}\n";
        }

        return file_put_contents($this->stylesFile, $out, LOCK_EX) !== false;
    }

    /**
     * Разбивает CSS на карту «селектор => тело правила» (поверхностный парсер без учёта @media).
     */
    private function parseCssRules(string $css): array
    {
        $rules = [];
        // Убираем комментарии
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $selector = preg_replace('/\s+/', ' ', trim($m[1]));
                $body = trim($m[2]);
                if ($selector !== '' && $body !== '') {
                    $rules[$selector] = $body;
                }
            }
        }

        return $rules;
    }

    /**
     * Извлекает пути JS-скриптов страницы. Всегда содержит дефолтный /assets/js/main.js.
     */
    private function extractScripts(DOMXPath $xpath): array
    {
        $scripts = ['/assets/js/main.js'];

        $nodes = $xpath->query('//script[@src]');
        foreach ($nodes as $node) {
            $src = trim($node->getAttribute('src'));
            if ($src === '') {
                continue;
            }

            // Пропускаем внешние библиотеки/аналитику/JSON-LD-подобные src
            if (preg_match('~(googleapis|gstatic|analytics|gtm|recaptcha|facebook|connect\.facebook)~i', $src)) {
                continue;
            }

            // Локальные относительные пути нормализуем к /assets/...
            if (!preg_match('~^(/|https?://)~', $src)) {
                $src = '/assets/js/' . ltrim($src, './');
            }

            if (!in_array($src, $scripts, true)) {
                $scripts[] = $src;
            }
        }

        return $scripts;
    }

    /**
     * Извлечь Schema.org JSON-LD из страницы
     */
/**
 * Извлечь Schema.org JSON-LD из страницы.
 *
 * Перед записью схема нормализуется под текущий сайт:
 *  - все поля "url" заменяются на APP_BASE_URL (хостинг из конфига);
 *  - все поля "name" заменяются на APP_NAME;
 *  - "dateModified" и "datePublished" устанавливаются в дату импорта.
 */
private function extractSchema(DOMXPath $xpath): array
{
    $schemas = [];
    $nodes = $xpath->query('//script[@type="application/ld+json"]');
    $now = date('Y-m-d');

    foreach ($nodes as $node) {
        $json = trim($node->textContent);
        if (empty($json)) {
            continue;
        }

        $data = json_decode($json, true);
        // Валидная схема: объект с @type ИЛИ объект с @graph (несколько схем в одном скрипте)
        $isValid = json_last_error() === JSON_ERROR_NONE
            && is_array($data)
            && (isset($data['@type']) || isset($data['@graph']));
        if ($isValid) {
            if (isset($data['@type'])) {
                $type = is_array($data['@type']) ? implode(',', $data['@type']) : (string)$data['@type'];
            } elseif (isset($data['@graph'])) {
                $types = [];
                foreach ((array)$data['@graph'] as $item) {
                    if (isset($item['@type'])) {
                        $types[] = is_array($item['@type']) ? implode(',', $item['@type']) : (string)$item['@type'];
                    }
                }
                $type = implode(',', array_unique($types)) ?: 'WebPage';
            } else {
                $type = 'WebPage';
            }
            $data = $this->normalizeSchemaUrls($data);
            $data = $this->normalizeSchemaNames($data);
            $data = $this->setSchemaDates($data, $now);
            $schemas[] = [
                'schema_type' => $type,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'priority' => 10, // Стандартный приоритет
            ];
        } else {
            $this->warnings[] = 'Найден невалидный JSON в schema.org скрипте';
        }
    }

    return $schemas;
}

/**
 * Рекурсивно установить dateModified / datePublished в дату импорта.
 * Если поле отсутствовало — оно добавляется к объекту схемы.
 */
private function setSchemaDates(array $node, string $date): array
{
    $isObject = isset($node['@type']) || array_keys($node) !== range(0, count($node) - 1);
    if ($isObject) {
        $node['dateModified'] = $date;
        $node['datePublished'] = $date;
    }

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = $this->setSchemaDates($value, $date);
        }
    }

    return $node;
}

/**
 * Рекурсивно заменить строковые поля "name" на APP_NAME.
 * Правило: решение о замене принимается по СОБСТВЕННОМУ @type каждого
 * узла-объекта, а не наследуется от родителя. Вложенный объект без своего
 * @type (напр. {"provider":{"@type":"Organization",...}} внутри Service)
 * сохраняет свой тип и подлежит замене; white-list действует только на
 * узлы, которые сами имеют @type из NAME_KEEP_TYPES (ListItem, Service…).
 */
private function normalizeSchemaNames(array $node): array
{
    $appName = trim((string)$this->config->get('APP_NAME', ''));
    if ($appName === '') {
        return $node;
    }

    // Определяем @type ТЕКУЩЕГО узла (строка или массив типов).
    $keepName = false;
    if (isset($node['@type'])) {
        $types = array_map('strval', is_array($node['@type']) ? $node['@type'] : [$node['@type']]);
        foreach ($types as $type) {
            // Поддержка полностью квалифицированных IRIs вида https://schema.org/ListItem
            $slashPos = strrpos($type, '/');
            $short = $slashPos === false ? $type : substr($type, $slashPos + 1);
            if (in_array($short, (array) ($this->rules['name_keep_types'] ?? []), true)) {
                $keepName = true;
                break;
            }
        }
    }

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = $this->normalizeSchemaNames($value);
        } elseif ($key === 'name' && is_string($value) && !$keepName) {
            $node[$key] = $appName;
        }
    }

    return $node;
}

/**
 * Рекурсивно заменить все поля "url" на базовый URL сайта (APP_BASE_URL).
 */
private function normalizeSchemaUrls(array $node): array
{
    $baseUrl = rtrim((string)$this->config->get('APP_BASE_URL', ''), '/');
    if ($baseUrl === '') {
        return $node;
    }

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = $this->normalizeSchemaUrls($value);
        } elseif ($key === 'url') {
            $node[$key] = $baseUrl . '/';
        }
    }

    return $node;
}


    /**
     * Извлекает основной контент между маркерами «как есть».
     *
     * Надёжный способ: вырезаем исходный HTML по строковым позициям
     * comment-узлов (регулярка по saveHTML), а затем парсим полученный
     * фрагмент отдельным DOMDocument'ом. Так сохраняются все классы и
     * inline-стили независимо от глубины залегания маркеров и «битости» разметки.
     */
    private function extractContent(string $sourceHtml, DOMDocument $doc, DOMXPath $xpath): string
    {
        // Ищем маркеры в ИСХОДНОМ HTML: в saveHTML() кириллические комментарии
        // сериализуются как numeric character references и не найдутся бы регуляркой.
        [$startPos, $endPos] = $this->findMarkerPositions($sourceHtml);

        if ($startPos === null) {
            // Fallback: старый поузловой обход нормализованного DOM
            return $this->extractContentByNodes($doc, $xpath);
        }

        if ($endPos === null) {
            $this->warnings[] = 'Маркер <!-- FOOTER --> не найден, берём до конца документа';
        }

        // Вырезаем raw-подстроку между маркерами — ничего не переформатируется
        $rawFragment = $endPos === null
            ? substr($sourceHtml, $startPos)
            : substr($sourceHtml, $startPos, $endPos - $startPos);

        // Отбрасываем закрывающие теги контейнеров, если они попали в начало среза
        $rawFragment = preg_replace('~^\s*</(main|div|section|article|body|html)\s*>~iu', '', $rawFragment, 1) ?? $rawFragment;

        // Если FOOTER-маркер не найден — обрезаем хвост перед <footer>
        if ($endPos === null) {
            $footerPos = stripos($rawFragment, '<footer');
            if ($footerPos !== false) {
                $rawFragment = substr($rawFragment, 0, $footerPos);
            }
        }

        // Парсим фрагмент отдельным документом (нормализация «битого» HTML + гарантия корректности)
        $fragDoc = new DOMDocument();
        libxml_use_internal_errors(true);
        $fragDoc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'
                . $rawFragment . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        // <style>, стоящие внутри контента, тоже уносятся в neutral-style.css
        $fragXp = new DOMXPath($fragDoc);
        $this->collectStyles($fragXp);
        $this->removeStyleTags($fragDoc, $fragXp);

        // Сохраняем ТОЛЬКО содержимое <body> — иначе в контент вместе с обёрткой
        // попадает служебный <!DOCTYPE html><html><head><meta ...></head><body> ... </body></html>,
        // который мы сами добавили для корректной обработки кодировки.
        $html = $this->extractInnerBody($fragDoc);

        // Контроль целостности: текст результата должен совпадать с текстом raw-среза
        $this->verifyContentIntegrity($html, $rawFragment);

        return $html;
    }

    /**
     * Возвращает внутреннее содержимое <body> документа без служебной обёртки
     * (<!DOCTYPE html><html><head>...</head><body> ... </body></html>).
     */
    private function extractInnerBody(DOMDocument $doc): string
    {
        $bodyList = $doc->getElementsByTagName('body');
        if ($bodyList->length === 0) {
            return trim((string)$doc->saveHTML());
        }

        $body = $bodyList->item(0);
        $inner = '';
        foreach ($body->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }

    /**
     * Страховка: срезает служебную HTML-обёртку, которая не должна попадать в контент.
     * Убирает ведущие <!DOCTYPE ...>, <html ...>, <head>...</head>, <body ...>
     * и закрывающие </body>, </html> — как в конце строки, так и сразу после
     * обёртки (вариант «<!DOCTYPE...><html><head>..</head><body> </body></html>КОНТЕНТ»,
     * который даёт loadHTML() при пустом/вынесенном содержимом <body>).
     */
    public function stripDocumentWrapper(string $content): string
    {
        $patterns = [
            '~^\s*<!DOCTYPE[^>]*>\s*~i',
            '~^\s*<html(?:\s[^>]*)?>\s*~i',
            '~^\s*<head(?:\s[^>]*)?>.*?</head>\s*~is',
            // Пустой "body-хвост" обёртки целиком: <body> </body></html> (пробел/перепад
            // между тегами допустим). ВАЖНО: этот шаблон должен стоять ДО одиночного
            // '<body>', иначе он съест только открывающий тег и хвост </body></html>
            // повиснет в начале контента.
            '~^\s*<body(?:\s[^>]*)?>\s*</body>\s*</html>\s*~i',
            '~^\s*<body(?:\s[^>]*)?>\s*~i',
            // Закрывающий хвост документа
            '~\s*</body>\s*</html>\s*$~i',
            '~\s*</body>\s*$~i',
            '~\s*</html>\s*$~i',
        ];

        do {
            $before = $content;
            foreach ($patterns as $p) {
                $content = preg_replace($p, '', $content, 1) ?? $content;
            }
            $content = trim($content);
        } while ($content !== $before);

        return $content;
    }

    /**
     * Находит строковые позиции маркеров в сериализованном HTML.
     * Возвращает [start, end]: start — позиция сразу после "-->" открывающего
     * маркера, end — позиция начала "<!--" закрывающего маркера (null, если нет).
     */
    private function findMarkerPositions(string $html): array
    {
        $start = null;
        $end   = null;

        if (preg_match('/<!--\s*ОСНОВНОЙ\s+КОНТЕНТ\s*-->/iu', $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
        } else {
            $this->warnings[] = 'Маркер <!-- ОСНОВНОЙ КОНТЕНТ --> не найден';
            return [null, null];
        }

        $tail = substr($html, $start);
        if (preg_match('/<!--\s*(?:PAGE\s+)?FOOTER\s*-->/iu', $tail, $m2, PREG_OFFSET_CAPTURE)) {
            $end = $start + $m2[0][1];
        }

        return [$start, $end];
    }

    /**
     * Проверка целостности: текстовое содержимое извлечённого HTML
     * должно совпадать с текстовым содержимым raw-среза исходника.
     */
    private function verifyContentIntegrity(string $extracted, string $rawFragment): void
    {
        $textExtracted = $this->plainText($extracted);
        $textRaw       = $this->plainText($rawFragment);

        if ($textRaw !== '' && $textExtracted !== $textRaw) {
            $this->warnings[] = 'Внимание: текст извлечённого контента отличается от исходного ('
                . mb_strlen($textRaw, 'UTF-8') . ' → ' . mb_strlen($textExtracted, 'UTF-8') . ' симв.)';
        }
    }

    private function plainText(string $html): string
    {
        $noComments = preg_replace('/<!--.*?-->/su', '', $html) ?? $html;
        $text = strip_tags($noComments);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Fallback: поузловой обход сиблингов между комментариями (старый алгоритм).
     */
    private function extractContentByNodes(DOMDocument $doc, DOMXPath $xpath): string
    {
        $commentNodes = $xpath->query('//comment()');

        if ($commentNodes->length === 0) {
            $this->warnings[] = 'Маркеры не найдены';
            return '';
        }

        $startNode = null;
        $endNode = null;

        foreach ($commentNodes as $comment) {
            $text = mb_strtoupper(trim($comment->textContent), 'UTF-8');

            if ($startNode === null && str_contains($text, 'ОСНОВНОЙ')) {
                $startNode = $comment;
                continue;
            }

            if ($startNode !== null && str_contains($text, 'FOOTER')) {
                $endNode = $comment;
                break;
            }
        }

        if ($startNode === null) {
            return '';
        }

        $html = '';
        $current = $startNode->nextSibling;

        while ($current !== null && $current !== $endNode) {
            $html .= $doc->saveHTML($current);
            $current = $current->nextSibling;
        }

        return $html;
    }
    private function downloadAndReplaceImages(string $html): string
    {
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) === 0) {
            return $html;
        }

        foreach (array_unique($matches[1]) as $src) {
            if (strpos($src, 'data:') === 0 || strpos($src, '/storage/uploads/import/') === 0) {
                continue;
            }

            if (strpos($src, 'http') !== 0) {
                continue;
            }

            // Кэш: одна и та же картинка скачивается один раз
            if (array_key_exists($src, $this->imageCache)) {
                $localPath = $this->imageCache[$src];
            } else {
                $localPath = $this->downloadImage($src);
                $this->imageCache[$src] = $localPath;
            }

            if ($localPath !== null) {
                $this->downloadedImages[] = [
                    'original' => $src,
                    'local' => $localPath,
                ];
                $html = str_replace($src, $localPath, $html);
            }
        }

        return $html;
    }

    /**
     * Защита от SSRF: разрешаем только http/https на публичные хосты.
     */
    private function isSafeImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $ip = gethostbyname($parts['host']);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // Блокируем приватные/петлбэк-диапазоны
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function downloadImage(string $url): ?string
    {
        try {
            if (!$this->isSafeImageUrl($url)) {
                $this->warnings[] = 'Пропущен небезопасный URL картинки: ' . $url;
                return null;
            }

            $context = stream_context_create([
                'http' => [
                    'timeout' => (int) ($this->rules['image_timeout_s'] ?? 10),
                    'max_redirects' => (int) ($this->rules['image_max_redirects'] ?? 3),
                    'user_agent' => 'VampqweEngine Importer/1.0',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $imageData = @file_get_contents($url, false, $context);
            if ($imageData === false || strlen($imageData) < 100) {
                return null;
            }

            if (strlen($imageData) > (int) ($this->rules['max_image_size'] ?? 10 * 1024 * 1024)) {
                $this->warnings[] = 'Картинка слишком большая, пропущена: ' . $url;
                return null;
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imageData);
            $extMap = [
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                'image/jpeg' => 'jpg',
            ];
            $extension = $extMap[$mimeType] ?? 'jpg';

            $filename = md5($url . microtime(true)) . '.' . $extension;
            $destPath = $this->uploadDir . DIRECTORY_SEPARATOR . $filename;

            // Используем file_put_contents напрямую
            file_put_contents($destPath, $imageData);

            return '/storage/uploads/import/' . $filename;
        } catch (\Throwable $e) {
            $this->warnings[] = 'Ошибка загрузки картинки: ' . $e->getMessage();
            return null;
        }
    }

    private function generateSlug(string $title): string
    {
        if (function_exists('transliterator_transliterate')) {
            $slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
        } else {
            // Транслитерация кириллицы без intl-расширения
            $slug = mb_strtolower($this->transliterateCyrllic($title), 'UTF-8');
        }

        $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Простейшая транслитерация кириллицы (fallback без intl).
     */
    private function transliterateCyrllic(string $text): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        return strtr(mb_strtolower($text, 'UTF-8'), $map);
    }
}