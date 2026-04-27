<?php
require_once __DIR__ . '/auth.php';
$user = getCurrentUser($pdo);
$userName = $user['full_name'] ?? ($_SESSION['email'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));
$userRole = $_SESSION['role'] ?? 'tenant';
?>
<!DOCTYPE html>
<html lang="en" class="dark" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . " | Primelink" : "Primelink Management System"; ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="<?php echo str_repeat('../', substr_count(basename($_SERVER['PHP_SELF']), '/')) ?>css/style.css">
    <script>
        // Tailwind config
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { 'accent-green': '#22c55e' },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        heading: ['Outfit', 'sans-serif'],
                    }
                }
            }
        };
        // Dark/Light mode initialization
        (function() {
            const saved = localStorage.getItem('theme');
            const html = document.documentElement;
            if (saved === 'light') {
                html.classList.remove('dark');
            } else {
                html.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-50 min-h-screen font-sans antialiased selection:bg-green-300/30">

<!-- ===== MOBILE DRAWER SIDEBAR ===== -->
<div class="mobile-drawer" id="mobileDrawer" onclick="closeMobileDrawer(event)">
    <div class="drawer-overlay"></div>
    <div class="drawer-panel" id="drawerPanel">
        <div class="flex items-center gap-3 mb-8 px-2">
            <div class="w-10 h-10 bg-accent-green rounded-xl flex items-center justify-center text-slate-900 shadow-lg">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div>
                <h1 class="text-lg font-black tracking-tight text-slate-900 dark:text-white leading-none">PRIMELINK</h1>
                <p class="text-[9px] font-black text-accent-green uppercase tracking-[0.2em]">Management</p>
            </div>
        </div>
        <?php include __DIR__ . '/sidebar_nav.php'; ?>
    </div>
</div>

<div class="flex min-h-screen">
