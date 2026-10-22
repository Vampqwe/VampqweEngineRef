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
 * Правило "значение в диапазоне"
 */
class BetweenRule implements ValidationRule
{
    private int|float $min;
    private int|float $max;

    public function __construct(int|float $min, int|float $max)
    {
        $this->min = $min;
        $this->max = $max;
    }

    public function validate(mixed $value, string $field, array $data = []): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        $num = (float) $value;

        return $num >= $this->min && $num <= $this->max;
    }

    public function getMessage(string $field, array $params = []): string
    {
        return "Поле '{$field}' должно быть в диапазоне от {$this->min} до {$this->max}";
    }

    public function getName(): string
    {
        return 'between';
    }
}