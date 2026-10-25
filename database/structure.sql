-- =====================================================
-- Структура БД для сайта "Достойный уход"
-- Только CREATE TABLE, индексы и FK. Без INSERT'ов.
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Таблица: authors
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `authors` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Имя автора',
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ЧПУ-слаг для страницы автора',
  `bio` text COLLATE utf8mb4_unicode_ci COMMENT 'Краткая биография',
  `photo` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL фото автора',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Email автора (не публикуется)',
  `schema_person` json DEFAULT NULL COMMENT 'Schema.org Person (JSON-LD)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Активен ли автор',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Авторы статей (для E-E-A-T и Schema.org Person)';

-- --------------------------------------------------------
-- Таблица: menu_items
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int DEFAULT NULL COMMENT 'ID родительского пункта (NULL = корень)',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название пункта меню',
  `url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL (если пустой — группа-заголовок)',
  `page_id` int DEFAULT NULL COMMENT 'Связь со страницей из БД (авто-обновление URL)',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки (меньше = выше)',
  `is_visible` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Видим в меню',
  `is_external` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Открывать в новой вкладке',
  `css_class` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Дополнительные CSS-классы',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Иконка (emoji или класс)',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Заметка (для администратора)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_visible` (`is_visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Таблица: pages
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Title страницы (<title>)',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Meta description',
  `keywords` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Meta keywords',
  `canonical` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Canonical URL',
  `og_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Open Graph: og:title',
  `og_description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Open Graph: og:description',
  `meta_robots` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'index, follow' COMMENT 'Meta robots',
  `og_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Open Graph: og:image',
  `reading_time` int UNSIGNED DEFAULT NULL COMMENT 'Время чтения статьи (минуты)',
  `views_count` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Количество просмотров страницы',
  `author_id` int UNSIGNED DEFAULT NULL COMMENT 'ID автора статьи (для E-E-A-T)',
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ЧПУ-слаг',
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'HTML-контент страницы',
  `scripts` json DEFAULT NULL COMMENT 'Уникальные JS-скрипты страницы (JSON-массив путей)',
  `page_type` enum('home','hub','article','404','500','page','contact','about') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'article' COMMENT 'Тип страницы',
  `parent_id` int UNSIGNED DEFAULT NULL COMMENT 'ID родительской страницы (для иерархии)',
  `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'Статус публикации',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки в меню',
  `is_in_menu` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Показывать в навигации (0/1)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `published_at` timestamp NULL DEFAULT NULL COMMENT 'Дата публикации',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_canonical` (`canonical`),
  KEY `idx_status` (`status`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_menu` (`is_in_menu`, `sort_order`),
  KEY `idx_type_status` (`page_type`, `status`),
  KEY `fk_page_author` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Страницы сайта "Достойный уход"';

-- --------------------------------------------------------
-- Таблица: redirects
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `redirects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `source` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Исходный URL (относительный, без домена)',
  `destination` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Куда перенаправлять',
  `status_code` smallint NOT NULL DEFAULT '301' COMMENT '301=постоянный, 302=временный',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `hit_count` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Счётчик срабатываний',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Заметка (зачем создан)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source` (`source`),
  KEY `idx_active` (`is_active`),
  KEY `idx_status` (`status_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Таблица: schema_org
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_org` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id` int UNSIGNED DEFAULT NULL COMMENT 'ID страницы (NULL для глобальных схем)',
  `route` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Маршрут для глобальных схем (*, /path/)',
  `schema_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Тип схемы: VeterinaryCare, Article, WebPage...',
  `data` json NOT NULL COMMENT 'Данные схемы в JSON (без @context и @type)',
  `priority` int NOT NULL DEFAULT '0' COMMENT 'Приоритет (больше = важнее)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Активна ли схема',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_page_schema` (`page_id`, `schema_type`),
  UNIQUE KEY `uk_route_schema` (`route`, `schema_type`),
  KEY `idx_page` (`page_id`),
  KEY `idx_route` (`route`),
  KEY `idx_active` (`is_active`),
  KEY `idx_type` (`schema_type`),
  KEY `idx_route_active` (`route`, `is_active`),
  KEY `idx_page_active` (`page_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Schema.org разметки для SEO/GEO';

-- --------------------------------------------------------
-- Таблица: tasks
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('todo','in_progress','done','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'todo',
  `priority` enum('low','normal','high','critical') COLLATE utf8mb4_unicode_ci DEFAULT 'normal',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'frontend, backend, bug, feature, seo, design',
  `due_date` date DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_category` (`category`),
  KEY `idx_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Таблица: users (для обычных пользователей админки)
-- Супер-админ хранится в config.env, а не здесь!
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Email для входа',
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Хэш пароля (password_hash)',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Имя пользователя',
  `role` enum('admin','editor','viewer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer' COMMENT 'Роль: admin, editor, viewer',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Активен ли пользователь',
  `last_login_at` timestamp NULL DEFAULT NULL COMMENT 'Последний вход',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_active` (`is_active`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Пользователи админки';

-- --------------------------------------------------------
-- Внешние ключи
-- --------------------------------------------------------
ALTER TABLE `menu_items`
  ADD CONSTRAINT `menu_items_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE;

ALTER TABLE `pages`
  ADD CONSTRAINT `fk_page_author` FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `schema_org`
  ADD CONSTRAINT `fk_schema_page` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;