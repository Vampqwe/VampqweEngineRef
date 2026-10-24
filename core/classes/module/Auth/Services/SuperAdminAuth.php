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


namespace Core\Module\Auth\Services;

use Core\System\Config\Config;

/**
 * Проверка супер-администратора из config.env.
 * Супер-админ не хранится в БД — он всегда доступен,
 * даже если БД удалена или повреждена.
 */
class SuperAdminAuth
{
    /** Нижний порог времени ответа isSuperAdmin(), мс (анти-timing). */
    private const MIN_RESPONSE_MS = 50;

    /**
    * Bcrypt-полином (cost 12) для «холостого» password_verify, когда хеш
     * супер-админа пуст/бит — время ветки остаётся сопоставимым.
     */
    private const DECOY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEeO7bJ1pB4M0/3lB4RgHZ8vV1qZ0GnQWOW';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Проверить, является ли пользователь супер-админом
     */
    public function isSuperAdmin(string $email, string $password): bool
    {
        // Минимальное время ответа (мс) для нормализации тайминга: без него
        // по разнице откликов различимы ветки «email неверный» и «bcrypt».
        $start = microtime(true);
        try {
            return $this->check($email, $password);
        } finally {
            $elapsedMs = (int) round((microtime(true) - $start) * 1000);
            if ($elapsedMs < self::MIN_RESPONSE_MS) {
                usleep((self::MIN_RESPONSE_MS - $elapsedMs) * 1000);
            }
        }
    }

    private function check(string $email, string $password): bool
    {
        $adminEmail = (string) $this->config->get('SUPER_ADMIN_EMAIL', '');
        $adminHash = (string) $this->config->get('SUPER_ADMIN_PASSWORD', '');

        if ($adminEmail === '' || $adminHash === '') {
            return false;
        }

        // hash_equals — константное время сравнения для email.
        $emailMatches = hash_equals($adminEmail, $email);

        // ВСЕГДА выполняем password_verify (даже при неверном email), чтобы
        // время ответа не зависело от того, угадан ли адрес (timing attack).
        // При пустом/битом хеше используем валидный bcrypt-полином той же стоимости.
        $passwordMatches = password_verify(
            $password,
            password_get_info($adminHash)['algo'] !== null && $adminHash !== ''
                ? $adminHash
                : self::DECOY_HASH
        );

        return $emailMatches && $passwordMatches;
    }

    /**
     * Получить данные супер-админа
     */
    public function getSuperAdminData(): array
    {
        return [
            'id' => 0, // Супер-админ не в БД
            'email' => $this->config->get('SUPER_ADMIN_EMAIL', ''),
            'name' => $this->config->get('SUPER_ADMIN_NAME', 'Супер Администратор'),
            'role' => 'super_admin',
            'is_super' => true,
        ];
    }

    /**
     * Сгенерировать хэш пароля (для CLI-утилит)
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}