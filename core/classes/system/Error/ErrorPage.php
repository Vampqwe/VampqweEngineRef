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


namespace Core\System\Error;

use Throwable;

/**
 * Генератор красивой HTML-страницы для ошибок
 */
class ErrorPage
{
    private bool $debug;
    private string $appName;
    private string $cspNonce;

    public function __construct(
        bool $debug = true,
        string $appName = 'Application',
        ?string $cspNonce = null
    )
    {
        $this->debug = $debug;
        $this->appName = $appName;
        $this->cspNonce = $cspNonce ?? bin2hex(random_bytes(16));
    }

    /**
     * Сгенерировать HTML для ошибки
     */
    public function render(Throwable $exception): string
    {
        $data = [
            'icon'        => $this->getIcon($exception),
            'title'       => $this->getTitle($exception),
            'message'     => $exception->getMessage(),
            'file'        => $exception->getFile(),
            'line'        => $exception->getLine(),
            'code'        => $exception->getCode(),
            'class'       => get_class($exception),
            'trace'       => $exception->getTrace(),
            'previous'    => $exception->getPrevious(),
            'timestamp'   => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'app_name'    => $this->appName,
            'debug'       => $this->debug,
        ];

        return $this->buildHtml($data);
    }

    /**
     * Сгенерировать HTML для фатальной ошибки PHP (без Throwable)
     */
    public function renderFatal(string $message, string $file, int $line): string
    {
        $data = [
            'icon'        => '💥',
            'title'       => 'Fatal Error',
            'message'     => $message,
            'file'        => $file,
            'line'        => $line,
            'code'        => 0,
            'class'       => 'PHP Fatal Error',
            'trace'       => [],
            'previous'    => null,
            'timestamp'   => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'app_name'    => $this->appName,
            'debug'       => $this->debug,
        ];

        return $this->buildHtml($data);
    }

    private function getIcon(Throwable $e): string
    {
        if ($e instanceof ErrorException) {
            return $e->getIcon();
        }

        return match (true) {
            str_contains(get_class($e), 'Http') => '🌐',
            str_contains(get_class($e), 'Database') || str_contains(get_class($e), 'PDO') => '🗄️',
            str_contains(get_class($e), 'Security') => '🔒',
            str_contains(get_class($e), 'Validation') => '✅',
            str_contains(get_class($e), 'FileSystem') => '',
            default => '❌',
        };
    }

    private function getTitle(Throwable $e): string
    {
        $class = get_class($e);
        $short = substr($class, strrpos($class, '\\') + 1);
        return $short;
    }

