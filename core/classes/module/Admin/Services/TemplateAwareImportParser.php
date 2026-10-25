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
use Core\System\Parser\HtmlParser;

/**
 * Парсер импорта с автоопределением шаблона исходного HTML.
 *
 * Наследует всю инфраструктуру HtmlImportParser (конфиг блоков, маркеры,
 * псевдонимы, нормализация схем), но перед прогоном определяет «семейство»
 * шаблона файла и подкручивает правила под него:
 *
 *  - «Голый шаблон»: файл не содержит ни контентных маркеров движка, ни
 *    <script type="application/ld+json">, ни <link rel="canonical"> — это
 *    самодостатичная страница из <style> + <nav> + секций + <footer>.
 *    Контент берётся побитным срезом от первого контентного комментария
 *    после <body> до закрывающего </body>; служебные хелпер-комментарии
 *    внутри среза сохраняются как есть (правило доверенного импорта).
 *  - Slug/canonical: если canonical отсутствует, slug вырезается из
 *    self-link навигации — ссылки <nav>, чей текст совпадает с заголовком
 *    страницы (детерминированный источник вместо транслитерации title).
 *  - Schema: при отсутствии JSON-LD в файле синтезируется минимальный
 *    набор WebSite + WebPage + BreadcrumbList из title/description/slug —
 *    в том же контракте rows (schema_type/data/priority), что и настоящий
 *    JSON-LD, поэтому ImportService::writeRows() не меняется. Глобальные
 *    схемы (page_id IS NULL) синтетические записи не создают.
 *
 * Если у файла есть собственные маркеры/JSON-LD/canonical — автоподстройка
 * НЕ применяется: импорт идёт по стандартному контракту движка.
 */
class TemplateAwareImportParser extends HtmlImportParser
{
    /** Разрешённые ограничители PCRE (совпадают с эвристикой HtmlParser::looksLikePcre). */
    private const PCRE_DELIMITERS = ['/', '~', '#', '%', '!'];

    /** Регулярка открывающего контентного маркера любого известного семейства. */
    private const CONTENT_MARKER_RE = '/<!--[<-]*\s*(?:ОСНОВНОЙ\s*КОНТЕНТ|MAIN[_ ]?CONTENT|CONTENT\s+(?:START|BEGIN)|nav(?:\s+(?:start|begin))?)\s*[>-]+-->/iu';

    /** Первый комментарий-маркер после открытия <body> — начало голого шаблона. */
    private const TEMPLATE_START_RE = '<body[^>]*>\s*(<!--[\s\S]*?-->[\s\S]*)';

    /** Закрывающий маркер среза: конец документа (служебные комментарии не отсекаем). */
    private const TEMPLATE_END_RE = '</body>';

    public function __construct(Config $config)
    {
        parent::__construct($config);
    }

    /**
     * Разбор HTML с автоподстройкой правил под определённый шаблон.
     *
     * @return array<string,mixed> контракт как у HtmlParser::parse()
     */
    public function parse(string|array $content): array
    {
        $html = is_array($content) ? (string) ($content['content'] ?? '') : $content;

        if (!$this->isBareTemplate($html)) {
            return parent::parse($html);
        }

        $originalRules = $this->getRules();

        try {
            $rules = $originalRules;
            if (isset($rules['blocks']['main_content']) && is_array($rules['blocks']['main_content'])) {
                $rules['blocks']['main_content']['start'] = $this->toDelimiter(self::TEMPLATE_START_RE, '~');
                $rules['blocks']['main_content']['end'] = $this->toDelimiter(self::TEMPLATE_END_RE, '~');
                // Хвостовые блоки среза (footer/script) — часть самодостатичной
                // страницы: целостность не сверяем (маркер end теперь regex).
                $rules['blocks']['main_content']['verify_integrity'] = false;
                $this->setRules($rules);
            }

            $parsed = parent::parse($html);
            $parsed = $this->applyTemplateSlug($html, $parsed);

            return $this->synthesizeSchema($parsed);
        } finally {
            // Правила парсера — общепрогонное состояние (синглтон DI):
            // восстанавливаем оригинальный конфиг для остальных потребителей.
            $this->setRules($originalRules);
        }
    }

    /**
     * Файл относится к «голому шаблону», если в нём нет ни контентных
     * маркеров движка, ни JSON-LD, ни canonical — т.е. штатный контракт
     * неприменим и нужна автоподстройка.
     */
    public function isBareTemplate(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        $hasMarker = preg_match(self::CONTENT_MARKER_RE, $html) === 1;
        $hasLdJson = stripos($html, 'application/ld+json') !== false;
        $hasCanonical = preg_match('~<link\b[^>]*rel=["\']canonical["\'][^>]*>~iu', $html) === 1;

        return !$hasMarker && !$hasLdJson && !$hasCanonical;
    }

