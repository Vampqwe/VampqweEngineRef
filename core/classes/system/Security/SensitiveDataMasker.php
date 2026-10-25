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

/**
 * Маскирование чувствительных данных в логах и выводе
 */
class SensitiveDataMasker
{
    private const MASK = '***REDACTED***';

    /** @var string[] */
    private array $sensitiveKeys;

    /**
     * @param string[] $sensitiveKeys
     */
    public function __construct(array $sensitiveKeys = [])
    {
        $this->sensitiveKeys = array_map('strtolower', $sensitiveKeys);
    }

    /**
     * Создать из строки с разделителем-запятой
     */
    public static function fromString(string $keysString, string $delimiter = ','): self
    {
        $keys = array_map('trim', explode($delimiter, $keysString));
        return new self($keys);
    }

    /**
     * Маскировать чувствительные данные в массиве (рекурсивно)
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function mask(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $keyStr = is_string($key) ? $key : (string) $key;

            if (is_array($value)) {
                $result[$key] = $this->mask($value);
            } elseif ($this->isSensitiveKey($keyStr)) {
                $result[$key] = self::MASK;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Маскировать email (оставить первые 2 символа и домен)
     */
    public function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return self::MASK;
        }

        [$local, $domain] = explode('@', $email, 2);
        $masked = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2));

        return $masked . '@' . $domain;
    }

    /**
     * Маскировать строку (оставить первые и последние N символов)
     */
    public function maskString(string $value, int $visibleStart = 0, int $visibleEnd = 0): string
    {
        $length = mb_strlen($value);

        if ($length <= $visibleStart + $visibleEnd) {
            return self::MASK;
        }

        $start = $visibleStart > 0 ? mb_substr($value, 0, $visibleStart) : '';
        $end = $visibleEnd > 0 ? mb_substr($value, -$visibleEnd) : '';
        $middle = str_repeat('*', max(1, $length - $visibleStart - $visibleEnd));

        return $start . $middle . $end;
    }

    /**
     * Маскировать номер карты (показать только последние 4)
     */
    public function maskCardNumber(string $cardNumber): string
    {
        $cleaned = preg_replace('/\D/', '', $cardNumber);

        if ($cleaned === null || strlen($cleaned) < 4) {
            return self::MASK;
        }

        return str_repeat('*', strlen($cleaned) - 4) . substr($cleaned, -4);
    }

    /**
     * Проверить, является ли ключ чувствительным
     */
    public function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->sensitiveKeys as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Добавить ключ в список чувствительных
     */
    public function addSensitiveKey(string $key): self
    {
        $this->sensitiveKeys[] = strtolower($key);
        return $this;
    }

    /**
     * Получить список чувствительных ключей
     *
     * @return string[]
     */
    public function getSensitiveKeys(): array
    {
        return $this->sensitiveKeys;
    }
}