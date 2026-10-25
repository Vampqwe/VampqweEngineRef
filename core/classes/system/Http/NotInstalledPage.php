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


namespace Core\System\Http;

/**
 * Страница-заглушка «Движок не установлен».
 *
 * Рендерится без БД, шаблонов и прочих сервисов — только нативный
 * HTML с инлайн-стилями, поэтому гарантированно работает даже
 * когда приложение находится в полу-сломанном состоянии.
 */
class NotInstalledPage
{
    public static function response(): Response
    {
        return Response::html(self::html(), StatusCode::SERVICE_UNAVAILABLE)
            ->setHeader('Retry-After', '3600')
            ->setHeader('X-Engine-State', 'not-installed');
    }

    private static function html(): string
    {
        $installUrl = htmlspecialchars('/admin/install', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Движок не установлен</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #e4ecf5 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #2c3e50;
        padding: 24px;
    }
    .card {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 12px 40px rgba(44, 62, 80, 0.12);
        max-width: 560px;
        width: 100%;
        padding: 48px 40px;
        text-align: center;
    }
    .icon {
        width: 72px; height: 72px;
        margin: 0 auto 24px;
        border-radius: 50%;
        background: #eef4fb;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 34px;
    }
    h1 { font-size: 24px; margin-bottom: 12px; }
    p  { font-size: 16px; line-height: 1.6; color: #5a6b7d; margin-bottom: 28px; }
    .btn {
        display: inline-block;
        background: #2f6fed;
        color: #fff;
        text-decoration: none;
        font-size: 16px;
        font-weight: 600;
        padding: 14px 32px;
        border-radius: 10px;
        transition: background .2s ease;
    }
    .btn:hover { background: #1f57c9; }
    .hint {
        margin-top: 20px;
        font-size: 13px;
        color: #90a0b3;
    }
    code {
        background: #f1f4f8;
        padding: 2px 6px;
        border-radius: 6px;
        font-size: 13px;
    }
</style>
</head>
<body>
    <div class="card">
        <div class="icon">🛠️</div>
        <h1>Движок не установлен</h1>
        <p>
            Сайт ещё не готов к работе: установка движка не выполнена
            или конфигурация базы данных указывает на пустую базу.
            Перейдите в админ панель и выполните установку.
        </p>
        <a class="btn" href="{$installUrl}">Перейти к установке</a>
        <div class="hint">Мастер установки доступен по адресу <code>/admin/install</code></div>
    </div>
</body>
</html>
HTML;
    }
}