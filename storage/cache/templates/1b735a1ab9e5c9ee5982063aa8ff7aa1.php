<?php $__template->setLayout('admin/layouts/admin'); ?>

<?php $__template->startSection('page_title', ); ?>
<?= htmlspecialchars($title ?? 'Настройки сайта', ENT_QUOTES, 'UTF-8') ?>
<?php $__template->endSection('page_title'); ?>

<?php $__template->startSection('page_subtitle', ); ?>
Управление конфигурацией движка
<?php $__template->endSection('page_subtitle'); ?>

<?php $__template->startSection('content', ); ?>
<?php echo $csrf_meta ?? ''; ?>

<form method="POST" action="/admin/settings">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <?php if (!empty($flash['error'])): ?>
    <div class="flash-message flash-error"><?= htmlspecialchars($flash['error'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($flash['success'])): ?>
    <div class="flash-message flash-success"><?= htmlspecialchars($flash['success'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($flash['info'])): ?>
    <div class="flash-message flash-info"><?= htmlspecialchars($flash['info'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Верхняя панель -->
    <div class="edit-topbar">
        <div class="edit-topbar-actions">
            <a href="/admin/" class="btn btn-secondary btn-sm">← Назад</a>
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Сохранить изменения в config.env?');">💾 Сохранить настройки</button>
        </div>
    </div>

    <!-- Предупреждение -->
    <div class="card" style="margin-bottom: 1.5rem; border-left: 4px solid var(--red);">
        <div class="card-body" style="padding: 1rem;">
            <strong>⚠️ Внимание:</strong> Изменение настроек напрямую влияет на работу сайта. Перед сохранением создаётся бэкап <code>config.env</code>. Критичные параметры (пароли БД, ключи шифрования) нельзя изменить через этот интерфейс.
        </div>
    </div>

    <!-- Секции настроек -->
    <?php foreach ($sections ?? [] as $sectionKey => $section): ?>
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header">
            <h3 class="card-title"><?= htmlspecialchars($section['icon'] ?? '⚙️') ?> <?= htmlspecialchars($section['label'] ?? $sectionKey, ENT_QUOTES, 'UTF-8') ?></h3>
        </div>
        <div class="card-body">
            <div class="form-grid" style="grid-template-columns: 1fr;">
                <?php foreach ($section['fields'] ?? [] as $key => $field): ?>
                <div class="form-group">
                    <label for="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($field['label'] ?? $key, ENT_QUOTES, 'UTF-8') ?>
                    </label>

                    <?php
                    $value = $field['value'] ?? '';
                    $type = $field['type'] ?? 'text';
                    ?>

                    <?php if ($type === 'bool'): ?>
                        <div class="form-group checkbox">
                            <label>
                                <input type="checkbox" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="1" <?= $value === 'true' || $value === '1' ? 'checked' : '' ?>>
                                Включено
                            </label>
                        </div>

                    <?php elseif ($type === 'select'): ?>
                        <select id="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                            <?php foreach ($field['options'] ?? [] as $option): ?>
                                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === 'int'): ?>
                        <input type="number" id="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" min="<?= (int)($field['min'] ?? 0) ?>" max="<?= (int)($field['max'] ?? 999999) ?>">

                    <?php elseif ($type === 'url'): ?>
                        <input type="url" id="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com">

                    <?php else: ?>
                        <input type="text" id="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>

                    <?php if (!empty($field['description'])): ?>
                    <small><?= htmlspecialchars($field['description'], ENT_QUOTES, 'UTF-8') ?></small>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Нижняя панель -->
    <div class="form-actions">
        <a href="/admin/" class="btn btn-secondary">← Отмена</a>
        <button type="submit" class="btn btn-primary" onclick="return confirm('Сохранить изменения в config.env?');">💾 Сохранить настройки</button>
    </div>

    <!-- Кнопка пересоздания seed-данных -->
    <div class="card" style="margin-top: 1.5rem; border-left: 4px solid var(--teal-medium);">
        <div class="card-body" style="padding: 1rem;">
            <h4 style="margin: 0 0 0.5rem 0;">🔄 Пересоздать seed-данные</h4>
            <p style="margin: 0 0 1rem 0; color: var(--silver); font-size: 0.875rem;">
                Обновит название сайта в Schema.org, главной странице и авторе по умолчанию на основе текущего APP_NAME из config.env.
            </p>
            <form method="POST" action="/admin/settings/rebuild-seed" style="display: inline;">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Пересоздать seed-данные с актуальным APP_NAME?');">
                    🔄 Пересоздать seed-данные
                </button>
            </form>
        </div>
    </div>
<?php $__template->endSection('content'); ?>