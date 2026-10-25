<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('title', ); ?>
Установка — Шаг 4
<?php $__template->endSection('title'); ?>

<?php $__template->startSection('content', ); ?>
<div class="install-wizard">
    <div class="install-header">
        <h1>🚀 Инициализация сайта</h1>
        <p>Шаг 4 из 5: Настройка базового URL сайта</p>
        <div class="progress-bar">
            <div class="progress-step completed" style="background: var(--green); border-color: var(--green); color: white;">✓</div>
            <div class="progress-step completed" style="background: var(--green); border-color: var(--green); color: white;">✓</div>
            <div class="progress-step completed" style="background: var(--green); border-color: var(--green); color: white;">✓</div>
            <div class="progress-step active">4</div>
            <div class="progress-step">5</div>
        </div>
    </div>

    <div class="install-card">
        <h2>Базовый URL сайта</h2>
        <p>Укажите полный адрес вашего сайта. Это значение будет использоваться для генерации ссылок в sitemap.xml, canonical-тегах и Open Graph разметке.</p>

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

        <form method="POST" action="/admin/install/step4">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <label for="base_url">Базовый URL</label>
                <input 
                    type="url" 
                    id="base_url" 
                    name="base_url" 
                    value="<?php echo htmlspecialchars((string) ($base_url), ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="https://example.com"
                    required
                >
                <small style="color: var(--silver); font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                    Без завершающего слэша. Например: https://example.com
                </small>
            </div>

            <h2 style="margin-top: 2rem;">Учётная запись администратора</h2>
            <p>Будет создана запись в таблице <code>users</code> (роль admin) и обновлён супер-доступ в <code>config.env</code>. Пароль хранится только как bcrypt-хэш.</p>

            <div class="form-group">
                <label for="admin_email">Email для входа</label>
                <input 
                    type="email" 
                    id="admin_email" 
                    name="admin_email" 
                    value="<?php echo htmlspecialchars((string) ($admin_email), ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="admin@example.com"
                    required
                    autocomplete="off"
                >
            </div>

            <div class="form-group">
                <label for="admin_name">Имя администратора</label>
                <input 
                    type="text" 
                    id="admin_name" 
                    name="admin_name" 
                    value="<?php echo htmlspecialchars((string) ($admin_name), ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="Администратор"
                >
            </div>

            <div class="form-group">
                <label for="admin_password">Пароль (мин. 8 символов)</label>
                <input 
                    type="password" 
                    id="admin_password" 
                    name="admin_password" 
                    minlength="8"
                    required
                    autocomplete="new-password"
                    placeholder="Надёжный пароль"
                >
            </div>

            <div class="form-actions" style="display: flex; gap: 1rem; margin-top: 2rem;">
                <a href="/admin/install/step3" class="btn" style="background: var(--dark-blue); color: var(--silver); border: 1px solid var(--border-color);">← Назад</a>
                <button type="submit" class="btn btn-primary">Сохранить и продолжить →</button>
            </div>
        </form>
    </div>
</div>
<?php $__template->endSection('content'); ?>