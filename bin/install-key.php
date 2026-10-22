<?php
declare(strict_types=1);

/**
 * CLI-утилита: сгенерировать и сохранить хэш ключа активации мастера установки.
 *
 * Использование:
 *   php bin/install-key.php generate   — создать новый случайный ключ,
 *                                        вывести его ОДИН раз и сохранить хэш
 *                                        в storage/.install_key
 *   php bin/install-key.php revoke     — отозвать сохранённый ключ (удалить файл)
 *
 * Доверенный контекст: запуск только из CLI (локально/по SSH).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Только CLI-режим.\n");
    exit(1);
}

$basePath = dirname(__DIR__);
$keyFile  = $basePath . '/storage/.install_key';
$action   = $argv[1] ?? '';

switch ($action) {
    case 'generate':
        $key = bin2hex(random_bytes(16));
        if (!is_dir(dirname($keyFile))) {
            mkdir(dirname($keyFile), 0755, true);
        }
        // HMAC-ключ должен совпадать с InstallService: берём INSTALL_HMAC_KEY
        // из config.env; при отсутствии — process-random (хэш переживёт только
        // текущий запуск CLI, что безопасно: ключ генерируется тут же).
        $hmacKey = '';
        $envFile = $basePath . '/config.env';
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^INSTALL_HMAC_KEY=(.*)$/', trim($line), $m)) {
                    $hmacKey = trim($m[1], " \"'");
                }
            }
        }
        if ($hmacKey === '') {
            $hmacKey = bin2hex(random_bytes(32));
            fwrite(STDERR, "ВНИМАНИЕ: INSTALL_HMAC_KEY не задан в config.env - хэш сохранён с одноразовым ключом.\n");
            fwrite(STDERR, "Задайте INSTALL_HMAC_KEY=<секрет> и перегенерируйте ключ, иначе проверка доступа не будет работать между процессами PHP.\n");
        }
        $hash = hash_hmac('sha256', $key, $hmacKey);
        file_put_contents($keyFile, $hash, LOCK_EX);
        chmod($keyFile, 0600);

        fwrite(STDOUT, "Новый ключ активации (покажите один раз, сохранён только хэш):\n  {$key}\n");
        fwrite(STDOUT, "Файл: {$keyFile}\n");
        break;

    case 'revoke':
        if (is_file($keyFile)) {
            unlink($keyFile);
            fwrite(STDOUT, "Ключ активации отозван.\n");
        } else {
            fwrite(STDOUT, "Файл ключа не найден — отзыв не требуется.\n");
        }
        break;

    default:
        fwrite(STDERR, "Использование: php bin/install-key.php [generate|revoke]\n");
        exit(1);
}
