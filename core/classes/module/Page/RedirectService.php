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


namespace Core\Module\Page;

use Core\System\Application\Application;
use Core\System\Application\InstalledGuard;
use Core\System\DataBase\DataBase;
use Throwable;

/**
 * Сервис для работы с редиректами из БД
 */
class RedirectService
{
    private DataBase $db;

    public function __construct(DataBase $db)
    {
        $this->db = $db;
    }

    /**
     * Найти редирект по исходному URL
     *
     * @return array{destination: string, status_code: int}|null
     */
    public function findRedirect(string $source): ?array
    {
        // Движок не установлен (нет метки .installed или таблиц в БД) —
        // молча считаем, что редиректов нет. Страховка на случай прямого
        // вызова сервиса мимо InstallGateMiddleware: запрос к несуществующей
        // таблице redirects раньше ронял весь сайт с DataBaseException.
        if (!$this->isReady()) {
            return null;
        }

        // Нормализуем путь
        $source = '/' . ltrim($source, '/');

        try {
            $redirect = $this->db->fetchOne(
                "SELECT destination, status_code FROM redirects
                 WHERE source = :source AND is_active = 1
                 LIMIT 1",
                ['source' => $source]
            );
        } catch (Throwable) {
            // Таблица отсутствует / БД недоступна — редиректов нет, сайт жив.
            return null;
        }

        if (!$redirect) {
            return null;
        }

        // Увеличиваем счетчик
        $this->db->execute(
            "UPDATE redirects SET hit_count = hit_count + 1 WHERE source = :source",
            ['source' => $source]
        );

        return [
            'destination' => $redirect['destination'],
            'status_code' => (int) $redirect['status_code'],
        ];
    }

    /**
     * Проверить и вернуть Response-редирект если нужно
     */
    public function checkAndRedirect(string $source): ?\Core\System\Http\Response
    {
        $redirect = $this->findRedirect($source);

        if ($redirect === null) {
            return null;
        }

        $statusCode = $redirect['status_code'] === 302
            ? \Core\System\Http\StatusCode::FOUND
            : \Core\System\Http\StatusCode::MOVED_PERMANENTLY;

        return \Core\System\Http\Response::redirect($redirect['destination'], $statusCode);
    }

    /**
     * Готова ли подсистема редиректов: метка установки + таблицы в БД.
     */
    private function isReady(): bool
    {
        try {
            $guard = Application::getInstance()->make(InstalledGuard::class);
            return $guard->isInstalled();
        } catch (Throwable) {
            // Guard не зарегистрирован (например, в юнит-тестах) —
            // полагаемся на try/catch вокруг самого запроса.
            return true;
        }
    }
}