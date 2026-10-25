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

namespace Core\System\Parser;

use SimpleXMLElement;

/**
 * Парсер формата .env: KEY=value, комментарии, кавычки, распаковка
 * escape-последовательностей. Наследует AbstractParser (warnings/файл-вход).
 *
 * Возвращает плоский массив строковых значений; предупреждения накапливаются
 * для строк без «=», пустых ключей и неизвестных директив.
 */
class EnvParser extends AbstractParser
{
    public function format(): string
    {
        return 'env';
    }

    /**
     * @param string|array<string,mixed> $content содержимое файла либо результат parseFile()
     * @return array<string,string>
     */
    public function parse(string|array $content): array
    {
        if (is_array($content)) {
            $content = (string) ($content['content'] ?? '');
        }

        $this->resetRunState();

        $lines = preg_split('/\R/', trim($this->normalizeInput($content))) ?: [];
        $result = [];

        foreach ($lines as $lineNo => $rawLine) {
            $line = trim($rawLine);

            // Пропускаем пустые строки и комментарии
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Поддержка "export KEY=value"
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            // Разделяем по первому '='
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                $this->addWarning("Пропущена строка {$lineNo}: отсутствует '='");
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Валидация имени ключа (буквы, цифры, подчёркивание)
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                $this->addWarning("Пропущен некорректный ключ '{$key}' в строке {$lineNo}");
                continue;
            }

            $result[$key] = $this->parseValue($value);
        }

        return $result;
    }

    /**
     * Нормализует значение: снимает парные кавычки и неэкранированные хвостовые
     * комментарии. Совместимость со старыми config.env без кавычек сохранена.
     *
     * Единая семантика .env для всего движка: Config, админский просмотрщик
     * конфигов и любые сторонние потребители используют ЭТУ реализацию
     * (public static), дублирующие версии в consuming-классах удалены.
     *
     * Форматы:
     *   KEY=value            -> value
     *   KEY="value"          -> value
     *   KEY='va\'lue'        -> va'lue   (одинарные кавычки — «сырое» значение)
     *   KEY="value # note"   -> value # note (комментарий внутри кавычек игнорируется)
     *   KEY=value # note     -> value
     *   KEY="say \"hi\""     -> say "hi" (распаковка \\ \" \n \r \t — только двойные кавычки)
     */
    public static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $first = $raw[0];

        if ($first === '"' || $first === "'") {
            $closePos = self::findClosingQuote($raw, 0, $first);

            // Закрывающей кавычки нет — трактуём как «сырое» значение до EOL
            if ($closePos === null) {
                return $first === '"' ? self::unescapeDoubleQuoted($raw) : $raw;
            }

            $inner = substr($raw, 1, $closePos - 1);
            $after = trim(substr($raw, $closePos + 1));

            // Мусор после закрывающей кавычки игнорируем (только комментарий или пусто)
            if ($first === '"') {
                return self::unescapeDoubleQuoted($inner);
            }

            return self::unescapeSingleQuoted($inner);
        }

        // Значение без кавычек: отрезаем неэкранированный хвостовой комментарий (#...)
        $hashPos = self::findUnescapedPosition($raw, '#');
        if ($hashPos !== null) {
            $raw = rtrim(substr($raw, 0, $hashPos));
        }

        return $raw;
    }

    /**
     * Быстрый статический разбор массива строк .env в карту KEY => value
     * без создания экземпляра и сбора предупреждений. Используется Config
     * при загрузке config.env: та же семантика кавычек/комментариев, что и
     * у instance-метода parse().
     *
     * @param string[] $lines
     * @return array<string, string>
     */
    public static function parseLines(array $lines): array
    {
        $result = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            if ($key === '') {
                continue;
            }

            $result[$key] = self::parseValue(trim(substr($line, $eqPos + 1)));
        }

        return $result;
    }

    /**
     * Позиция НЕЭКРАНИРОВАННОГО символа $needle (перед ним чётное число '\').
     */
    private static function findUnescapedPosition(string $s, string $needle): ?int
    {
        $offset = 0;
        $length = strlen($s);

        while ($offset < $length) {
            $pos = strpos($s, $needle, $offset);
            if ($pos === false) {
                return null;
            }

            $backslashes = 0;
            for ($i = $pos - 1; $i >= 0 && $s[$i] === '\\'; $i--) {
                $backslashes++;
            }

            if ($backslashes % 2 === 0) {
                return $pos;
            }

            $offset = $pos + 1;
        }

        return null;
    }

    /**
     * Распаковка escape-последовательностей значения в двойных кавычках.
     */
    private static function unescapeDoubleQuoted(string $inner): string
    {
        return str_replace(
            ['\\\\', '\"', "\\n", "\\r", "\\t"],
            ['\\', '"', "\n", "\r", "\t"],
            $inner
        );
    }

    /**
     * Распаковка «сырого» одинарного кавычения: экранируется только \' и \\.
     */
    private static function unescapeSingleQuoted(string $inner): string
    {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
    }

    /**
     * Находит позицию закрывающей кавычки $quote, начиная со следующего
     * символа после открывающей. Для двойных кавычек учитываются экранирования \".
     */
    private static function findClosingQuote(string $raw, int $openPos, string $quote): ?int
    {
        $length = strlen($raw);
        $i = $openPos + 1;

        while ($i < $length) {
            $pos = strpos($raw, $quote, $i);
            if ($pos === false) {
                return null;
            }

            if ($quote === '"') {
                $backslashes = 0;
                for ($j = $pos - 1; $j >= $i && $raw[$j] === '\\'; $j--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 1) {
                    $i = $pos + 1;
                    continue;
                }
            }

            return $pos;
        }

        return null;
    }

    /**
     * Сборка содержимого .env из ассоциативного массива (обратная операция).
     * Ключи сортируются по алфавиту, значения при необходимости берутся в кавычки.
     *
     * @param array<string,mixed> $data
     */
    public function build(array $data): string
    {
        ksort($data);
        $lines = [];

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = '';
            } else {
                $value = (string) $value;
            }

            // Экранирование спецсимволов в значении
            if ($value === '' || preg_match('/^[\w\-:.\/]+$/', $value) !== 1) {
                $value = '"' . str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\"', '\n', '\r', '\t'], $value) . '"';
            }

            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Синтаксическая проверка содержимого .env.
     *
     * @return array<int,array{line:int,error:string}> Список ошибок ([] если всё валидно)
     */
    public function validate(string $content): array
    {
        $errors = [];
        $lines = preg_split('/\R/', trim($content)) ?: [];

        foreach ($lines as $lineNo => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                $errors[] = ['line' => $lineNo + 1, 'error' => "Строка без '=': {$line}"];
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                $errors[] = ['line' => $lineNo + 1, 'error' => "Некорректное имя ключа: {$key}"];
            }
        }

        return $errors;
    }

    /**
     * Проверка синтаксиса XML-строки (общая утилита модуля Parser).
     */
    public static function isWellFormedXml(string $xml): bool
    {
        libxml_use_internal_errors(true);
        try {
            new SimpleXMLElement($xml);
            $valid = true;
        } catch (\Exception) {
            $valid = false;
        }
        libxml_clear_errors();

        return $valid;
    }
}
