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

use Core\System\Validator\ValidationRule;

/**
 * Правило "максимальная длина"
 */
class MaxLengthRule implements ValidationRule
{
    private int $max;

    public function __construct(int $max)
    {
        $this->max = $max;
    }

    public function validate(mixed $value, string $field, array $data = []): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return mb_strlen($value) <= $this->max;
    }

    public function getMessage(string $field, array $params = []): string
    {
        return "Поле '{$field}' должно содержать максимум {$this->max} символов";
    }

    public function getName(): string
    {
        return 'max_length';
    }
}