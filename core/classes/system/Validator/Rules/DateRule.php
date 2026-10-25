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
use DateTime;

class DateRule implements ValidationRule
{
    private ?string $format;

    public function __construct(?string $format = null)
    {
        $this->format = $format;
    }

    public function validate(mixed $value, string $field, array $data = []): bool
    {
        if (!is_string($value)) {
            return false;
        }

        if ($this->format !== null) {
            $date = DateTime::createFromFormat($this->format, $value);
            return $date !== false && $date->format($this->format) === $value;
        }

        return strtotime($value) !== false;
    }

    public function getMessage(string $field, array $params = []): string
    {
        if ($this->format !== null) {
            return "Поле '{$field}' должно быть датой в формате {$this->format}";
        }

        return "Поле '{$field}' должно быть валидной датой";
    }

    public function getName(): string
    {
        return 'date';
    }
}