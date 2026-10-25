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

use Core\System\Application\EngineState;

/**
 * Страница-заглушка «База данных временно недоступна».
 *
 * Показывается, когда движок УСТАНОВЛЕН (метка storage/.installed на месте,
 * таблицы были), но соединение с MySQL не устанавливается: рестарт БД,
 * сетевой сбой, исчерпаны соединения. Установка при этом НЕ повреждена —
 * в отличие от NotInstalledPage здесь нет и ссылки на мастер установки,
 * чтобы не подталкивать администратора к разрушительной переустановке.
 *
 * Рендерится без БД и шаблонов — только нативный HTML с инлайн-стилями.
 */
class DbUnavailablePage
{
    public static function response(): Response
    {
        return Response::html(self::html(), StatusCode::SERVICE_UNAVAILABLE)
            ->setHeader('Retry-After', '30')
            ->setHeader('X-Engine-State', EngineState::DbUnreachable->value);
    }

    private static function html(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Сервис временно недоступен</title>
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
        background: #fdf3e7;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 34px;
    }
    h1 { font-size: 24px; margin-bottom: 12px; }
    p  { font-size: 16px; line-height: 1.6; color: #5a6b7d; margin-bottom: 20px; }
    .hint { font-size: 13px; color: #90a0b3; }
    code { background: #f1f4f8; padding: 2px 6px; border-radius: 6px; font-size: 13px; }
</style>
</head>
<body>
    <div class="card">
        <div class="icon">🗄️</div>
        <h1>Сервис временно недоступен</h1>
        <p>
            Нет соединения с базой данных. Сайт установлен корректно —
            как только база станет доступна, всё заработает автоматически.
            Попробуйте обновить страницу через минуту.
        </p>
        <div class="hint">Администратору: проверьте доступность MySQL/MariaDB (<code>DB_HOST</code>, <code>DB_PORT</code>) и логи приложения.</div>
    </div>
</body>
</html>
HTML;
    }
}
