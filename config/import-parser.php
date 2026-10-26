<?php

declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * Правила парсинга/импорта HTML-страниц (модуль «Админ-панель»,
 * сервис HtmlImportParser). Все управляемые значения импорта — здесь.
 */

return [

    // Максимальный размер загружаемого HTML-файла, байт (20 МБ).
    'max_file_size' => 20 * 1024 * 1024,

    // Максимальный размер скачиваемого изображения, байт (10 МБ).
    'max_image_size' => 10 * 1024 * 1024,

    // Разрешённые расширения загружаемых файлов.
    'allowed_extensions' => ['html', 'htm'],

    // Подкаталог storage для загруженных файлов импорта.
    'upload_dir' => 'uploads/import',

    // ⚠️ МЕХАНИКА КОПИРОВАНИЯ СТИЛЕЙ ИЗ СТРАНИЦ ПРИ ИМПОРТЕ УДАЛЕНА:
    // импорт не извлекает <style> из HTML, не вырезает их из контента и
    // НЕ создаёт никаких CSS-файлов (ни общий neutral-style.css, ни
    // per-page page-styles/*.css). Стили страницы живут там же, где были
    // задуманы — в контенте страницы и в её собственных подключённых
    // стилях (meta/layouts). Ключи 'styles_file'/'styles_mode' упразднены.

    // Максимум редиректов при скачивании изображений.
    'image_max_redirects' => 3,

    // Таймаут HTTP-запроса при скачивании изображений, сек.
    'image_timeout_s' => 10,

    /**
     * Режим canonical для импортируемых страниц:
     *  - 'derived' (по умолчанию): canonical = APP_BASE_URL + slug;
     *  - 'source': canonical берётся из <link rel="canonical"> исходника
     *    целиком, а slug вырезается из path этого URL (блок 'slug' с
     *    source='slug_from_canonical'). Если canonical в файле отсутствует —
     *    автоматический возврат к 'derived'.
     */
    'canonical_mode' => 'source',

    /**
     * Побитный режим импорта контента: true — pages.content сохраняется
     * ТОЧНЫМ срезом исходного HTML между маркерами main_content (включая
     * сами комментарии), без DOM-нормализации, скачивания картинок и сбора
     * стилей. Работает в связке с content_mode='raw' в config/page-content.php.
     *
     * ⚠️ ПРАВИЛО ДОВЕРЕННОГО ИМПОРТА — НЕ ТРОГАТЬ !!!
     * В этом режиме на всей цепочке (парсер → writer → БД) из контента не
     * вырезается НИЧЕГО: ни <style>, ни внешние ссылки, ни обёртки. В БД
     * ложатся байты исходного файла один-в-один. Использовать только для
     * доверенных источников HTML; при импорте чужих файлов выставьте
     * 'content_raw' => false (тогда включится санитайзер).
     */
    'content_raw' => true,

    /**
     * ═══════════════════════════════════════════════════════════════════
     * БЛОКИ ИМПОРТА (гибкая конфигурация)
     * ═══════════════════════════════════════════════════════════════════
     *
     * Каждый блок = «что вытянуть из HTML» + «куда положить в БД».
     * Ключ массива — машинное имя блока (используется в отчёте предпросмотра).
     *
     * Поля описания блока:
     *  - 'type'         : 'marker' | 'xpath' | 'meta' | 'schema' | 'script' | 'title'
     *      marker — срез HTML между двумя комментариями-маркерами;
     *      xpath  — узлы по XPath-выражению (собирается innerHTML найденного узла);
     *      meta   — значение атрибута <meta name="..."> или property="...";
     *      schema — JSON-LD (application/ld+json), как раньше;
     *      script — пути <script src="...">;
     *      title  — <title>, иначе первый <h1>.
     *  - 'start'        : (marker) открывающий маркер. Строка — точное имя
     *                     комментария (регистронезависимо), либо регулярка '~...~'.
     *  - 'end'          : (marker) закрывающий маркер; null = до конца документа.
     *  - 'expression'   : (xpath/meta/script/title) XPath; для meta — имя мета-тега.
     *  - 'fallback_xpath': (marker) если маркеры не найдены — взять узел по XPath.
     *  - 'required'     : true — отсутствие содержимого блока = ошибка импорта.
     *  - 'strip_wrapper': (marker/xpath) снимать ли служебную обёртку <!DOCTYPE/html/head/body>.
     *  - 'download_images': (marker/xpath) скачивать ли внешние <img> из блока.
     *  - 'target'       : куда писать:
     *        ['table' => 'pages', 'column' => 'content']            — обычная колонка;
     *        ['table' => 'schema_org', 'mode' => 'rows',
     *         'fields' => ['schema_type' => '{schema_type}', 'data' => '{json}']]
     *            — режим 'rows': каждая найденая сущность = отдельная строка;
     *              плейсхолдеры {json}, {schema_type}, {block} подставляются из данных;
     *        ['mode' => 'json_array']                                — список значений
     *              сериализуется в JSON-массив (для колонок типа json, напр. scripts).
     *      Колонки table/column проверяются по information_schema перед записью;
     *      запись идёт через подготовленные запросы (значения — только параметрами).
     *  - 'transform'    : 'trim' | 'slug' | 'lowercase' | 'strip_tags' | 'plain_text'
     *                     (применяется к строковому значению после извлечения).
     *  - 'default'      : значение по умолчанию, если блок пуст (например slug из title).
     *  - 'source'       : спец-источник: 'slug_from_title' — slug из заголовка страницы.
     */
    'blocks' => [

        // Заголовок страницы → pages.title
        'title' => [
            'type'   => 'title',
            'target' => ['table' => 'pages', 'column' => 'title'],
            'required' => true,
        ],

        // Slug: режим canonical_mode='source' — вырезается из path тега
        // <link rel="canonical"> исходника; если canonical отсутствует —
        // фолбэк на генерацию из title (slug_from_title).
        'slug' => [
            'source' => 'slug_from_canonical',
            'target' => ['table' => 'pages', 'column' => 'slug'],
        ],

        // Canonical из <link rel="canonical" href="...">. Извлечение — блоком
        // 'canonical' (type='meta' читает и link[rel]/href); запись в колонку
        // canonical выполняет PageImportWriter::buildPageRecord() с учётом
        // canonical_mode: 'source' — URL целиком из исходника, 'derived' —
        // APP_BASE_URL+slug. target.column=false — псевдо-колонка: значение
        // не пишется в БД напрямую, writer берёт его по имени блока из
        // $parsed['values']['canonical']. Колонка canonical NOT NULL, поэтому
        // блок помечен required: при canonical_mode='derived' это страховка
        // от пустого canonical; при отсутствии тега writer честно вернёт
        // ошибку предпросмотра.
        'canonical' => [
            'type'   => 'meta',
            'expression' => 'canonical',
            'required' => true,
            'target' => ['table' => 'pages', 'column' => false],
        ],

        // Meta keywords → pages.keywords (в layouts/main.html присутствует)
        'keywords' => [
            'type'   => 'meta',
            'expression' => 'keywords',
            'target' => ['table' => 'pages', 'column' => 'keywords'],
            'transform' => 'trim',
        ],

        // Meta robots → pages.meta_robots (дефолт как в шаблоне: index, follow)
        'robots' => [
            'type'   => 'meta',
            'expression' => 'robots',
            'target' => ['table' => 'pages', 'column' => 'meta_robots'],
            'default' => 'index, follow',
        ],

        // Open Graph: og:image → pages.og_image (поддерживает и
        // meta[property], и <link rel="og:image">). og_title/og_description
        // writer всегда берёт из title/description — отдельных блоков нет.
        'og_image' => [
            'type'   => 'meta',
            'expression' => 'og:image',
            'target' => ['table' => 'pages', 'column' => 'og_image'],
        ],

        // Meta description (og:description как fallback) → pages.description
        'description' => [
            'type'   => 'meta',
            'expression' => 'description',
            'fallback_meta' => ['property' => 'og:description'],
            'target' => ['table' => 'pages', 'column' => 'description'],
            'transform' => 'trim',
        ],

        // Основной контент между комментариями-маркерами → pages.content
        'main_content' => [
            'type'   => 'marker',
            // Поддерживаются и старые маркеры движка, и произвольные вида
            // <!-- nav --> ... <!-- end nav --> — задайте свои ниже.
            'start'  => '<!-- ОСНОВНОЙ КОНТЕНТ -->',
            'end'    => '<!-- FOOTER -->',
            // Fallback, если маркеры не найдены:
            'fallback_xpath' => '(//main | //article | //[@id="content"])[1]',
            'strip_wrapper'  => true,
            'collect_styles' => true,
            'download_images' => true,
            'verify_integrity' => true,
            'required' => true,
            'target'   => ['table' => 'pages', 'column' => 'content'],
        ],

        // Пример навигационного блока с маркерами вашего формата
        // (раскомментируйте и адаптируйте target под свою таблицу/колонку):
        // 'nav' => [
        //     'type'  => 'marker',
        //     'start' => '<---nav--->',
        //     'end'   => '<---end nav--->',
        //     'target' => ['table' => 'menu_items', 'column' => 'html'],
        // ],

        // Schema.org JSON-LD → строки таблицы schema_org
        // Ключи плейсхолдеров = колонки rows-строки парсера
        // (HtmlParser::extractSchema отдаёт schema_type/data/priority).
        'schema' => [
            'type'   => 'schema',
            'target' => [
                'table' => 'schema_org',
                'mode'  => 'rows',
                'fields' => [
                    'schema_type' => '{schema_type}',
                    'data'        => '{data}',
                    'priority'    => 10,
                ],
            ],
        ],

        // Пути JS-скриптов → pages.scripts (JSON-массив)
        'scripts' => [
            'type'   => 'script',
            'target' => ['table' => 'pages', 'column' => 'scripts', 'mode' => 'json_array'],
            'default' => ['/assets/js/main.js'],
        ],
    ],

    /**
     * Псевдонимы маркеров: часто один и тот же логический блок называется
     * по-разному в разных шаблонах. Формат — PCRE-регулярка без модификатора
     * (модификаторы iu/s добавляются автоматически). Применяются, если
     * основной 'start'/'end' блока не найден.
     */
    'marker_aliases' => [
        'start' => [
            '/<!--\\s*ОСНОВНОЙ\\s+КОНТЕНТ\\s*-->/u',
            '/<!--\\s*(?:MAIN[_ ]?)?CONTENT\\s*-->/iu',
            '/<!--[<-]*\\s*nav(?:\\s+(?:start|begin))?\\s*[>-]+-->/iu',
        ],
        'end' => [
            '/<!--\\s*(?:PAGE\\s+)?FOOTER\\s*-->/iu',
            '/<!--\\s*END\\s+(?:MAIN|CONTENT)\\s*-->/iu',
            '/<!--[<-]*\\s*end\\s*nav\\s*[>-]+-->/iu',
        ],
    ],

    /**
     * Типы schema.org, чьё поле "name" является содержательным (название
     * пункта меню, услуги, процедуры, шага, вопроса и т.п.) — НЕ подлежит
     * замене на APP_NAME. Для всех прочих типов (Organization, WebSite,
     * LocalBusiness, Physician и др.) имя бренда подставляется тотально.
     */
    'name_keep_types' => [
        // Структурные / навигационные
        'ListItem',
        'SiteNavigationElement',
        'BreadcrumbList',
        'HowToStep',
        'HowToSection',
        'ItemList',
        // Коммерческие сущности
        'Offer',
        'Demand',
        'Product',
        'Service',
        'ProgramMembership',
        // Медицинский контент
        'MedicalProcedure',
        'MedicalTherapy',
        'MedicalTest',
        'MedicalCondition',
        'MedicalSymptom',
        'MedicalCause',
        'MedicalRiskFactor',
        'DietarySupplement',
        'ExercisePlan',
        // FAQ / события / медиа
        'Question',
        'Answer',
        'Event',
        'ImageObject',
        'VideoObject',
        'AudioObject',
    ],
];
