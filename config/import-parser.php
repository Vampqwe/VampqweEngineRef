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
