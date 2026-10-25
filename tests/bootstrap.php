<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit-окружения.
 *
 * В CI (GitHub Actions) config.env отсутствует намеренно — секреты не
 * коммитятся. Системные классы (SessionManager, CsrfToken), обращающиеся
 * к Config::getInstance() лениво, падали с «Config не инициализирован».
 * Здесь создаётся временный тестовый env и пробрасывается через
 * VCORE_TEST_CONFIG_ENV; Config использует его как последний fallback
 * (см. Core\System\Config\Config::getInstance). Если реальный config.env
 * присутствует локально — он имеет приоритет, ничего не меняется.
 */
if (getenv('VCORE_TEST_CONFIG_ENV') === false || !is_file((string) getenv('VCORE_TEST_CONFIG_ENV'))) {
    $tmpEnv = sys_get_temp_dir() . '/vcore_phpunit_config.env';
    if (!is_file($tmpEnv)) {
        file_put_contents(
            $tmpEnv,
            "APP_ENV=test\nAPP_DEBUG=false\nAPP_NAME=test\nSECURITY_ENFORCE_CONFIG_PERMS=false\n"
        );
    }
    @chmod($tmpEnv, 0640);
    putenv('VCORE_TEST_CONFIG_ENV=' . $tmpEnv);
}
