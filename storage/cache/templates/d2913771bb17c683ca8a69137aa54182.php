<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string) ($title), ENT_QUOTES, 'UTF-8'); ?> — Достойный уход</title>
    <link rel="stylesheet" href="/assets/css/admin-login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">🏥</div>
                <h1 class="login-title">Достойный уход</h1>
                <p class="login-subtitle">Админ-панель</p>
            </div>

            <?php if (!empty($flash['error'])): ?>
            <div class="flash-message flash-error">
                <?php echo htmlspecialchars((string) ($flash['error']), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($flash['success'])): ?>
            <div class="flash-message flash-success">
                <?php echo htmlspecialchars((string) ($flash['success']), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="/admin/login">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-input" 
                        placeholder="admin@example.com"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Пароль</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input" 
                        placeholder="••••••••"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary">Войти</button>
            </form>

            <div class="login-footer">
                <p>Забыли пароль? Обратитесь к супер-администратору</p>
                <p style="margin-top: 0.5rem;">
                    <a href="/">← Вернуться на сайт</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>