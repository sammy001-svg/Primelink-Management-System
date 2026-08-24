<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'tenant';
$user = getCurrentUser($pdo);
$userName  = $user['full_name'] ?? ($_SESSION['email'] ?? 'User');
$userEmail = $user['email'] ?? '';
$userInitial = strtoupper(substr($userName, 0, 1));

require_once __DIR__ . '/notify.php';
$_unreadCount = getUnreadCount($pdo, $_SESSION['user_id'] ?? '');
?>
<!-- ===== DESKTOP SIDEBAR ===== -->
<aside id="mainSidebar" class="main-sidebar hidden lg:flex">

    <!-- Brand -->
    <div class="flex items-center gap-2.5 px-4 shrink-0" style="height:52px;border-bottom:1px solid var(--border);">
        <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0" style="background:var(--accent-green);color:#fff;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div class="sidebar-logo-text leading-tight min-w-0">
            <p class="text-[13.5px] font-semibold tracking-tight truncate" style="color:var(--text)">Primelink</p>
            <p class="text-[10.5px] truncate" style="color:var(--text-subtle)">Management</p>
        </div>
    </div>

    <!-- Navigation -->
    <div class="flex-1 px-2.5 py-3 overflow-y-auto">
        <?php include __DIR__ . '/sidebar_nav.php'; ?>
    </div>

    <!-- User footer -->
    <div class="p-2.5 shrink-0" style="border-top:1px solid var(--border);">
        <a href="profile.php" class="sidebar-link group">
            <div class="relative shrink-0">
                <div class="w-6 h-6 rounded-md flex items-center justify-center text-[11px] font-semibold"
                     style="background:var(--accent-green-light);color:var(--accent-green);">
                    <?php echo $userInitial; ?>
                </div>
            </div>
            <div class="flex-1 min-w-0 sidebar-profile-info leading-tight">
                <p class="text-[12.5px] font-medium truncate" style="color:var(--text)"><?php echo htmlspecialchars($userName); ?></p>
                <p class="text-[11px] truncate" style="color:var(--text-subtle)"><?php echo ucfirst($role); ?></p>
            </div>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0 sidebar-profile-info" style="color:var(--text-subtle)"><path d="m9 18 6-6-6-6"/></svg>
        </a>
        <a href="logout.php" class="sidebar-link">
            <span class="sidebar-icon-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
            </span>
            <span class="sidebar-footer-logout-text">Sign out</span>
        </a>
    </div>
</aside>

<script>
(function() {
    const sidebar = document.getElementById('mainSidebar');
    if (!sidebar) return;
    if (localStorage.getItem('sidebarCollapsed') === '1') {
        sidebar.classList.add('collapsed');
    }
})();
function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    if (!sidebar) return;
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');
    const icon = document.getElementById('sidebarCollapseIcon');
    if (icon) icon.style.transform = isCollapsed ? 'scaleX(-1)' : '';
}
</script>

