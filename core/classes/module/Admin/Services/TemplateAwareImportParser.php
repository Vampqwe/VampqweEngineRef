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

/*
 * CHANGELOG 2026-10 (прогон на реальных файлах page/*.html):
 *  - «Голый шаблон» переопределён: файл считается самодостатичным, когда в нём
 *    нет контентных маркеров движка (canonical и JSON-LD больше не признак
 *    штатного контракта — они есть у реальных шаблонов diagnostika);
 *  - срез контента строится от первого <nav>/<main>/<article>/<section> после
 *    <body> до </body> включительно (в контент попадают <footer> и <script>);
 *  - start/end-маркеры совместимы с content_raw-срезом: end включает </body>
 *    в срез, чтобы побитный режим не тянул хвостовые </html></body>;
 *  - canonical исходника вырезается из контента (дубль canonical ломает
 *    PageService::renderCanonical() при рендере); slug берётся из канонического
 *    URL файла, fallback — self-link навигации;
 *  - синтез схем усилен: @graph разбирается на отдельные записи, даты схем
 *    приводятся к ISO-8601 (MySQL JSON-колонка + strict-режим иначе роняют
 *    всю транзакцию импорта на невалидном datetime).
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

    /**
     * Начало среза самодостатичной страницы: первый контентный элемент после
     * <body> (навигация — часть страницы: файл рендерится как есть).
     */
    private const TEMPLATE_START_BODY_RE = '(<body[^>]*>[\s\S]*?)(<(?:nav(?:\s|>|$)|main(?:\s|>|$)|article(?:\s|>|$)|section(?:\s|>|$)))';

    /** Конец среза: закрывающий </body> ВКЛЮЧИТЕЛЬНО (в срез попадают footer/script). */
    private const TEMPLATE_END_BODY_RE = '([\s\S]*</body)>';

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

        // Нормализация та же, что внутри HtmlParser::parse(): срезы и правки
        // контента считаем по нормализованному исходнику (CRLF → LF, BOM).
        $sourceHtml = str_replace(["\r\n", "\r"], "\n", $html);
        $sourceHtml = ltrim($sourceHtml, "\xEF\xBB\xBF");

        $originalRules = $this->getRules();

        try {
            $rules = $originalRules;
            if (isset($rules['blocks']['main_content']) && is_array($rules['blocks']['main_content'])) {
                $rules['blocks']['main_content']['start'] = $this->toDelimiter(self::TEMPLATE_START_BODY_RE, '~');
                $rules['blocks']['main_content']['end'] = $this->toDelimiter(self::TEMPLATE_END_BODY_RE, '~');
                // Хвостовые блоки среза (footer/script) — часть самодостатичной
                // страницы: целостность не сверяем (маркер end теперь regex).
                $rules['blocks']['main_content']['verify_integrity'] = false;
                $this->setRules($rules);
            }

            $parsed = parent::parse($sourceHtml);

            // Контент самодостатичного файла чистим от артефактов шаблона:
            // дублирующий <link rel="canonical"> извлечённого источника
            // (движок отдаст свой canonical при рендере — два тега в <head>
            // ломают PageService::renderCanonical()).
            $parsed = $this->stripSourceCanonicalFromContent($parsed);

            $parsed = $this->applyTemplateSlug($sourceHtml, $parsed);

            return $this->synthesizeSchema($parsed);
        } finally {
            // Правила парсера — общепрогонное состояние (синглтон DI):
            // восстанавливаем оригинальный конфиг для остальных потребителей.
            $this->setRules($originalRules);
        }
    }

    /**
     * Файл относится к «голому шаблону», если в нём нет контентных маркеров
     * движка — т.е. штатный контракт неприменим и нужна автоподстройка.
     *
     * Canonical и JSON-LD признаком штатного контракта НЕ являются: реальные
     * самодостатичные шаблоны содержат и то, и другое (проверено на файлах
     * семейства diagnostika), но между маркерами движка их контент не лежит.
     */
    public function isBareTemplate(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        return preg_match(self::CONTENT_MARKER_RE, $html) !== 1;
    }

    /**
     * Убрать из контента строку <link rel="canonical"> источника. Правки
     * применяются по ссылкам к одному и тому же контенту (content,
     * values['content'], blocks['main_content']['value']).
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function stripSourceCanonicalFromContent(array $parsed): array
    {
        $strip = static function (string $html): string {
            return preg_replace(
                '~[ \t]*<link\b[^>]*rel=["\']canonical["\'][^>]*>[ \t]*\n?~iu',
                '',
                $html
            ) ?? $html;
        };

        if (isset($parsed['content']) && is_string($parsed['content'])) {
            $parsed['content'] = $strip($parsed['content']);
        }
        if (isset($parsed['values']['content']) && is_string($parsed['values']['content'])) {
            $parsed['values']['content'] = $strip($parsed['values']['content']);
        }
        if (isset($parsed['blocks']['main_content']['value']) && is_string($parsed['blocks']['main_content']['value'])) {
            $parsed['blocks']['main_content']['value'] = $strip($parsed['blocks']['main_content']['value']);
        }

        return $parsed;
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
     * Slug самодостатичного файла. Приоритет — canonical источника (он есть
     * у реальных шаблонов diagnostika): слаг берётся из канонического URL,
     * поэтому страница занимает ровно тот путь, который заявлен в файле.
     * Fallback — self-link навигации: ссылка <a href="/путь/"> внутри <nav>,
     * чей текст совпадает с заголовком страницы (или является его префиксом).
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function applyTemplateSlug(string $html, array $parsed): array
    {
        $sourceCanonical = trim((string) ($parsed['values']['canonical'] ?? ''));

        // Canonical источника всегда выигрывает у translit-слэга из title,
        // который мог подставить штатный parse() (slug_from_title).
        if ($sourceCanonical !== '') {
            $slug = HtmlParser::slugFromCanonicalUrl($sourceCanonical);
            if ($slug !== '') {
                $parsed['slug'] = $slug;
                if (array_key_exists('slug', $parsed['values'])) {
                    $parsed['values']['slug'] = $slug;
                }
            }

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
