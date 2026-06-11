<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'tenant';
$user = getCurrentUser($pdo);
$userName = $user['full_name'] ?? ($_SESSION['email'] ?? 'User');
$userEmail = $user['email'] ?? '';
$userInitial = strtoupper(substr($userName, 0, 1));

require_once __DIR__ . '/notify.php';
$_unreadCount = getUnreadCount($pdo, $_SESSION['user_id'] ?? '');
?>
<!-- ===== DESKTOP SIDEBAR ===== -->
<aside class="w-[272px] min-h-screen sticky top-0 hidden lg:flex flex-col bg-white dark:bg-slate-950 border-r border-slate-200/80 dark:border-slate-800/80" style="height: 100vh; overflow-y: auto;">
    <!-- Logo -->
    <div class="flex items-center gap-3 p-6 pb-4 border-b border-slate-100 dark:border-slate-800/50">
        <div class="w-10 h-10 bg-accent-green rounded-xl flex items-center justify-center text-slate-900 shadow-lg shadow-green-400/20 shrink-0">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div>
            <h1 class="text-[17px] font-black tracking-tight text-slate-900 dark:text-white leading-none">PRIMELINK</h1>
            <p class="text-[9px] font-black text-accent-green uppercase tracking-[0.2em]">Management</p>
        </div>
    </div>

    <!-- Navigation -->
    <div class="flex-1 px-4 py-5 overflow-y-auto">
        <?php include __DIR__ . '/sidebar_nav.php'; ?>
    </div>

    <!-- User Profile Footer -->
    <div class="p-4 border-t border-slate-100 dark:border-slate-800/50">
        <a href="profile.php" class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-all group cursor-pointer">
            <div class="w-9 h-9 rounded-xl bg-linear-to-br from-green-500 to-green-700 flex items-center justify-center text-white font-black text-sm shrink-0 shadow-md">
                <?php echo $userInitial; ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($userName); ?></p>
                <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider"><?php echo ucfirst($role); ?></p>
            </div>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300 dark:text-slate-600 group-hover:text-accent-green transition-colors"><path d="m9 18 6-6-6-6"/></svg>
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
        <div class="hidden lg:flex flex-1 max-w-sm relative" id="global-search-wrap">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none z-10"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" id="global-search-input" placeholder="Search properties, tenants… (Ctrl+K)"
                   autocomplete="off"
                   class="w-full pl-10 pr-10 py-2.5 bg-slate-100 dark:bg-slate-800/60 rounded-xl text-sm font-medium text-slate-700 dark:text-slate-300 placeholder-slate-400 border-none focus:outline-none focus:ring-2 focus:ring-green-400/30">
            <span id="global-search-spinner" class="hidden absolute right-3 top-1/2 -translate-y-1/2">
                <svg class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            </span>
            <!-- Results dropdown -->
            <div id="global-search-results"
                 class="hidden absolute top-full mt-2 left-0 right-0 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden max-h-[420px] overflow-y-auto">
            </div>
        </div>
<script>
(function() {
    const input   = document.getElementById('global-search-input');
    const results = document.getElementById('global-search-results');
    const spinner = document.getElementById('global-search-spinner');
    if (!input) return;

    const iconSvg = {
        user:     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        building: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>',
        file:     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        dollar:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        tool:     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
    };
    const typeBadgeColor = {
        Tenant: 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        Property: 'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400',
        Lease: 'bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400',
        Payment: 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
        Maintenance: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    };

    let timer;
    let activeIdx = -1;
    let items = [];

    function close() { results.classList.add('hidden'); activeIdx = -1; }
    function open()  { results.classList.remove('hidden'); }

    function render(data) {
        items = data.results || [];
        if (items.length === 0) {
            results.innerHTML = '<div class="p-5 text-center text-sm text-slate-400 font-medium">No results found</div>';
            open(); return;
        }
        results.innerHTML = items.map((r, i) => `
            <a href="${r.url}" class="search-result-item flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer" data-idx="${i}">
                <span class="w-8 h-8 rounded-lg ${typeBadgeColor[r.type] || 'bg-slate-100 text-slate-500'} flex items-center justify-center shrink-0">
                    ${iconSvg[r.icon] || ''}
                </span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-slate-900 dark:text-white truncate">${r.label}</p>
                    <p class="text-[10px] text-slate-400 font-medium truncate">${r.sub}</p>
                </div>
                <span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full ${typeBadgeColor[r.type] || ''} shrink-0">${r.type}</span>
            </a>
        `).join('');
        open();
    }

    async function doSearch(q) {
        spinner.classList.remove('hidden');
        try {
            const res  = await fetch('search.php?q=' + encodeURIComponent(q));
            const data = await res.json();
            render(data);
        } catch { close(); }
        finally { spinner.classList.add('hidden'); }
    }

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { close(); return; }
        timer = setTimeout(() => doSearch(q), 250);
    });

    input.addEventListener('keydown', e => {
        const els = results.querySelectorAll('.search-result-item');
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, els.length - 1); els.forEach((el, i) => el.classList.toggle('bg-slate-100', i === activeIdx)); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, -1); els.forEach((el, i) => el.classList.toggle('bg-slate-100', i === activeIdx)); }
        if (e.key === 'Enter' && activeIdx >= 0) { e.preventDefault(); els[activeIdx]?.click(); }
        if (e.key === 'Escape') { close(); input.blur(); }
    });

    document.addEventListener('click', e => { if (!document.getElementById('global-search-wrap').contains(e.target)) close(); });

    // Ctrl+K / Cmd+K shortcut
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); input.focus(); input.select(); }
    });
})();
</script>

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
                <?php if ($_unreadCount > 0): ?>
                <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] bg-red-500 text-white text-[9px] font-black rounded-full flex items-center justify-center px-1 leading-none"><?php echo $_unreadCount > 99 ? '99+' : $_unreadCount; ?></span>
                <?php else: ?>
                <span class="notif-dot"></span>
                <?php endif; ?>
            </a>

            <!-- User Avatar -->
            <a href="profile.php" class="w-9 h-9 rounded-xl bg-linear-to-br from-green-500 to-green-700 flex items-center justify-center text-white font-black text-sm shadow-md hover:shadow-green-400/30 hover:scale-105 transition-all shrink-0">
                <?php echo $userInitial; ?>
            </a>
        </div>
    </header>

    <!-- Page Content -->
    <main class="flex-1 p-6 lg:p-8">
