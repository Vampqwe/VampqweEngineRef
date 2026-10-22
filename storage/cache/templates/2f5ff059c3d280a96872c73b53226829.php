<!DOCTYPE html>
<html lang="ru">
<head>

	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo $__template->yieldSection('title', $app_name); ?></title>
    <meta name="description" content="<?php echo $__template->yieldSection('description', ''); ?>">
    <meta name="keywords" content="<?php echo $__template->yieldSection('keywords', ''); ?>">
    <meta name="robots" content="<?php echo $__template->yieldSection('robots', 'index, follow'); ?>">

    <link rel="canonical" href="<?php echo $__template->yieldSection('canonical', $base_url); ?>">

    
    <meta property="og:title" content="<?php echo $__template->yieldSection('og_title', ''); ?>">
    <meta property="og:description" content="<?php echo $__template->yieldSection('og_description', ''); ?>">
    <meta property="og:type" content="<?php echo $__template->yieldSection('og_type', 'website'); ?>">
    <meta property="og:url" content="<?php echo $__template->yieldSection('canonical', $base_url); ?>">
    <meta property="og:site_name" content="Достойный уход">
    <meta property="og:locale" content="ru_BY">
    <?php echo $__template->yieldSection('og_image_tag', ''); ?>

	<link rel="icon" type="image/svg+xml" href="/favicon.svg">
	<link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png">
	<link rel="apple-touch-icon" sizes="180x180" href="/favicon-180.png">

	<!-- Preload критических шрифтов (LCP элемент использует Playfair Display) -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="preload" href="/assets/fonts/nuFiD-vYSZviVYUb_rj3ij__anPXDTjYgEM86xRbPQ.woff2" as="font" type="font/woff2" crossorigin>
	<link rel="preload" href="/assets/fonts/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7W0Q5nw.woff2" as="font" type="font/woff2" crossorigin>
	<link href="/assets/fonts/fontsgoogleapis300400500PlayfairDisplayitalwght4005001400displayswap.css" rel="stylesheet">

	<link rel="stylesheet" href="/assets/css/style.css">
	
	<?php 
		$neutralFsPath = \Core\System\Config\Config::getInstance()->getStoragePath('neutral-style.css');
	 ?>
	<?php if (is_file($neutralFsPath) && filesize($neutralFsPath) > 0): ?>
	<link rel="stylesheet" href="/storage/neutral-style.css?v=<?php echo htmlspecialchars((string) (filemtime($neutralFsPath)), ENT_QUOTES, 'UTF-8'); ?>">
	<?php endif; ?>

    
    <?php echo $template->cspNonceMeta(); ?>
    
    
    <?php echo $__template->yieldSection('schema_scripts', ''); ?>
    
    
    <?php echo $__template->yieldSection('head', ''); ?>
    
    <style nonce="<?php echo htmlspecialchars((string) ($template->getCspNonce()), ENT_QUOTES, 'UTF-8'); ?>">
        :root {
            --sage: #87a878;
            --sage-dark: #5c7a52;
            --bg-primary: #faf9f6;
            --bg-secondary: #f0efe9;
            --text-primary: #2c2c2c;
            --text-muted: #6b6b6b;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .main-nav {
            background: white;
            border-bottom: 1px solid #e5e5e5;
            padding: 15px 0;
        }
        
        .main-nav ul {
            list-style: none;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        
        .main-nav a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .main-nav a:hover {
            color: var(--sage);
        }
        
        main {
            min-height: 70vh;
            padding: 40px 0;
        }
        
        footer {
            background: var(--text-primary);
            color: white;
            padding: 40px 0;
            text-align: center;
        }
        
        footer a {
            color: var(--sage);
            text-decoration: none;
        }
    </style>
</head>
<body>
    

    <?php echo $template->renderPartial('partials/menu', ['menu_items' => $menu_items ?? []]); ?>

    
    
    <main>
        <?php echo $__template->yieldSection('content', ''); ?>
    </main>
    
    
    <footer>
        <div class="container">
            <p>&copy; <?php echo htmlspecialchars((string) (date('Y')), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string) ($app_name), ENT_QUOTES, 'UTF-8'); ?>. Все права защищены.</p>
            <p style="margin-top: 10px; font-size: 0.9rem; opacity: 0.7;">
                Проект ветеринарной клиники <a href="https://103vet.by" target="_blank">103vet.by</a>
            </p>
        </div>
    </footer>
    
    
    <?php echo $__template->yieldSection('scripts', ''); ?>
</body>
</html>