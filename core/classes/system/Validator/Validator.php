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


namespace Core\System\Validator;

use Core\System\Validator\Rules\BetweenRule;
use Core\System\Validator\Rules\DateRule;
use Core\System\Validator\Rules\EmailRule;
use Core\System\Validator\Rules\InRule;
use Core\System\Validator\Rules\MaxLengthRule;
use Core\System\Validator\Rules\MinLengthRule;
use Core\System\Validator\Rules\NumericRule;
use Core\System\Validator\Rules\RegexRule;
use Core\System\Validator\Rules\RequiredRule;
use Core\System\Validator\Rules\UrlRule;
use InvalidArgumentException;
use LogicException;

/**
 * Валидатор данных с fluent API
 */
class Validator
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, ValidationRule[]> */
    private array $rules = [];

    private ?string $currentField = null;

    /** @var array<string, ValidationRule> */
    private array $customRules = [];

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function field(string $field): self
    {
        $this->currentField = $field;

        if (!isset($this->rules[$field])) {
            $this->rules[$field] = [];
        }

        return $this;
    }

    public function addRule(string $name, ValidationRule $rule): self
    {
        $this->customRules[$name] = $rule;
        return $this;
    }

    public function required(): self
    {
        $this->addCurrentRule(new RequiredRule());
        return $this;
    }

    public function email(): self
    {
        $this->addCurrentRule(new EmailRule());
        return $this;
    }

    public function minLength(int $min): self
    {
        $this->addCurrentRule(new MinLengthRule($min));
        return $this;
    }

    public function maxLength(int $max): self
    {
        $this->addCurrentRule(new MaxLengthRule($max));
        return $this;
    }

    public function numeric(): self
    {
        $this->addCurrentRule(new NumericRule());
        return $this;
    }

    public function between(int|float $min, int|float $max): self
    {
        $this->addCurrentRule(new BetweenRule($min, $max));
        return $this;
    }

    /**
     * @param array<mixed> $allowed
     */
    public function in(array $allowed): self
    {
        $this->addCurrentRule(new InRule($allowed));
        return $this;
    }

    public function regex(string $pattern): self
    {
        $this->addCurrentRule(new RegexRule($pattern));
        return $this;
    }

    public function url(): self
    {
        $this->addCurrentRule(new UrlRule());
        return $this;
    }

    public function date(?string $format = null): self
    {
        $this->addCurrentRule(new DateRule($format));
        return $this;
    }

    public function rule(string $name): self
    {
        if (!isset($this->customRules[$name])) {
            throw new InvalidArgumentException("Правило '{$name}' не зарегистрировано");
        }

        $this->addCurrentRule($this->customRules[$name]);
        return $this;
    }

    /**
     * @param callable(mixed, string, array): bool $callback
     */
    public function custom(callable $callback, string $message): self
    {
        $rule = new class($callback, $message) implements ValidationRule {
            /** @var callable */
            private $callback;
            private string $message;

            public function __construct(callable $callback, string $message)
            {
                $this->callback = $callback;
                $this->message = $message;
            }

            public function validate(mixed $value, string $field, array $data = []): bool
            {
                return ($this->callback)($value, $field, $data);
            }

            public function getMessage(string $field, array $params = []): string
            {
                return str_replace('{field}', $field, $this->message);
            }

            public function getName(): string
            {
                return 'custom';
            }
        };

        $this->addCurrentRule($rule);
        return $this;
    }

    public function validate(): ValidationResult
    {
        $errors = [];

        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                if (!$rule->validate($value, $field, $this->data)) {
                    if (!isset($errors[$field])) {
                        $errors[$field] = [];
                    }

                    $errors[$field][] = $rule->getMessage($field);
                }
            }
        }

        return new ValidationResult(empty($errors), $errors);
    }

    /**
     * @throws ValidationException
     */
    public function validateOrFail(): ValidationResult
    {
        $result = $this->validate();

        if ($result->isInvalid()) {
            throw ValidationException::fromResult($result);
        }

        return $result;
    }

    public function reset(): self
    {
        $this->data = [];
        $this->rules = [];
        $this->currentField = null;
        return $this;
    }

    private function addCurrentRule(ValidationRule $rule): void
    {
        if ($this->currentField === null) {
            throw new LogicException('Сначала вызовите field() для указания поля');
        }

        $this->rules[$this->currentField][] = $rule;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function make(array $data): self
    {
        return (new self())->setData($data);
    }
}