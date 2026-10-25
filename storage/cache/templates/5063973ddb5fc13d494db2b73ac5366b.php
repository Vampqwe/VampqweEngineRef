<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('title', ); ?>
Установка — Шаг 3
<?php $__template->endSection('title'); ?>

<?php $__template->startSection('content', ); ?>
<div class="install-wizard">
    <div class="install-header">
        <h1> Инициализация сайта</h1>
        <p>Шаг 3 из 5: Заполнение начальными данными</p>
        <div class="progress-bar">
            <div class="progress-step completed" style="background: var(--green); border-color: var(--green); color: white;">✓</div>
            <div class="progress-step completed" style="background: var(--green); border-color: var(--green); color: white;">✓</div>
            <div class="progress-step active">3</div>
            <div class="progress-step">4</div>
            <div class="progress-step">5</div>
        </div>
    </div>

    <div class="install-card">
        <h2>Начальные данные</h2>
        <p>На этом этапе в базу данных будут добавлены:</p>
        <ul style="margin: 1rem 0 1rem 2rem; color: var(--silver);">
            <li>Главная страница сайта</li>
            <li>Страница 404</li>
            <li>Автор по умолчанию (Команда 103vet)</li>
            <li>Глобальная Schema.org разметка (VeterinaryCare)</li>
            <li>Базовое меню навигации</li>
        </ul>

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

        <form method="POST" action="/admin/install/step3">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-actions" style="display: flex; gap: 1rem; margin-top: 2rem;">
                <a href="/admin/install/step2" class="btn" style="background: var(--dark-blue); color: var(--silver); border: 1px solid var(--border-color);">← Назад</a>
                <button type="submit" class="btn btn-primary">Заполнить данными →</button>
            </div>
        </form>
    </div>
</div>
<?php $__template->endSection('content'); ?>