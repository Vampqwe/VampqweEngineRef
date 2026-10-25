<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('title', ); ?>
Установка — Шаг 2
<?php $__template->endSection('title'); ?>

<?php $__template->startSection('content', ); ?>
<div class="install-wizard">
    <div class="install-header">
        <h1>🚀 Инициализация сайта</h1>
        <p>Шаг 2 из 5: Создание структуры базы данных</p>
        <div class="progress-bar">
            <div class="progress-step completed" style="background: var(--green); border-color: var(--green); color: white;">✓</div>
            <div class="progress-step active">2</div>
            <div class="progress-step">3</div>
            <div class="progress-step">4</div>
            <div class="progress-step">5</div>
        </div>
    </div>

    <div class="install-card">
        <h2>Создание таблиц</h2>
        <p>На этом этапе будут созданы все необходимые таблицы в базе данных на основе файла <code>database/structure.sql</code>.</p>
        <p style="color: var(--silver); font-size: 0.875rem; margin-top: 0.5rem;">
            ⚠️ Внимание: используется безопасный режим <code>CREATE TABLE IF NOT EXISTS</code>. Существующие таблицы не будут удалены или перезаписаны.
        </p>

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

        <form method="POST" action="/admin/install/step2">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars((string) ($csrf_token), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-actions" style="display: flex; gap: 1rem; margin-top: 2rem;">
                <a href="/admin/install" class="btn" style="background: var(--dark-blue); color: var(--silver); border: 1px solid var(--border-color);">← Назад</a>
                <button type="submit" class="btn btn-primary">Создать таблицы →</button>
            </div>
        </form>
    </div>
</div>
<?php $__template->endSection('content'); ?>