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


namespace Core\System\TimeDate;

/**
 * Enum стандартных форматов даты и времени
 */
enum DateFormat: string
{
    case ISO8601   = 'Y-m-d\TH:i:sP';
    case Date      = 'Y-m-d';
    case Time      = 'H:i:s';
    case DateTime  = 'Y-m-d H:i:s';
    case Timestamp = 'U';
    case RFC2822   = 'r';
    case Cookie    = 'l, d-M-Y H:i:s T';
}