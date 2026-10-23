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


namespace Core\System\Template;

/**
 * Компилятор шаблонов: преобразует синтаксис шаблона в чистый PHP
 */
class TemplateCompiler
{
    private int $maxNestingDepth = 10;

    /**
     * Скомпилировать шаблон в PHP-код
     */
    public function compile(string $template): string
    {
        $compiled = $template;

        // Порядок критически важен!
        $compiled = $this->compileComments($compiled);
        $compiled = $this->compileExtends($compiled);
        $compiled = $this->compileSections($compiled);
        $compiled = $this->compileYield($compiled);
        $compiled = $this->compileIncludes($compiled);
        $compiled = $this->compileRawEcho($compiled);
        $compiled = $this->compileEcho($compiled);
        $compiled = $this->compileForelse($compiled);
        $compiled = $this->compileDirectives($compiled);

        return $compiled;
    }

    /**
     * Конвертировать точечную нотацию в массивный доступ
     * page.title -> $page['title']
     * $user.name -> $user['name']
     */
    private function convertDotNotation(string $expression): string
    {
        $expr = $expression;

        // Паттерн 1: $var.prop1.prop2 -> $var['prop1']['prop2']
        $expr = preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)(\.[a-zA-Z_][a-zA-Z0-9_]*)+/',
            function ($matches) {
                $fullMatch = $matches[0];
                $varName = $matches[1];
                
                // Извлекаем все свойства после точки
                preg_match_all('/\.([a-zA-Z_][a-zA-Z0-9_]*)/', $fullMatch, $propMatches);
                $props = $propMatches[1];
                
                $result = '$' . $varName;
                foreach ($props as $prop) {
                    $result .= "['{$prop}']";
                }
                
                return $result;
            },
            $expr
        );

        // Паттерн 2: var.prop1.prop2 -> $var['prop1']['prop2'] (без $)
        $expr = preg_replace_callback(
            '/(?<![a-zA-Z0-9_\'"\$])([a-zA-Z_][a-zA-Z0-9_]*)(\.[a-zA-Z_][a-zA-Z0-9_]*)+/',
            function ($matches) {
                $fullMatch = $matches[0];
                $varName = $matches[1];
                
                // Извлекаем все свойства после точки
                preg_match_all('/\.([a-zA-Z_][a-zA-Z0-9_]*)/', $fullMatch, $propMatches);
                $props = $propMatches[1];
                
                $result = '$' . $varName;
                foreach ($props as $prop) {
                    $result .= "['{$prop}']";
                }
                
                return $result;
            },
            $expr
        );

        return $expr;
    }

    /**
     * Добавить $ к простым переменным (если нет)
     */
    private function addDollarSign(string $expression): string
    {
        $expr = $expression;

        // PHP-ключевые слова и функции, которые НЕ должны получать $
        $skipWords = [
            'true', 'false', 'null', 'and', 'or', 'xor',
            'if', 'else', 'elseif', 'endif', 'foreach', 'endforeach',
            'as', 'in', 'isset', 'empty', 'count', 'array',
            'new', 'class', 'function', 'return', 'echo', 'print',
            'for', 'endfor', 'while', 'endwhile', 'break', 'continue',
            'switch', 'case', 'default', 'endswitch', 'do', 'enddo',
            'try', 'catch', 'finally', 'throw', 'instanceof',
            'include', 'require', 'include_once', 'require_once',
            'match', 'fn', 'yield', 'from', 'where', 'order', 'by',
            'limit', 'offset', 'group', 'having', 'join', 'left', 'right',
            'inner', 'outer', 'on', 'union', 'all', 'distinct',
            'select', 'update', 'delete', 'insert', 'into', 'values',
            'set', 'where', 'like', 'between', 'is', 'not', 'and', 'or',
        ];

        // Добавляем $ к словам, которые:
        // 1. Не начинаются с $, ', ", (, цифры
        // 2. Не являются PHP-ключевыми словами
        // 3. Не являются функциями (после них идет '(')
        // 4. Не являются UPPER_CASE константами
        $expr = preg_replace_callback(
            '/(?<![a-zA-Z0-9_\'"\$])([a-z_][a-zA-Z0-9_]*)(?![a-zA-Z0-9_\'"\$\(])/',
            function ($matches) use ($skipWords) {
                $word = $matches[1];
                
                // Пропускаем PHP-ключевые слова
                if (in_array(strtolower($word), $skipWords, true)) {
                    return $word;
                }
                
                // Пропускаем UPPER_CASE константы
                if (strtoupper($word) === $word && strlen($word) > 1) {
                    return $word;
                }
                
                return '$' . $word;
            },
            $expr
        );

        return $expr;
    }

    /**
     * Обработка переменных для {{ }} и {!! !!}
     */
    public function compileVariables(string $expression): string
    {
        $expr = trim($expression);

        // Если уже начинается с $, (, ', ", цифры — не трогаем начало
        // Но всё равно обрабатываем точечную нотацию
        if (preg_match('/^[\$\(\'\"0-9]/', $expr)) {
            // Всё равно конвертируем точечную нотацию
            return $this->convertDotNotation($expr);
        }

        // Конвертируем точечную нотацию
        $expr = $this->convertDotNotation($expr);

        // Добавляем $ к простым переменным
        $expr = $this->addDollarSign($expr);

        return $expr;
    }

    /**
     * Обработка переменных внутри директив (@if, @foreach, etc.)
     */
    private function compileDirectiveExpression(string $expression): string
    {
        $expr = trim($expression);

        // Сначала конвертируем точечную нотацию
        $expr = $this->convertDotNotation($expr);

        // Затем добавляем $ к простым переменным
        $expr = $this->addDollarSign($expr);

        return $expr;
    }

    private function compileComments(string $template): string
    {
        return preg_replace('/\{\{--(.*?)--\}\}/s', '', $template);
    }

    private function compileExtends(string $template): string
{
    return preg_replace(
        "/@extends\s*\(\s*['\"]([^'\"]+)['\"]\s*\)/",
        "<?php \$__template->setLayout('\$1'); ?>",
        $template
    );
}

    private function compileSections(string $template): string
    {
        $pattern = '/@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(true|false))?\s*\)(.*?)@endsection/s';

        return preg_replace_callback($pattern, function ($matches) {
            // Экранируем кавычки: имя секции подставляется в PHP-код шаблона,
            // без этого @section("a'");eval-payload(...) превращается в инъекцию кода.
            $name = addslashes($matches[1]);
            $overwrite = ($matches[2] ?? 'true') === 'true';
            $content = $matches[3];
            return "<?php \$__template->startSection('{$name}', {$overwrite}); ?>{$content}<?php \$__template->endSection('{$name}'); ?>";
        }, $template);
    }

