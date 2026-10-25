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


namespace Core\System\Validator\Rules;

use Core\System\Security\SecurityException;
use Core\System\Validator\ValidationRule;

/**
 * Правило "соответствие регулярному выражению" с защитой от ReDoS
 */
class RegexRule implements ValidationRule
{
    private string $pattern;
    private int $maxLength;
    private float $timeoutMs;

    public function __construct(
        string $pattern,
        int $maxLength = 10000,
        float $timeoutMs = 100.0
    ) {
        $this->pattern = $pattern;
        $this->maxLength = $maxLength;
        $this->timeoutMs = $timeoutMs;

        // Проверяем валидность regex при создании
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException("Невалидное регулярное выражение: {$pattern}");
        }
    }

    public function validate(mixed $value, string $field, array $data = []): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Защита от слишком длинных строк
        if (mb_strlen($value) > $this->maxLength) {
            return false;
        }

        $start = microtime(true);
        $result = @preg_match($this->pattern, $value);
        $elapsed = (microtime(true) - $start) * 1000;

        // Защита от ReDoS
        if ($elapsed > $this->timeoutMs) {
            throw SecurityException::regexTimeout($this->pattern);
        }

        return $result === 1;
    }

    public function getMessage(string $field, array $params = []): string
    {
        return "Поле '{$field}' имеет неверный формат";
    }

    public function getName(): string
    {
        return 'regex';
    }
}