<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string) ($title), ENT_QUOTES, 'UTF-8'); ?> — Админ-панель</title>
   <link rel="stylesheet" href="/assets/css/admin.css">
    
    <?php echo $csrf_meta ?? ''; ?>
</head>
<body>
    <div class="admin-container">
        <aside class="sidebar">
            <div class="logo">
                <h1>🏥 Достойный уход</h1>
                <span>Админ-панель</span>
            </div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/admin/" class="nav-link <?php echo htmlspecialchars((string) ($request_path == '/admin' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="nav-icon"></span>
                        <span>Дашборд</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/pages" class="nav-link">
                        <span class="nav-icon"></span>
                        <span>Страницы</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/menu" class="nav-link">
                        <span class="nav-icon"></span>
                        <span>Меню</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/authors" class="nav-link">
                        <span class="nav-icon">✍️</span>
                        <span>Авторы</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/schema" class="nav-link">
                        <span class="nav-icon">🔷</span>
                        <span>Schema.org</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/redirects" class="nav-link">
                        <span class="nav-icon">🔀</span>
                        <span>Редиректы</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/import" class="nav-link">
                        <span class="nav-icon"></span>
                        <span>Импорт страниц</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/settings" class="nav-link">
                        <span class="nav-icon">️</span>
                        <span>Настройки</span>
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
                    <form method="POST" action="/admin/logout" style="display: inline;">
                        <button type="submit" class="btn btn-danger">
                            <span>🚪</span>
                            <span>Выйти</span>
                        </button>
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