    /**
     * Собрать валидную PCRE из тела регулярки: выбрать ограничитель,
     * отсутствующий в теле (эвристика looksLikePcre требует вхождения
     * ограничителя в паттерн, иначе строка примется за литеральный маркер).
     *
     * @param string $body   тело паттерна без ограничителей и флагов
     * @param string $preferred предпочтительный ограничитель
     * @return string готовое PCRE вида '<d>тело<d>iu'
     */
    private function toDelimiter(string $body, string $preferred): string
    {
        foreach (array_merge([$preferred], self::PCRE_DELIMITERS) as $d) {
            if (strpbrk($body, $d) === false) {
                return $d . $body . $d . 'iu';
            }
        }

        // Теоретически недостижимо: тело не содержит все пять ограничителей.
        return $preferred . $body . $preferred . 'iu';
    }

    /**
     * Slug из self-link навигации: среди <a href="/путь/"> внутри <nav>
     * выбирается ссылка, чей текст совпадает с заголовком страницы (или
     * является его префиксом) — это текущая страница меню. Fallback —
     * последняя непустая относительная ссылка навигации.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function applyTemplateSlug(string $html, array $parsed): array
    {
        // Применяем только когда штатный источник (canonical) ничего не дал.
        $sourceCanonical = trim((string) ($parsed['values']['canonical'] ?? ''));
        if ($sourceCanonical !== '') {
            return $parsed;
        }

        if (!preg_match_all('~<nav\b[^>]*>[\s\S]*?</nav>~iu', $html, $navs) || $navs === []) {
            return $parsed;
        }

        $links = [];
        foreach ($navs[0] as $navHtml) {
            if (preg_match_all(
                '~<a\b[^>]*href="(/[^"#?]*)"[^>]*>(.*?)</a>~iu',
                $navHtml,
                $m,
                PREG_SET_ORDER
            )) {
                foreach ($m as $set) {
                    $links[] = ['href' => $set[1], 'text' => trim(strip_tags($set[2]))];
                }
            }
        }

        if ($links === []) {
            return $parsed;
        }

        $title = trim((string) ($parsed['title'] ?? ''));
        // Заголовок без бренда: «Название | Домен» → «Название».
        $coreTitle = $title !== '' && str_contains($title, '|')
            ? trim(explode('|', $title)[0])
            : $title;

        $chosen = '';
        if ($coreTitle !== '') {
            $bestLen = -1;
            foreach ($links as $link) {
                $text = $link['text'];
                if ($text === '') {
                    continue;
                }
                $normText = mb_strtolower((string) preg_replace('~\s+~u', ' ', $text));
                $normTitle = mb_strtolower((string) preg_replace('~\s+~u', ' ', $coreTitle));
                if ($normText === $normTitle || str_starts_with($normTitle, $normText)) {
                    if (strlen($text) > $bestLen) {
                        $bestLen = strlen($text);
                        $chosen = $link['href'];
                    }
                }
            }
        }

        if ($chosen === '') {
            $chosen = $links[count($links) - 1]['href'];
        }

        $slug = HtmlParser::slugFromCanonicalUrl($chosen);
        if ($slug === '') {
            return $parsed;
        }

        $parsed['slug'] = $slug;
        if (array_key_exists('slug', $parsed['values'])) {
            $parsed['values']['slug'] = $slug;
        }

        return $parsed;
    }

    /**
     * Синтез минимального набора схем для файлов без JSON-LD. Формат rows
     * идентичен реальному extractSchema(), поэтому весь нижний поток
     * (валидация JsonRule, срезка @context/@type, дедуп по page_id)
     * работает без изменений.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function synthesizeSchema(array $parsed): array
    {
        $schemas = (array) ($parsed['schemas'] ?? []);
        if ($schemas !== []) {
            return $parsed; // реальный JSON-LD приоритетнее синтетического
        }

        $title = trim((string) ($parsed['title'] ?? ''));
        if ($title === '') {
            return $parsed;
        }

        $baseUrl = rtrim((string) Config::getInstance()->get('APP_BASE_URL', ''), '/');
        $slug = trim((string) ($parsed['slug'] ?? ''), '/');
        $path = '/' . $slug . '/';
        $url = $baseUrl !== '' ? $baseUrl . $path : $path;
        $description = trim((string) ($parsed['description'] ?? ''));
        $siteName = (string) Config::getInstance()->get('APP_NAME', '');
        $siteName = $siteName !== '' ? $siteName : 'Сайт';

        // Название страницы: <title> до разделителя бренда.
        $name = $title;
        if (str_contains($name, '|')) {
            $name = trim(explode('|', $name)[0]);
        }

        $webSite = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteName,
            'url' => $baseUrl !== '' ? $baseUrl : '/',
        ];

        $webPage = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $name,
            'url' => $url,
        ];
        if ($description !== '') {
            $webPage['description'] = $description;
        }

        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => ($baseUrl !== '' ? $baseUrl : '') . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url],
            ],
        ];

        foreach ([[$webSite, 'WebSite'], [$webPage, 'WebPage'], [$breadcrumb, 'BreadcrumbList']] as [$data, $type]) {
            $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($raw === false) {
                continue;
            }
            $schemas[] = ['schema_type' => $type, 'data' => $raw, 'priority' => 10];
        }

        $parsed['schemas'] = $schemas;
        $parsed['row_sets']['schema_org'] = $schemas;

        return $parsed;
    }
}
