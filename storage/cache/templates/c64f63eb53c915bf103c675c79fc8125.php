<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string) ($title), ENT_QUOTES, 'UTF-8'); ?> — Админ-панель</title>
   <link rel="stylesheet" href="/assets/css/admin.css">
    
    <?php echo $csrf_meta ?? ''; ?>
</head>

<?php 
    $_reqPath = rtrim((string) ($request_path ?? '/'), '/') ?: '/';
 ?>
<body>
    <div class="admin-container">
        <header class="topbar">
            <div class="logo">
                <h1>🏥 Достойный уход</h1>
                <span>Админ</span>
            </div>
            <nav class="topnav">
                <ul class="nav-menu">
                    <li class="nav-item"><a href="/admin" class="nav-link <?php echo htmlspecialchars((string) ($_reqPath === '/admin' ? 'active' : ''), ENT_QUOTES, 'UTF-8'); ?>">Дашборд</a></li>
                    <li class="nav-item"><a href="/admin/pages" class="nav-link <?php echo $_reqPath === '/admin/pages' || str_starts_with($_reqPath, '/admin' . chr(47) . 'pages/') ? 'active' : ''; ?>" data-nav="pages">Страницы</a></li>
                    <li class="nav-item"><a href="/admin/menu" class="nav-link <?php echo $_reqPath === '/admin/menu' || str_starts_with($_reqPath, '/admin' . chr(47) . 'menu/') ? 'active' : ''; ?>" data-nav="menu">Меню</a></li>
                    <li class="nav-item"><a href="/admin/authors" class="nav-link <?php echo $_reqPath === '/admin/authors' || str_starts_with($_reqPath, '/admin' . chr(47) . 'authors/') ? 'active' : ''; ?>" data-nav="authors">Авторы</a></li>
                    <li class="nav-item"><a href="/admin/schema" class="nav-link <?php echo $_reqPath === '/admin/schema' || str_starts_with($_reqPath, '/admin' . chr(47) . 'schema/') ? 'active' : ''; ?>" data-nav="schema">Schema.org</a></li>
                    <li class="nav-item"><a href="/admin/redirects" class="nav-link <?php echo $_reqPath === '/admin/redirects' || str_starts_with($_reqPath, '/admin' . chr(47) . 'redirects/') ? 'active' : ''; ?>" data-nav="redirects">Редиректы</a></li>
                    <li class="nav-item"><a href="/admin/import" class="nav-link <?php echo $_reqPath === '/admin/import' || str_starts_with($_reqPath, '/admin' . chr(47) . 'import/') ? 'active' : ''; ?>" data-nav="import">Импорт</a></li>
                    <li class="nav-item"><a href="/admin/config" class="nav-link <?php echo $_reqPath === '/admin/config' || str_starts_with($_reqPath, '/admin' . chr(47) . 'config/') ? 'active' : ''; ?>" data-nav="config">Конфиг</a></li>
                    <li class="nav-item"><a href="/admin/settings" class="nav-link <?php echo $_reqPath === '/admin/settings' || str_starts_with($_reqPath, '/admin' . chr(47) . 'settings/') ? 'active' : ''; ?>" data-nav="settings">Настройки</a></li>
                </ul>
            </nav>
            <div class="header-actions">
                <a href="/" class="btn btn-sm" target="_blank">↗ Сайт</a>
                <form method="POST" action="/admin/logout">
                    <button type="submit" class="btn btn-sm btn-danger">Выйти</button>
                </form>
            </div>
        </header>

        <main class="main-content">
            <div class="page-subheader">
                <div>
                    <h2><?php echo $__template->yieldSection('page_title', 'Дашборд'); ?></h2>
                    <p><?php echo $__template->yieldSection('page_subtitle', 'Панель управления'); ?></p>
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