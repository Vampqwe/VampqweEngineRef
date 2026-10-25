<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('title', ); ?>
Установка — Шаг 1
<?php $__template->endSection('title'); ?>

<?php $__template->startSection('content', ); ?>
<div class="install-wizard">
    <div class="install-header">
        <h1>🚀 Инициализация сайта</h1>
        <p>Шаг 1 из 5: Проверка подключения к базе данных</p>
        <div class="progress-bar">
            <div class="progress-step active">1</div>
            <div class="progress-step">2</div>
            <div class="progress-step">3</div>
            <div class="progress-step">4</div>
            <div class="progress-step">5</div>
        </div>
    </div>

    <div class="install-card">
        <h2>Подключение к MySQL</h2>
        <p>Введите данные для подключения к базе данных.</p>

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

        <form method="POST" action="/admin/install/step1">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <label for="db_host">Хост</label>
                <input type="text" id="db_host" name="db_host" value="127.0.0.1" required>
            </div>

            <div class="form-group">
                <label for="db_port">Порт</label>
                <input type="number" id="db_port" name="db_port" value="3306" required>
            </div>

            <div class="form-group">
                <label for="db_name">Имя базы данных</label>
                <input type="text" id="db_name" name="db_name" required>
            </div>

            <div class="form-group">
                <label for="db_user">Пользователь</label>
                <input type="text" id="db_user" name="db_user" required>
            </div>

            <div class="form-group">
                <label for="db_pass">Пароль</label>
                <input type="password" id="db_pass" name="db_pass" autocomplete="new-password" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Проверить подключение →</button>
            </div>
        </form>
    </div>
</div>
<?php $__template->endSection('content'); ?>