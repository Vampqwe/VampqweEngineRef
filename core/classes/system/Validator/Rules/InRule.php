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

class InRule implements ValidationRule
{
    /** @var array<mixed> */
    private array $allowed;

    /**
     * @param array<mixed> $allowed
     */
    public function __construct(array $allowed)
    {
        $this->allowed = $allowed;
    }

    public function validate(mixed $value, string $field, array $data = []): bool
    {
        return in_array($value, $this->allowed, true);
    }

    public function getMessage(string $field, array $params = []): string
    {
        $allowed = implode(', ', array_map('strval', $this->allowed));
        return "Поле '{$field}' должно быть одним из: {$allowed}";
    }

    public function getName(): string
    {
        return 'in';
    }
}