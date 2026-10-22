<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string) ($title), ENT_QUOTES, 'UTF-8'); ?> — Админ-панель</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="/assets/js/schema-editor.js" defer></script>
    
    <?php echo $csrf_meta ?? ''; ?>
</head>
<body>
    <div class="admin-container">
        <aside class="sidebar">
            <div class="logo">
                <h1>🏥 <?= htmlspecialchars(config('APP_NAME') ?? 'VampqweEngine', ENT_QUOTES, 'UTF-8') ?></h1>
                <span>Админ-панель</span>
            </div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/admin/" class="nav-link <?php echo htmlspecialchars((string) ($request_path === '/admin' || $request_path === '/$admin/' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon"></span>
                        <span>Дашборд</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/pages" class="nav-link <?php echo htmlspecialchars((string) (str_starts_with($request_path, '/$admin/pages') ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon"></span>
                        <span>Страницы</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/menu" class="nav-link <?php echo htmlspecialchars((string) ($request_path == '/$admin/menu' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon"></span>
                        <span>Меню</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/schema" class="nav-link <?php echo htmlspecialchars((string) ($request_path == '/$admin/schema' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon">🔷</span>
                        <span>Schema.org</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/import" class="nav-link <?php echo htmlspecialchars((string) ($request_path == '/$admin/import' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon"></span>
                        <span>Импорт страниц</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/settings" class="nav-link <?php echo htmlspecialchars((string) ($request_path == '/$admin/settings' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon">️</span>
                        <span>Настройки</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/config" class="nav-link <?php echo htmlspecialchars((string) ($request_path == '/$admin/config' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon">📄</span>
                        <span>config.env</span>
                    </a>
                </li>
            </ul>
        </aside>

        <main class="main-content">
            <div class="header">
                <div class="header-title">
                    <h2><?php echo $__template->yieldSection('page_title', 'Дашборд'); ?></h2>
                    <p><?php echo $__template->yieldSection('page_subtitle', 'Панель управления'); ?></p>
                </div>
                <div class="header-actions">
                    <a href="/" class="btn btn-primary" target="_blank">
                        <span>🌐</span>
                        <span>Открыть сайт</span>
                    </a>
                    <form method="POST" action="/admin/logout" style="display: inline;" class="logout-form">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-danger">🚪 Выйти</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($flash['error'])): ?>
            <div class="flash-message flash-error"><?php echo htmlspecialchars((string) ($flash['error']), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!empty($flash['success'])): ?>
            <div class="flash-message flash-success"><?php echo htmlspecialchars((string) ($flash['success']), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="admin-content">
                <?php echo $__template->yieldSection('content', ''); ?>
            </div>
        </main>
    </div>

    <script src="/assets/js/admin.js"></script>
</body>
</html>