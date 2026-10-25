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


namespace Core\System\Security;

final class TrustedProxy
{
    public static function contains(string $ip, string $trustedProxies): bool
    {
        $packedIp = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($packedIp === false) {
            return false;
        }

        foreach (array_map('trim', explode(',', $trustedProxies)) as $trustedProxy) {
            if ($trustedProxy === '') {
                continue;
            }

            if (!str_contains($trustedProxy, '/')) {
                $trustedIp = filter_var($trustedProxy, FILTER_VALIDATE_IP) !== false
                    ? inet_pton($trustedProxy)
                    : false;
                if ($trustedIp !== false && hash_equals($trustedIp, $packedIp)) {
                    return true;
                }
                continue;
            }

            [$network, $prefix] = explode('/', $trustedProxy, 2);
            $packedNetwork = filter_var($network, FILTER_VALIDATE_IP) !== false
                ? inet_pton($network)
                : false;
            $prefixLength = filter_var($prefix, FILTER_VALIDATE_INT);

            if ($packedNetwork === false || $prefixLength === false || strlen($packedNetwork) !== strlen($packedIp)) {
                continue;
            }

            $maxPrefix = strlen($packedIp) * 8;
            if ($prefixLength < 0 || $prefixLength > $maxPrefix) {
                continue;
            }

            $wholeBytes = intdiv($prefixLength, 8);
            $remainingBits = $prefixLength % 8;
            if (substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
                continue;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}