private function compileYield(string $template): string
{
    return preg_replace_callback(
        "/@yield\s*\(\s*['\"]([^'\"]+)['\"]\s*(?:,\s*(.+?))?\s*\)/",
        function ($matches) {
            $name = addslashes($matches[1]); // защита от выхода из строкового литерала
            $default = isset($matches[2]) ? trim($matches[2]) : '';

            if ($default === '') {
                $defaultPhp = "''";
            } elseif (preg_match('/^([\'"])(.*)\1$/s', $default, $q)) {
                // Строка в кавычках
                $defaultPhp = "'" . addslashes($q[2]) . "'";
            } else {
                // Переменная или выражение (app_name, base_url, etc.)
                $defaultPhp = $this->compileVariables($default);
            }

            return "<?php echo \$__template->yieldSection('{$name}', {$defaultPhp}); ?>";
        },
        $template
    );
}

    private function compileIncludes(string $template): string
    {
        return preg_replace_callback(
            "/@include\s*\(\s*['\"]([^'\"]+)['\"]\s*(?:,\s*(\[[^\]]*\]))?\s*\)/",
            function ($matches) {
                $path = addslashes($matches[1]); // защита от выхода из строкового литерала
                $data = $matches[2] ?? '[]';
                return "<?php echo \$__template->renderPartial('{$path}', {$data}); ?>";
            },
            $template
        );
    }

    private function compileRawEcho(string $template): string
    {
        return preg_replace_callback('/\{!!\s*(.+?)\s*!!\}/', function ($matches) {
            $expr = $this->compileVariables($matches[1]);
            return "<?php echo {$expr}; ?>";
        }, $template);
    }

    private function compileEcho(string $template): string
    {
        return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/', function ($matches) {
            $expr = $this->compileVariables($matches[1]);
            return "<?php echo htmlspecialchars((string) ({$expr}), ENT_QUOTES, 'UTF-8'); ?>";
        }, $template);
    }

    /**
     * Обработка @forelse — ДО @foreach и @endfor!
     */
    private function compileForelse(string $template): string
    {
        return preg_replace_callback(
            '/@forelse\s*\(((?:[^()]*|\([^()]*\))*)\)\s*(.*?)@empty\s*(.*?)@endforelse/s',
            function ($matches) {
                $expr = trim($matches[1]);
                $body = $matches[2];
                $emptyBody = $matches[3];

                if (!preg_match('/^(.+?)\s+as\s+(.+)$/s', $expr, $parts)) {
                    throw TemplateException::invalidSyntax('forelse', "Неверный синтаксис: {$expr}");
                }

                $collection = trim($parts[1]);
                $asPart = trim($parts[2]);

                $collection = $this->compileDirectiveExpression($collection);
                $asPart = $this->compileDirectiveExpression($asPart);

                return "<?php if (!empty({$collection})): foreach ({$collection} as {$asPart}): ?>{$body}<?php endforeach; else: ?>{$emptyBody}<?php endif; ?>";
            },
            $template
        );
    }

    /**
     * Обработка управляющих директив
     */
    private function compileDirectives(string $template): string
    {
        // Директивы с выражениями в скобках (с поддержкой вложенных скобок)
        $expressionDirectives = [
            '@if'      => '<?php if (%s): ?>',
            '@elseif'  => '<?php elseif (%s): ?>',
            '@unless'  => '<?php if (!(%s)): ?>',
            '@isset'   => '<?php if (isset(%s)): ?>',
            '@empty'   => '<?php if (empty(%s)): ?>',
            '@foreach' => '<?php foreach (%s): ?>',
            '@for'     => '<?php for (%s): ?>',
            '@while'   => '<?php while (%s): ?>',
        ];

        foreach ($expressionDirectives as $directive => $replacement) {
            $pattern = '/' . preg_quote($directive, '/') . '\s*\(((?:[^()]*|\([^()]*\))*)\)/s';
            $template = preg_replace_callback($pattern, function ($matches) use ($replacement) {
                $expr = $this->compileDirectiveExpression($matches[1]);
                return sprintf($replacement, $expr);
            }, $template);
        }

        // Закрывающие теги
        $closingPatterns = [
            '/@endforelse\b/'  => '<?php endif; ?>',
            '/@endforeach\b/'  => '<?php endforeach; ?>',
            '/@endfor\b/'      => '<?php endfor; ?>',
            '/@endwhile\b/'    => '<?php endwhile; ?>',
            '/@endif\b/'       => '<?php endif; ?>',
            '/@endunless\b/'   => '<?php endif; ?>',
            '/@endisset\b/'    => '<?php endif; ?>',
            '/@endempty\b/'    => '<?php endif; ?>',
            '/@else\b/'        => '<?php else: ?>',
            '/@php\b/'         => '<?php ',
            '/@endphp\b/'      => ' ?>',
        ];

        foreach ($closingPatterns as $pattern => $replacement) {
            $template = preg_replace($pattern, $replacement, $template);
        }

        return $template;
    }

    /**
     * Валидация шаблона
     */
    public function validate(string $template, string $templateName = ''): void
    {
        // Ограничение глубины вложенных блоков (@if внутри @if и т.п.) —
        // защита от взрывного разрастания компилируемого кода (ранее
        // maxNestingDepth был объявлен, но нигде не применялся).
        $blockOpens = 0;
        if (preg_match_all('/@(?:if|foreach|for|while|forelse|unless|isset)(?=[\s(])/', $template, $mm)) {
            $blockOpens = count($mm[0]);
        }
        if ($blockOpens > $this->maxNestingDepth) {
            throw TemplateException::invalidSyntax(
                $templateName,
                "Слишком много вложенных блоков: {$blockOpens} (максимум {$this->maxNestingDepth})"
            );
        }

        $pairs = [
            '@if'      => '@endif',
            '@foreach' => '@endforeach',
            '@for'     => '@endfor',
            '@while'   => '@endwhile',
            '@forelse' => '@endforelse',
            '@unless'  => '@endunless',
            '@isset'   => '@endisset',
            '@section' => '@endsection',
        ];

        foreach ($pairs as $open => $close) {
            $openPattern = '/' . preg_quote($open, '/') . '(?=[\s(])/';
            $closePattern = '/' . preg_quote($close, '/') . '\b/';
            
            $openCount = preg_match_all($openPattern, $template);
            $closeCount = preg_match_all($closePattern, $template);

            if ($openCount !== $closeCount) {
                throw TemplateException::invalidSyntax(
                    $templateName,
                    "Несовпадение пар: {$open} ({$openCount}) и {$close} ({$closeCount})"
                );
            }
        }
    }

    public function setMaxNestingDepth(int $depth): self
    {
        $this->maxNestingDepth = $depth;
        return $this;
    }
}