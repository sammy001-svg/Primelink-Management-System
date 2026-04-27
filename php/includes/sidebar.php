<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'tenant';
$user = getCurrentUser($pdo);
$userName = $user['full_name'] ?? ($_SESSION['email'] ?? 'User');
$userEmail = $user['email'] ?? '';
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!-- ===== DESKTOP SIDEBAR ===== -->
<aside class="w-[272px] min-h-screen sticky top-0 hidden lg:flex flex-col bg-white dark:bg-slate-950 border-r border-slate-200/80 dark:border-slate-800/80" style="height: 100vh; overflow-y: auto;">
    <!-- Logo -->
    <div class="flex items-center gap-3 p-6 pb-4 border-b border-slate-100 dark:border-slate-800/50">
        <div class="w-10 h-10 bg-accent-gold rounded-xl flex items-center justify-center text-slate-900 shadow-lg shadow-amber-400/20 shrink-0">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div>
            <h1 class="text-[17px] font-black tracking-tight text-slate-900 dark:text-white leading-none">PRIMELINK</h1>
            <p class="text-[9px] font-black text-accent-gold uppercase tracking-[0.2em]">Management</p>
        </div>
    </div>

    <!-- Navigation -->
    <div class="flex-1 px-4 py-5 overflow-y-auto">
        <?php include __DIR__ . '/sidebar_nav.php'; ?>
    </div>

    <!-- User Profile Footer -->
    <div class="p-4 border-t border-slate-100 dark:border-slate-800/50">
        <a href="profile.php" class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-all group cursor-pointer">
            <div class="w-9 h-9 rounded-xl bg-linear-to-br from-accent-gold to-amber-600 flex items-center justify-center text-white font-black text-sm shrink-0 shadow-md">
                <?php echo $userInitial; ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($userName); ?></p>
                <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider"><?php echo ucfirst($role); ?></p>
            </div>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300 dark:text-slate-600 group-hover:text-accent-gold transition-colors"><path d="m9 18 6-6-6-6"/></svg>
        </a>
        <a href="logout.php" class="mt-1 flex items-center gap-3 px-4 py-3 rounded-xl text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 transition-all font-bold text-sm">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- ===== MAIN CONTENT WRAPPER ===== -->
<div class="flex-1 flex flex-col min-w-0">
    <!-- Top Bar -->
    <header class="topbar">
        <!-- Hamburger (mobile) -->
        <button onclick="openMobileDrawer()" class="lg:hidden p-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors text-slate-500" aria-label="Open menu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
        </button>

        <!-- Page Title (mobile) -->
        <div class="lg:hidden flex-1">
            <h2 class="text-base font-black text-slate-900 dark:text-white"><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard'; ?></h2>
        </div>

        <!-- Search (desktop) -->
        <div class="hidden lg:flex flex-1 max-w-sm relative">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" placeholder="Search properties, tenants..." class="w-full pl-10 pr-4 py-2.5 bg-slate-100 dark:bg-slate-800/60 rounded-xl text-sm font-medium text-slate-700 dark:text-slate-300 placeholder-slate-400 border-none focus:outline-none focus:ring-2 focus:ring-amber-400/30">
        </div>

        <!-- Right Actions -->
        <div class="flex items-center gap-2">
            <!-- Dark mode toggle -->
            <button onclick="toggleDarkMode()" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 transition-all tooltip-wrap" aria-label="Toggle theme">
                <span class="tooltip">Toggle theme</span>
                <svg class="dark:hidden" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                <svg class="hidden dark:block" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>

            <!-- Notifications -->
            <a href="notifications.php" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 transition-all relative tooltip-wrap" aria-label="Notifications">
                <span class="tooltip">Notifications</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="notif-dot"></span>
            </a>

            <!-- User Avatar -->
            <a href="profile.php" class="w-9 h-9 rounded-xl bg-linear-to-br from-accent-gold to-amber-600 flex items-center justify-center text-white font-black text-sm shadow-md hover:shadow-amber-400/30 hover:scale-105 transition-all shrink-0">
                <?php echo $userInitial; ?>
            </a>
        </div>
    </header>

    <!-- Page Content -->
    <main class="flex-1 p-6 lg:p-8">