<!-- ===== MAIN CONTENT WRAPPER ===== -->
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">

    <!-- Top Bar -->
    <header class="topbar">
        <!-- LEFT GROUP: toggle + search -->
        <div class="topbar-left">

            <!-- Sidebar collapse (desktop) -->
            <button onclick="toggleSidebar()" class="topbar-btn hidden lg:flex tooltip-wrap" aria-label="Toggle sidebar">
                <span class="tooltip">Toggle sidebar</span>
                <svg id="sidebarCollapseIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m14 9 3 3-3 3"/></svg>
            </button>

            <!-- Hamburger (mobile) -->
            <button onclick="openMobileDrawer()" class="topbar-btn lg:hidden" aria-label="Open menu">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
            </button>

            <!-- Page title (mobile) -->
            <div class="lg:hidden flex-1 min-w-0">
                <h2 class="text-[14px] font-semibold truncate" style="color:var(--text)"><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard'; ?></h2>
            </div>

            <!-- Search (desktop) -->
            <div class="hidden lg:flex flex-1 max-w-lg relative" id="global-search-wrap">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none z-10"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" id="global-search-input" placeholder="Search tenants, properties… (Ctrl+K)"
                   autocomplete="off"
                   class="global-search-input">
            <span id="global-search-spinner" class="hidden absolute right-3 top-1/2 -translate-y-1/2">
                <svg class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            </span>
            <div id="global-search-results"
                 class="hidden absolute top-full mt-1.5 left-0 right-0 z-50 overflow-hidden max-h-[400px] overflow-y-auto"
                 style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-card);box-shadow:var(--shadow-overlay);">
            </div>
        </div>
        <script>
        (function() {
            const input=document.getElementById('global-search-input'),results=document.getElementById('global-search-results'),spinner=document.getElementById('global-search-spinner');
            if(!input)return;
            const iconSvg={user:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',building:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>',file:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',dollar:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',tool:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'};
            const badge={Tenant:'bg-blue-100 dark:bg-blue-900/30 text-blue-600',Property:'bg-purple-100 dark:bg-purple-900/30 text-purple-600',Lease:'bg-orange-100 dark:bg-orange-900/30 text-orange-600',Payment:'bg-green-100 dark:bg-green-900/30 text-green-600',Maintenance:'bg-red-100 dark:bg-red-900/30 text-red-600'};
            let timer,ai=-1;
            const close=()=>{results.classList.add('hidden');ai=-1;};
            const open=()=>results.classList.remove('hidden');
            function render(data){
                const items=data.results||[];
                if(!items.length){results.innerHTML='<div class="p-5 text-center text-sm text-slate-400">No results found</div>';open();return;}
                results.innerHTML=items.map((r,i)=>`<a href="${r.url}" class="search-result-item flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors" data-idx="${i}"><span class="w-8 h-8 rounded-lg ${badge[r.type]||'bg-slate-100 text-slate-500'} flex items-center justify-center shrink-0">${iconSvg[r.icon]||''}</span><div class="flex-1 min-w-0"><p class="text-sm font-bold text-slate-900 dark:text-white truncate">${r.label}</p><p class="text-[10px] text-slate-400 truncate">${r.sub}</p></div><span class="text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full ${badge[r.type]||''} shrink-0">${r.type}</span></a>`).join('');
                open();
            }
            async function doSearch(q){spinner.classList.remove('hidden');try{const res=await fetch('search.php?q='+encodeURIComponent(q));render(await res.json());}catch{close();}finally{spinner.classList.add('hidden');}}
            input.addEventListener('input',()=>{const q=input.value.trim();clearTimeout(timer);if(q.length<2){close();return;}timer=setTimeout(()=>doSearch(q),250);});
            input.addEventListener('keydown',e=>{const els=results.querySelectorAll('.search-result-item');if(e.key==='ArrowDown'){e.preventDefault();ai=Math.min(ai+1,els.length-1);els.forEach((el,i)=>el.classList.toggle('bg-slate-100',i===ai));}if(e.key==='ArrowUp'){e.preventDefault();ai=Math.max(ai-1,-1);els.forEach((el,i)=>el.classList.toggle('bg-slate-100',i===ai));}if(e.key==='Enter'&&ai>=0){e.preventDefault();els[ai]?.click();}if(e.key==='Escape'){close();input.blur();}});
            document.addEventListener('click',e=>{if(!document.getElementById('global-search-wrap').contains(e.target))close();});
            document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='k'){e.preventDefault();input.focus();input.select();}});
        })();
        </script>

        </div><!-- end topbar-left -->

        <!-- Right Actions -->
        <div class="topbar-right">
            <!-- Dark mode toggle -->
            <button onclick="toggleDarkMode()" class="topbar-btn tooltip-wrap" aria-label="Toggle theme">
                <span class="tooltip">Toggle theme</span>
                <svg class="dark:hidden" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                <svg class="hidden dark:block" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>

            <!-- Notifications -->
            <a href="notifications.php" class="topbar-btn relative tooltip-wrap" aria-label="Notifications">
                <span class="tooltip">Notifications</span>
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($_unreadCount > 0): ?>
                <span class="absolute top-0.5 right-0.5 min-w-[15px] h-[15px] text-[9.5px] font-medium rounded-full flex items-center justify-center px-1 leading-none"
                      style="background:var(--danger);color:#fff;"><?php echo $_unreadCount > 99 ? '99+' : $_unreadCount; ?></span>
                <?php else: ?>
                <span class="notif-dot"></span>
                <?php endif; ?>
            </a>

            <?php if (in_array($userRole, ['admin','staff'])): ?>
            <!-- Quick Create (+) -->
            <div class="quick-create-wrap">
                <button onclick="toggleQuickCreate()" id="qcBtn"
                    class="topbar-btn tooltip-wrap" style="background:var(--accent-green);color:#fff;border-color:var(--accent-green);"
                    aria-label="Quick create">
                    <span class="tooltip">Quick Create</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                </button>
                <div id="quickCreateMenu" class="quick-create-menu">
                    <div class="px-4 pt-3 pb-2">
                        <p class="section-label">Quick create</p>
                    </div>
                    <a href="properties.php?action=new" onclick="closeQuickCreate()">
                        <span class="qc-icon bg-blue-50 dark:bg-blue-900/30 text-blue-500"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg></span>
                        New Property
                    </a>
                    <a href="tenants.php?action=new" onclick="closeQuickCreate()">
                        <span class="qc-icon bg-green-50 dark:bg-green-900/30 text-green-500"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
                        Register Tenant
                    </a>
                    <a href="leases.php?action=new" onclick="closeQuickCreate()">
                        <span class="qc-icon bg-purple-50 dark:bg-purple-900/30 text-purple-500"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                        Create Lease
                    </a>
                    <a href="financials.php?action=new" onclick="closeQuickCreate()">
                        <span class="qc-icon bg-orange-50 dark:bg-orange-900/30 text-orange-500"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                        Record Payment
                    </a>
                    <a href="maintenance.php?action=new" onclick="closeQuickCreate()">
                        <span class="qc-icon bg-red-50 dark:bg-red-900/30 text-red-500"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                        Log Maintenance
                    </a>
                    <a href="tokens.php?action=new" onclick="closeQuickCreate()">
                        <span class="qc-icon bg-yellow-50 dark:bg-yellow-900/30 text-yellow-600"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></span>
                        Issue Token
                    </a>
                </div>
            </div>
            <script>
            function toggleQuickCreate(){const m=document.getElementById('quickCreateMenu');if(m)m.classList.toggle('open');}
            function closeQuickCreate(){const m=document.getElementById('quickCreateMenu');if(m)m.classList.remove('open');}
            document.addEventListener('click',e=>{const btn=document.getElementById('qcBtn');const m=document.getElementById('quickCreateMenu');if(m&&btn&&!btn.contains(e.target)&&!m.contains(e.target))m.classList.remove('open');});
            </script>
            <?php endif; ?>

            <!-- User Avatar -->
            <a href="profile.php" class="w-7 h-7 rounded-md flex items-center justify-center text-[11.5px] font-semibold shrink-0 tooltip-wrap ml-1"
               style="background:var(--accent-green-light);color:var(--accent-green);">
                <span class="tooltip">My profile</span>
                <?php echo $userInitial; ?>
            </a>
        </div>
    </header>

    <!-- Breadcrumb bar (desktop only) -->
    <?php if ($_curPage !== 'dashboard.php' && $_crumb[1] !== null): ?>
    <div class="breadcrumb-bar hidden lg:flex">
        <a href="dashboard.php">Home</a>
        <?php if ($_crumb[1] && $_crumb[2]): ?>
        <span class="breadcrumb-separator">›</span>
        <a href="<?php echo $_crumb[2]; ?>"><?php echo $_crumb[1]; ?></a>
        <?php endif; ?>
        <span class="breadcrumb-separator">›</span>
        <span class="breadcrumb-current"><?php echo $_crumb[0]; ?></span>
    </div>
    <?php endif; ?>

    <!-- Page Content -->
    <main class="flex-1 p-4 lg:p-6">
