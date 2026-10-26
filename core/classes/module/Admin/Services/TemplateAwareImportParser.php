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
 * CHANGELOG 2026-10 (окончательное поведение):
 *  - Контракт импорта НЕ меняется: контент всегда вырезается по маркерам
 *    движка из config/import-parser.php ('start' => '<!-- ОСНОВНОЙ КОНТЕНТ -->',
 *    'end' => '<!-- FOOTER -->') точным побитным срезом исходника
 *    (content_raw=true). Никаких автоподстрой regex-правил, никаких срезов
 *    body→</body>: навигация, футер и JS в контент НЕ попадают.
 *  - Файлы без маркеров считаются НЕСООТВЕТСТВУЮЩИМИ контракту: parse()
 *    возвращает content='' с явной ошибкой required-блока — импорт их
 *    отвергает, а не тащит в БД весь шаблон. Это ровно то поведение,
 *    что было до появления данного класса.
 *  - Единственная оставшаяся функция — slugFromSourceCanonical(): если
 *    штатный парсер не применил slug_from_canonical (в конфиге блока
 *    'slug' нет source='slug_from_canonical'), подставляет slug из
 *    <link rel="canonical"> исходника вместо translit-слэга из <title>.
 *  - Исторические эвристики (bare-template slice, stripSourceCanonical,
 *    self-link slug fallback, schema synthesis) удалены: они ломали
 *    контракт побитного импорта (страницы залетали в БД вместе
 *    с nav/footer/js).
 */

namespace Core\Module\Admin\Services;

use Core\System\Config\Config;
use Core\System\Parser\HtmlParser;

/**
 * Парсер импорта, работающий строго по стандартному контракту движка
 * (маркеры из config/import-parser.php + побитный raw-срез).
 *
 * От HtmlImportParser отличается только одним усилением: slug страницы
 * берётся из canonical-URL исходника (canonical_mode='source'), даже если
 * блок 'slug' в конфиге не размечен как 'slug_from_canonical'. Никакого
 * изменения правил вырезания контента здесь нет и быть не должно.
 */
class TemplateAwareImportParser extends HtmlImportParser
{
    public function __construct(Config $config)
    {
        parent::__construct($config);
    }

    /**
     * Разбор HTML по стандартному контракту движка.
     *
     * @return array<string,mixed> контракт как у HtmlParser::parse()
     */
    public function parse(string|array $content): array
    {
        $parsed = parent::parse($content);

        return $this->applySourceCanonicalSlug($parsed);
    }

    /**
     * Slug из canonical источника — только если штатный parse() его не
     * применил (т.е. ни один блок не имеет source='slug_from_canonical').
     * Контент при этом НЕ трогается: правится исключительно поле slug.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function applySourceCanonicalSlug(array $parsed): array
    {
        if (($this->getRules()['blocks']['slug']['source'] ?? '') === 'slug_from_canonical') {
            return $parsed; // штатный механизм уже отработал в parent::parse()
        }

        $sourceCanonical = trim((string) ($parsed['values']['canonical'] ?? ''));
        if ($sourceCanonical === '') {
            return $parsed;
        }

        $slug = HtmlParser::slugFromCanonicalUrl($sourceCanonical);
        if ($slug === '') {
            return $parsed;
        }

        $parsed['slug'] = $slug;
        if (array_key_exists('slug', $parsed['values'])) {
            $parsed['values']['slug'] = $slug;
        }

        return $parsed;
    }
}
