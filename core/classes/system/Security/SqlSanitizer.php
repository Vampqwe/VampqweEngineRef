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
 * Санитизация SQL-запросов для защиты от инъекций
 */
class SqlSanitizer
{
    private bool $enforcePrepared;

    public function __construct(bool $enforcePrepared = true)
    {
        $this->enforcePrepared = $enforcePrepared;
    }

    /**
     * Проверить SQL-запрос на безопасность
     *
     * @param array<mixed> $params
     * @throws SecurityException
     */
    public function validate(string $sql, array $params = []): void
    {
        if (!$this->enforcePrepared) {
            return;
        }

        // Проверяем наличие плейсхолдеров. Именованные — только вне строковых
        // литералов и каталоговых имён: двоеточие внутри '...text...' или
        // `schema_org:schema_type` не является параметром (иначе легитимные
        // запросы с JSON/двоеточиями в тексте дают ложный «несоответствие
        // количества параметров» и падают).
        [$namedList, $hasPositionalPlaceholders] = $this->scanPlaceholders($sql);
        $hasNamedPlaceholders = $namedList !== [];

        if (!$hasNamedPlaceholders && !$hasPositionalPlaceholders && !empty($params)) {
            throw SecurityException::unsafeSqlQuery(
                'Переданы параметры, но в запросе нет плейсхолдеров. Используйте prepared statements.'
            );
        }

        // Проверяем несоответствие количества параметров
        if ($hasNamedPlaceholders) {
            $expectedCount = count(array_unique($namedList));
            $actualCount = count($params);

            if ($expectedCount !== $actualCount) {
                throw SecurityException::unsafeSqlQuery(
                    "Несоответствие количества параметров: ожидается {$expectedCount}, передано {$actualCount}"
                );
            }
        } elseif ($hasPositionalPlaceholders) {
            $expectedCount = substr_count($sql, '?');
            $actualCount = count($params);

            if ($expectedCount !== $actualCount) {
                throw SecurityException::unsafeSqlQuery(
                    "Несоответствие количества параметров: ожидается {$expectedCount}, передано {$actualCount}"
                );
            }
        }

        // Проверяем подозрительные паттерны
        $this->checkSuspiciousPatterns($sql);
    }

    /**
     * Посимвольно просканировать SQL и вернуть найденные плейсхолдеры.
     *
     * Игнорируются: содержимое одинарных/двойных кавычек и обратных апострофов
     * (строковые литералы, JSON в значениях, каталоговые имена), а также
     * PHP-вставки "запятая + двоеточие" внутри двойных кавычек ("{$a}: {$b}").
     * Так двоеточие в 'text: value' или `schema_org:schema_type` не ложится
     * на именованный параметр и не вызывает ложный «несоответствие количества
     * параметров».
     *
     * @return array{0: string[], 1: bool} [список имён именованных
     *         плейсхолдеров, есть ли позиционные '?']
     */
    private function scanPlaceholders(string $sql): array
    {
        $named = [];
        $positional = false;
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            // Строковый литерал / каталоговое имя — пропускаем целиком.
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === '\\' && $quote !== '`') {
                        $i += 2; // экранированный символ внутри кавычки
                        continue;
                    }
                    if ($sql[$i] === $quote) {
                        // удвоенная кавычка = литеральная кавычка (SQL-escape)
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Позиционный плейсхолдер.
            if ($ch === '?') {
                $positional = true;
                $i++;
                continue;
            }

            // Именованный плейсхолдер :name (только если до него не буква/цифра/
            // подчёркивание — иначе это часть текста, и не после запятой внутри
            // строки — строки уже пропущены выше).
            if ($ch === ':'
                && $i + 1 < $len
                && preg_match('/[A-Za-z_]/', $sql[$i + 1])
                && ($i === 0 || !preg_match('/[A-Za-z0-9_]/', $sql[$i - 1]))
            ) {
                $j = $i + 1;
                while ($j < $len && preg_match('/[A-Za-z0-9_.]/', $sql[$j])) {
                    $j++;
                }
                $named[] = substr($sql, $i + 1, $j - $i - 1);
                $i = $j;
                continue;
            }

            $i++;
        }

        return [$named, $positional];
    }

    /**
     * Экранировать идентификатор (имя таблицы/колонки)
     */
    public function escapeIdentifier(string $identifier): string
    {
        // Убираем недопустимые символы
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);

        if ($identifier === null || $identifier === '') {
            throw SecurityException::unsafeSqlQuery('Пустой идентификатор');
        }

        return '`' . $identifier . '`';
    }

    /**
     * Экранировать строковое значение (если нельзя использовать prepared statements)
     *
     * addslashes() недостаточен при некоторых charset (GBK и т.п.), поэтому
     * экранируем реальные опасные символы: обратный слэш и одинарную кавычку
     * (удвоение — стандарт SQL-escaping для MySQL).
     */
    public function escapeString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "''"], $value);
    }

    /**
     * Проверить SQL на подозрительные паттерны
     *
     * @throws SecurityException
     */
    private function checkSuspiciousPatterns(string $sql): void
    {
        // Список опасных паттернов
        $patterns = [
            '/\bUNION\s+SELECT\b/i' => 'UNION SELECT',
            '/;\s*(DROP|DELETE|TRUNCATE|ALTER|CREATE)\b/i' => 'Stacked queries',
            '/\b(SLEEP|BENCHMARK|WAITFOR)\s*\(/i' => 'Time-based injection',
            '/\bLOAD_FILE\s*\(/i' => 'LOAD_FILE',
            '/\bINTO\s+OUTFILE\b/i' => 'INTO OUTFILE',
            '/\bINTO\s+DUMPFILE\b/i' => 'INTO DUMPFILE',
            '/\/\*.*\*\//s' => 'SQL-комментарии',
        ];

        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $sql)) {
                throw SecurityException::unsafeSqlQuery(
                    "Обнаружен подозрительный паттерн: {$name}"
                );
            }
        }
    }

    /**
     * Включить/выключить принудительную проверку
     */
    public function setEnforcePrepared(bool $enforce): self
    {
        $this->enforcePrepared = $enforce;
        return $this;
    }
}