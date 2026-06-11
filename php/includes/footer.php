<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'tenant';
?>
    </main>
</div><!-- end flex-1 main wrapper -->
</div><!-- end outer flex -->

<!-- ===== TOAST CONTAINER ===== -->
<div id="toast-container"></div>

<!-- ===== CONFIRM DIALOG ===== -->
<div id="confirm-overlay">
    <div id="confirm-box">
        <div class="flex items-start gap-4 mb-6">
            <div id="confirm-icon" class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 bg-red-100 dark:bg-red-900/30 text-red-500">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </div>
            <div>
                <h3 id="confirm-title" class="font-black text-slate-900 dark:text-white text-lg leading-tight">Are you sure?</h3>
                <p id="confirm-msg" class="text-slate-500 text-sm mt-1 leading-relaxed">This action cannot be undone.</p>
            </div>
        </div>
        <div class="flex gap-3 justify-end">
            <button onclick="closeConfirm()" class="px-5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-black text-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all">Cancel</button>
            <a id="confirm-action-btn" href="#" class="px-5 py-2.5 rounded-xl bg-red-500 text-white font-black text-sm hover:bg-red-600 transition-all">Delete</a>
        </div>
    </div>
</div>

<!-- ===== MOBILE BOTTOM NAV ===== -->
<nav class="mobile-nav" aria-label="Mobile navigation">
    <a href="dashboard.php" class="mobile-nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        Home
    </a>
    <a href="maintenance.php" class="mobile-nav-link <?php echo $current_page == 'maintenance.php' ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        Fix
    </a>
    <a href="financials.php" class="mobile-nav-link <?php echo $current_page == 'financials.php' ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Finance
    </a>
    <?php if ($role !== 'tenant'): ?>
    <a href="properties.php" class="mobile-nav-link <?php echo $current_page == 'properties.php' ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M3 7V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2"/><path d="M5 21V7"/><path d="M19 21V7"/></svg>
        Props
    </a>
    <?php endif; ?>
    <a href="profile.php" class="mobile-nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-8 8-8s8 4 8 8"/></svg>
        Profile
    </a>
</nav>

<script>
// ========== DARK MODE ==========
function toggleDarkMode() {
    const html = document.documentElement;
    if (html.classList.contains('dark')) {
        html.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    } else {
        html.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    }
}

// ========== MOBILE DRAWER ==========
function openMobileDrawer() {
    document.getElementById('mobileDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeMobileDrawer(e) {
    if (e.target === e.currentTarget || e.target.closest('.drawer-overlay')) {
        document.getElementById('mobileDrawer').classList.remove('open');
        document.body.style.overflow = '';
    }
}

// ========== SUB-MENU ==========
function toggleSubMenu(btn) {
    const el = btn.nextElementSibling;
    if (!el) return;
    const icon = btn.querySelector('.sidebar-submenu-caret');
    if (el.classList.contains('hidden')) {
        el.classList.remove('hidden');
        if (icon) icon.style.transform = 'rotate(180deg)';
    } else {
        el.classList.add('hidden');
        if (icon) icon.style.transform = 'rotate(0deg)';
    }
}

// ========== MODAL ==========
function openModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
}
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.style.display = 'none';
            document.body.style.overflow = '';
        });
        const drawer = document.getElementById('mobileDrawer');
        if (drawer && drawer.classList.contains('open')) {
            drawer.classList.remove('open');
            document.body.style.overflow = '';
        }
        closeConfirm();
    }
});

// ========== TOAST SYSTEM ==========
function showToast(message, type = 'success', duration = 4500) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const icons = {
        success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        info:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
    };

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <span style="flex-shrink:0">${icons[type] || icons.info}</span>
        <span style="flex:1;line-height:1.4">${message}</span>
        <button class="toast-close" onclick="this.closest('.toast').remove()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>`;
    container.appendChild(toast);

    requestAnimationFrame(() => {
        requestAnimationFrame(() => toast.classList.add('show'));
    });

    const timer = setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 400);
    }, duration);

    toast.querySelector('.toast-close').addEventListener('click', () => clearTimeout(timer));
}

// ========== CONFIRM DIALOG ==========
function confirmAction(url, message = 'This action cannot be undone.', title = 'Are you sure?', btnLabel = 'Delete', btnClass = 'bg-red-500 hover:bg-red-600') {
    const overlay = document.getElementById('confirm-overlay');
    const titleEl = document.getElementById('confirm-title');
    const msgEl   = document.getElementById('confirm-msg');
    const actionBtn = document.getElementById('confirm-action-btn');
    if (!overlay) return;

    titleEl.textContent = title;
    msgEl.textContent   = message;
    actionBtn.textContent = btnLabel;
    actionBtn.href = url;
    actionBtn.className = `px-5 py-2.5 rounded-xl ${btnClass} text-white font-black text-sm transition-all`;

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeConfirm() {
    const overlay = document.getElementById('confirm-overlay');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('confirm-overlay')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeConfirm();
});

// ========== URL PARAM → TOAST ==========
(function() {
    const params = new URLSearchParams(window.location.search);
    const toastMsg  = params.get('toast_msg');
    const toastType = params.get('toast_type') || 'success';
    if (toastMsg) {
        setTimeout(() => showToast(decodeURIComponent(toastMsg), toastType), 200);
        // Clean URL without reload
        const url = new URL(window.location.href);
        url.searchParams.delete('toast_msg');
        url.searchParams.delete('toast_type');
        window.history.replaceState({}, '', url);
    }
    // Legacy: inline ?success / ?error params on pages that still use them
    const success = params.get('success');
    const error   = params.get('error');
    if (success && !toastMsg) {
        const msgs = {
            created: 'Created successfully.',
            updated: 'Updated successfully.',
            deleted: 'Deleted successfully.',
            saved:   'Changes saved.',
            sent:    'Sent successfully.',
        };
        setTimeout(() => showToast(msgs[success] || 'Operation successful.', 'success'), 200);
    }
    if (error && !toastMsg) {
        const msgs = {
            email_exists:   'A user with that email already exists.',
            not_found:      'Record not found.',
            permission:     'You do not have permission to do that.',
            validation:     'Please check your input and try again.',
        };
        setTimeout(() => showToast(msgs[error] || decodeURIComponent(error), 'error'), 200);
    }
})();
</script>
</body>
</html>
