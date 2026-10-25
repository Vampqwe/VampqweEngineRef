<?php

declare(strict_types=1);

/**
 * VampqweEngineMedium — проприетарный PHP-движок
 *
 * @copyright  Copyright (c) 2024-2026. Все права защищены.
 * @author     [Дедюля Александр Иванович / VampqweEngine]
 * @license    Proprietary
 * @link       https://github.com/Vampqwe/VampqweEngine.git
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
 * Единая точка определения корня проекта (APP_ROOT_PATH).
 *
 * Иерархия источников (по приоритету):
 *  1. явный $explicitRoot (Application::init() из index.php);
 *  2. константа VCORE_ROOT (если определена до загрузки автозагрузчика);
 *  3. config.env → APP_ROOT_PATH (абсолютный путь к корню проекта);
 *  4. fallback — реальный корень движка от расположения этого файла
 *     (core/classes/system/Application → 4 уровня вверх).
 *
 * ВСЕ потребители базового пути обязаны использовать этот класс
 * (напрямую или через Application::getBasePath()/base_path()).
 * Прямое чтение FS_BASE_PATH/APP_BASE_PATH/getcwd() для вычисления
 * корня проекта ЗАПРЕЩЕНО.
 */
final class ProjectRoot
{
    /** Ключ config.env, задающий корень проекта. */
    public const CONFIG_KEY = 'APP_ROOT_PATH';

    private static ?string $resolved = null;

    /**
     * Установить корень явно (вызывается Application при init).
     * Повторные вызовы с тем же значением безопасны (идемпотентность).
     */
    public static function set(?string $explicitRoot): void
    {
        $normalized = self::normalize($explicitRoot ?? '');

        if ($normalized === '') {
            return;
        }

        if (self::$resolved !== null && self::$resolved !== $normalized) {
            throw new ApplicationException(
                'Попытка изменить корень проекта после его фиксации: '
                . self::$resolved . ' → ' . $normalized
            );
        }

        self::$resolved = $normalized;
    }

    /**
     * Текущий корень проекта. Если ещё не зафиксирован — детектируется
     * по иерархии источников (см. доккласс), но НЕ кешируется: явный
     * set() (Application::init) имеет приоритет над ранней детекцией.
     */
    public static function get(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        return self::detect();
    }

    /**
     * Зафиксированный корень или null (для диагностических проверок).
     */
    public static function current(): ?string
    {
        return self::$resolved;
    }

    public static function reset(): void
    {
        self::$resolved = null;
    }

    /**
     * Собрать путь внутри корня проекта.
     */
    public static function path(string $subpath = ''): string
    {
        $root = self::get();

        return $subpath === ''
            ? $root
            : $root . DIRECTORY_SEPARATOR . ltrim(self::normalize($subpath), '/\\');
    }

    /**
     * Детекция корня без явного set(): VCORE_ROOT → APP_ROOT_PATH из
     * config.env → fallback от расположения файлов движка.
     */
    private static function detect(): string
    {
        // 1. Константа VCORE_ROOT (тот же контракт, что и у Config::getInstance()).
        $vcore = defined('VCORE_ROOT') ? self::normalize((string) VCORE_ROOT) : '';
        if ($vcore !== '' && is_dir($vcore)) {
            return $vcore;
        }

        // 2. config.env: ищем рядом с этим файлом (не по getcwd()!) и читаем APP_ROOT_PATH.
        $envFile = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'config.env';

        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, self::CONFIG_KEY . '=')) {
                    continue;
                }

                $value = self::normalize(trim($line, " \t\"'") === ''
                    ? substr($line, strlen(self::CONFIG_KEY . '='))
                    : trim(substr($line, strlen(self::CONFIG_KEY . '=')), " \t\"'"));

                if ($value !== '' && is_dir($value)) {
                    return $value;
                }

                break;
            }
        }

        // 3. Fallback — физический корень движка.
        return self::fallbackRoot();
    }

    /**
     * Физический корень проекта от расположения файлов движка
     * (core/classes/system/Application → 4 уровня вверх).
     */
    public static function fallbackRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function normalize(string $path): string
    {
        return rtrim($path, '/\\');
    }
}
