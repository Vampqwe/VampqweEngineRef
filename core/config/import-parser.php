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

    // Файл промежуточных стилей в storage (относительный путь).
    'styles_file' => 'neutral-style.css',

    // Максимум редиректов при скачивании изображений.
    'image_max_redirects' => 3,

    // Таймаут HTTP-запроса при скачивании изображений, сек.
    'image_timeout_s' => 10,

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
     *  - 'collect_styles': (marker/xpath) уносить ли <style> из блока в neutral-style.css.
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

        // Slug: генерируется из title (спец-источник), не парсится из HTML
        'slug' => [
            'source' => 'slug_from_title',
            'target' => ['table' => 'pages', 'column' => 'slug'],
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
        'schema' => [
            'type'   => 'schema',
            'target' => [
                'table' => 'schema_org',
                'mode'  => 'rows',
                'fields' => [
                    'schema_type' => '{schema_type}',
                    'data'        => '{json}',
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
