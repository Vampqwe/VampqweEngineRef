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


namespace Core\System\Application;

/**
 * Единая модель состояния движка для мониторинга и шлюза установки.
 *
 * Разделяет три разных ситуации, которые раньше сливались в одну
 * boolean-проверку «установлен / не установлен»:
 *
 *  - INSTALLED      — метка storage/.installed есть, таблицы в БД есть;
 *  - NOT_INSTALLED  — установка не выполнена (нет метки и/или нет таблиц):
 *                     пользователю показывается страница «Движок не установлен»;
 *  - DB_UNREACHABLE — метка на месте, но соединение с БД не устанавливается:
 *                     движок установлен, просто база временно недоступна
 *                     (сетевой сбой, рестарт MySQL). Это readiness-проблема,
 *                     а НЕ сигнал сброса установки.
 *
 * Так liveness/readiness-пробы и мастер установки перестают путать
 * «пустую базу при первом запуске» и «живой сайт без БД».
 */
enum EngineState: string
{
    case Installed      = 'installed';
    case NotInstalled   = 'not_installed';
    case DbUnreachable  = 'db_unreachable';

    public function isInstalled(): bool
    {
        return $this === self::Installed || $this === self::DbUnreachable;
    }

    /** Требует ли состояние вмешательства (мастер установки / починка БД). */
    public function needsAttention(): bool
    {
        return $this !== self::Installed;
    }
}
