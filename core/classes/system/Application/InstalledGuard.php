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

use Core\System\DataBase\DataBase;
use PDO;
use Throwable;

/**
 * Сторож состояния движка — ЕДИНЫЙ ИСТОЧНИК ПРАВДЫ.
 *
 * Раньше статус установки проверялся в двух местах с разной логикой:
 *  - InstalledGuard::isInstalled()  = метка .installed + таблицы в БД;
 *  - InstallService::isInstalled()  = только метка .installed.
 * Из-за расхождения middleware мастера и глобальный шлюз могли давать
 * противоречивые ответы (например, мастер «уже установлено», а сайт — «не установлен»).
 * Теперь InstallService делегирует проверку сюда.
 *
 * additionally guard различает три состояния (EngineState):
 *  - NotInstalled   — нет метки и/или нет таблиц → страница «Движок не установлен»;
 *  - DbUnreachable  — метка есть, но БД не отвечает → это НЕ переустановка,
 *                     а временная readiness-проблема (мониторинг, 503 + Retry-After);
 *  - Installed      — всё в порядке.
 */
class InstalledGuard
{
    /** Маршруты, доступные без установки (мастер установки и health-пробы). */
    private const PUBLIC_WHITELIST_PREFIXES = [
        '/admin/install',
        // liveness/readiness-пробы должны отвечать ВСЕГДА,
        // включая состояние «не установлено» и «БД недоступна».
        '/api/health',
    ];

    /**
     * Служебные пути, которые НЕ обслуживаются отдельными маршрутами.
     *
     * Раньше '/not-installed' числился в whitelist, но соответствующего
     * маршрута не существовало: запрос уходил в fallback (PageController),
     * обращался к БД и падал либо давал 404 вместо заглушки. Теперь это
     * внутренний путь InstallGateMiddleware: он явно исключён из whitelist
     * и рендерится шлюзом напрямую (см. handle()).
     */
    private const INTERNAL_PATHS = ['/not-installed'];

    /** Ключевые таблицы движка, по которым судим о схеме БД. */
    private const REQUIRED_TABLES = ['pages', 'redirects'];

    private static ?EngineState $requestCache = null;

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * Полный признак установленности: файл метки + наличие ключевых таблиц.
     * Результат кэшируется на время текущего запроса.
     */
    public function isInstalled(): bool
    {
        return $this->state()->isInstalled();
    }

    /**
     * Текущее состояние движка (с кэшем на запрос).
     */
    public function state(): EngineState
    {
        if (self::$requestCache !== null) {
            return self::$requestCache;
        }

        return self::$requestCache = $this->resolveState();
    }

    /**
     * Сбросить кэш (используется мастером установки после успешной установки).
     */
    public static function resetCache(): void
    {
        self::$requestCache = null;
    }

    /**
     * Существует ли метка установки storage/.installed.
     */
    public function hasMarkerFile(): bool
    {
        return $this->checkMarkerFile();
    }

    /**
     * Доступен ли путь без установки (маршруты мастера установки и заглушка).
     */
    public function isWhitelistedPath(string $path): bool
    {
        // Внутренние пути шлюза (/not-installed) не пропускаем дальше —
        // они обслуживаются самим InstallGateMiddleware.
        foreach (self::INTERNAL_PATHS as $internal) {
            if ($path === $internal || str_starts_with($path, $internal . '/')) {
                return false;
            }
        }

        foreach (self::PUBLIC_WHITELIST_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    public function markerPath(): string
    {
        return rtrim($this->basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . '.installed';
    }

    // ─── Resolution ───

    private function resolveState(): EngineState
    {
        $hasMarker = $this->checkMarkerFile();

        $schema = $this->checkDatabaseSchema();

        if ($schema['connected'] && $schema['tables_present']) {
            // Таблицы на месте: если метки нет — установка фактически
            // не завершена (или удалён .installed) → ведём в мастер.
            return $hasMarker ? EngineState::Installed : EngineState::NotInstalled;
        }

        if (!$schema['connected']) {
            // Метка есть, но БД не отвечает → движок установлен, база «лежит».
            // Это readiness-состояние, а НЕ приглашение переустанавливать движок.
            return $hasMarker ? EngineState::DbUnreachable : EngineState::NotInstalled;
        }

        // Соединение есть, таблиц нет → пустая база при первом запуске
        // или конфиг указал на другую БД (SQLSTATE[42S02]).
        return EngineState::NotInstalled;
    }

    /**
     * Проверка файла-метки установки.
     */
    private function checkMarkerFile(): bool
    {
        return file_exists($this->markerPath());
    }

    /**
     * Проверка соединения с БД и наличия ключевых таблиц движка.
     *
     * Именно этот шаг спасает от падения, когда .installed удалили,
     * а конфиг указал на пустую/другую базу (SQLSTATE[42S02]).
     *
     * ПОЛИТИКА ДВИЖКА: рабочее подключение — только MySQL/MariaDB.
     * SQLite/PostgreSQL зарезервированы как неактивные драйверы и
     * здесь не обслуживаются (любое иное соединение считается невалидным).
     *
     * @return array{connected: bool, tables_present: bool}
     */
    private function checkDatabaseSchema(): array
    {
        try {
            $db  = Application::getInstance()->make(DataBase::class);
            $pdo = $db->getConnection();

            $driver = strtolower(
                (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)
            );

            // Только MySQL/MariaDB; любой другой драйвер считается невалидным.
            if ($driver !== 'mysql') {
                return ['connected' => true, 'tables_present' => false];
            }

            // Дёргаем information_schema — это безопасно и не зависит
            // от существования самих таблиц движка.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN (:t1, :t2)'
            );

            $stmt->execute(['t1' => self::REQUIRED_TABLES[0], 't2' => self::REQUIRED_TABLES[1]]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'connected'      => true,
                'tables_present' => isset($row['c']) && (int) $row['c'] >= count(self::REQUIRED_TABLES),
            ];
        } catch (Throwable) {
            // Нет соединения / нет БД — различаем это от «пустой схемы»
            // через EngineState::DbUnreachable (когда метка на месте).
            return ['connected' => false, 'tables_present' => false];
        }
    }
}