    private function buildHtml(array $data): string
    {
        if (!$this->debug) {
            $data = array_replace($data, [
                'icon' => '⚠',
                'title' => 'Ошибка сервера',
                'message' => 'Внутренняя ошибка сервера.',
                'file' => '',
                'line' => 0,
                'code' => 0,
                'class' => '',
                'trace' => [],
                'previous' => null,
                'php_version' => '',
            ]);
        }

        $traceHtml = $this->renderTrace($data['trace']);
        $previousHtml = $this->debug && $data['previous']
            ? $this->renderPrevious($data['previous'])
            : '';
        $contextHtml = $this->debug ? $this->renderContext($data) : '';
        
        // Выносим тернарные операторы за пределы heredoc
        $debugBadge = $data['debug'] ? '<span class="badge badge-debug">Debug Mode</span>' : '';
        $traceSection = ($data['debug'] && !empty($data['trace'])) 
            ? $this->renderTraceSection($data['trace']) 
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$data['icon']} {$this->escape($data['title'])} — {$this->escape($data['app_name'])}</title>
    <style nonce="{$this->cspNonce}">
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            color: #e4e4e7;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            padding: 40px 20px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
        }

        .header-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
        }

        .header .subtitle {
            color: #a1a1aa;
            font-size: 14px;
        }

        .card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border-left: 4px solid #ef4444;
            padding: 16px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
            color: #fecaca;
        }

        .location {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 8px;
            margin-top: 16px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .location-icon {
            font-size: 20px;
        }

        .location-text {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #93c5fd;
            word-break: break-all;
        }

        .location-line {
            background: rgba(59, 130, 246, 0.3);
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
        }

        .trace-item {
            padding: 12px;
            border-left: 3px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 8px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 6px;
            transition: all 0.2s;
        }

        .trace-item:hover {
            border-left-color: #3b82f6;
            background: rgba(59, 130, 246, 0.05);
        }

        .trace-number {
            display: inline-block;
            background: rgba(59, 130, 246, 0.3);
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 10px;
            min-width: 30px;
            text-align: center;
        }

        .trace-call {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #e4e4e7;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .trace-file {
            font-size: 12px;
            color: #a1a1aa;
            margin-top: 4px;
            padding-left: 40px;
            word-break: break-all;
        }

        .context-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }

        .context-item {
            background: rgba(0, 0, 0, 0.2);
            padding: 12px;
            border-radius: 8px;
        }

        .context-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #a1a1aa;
            margin-bottom: 4px;
        }

        .context-value {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #e4e4e7;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #e4e4e7;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-fatal {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .badge-debug {
            background: rgba(234, 179, 8, 0.2);
            color: #fde047;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: #3b82f6;
            cursor: pointer;
            font-size: 13px;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .toggle-btn:hover {
            background: rgba(59, 130, 246, 0.1);
        }

        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .collapsible-content.expanded {
            max-height: 10000px;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #a1a1aa;
            font-size: 12px;
        }

        .copy-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s;
            z-index: 1000;
        }

        .copy-notification.show {
            opacity: 1;
            transform: translateY(0);
        }

        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        @media (max-width: 768px) {
            .header h1 { font-size: 24px; }
            .header-icon { font-size: 60px; }
            .context-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-icon">{$data['icon']}</div>
            <h1>{$this->escape($data['title'])}</h1>
            <div class="subtitle">
                <span class="badge badge-fatal">Fatal Error</span>
                {$data['timestamp']} • PHP {$data['php_version']}
                {$debugBadge}
            </div>
        </div>

        <div class="card">
            <div class="card-title"> Сообщение об ошибке</div>
            <div class="error-message">{$this->escape($data['message'])}</div>
            
            <div class="location">
                <span class="location-icon"></span>
                <span class="location-text">{$this->escape($data['file'])}</span>
                <span class="location-line">строка {$data['line']}</span>
            </div>
        </div>

        {$contextHtml}

        {$traceSection}

        {$previousHtml}

        <div class="card">
            <div class="card-title">️ Действия</div>
            <div class="actions">
                <button class="btn btn-primary" id="copy-error">
                    📋 Скопировать отчёт
                </button>
                <button class="btn btn-secondary" id="reload-error">
                    🔄 Перезагрузить страницу
                </button>
                <a href="/" class="btn btn-secondary">
                    🏠 На главную
                </a>
            </div>
        </div>

        <div class="footer">
            {$this->escape($data['app_name'])} • Обработчик ошибок v1.0
        </div>
    </div>

    <div id="copy-notification" class="copy-notification">
        ✅ Отчёт скопирован в буфер обмена
    </div>

    <script nonce="{$this->cspNonce}">
        function copyError() {
            const report = `
Ошибка: {$this->escapeJs($data['title'])}
Сообщение: {$this->escapeJs($data['message'])}
Файл: {$this->escapeJs($data['file'])}
Строка: {$data['line']}
Время: {$data['timestamp']}
PHP: {$data['php_version']}

Stack Trace:
{$this->getTraceText($data['trace'])}
            `.trim();

            navigator.clipboard.writeText(report).then(() => {
                const notification = document.getElementById('copy-notification');
                notification.classList.add('show');
                setTimeout(() => notification.classList.remove('show'), 2000);
            });
        }

        document.getElementById('copy-error')?.addEventListener('click', copyError);
        document.getElementById('reload-error')?.addEventListener('click', () => location.reload());
        document.querySelectorAll('[data-action="toggle-trace"]').forEach((button) => {
            button.addEventListener('click', () => {
                const content = button.closest('.card')?.querySelector('.collapsible-content');
                content?.classList.toggle('expanded');
            });
        });
    </script>
</body>
</html>
HTML;
    }

    private function renderTrace(array $trace): string
    {
        if (empty($trace)) {
            return '<p style="color: #a1a1aa; font-style: italic;">Стек вызовов отсутствует</p>';
        }

        $html = '';
        foreach ($trace as $i => $item) {
            $number = $i + 1;
            $call = $this->formatTraceCall($item);
            $file = $item['file'] ?? 'unknown';
            $line = $item['line'] ?? 0;

            $html .= <<<HTML
            <div class="trace-item">
                <span class="trace-number">#{$number}</span>
                <span class="trace-call">{$this->escape($call)}</span>
                <div class="trace-file">📄 {$this->escape($file)}:{$line}</div>
            </div>
            HTML;
        }

        return $html;
    }

    private function renderTraceSection(array $trace): string
    {
        $traceHtml = $this->renderTrace($trace);
        $count = count($trace);

        return <<<HTML
        <div class="card">
            <div class="card-title">
                📚 Stack Trace ({$count} вызовов)
                <button class="toggle-btn" data-action="toggle-trace">Свернуть/Развернуть</button>
            </div>
            <div id="trace-content" class="collapsible-content expanded">
                {$traceHtml}
            </div>
        </div>
        HTML;
    }

    private function formatTraceCall(array $item): string
    {
        $class = $item['class'] ?? '';
        $type = $item['type'] ?? '';
        $function = $item['function'] ?? '';
        $args = $item['args'] ?? [];

        $call = $class . $type . $function . '(';

        if (!empty($args)) {
            $call .= count($args) . ' аргумент(ов)';
        }

        $call .= ')';

        return $call;
    }

    private function renderPrevious(Throwable $previous): string
    {
        $class = get_class($previous);
        $short = substr($class, strrpos($class, '\\') + 1);
        $message = $this->escape($previous->getMessage());
        $file = $this->escape($previous->getFile());
        $line = $previous->getLine();

        return <<<HTML
        <div class="card">
            <div class="card-title">🔗 Предыдущее исключение: {$short}</div>
            <div class="error-message">{$message}</div>
            <div class="location">
                <span class="location-icon"></span>
                <span class="location-text">{$file}</span>
                <span class="location-line">строка {$line}</span>
            </div>
        </div>
        HTML;
    }

    private function renderContext(array $data): string
    {
        return <<<HTML
        <div class="card">
            <div class="card-title">ℹ️ Контекст</div>
            <div class="context-grid">
                <div class="context-item">
                    <div class="context-label">Класс исключения</div>
                    <div class="context-value">{$this->escape($data['class'])}</div>
                </div>
                <div class="context-item">
                    <div class="context-label">Код ошибки</div>
                    <div class="context-value">{$data['code']}</div>
                </div>
                <div class="context-item">
                    <div class="context-label">Версия PHP</div>
                    <div class="context-value">{$data['php_version']}</div>
                </div>
                <div class="context-item">
                    <div class="context-label">Время возникновения</div>
                    <div class="context-value">{$data['timestamp']}</div>
                </div>
                <div class="context-item">
                    <div class="context-label">OS</div>
                    <div class="context-value">{$this->escape(PHP_OS)}</div>
                </div>
                <div class="context-item">
                    <div class="context-label">SAPI</div>
                    <div class="context-value">{$this->escape(PHP_SAPI)}</div>
                </div>
            </div>
        </div>
        HTML;
    }

    private function getTraceText(array $trace): string
    {
        $text = '';
        foreach ($trace as $i => $item) {
            $number = $i + 1;
            $call = $this->formatTraceCall($item);
            $file = $item['file'] ?? 'unknown';
            $line = $item['line'] ?? 0;
            $text .= "#{$number} {$call} in {$file}:{$line}\n";
        }
        return $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private function escapeJs(string $text): string
    {
        return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $text);
    }
}