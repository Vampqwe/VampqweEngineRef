<?php $__template->setLayout('layouts/main'); ?>

<?php $__template->startSection('title', ); ?><?php echo htmlspecialchars((string) (trim($page['title'])), ENT_QUOTES, 'UTF-8'); ?><?php $__template->endSection('title'); ?>

<?php $__template->startSection('description', ); ?><?php echo htmlspecialchars((string) (trim($page['description'])), ENT_QUOTES, 'UTF-8'); ?><?php $__template->endSection('description'); ?>

<?php $__template->startSection('keywords', ); ?><?php echo htmlspecialchars((string) (trim($page['keywords'] ?? '')), ENT_QUOTES, 'UTF-8'); ?><?php $__template->endSection('keywords'); ?>

<?php $__template->startSection('robots', ); ?><?php echo htmlspecialchars((string) (trim($page['meta_robots'])), ENT_QUOTES, 'UTF-8'); ?><?php $__template->endSection('robots'); ?>

<?php $__template->startSection('canonical', ); ?><?php echo htmlspecialchars((string) (trim($canonical_url)), ENT_QUOTES, 'UTF-8'); ?><?php $__template->endSection('canonical'); ?>

<?php $__template->startSection('og_title', ); ?><?php echo htmlspecialchars((string) (trim($page['og_title'])), ENT_QUOTES, 'UTF-8'); ?><?php $__template->endSection('og_title'); ?>

<?php $__template->startSection('og_description', ); ?><?php echo htmlspecialchars((string) (trim($page['og_description'])), ENT_QUOTES, 'UTF-8'); ?><?php $__template->endSection('og_description'); ?>

<?php $__template->startSection('og_image_tag', ); ?>
    <?php if (!empty($page['og_image'])): ?>
        <meta property="og:image" content="<?php echo htmlspecialchars((string) ($og_image_url), ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
<?php $__template->endSection('og_image_tag'); ?>

<?php $__template->startSection('schema_scripts', ); ?>
    <?php foreach ($page['schema_org'] as $schema): ?>
        <?php echo $template->schemaJson($schema); ?>
    <?php endforeach; ?>
<?php $__template->endSection('schema_scripts'); ?>

<?php $__template->startSection('content', ); ?>
    
    <?php echo $template->trustedHtml($page['content']); ?>
<?php $__template->endSection('content'); ?>

<?php $__template->startSection('scripts', ); ?>
    <?php echo $template->scripts($page['scripts']); ?>
<?php $__template->endSection('scripts'); ?>