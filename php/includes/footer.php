<?php
// Dark mode JS, mobile nav JS and footer close
?>
    </main>
</div><!-- end flex -->

<!-- ===== MOBILE BOTTOM NAV ===== -->
<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'tenant';
?>
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
        Pay
    </a>
    <?php if ($role != 'tenant'): ?>
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
// ========== DARK MODE TOGGLE ==========
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

// ========== MODAL HELPERS ==========
function openModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
}
// Close modal on overlay click
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});
// Close on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.style.display = 'none'; document.body.style.overflow = '';
        });
        closeMobileDrawer({target: document.getElementById('mobileDrawer'), currentTarget: document.getElementById('mobileDrawer')});
    }
});

// ========== SUCCESS TOAST AUTO-DISMISS ==========
setTimeout(() => {
    document.querySelectorAll('.success-toast').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(-10px)';
        setTimeout(() => el.remove(), 300);
    });
}, 4000);
</script>
</body>
</html>
