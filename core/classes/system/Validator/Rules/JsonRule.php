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
 * Правило «значение является валидным JSON».
 *
 * Параметры:
 *  - 'assoc'   => bool (по умолчанию true) — декодировать в массив;
 *  - 'objects' => bool (по умолчанию false) — разрешены только JSON-объекты
 *    (для колонок типа json, ожидающих структуру вида {...}, а не скаляр).
 */
class JsonRule implements ValidationRule
{
    public function validate(mixed $value, string $field, array $data = []): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && $decoded !== null;
    }

    /**
     * Строгая проверка: значение должно быть валидным JSON-ОБЪЕКТОМ или
     * МАССИВом верхнего уровня (скаляры «5», «true», «null» отклоняются).
     * Используется импортером перед записью в NOT NULL json-колонки.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isValidStructure(mixed $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    public function getMessage(string $field, array $params = []): string
    {
        return "Поле '{$field}' должно содержать валидный JSON";
    }

    public function getName(): string
    {
        return 'json';
    }
}
