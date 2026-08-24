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
<aside id="mainSidebar" class="main-sidebar hidden lg:flex lg:flex-col">

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

            <!-- ── Global search ─────────────────────────────────── -->
            <div class="gsearch hidden lg:block" id="gsearch">
              <div class="gsearch-head">
                <div class="gsearch-field" onclick="gsFocus()">
                    <span class="gsearch-icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    </span>
                    <input type="text" id="gsearchInput" class="gsearch-input"
                           placeholder="Search tenants, properties, leases…"
                           autocomplete="off" spellcheck="false"
                           role="combobox" aria-expanded="false" aria-controls="gsearchPanel"
                           aria-autocomplete="list" aria-label="Search everything">
                    <span class="gsearch-spinner" aria-hidden="true">
                        <svg class="animate-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                    </span>
                    <button type="button" class="gsearch-clear" onclick="gsClear()" aria-label="Clear search">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                    <span class="gsearch-kbd" aria-hidden="true">
                        <kbd id="gsearchMod">Ctrl</kbd><kbd>K</kbd>
                    </span>
                </div>
                <button type="button" class="gsearch-sheet-close" onclick="gsCloseSheet()" aria-label="Close search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
              </div>

                <div class="gsearch-panel" id="gsearchPanel" role="listbox" aria-label="Search results">
                    <div class="gsearch-scroll" id="gsearchScroll"></div>
                    <div class="gsearch-foot">
                        <span><kbd>&#8593;</kbd><kbd>&#8595;</kbd> navigate</span>
                        <span><kbd>&#8629;</kbd> open</span>
                        <span><kbd>esc</kbd> close</span>
                    </div>
                </div>
            </div>

            <!-- Opens the same search as a sheet on small screens -->
            <button type="button" class="topbar-btn gsearch-trigger ml-auto" onclick="gsOpenSheet()" aria-label="Search">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            </button>

            <script>
            (function () {
                var wrap   = document.getElementById('gsearch');
                var input  = document.getElementById('gsearchInput');
                var panel  = document.getElementById('gsearchPanel');
                var scroll = document.getElementById('gsearchScroll');
                if (!wrap || !input) return;

                var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
                if (isMac) document.getElementById('gsearchMod').textContent = '⌘';

                var ICONS = {
                    user:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                    building: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>',
                    file:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
                    dollar:   '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
                    tool:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'
                };
                // Results arrive flat; this is the order the groups read best in
                var ORDER = ['Tenant', 'Property', 'Lease', 'Payment', 'Maintenance'];
                var PLURAL = {
                    Tenant: 'Tenants', Property: 'Properties', Lease: 'Leases',
                    Payment: 'Payments', Maintenance: 'Maintenance'
                };

                var items = [];        // flat list of <a> nodes, in render order
                var active = -1;
                var timer = null;
                var lastQuery = '';

                function esc(str) {
                    return String(str == null ? '' : str)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                }

                // Show the user why each row matched
                function highlight(text, query) {
                    var safe = esc(text);
                    var q = query.trim();
                    if (!q) return safe;
                    var at = safe.toLowerCase().indexOf(q.toLowerCase());
                    if (at < 0) return safe;
                    return safe.slice(0, at) + '<mark>' + safe.slice(at, at + q.length) +
                           '</mark>' + safe.slice(at + q.length);
                }

                function open()  { wrap.classList.add('is-open');    input.setAttribute('aria-expanded', 'true'); }
                function close() { wrap.classList.remove('is-open'); input.setAttribute('aria-expanded', 'false'); active = -1; }

                function state(html) { scroll.innerHTML = '<div class="gsearch-state">' + html + '</div>'; items = []; active = -1; }

                function render(results, query) {
                    if (!results.length) {
                        state('No matches for <strong>' + esc(query) + '</strong>');
                        open();
                        return;
                    }

                    var groups = {};
                    results.forEach(function (r) { (groups[r.type] = groups[r.type] || []).push(r); });

                    var types = ORDER.filter(function (t) { return groups[t]; })
                        .concat(Object.keys(groups).filter(function (t) { return ORDER.indexOf(t) < 0; }));

                    var html = '';
                    types.forEach(function (type) {
                        html += '<div class="gsearch-group-label">' + esc(PLURAL[type] || type) + '</div>';
                        groups[type].forEach(function (r) {
                            html += '<a class="gsearch-item" role="option" aria-selected="false" href="' + esc(r.url) + '">' +
                                        '<span class="gsearch-item-icon">' + (ICONS[r.icon] || ICONS.file) + '</span>' +
                                        '<span class="gsearch-item-body">' +
                                            '<span class="gsearch-item-label">' + highlight(r.label, query) + '</span>' +
                                            '<span class="gsearch-item-sub">' + esc(r.sub) + '</span>' +
                                        '</span>' +
                                        '<span class="gsearch-enter" aria-hidden="true">&#8629;</span>' +
                                    '</a>';
                        });
                    });

                    scroll.innerHTML = html;
                    items = Array.prototype.slice.call(scroll.querySelectorAll('.gsearch-item'));
                    active = -1;
                    open();
                }

                function setActive(next) {
                    if (!items.length) return;
                    if (active >= 0) {
                        items[active].classList.remove('is-active');
                        items[active].setAttribute('aria-selected', 'false');
                    }
                    active = (next + items.length) % items.length;
                    items[active].classList.add('is-active');
                    items[active].setAttribute('aria-selected', 'true');
                    items[active].scrollIntoView({ block: 'nearest' });
                }

                function run(q) {
                    wrap.classList.add('is-loading');
                    fetch('search.php?q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (q !== lastQuery) return;          // a newer keystroke won
                            render(data.results || [], q);
                        })
                        .catch(function () {
                            state('Could not reach the server. Check your connection and try again.');
                            open();
                        })
                        .finally(function () { wrap.classList.remove('is-loading'); });
                }

                input.addEventListener('input', function () {
                    var q = input.value.trim();
                    lastQuery = q;
                    wrap.classList.toggle('has-query', q.length > 0);
                    clearTimeout(timer);

                    if (q.length === 0) { close(); return; }
                    if (q.length < 2) {
                        state('Keep typing &mdash; at least 2 characters');
                        open();
                        return;
                    }
                    timer = setTimeout(function () { run(q); }, 220);
                });

                input.addEventListener('focus', function () {
                    if (input.value.trim().length >= 2 && items.length) open();
                });

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown')      { e.preventDefault(); setActive(active + 1); }
                    else if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(active - 1); }
                    else if (e.key === 'Home' && items.length) { e.preventDefault(); setActive(0); }
                    else if (e.key === 'End'  && items.length) { e.preventDefault(); setActive(items.length - 1); }
                    else if (e.key === 'Enter') {
                        if (active >= 0 && items[active]) { e.preventDefault(); items[active].click(); }
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        if (wrap.classList.contains('is-open')) close();
                        else { gsClear(); input.blur(); }
                        if (wrap.classList.contains('is-sheet')) gsCloseSheet();
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!wrap.contains(e.target)) close();
                });

                document.addEventListener('keydown', function (e) {
                    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                        e.preventDefault();
                        if (window.matchMedia('(min-width: 1024px)').matches) gsFocus();
                        else gsOpenSheet();
                    }
                });

                window.gsFocus = function () { input.focus(); input.select(); };
                window.gsClear = function () {
                    input.value = '';
                    lastQuery = '';
                    wrap.classList.remove('has-query');
                    close();
                    input.focus();
                };
                window.gsOpenSheet = function () {
                    wrap.classList.add('is-sheet', 'is-open');
                    wrap.classList.remove('hidden');
                    setTimeout(function () { input.focus(); }, 30);
                };
                window.gsCloseSheet = function () {
                    wrap.classList.remove('is-sheet', 'is-open');
                    if (!window.matchMedia('(min-width: 1024px)').matches) wrap.classList.add('hidden');
                };
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
