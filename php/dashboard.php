<?php
/**
 * Main Dashboard
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$role = $_SESSION['role'] ?? 'tenant';
$user = getCurrentUser($pdo);
$userName = $user['full_name'] ?? 'User';
$pageTitle = "Dashboard";

// Route landlords to their scoped dashboard
if ($role === 'landlord') {
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/sidebar.php';
    include __DIR__ . '/includes/landlord_dashboard.php';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Route tenants to their scoped dashboard
if ($role === 'tenant') {
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/sidebar.php';
    include __DIR__ . '/includes/tenant_dashboard.php';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// ========== LIVE STATS FROM DATABASE (Admin/Staff only reach here) ==========
$stats = [];
$stats['total_properties']    = $pdo->query("SELECT COUNT(*) FROM properties")->fetchColumn();
$stats['active_tenants']      = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status='Active'")->fetchColumn();
$stats['pending_maintenance'] = $pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status='Pending'")->fetchColumn();
$stats['tokens_sold']         = $pdo->query("SELECT COUNT(*) FROM tokens")->fetchColumn();
$stats['revenue_mtd']         = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND MONTH(transaction_date)=MONTH(NOW())")->fetchColumn();

// Monthly revenue for chart (last 6 months)
$chartLabels = [];
$chartData   = [];
for ($i = 5; $i >= 0; $i--) {
    $label = date('M', strtotime("-$i months"));
    $month = date('m', strtotime("-$i months"));
    $year  = date('Y', strtotime("-$i months"));
    $rev   = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND MONTH(transaction_date)=$month AND YEAR(transaction_date)=$year")->fetchColumn();
    $chartLabels[] = $label;
    $chartData[]   = (float)$rev;
}

// Recent activity
$recentMaint        = $pdo->query("SELECT title, status, created_at FROM maintenance_requests ORDER BY created_at DESC LIMIT 3")->fetchAll();
$recentTransactions = $pdo->query("SELECT t.full_name, tx.amount, tx.status, tx.transaction_date FROM transactions tx JOIN tenants t ON tx.tenant_id = t.id ORDER BY tx.transaction_date DESC LIMIT 3")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <!-- Greeting -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl lg:text-3xl font-black text-slate-900 dark:text-white tracking-tight">
                Good <?php echo (date('H') < 12) ? 'morning' : ((date('H') < 18) ? 'afternoon' : 'evening'); ?>, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?> 👋
            </h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-0.5"><?php echo date('l, F j, Y'); ?></p>
        </div>
        <?php if ($role !== 'tenant'): ?>
        <div class="flex gap-3 flex-wrap">
            <button onclick="openModal('newPropertyModal')" class="btn-primary text-xs gap-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add Property
            </button>
            <a href="tenants.php?action=new" class="btn-green text-xs gap-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add Tenant
            </a>
        </div>
        <?php else: ?>
        <a href="maintenance.php?action=new" class="btn-green text-xs">+ New Maintenance Request</a>
        <?php endif; ?>
    </div>

    <?php if ($role !== 'tenant'): ?>
    <!-- ===== ADMIN DASHBOARD ===== -->

    <!-- Stats Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
        <?php
        $statCards = [
            ['label' => 'Total Properties', 'value' => number_format($stats['total_properties']), 'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/></svg>', 'color' => 'text-blue-500', 'bg' => 'bg-blue-50 dark:bg-blue-900/20'],
            ['label' => 'Active Tenants',   'value' => number_format($stats['active_tenants']),   'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>', 'color' => 'text-green-500', 'bg' => 'bg-green-50 dark:bg-green-900/20'],
            ['label' => 'Utility Tokens Sold', 'value' => number_format($stats['tokens_sold']),  'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>', 'color' => 'text-purple-500', 'bg' => 'bg-purple-50 dark:bg-purple-900/20'],
            ['label' => 'Revenue (MTD)',     'value' => 'KSh ' . number_format($stats['revenue_mtd']), 'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', 'color' => 'text-orange-500', 'bg' => 'bg-orange-50 dark:bg-orange-900/20'],
        ];
        foreach ($statCards as $card): ?>
        <div class="glass-card stat-card p-5 lg:p-6 flex items-center gap-4 cursor-pointer hover:-translate-y-0.5 transition-transform">
            <div class="p-3 rounded-xl <?php echo $card['bg']; ?> <?php echo $card['color']; ?> shrink-0">
                <?php echo $card['icon']; ?>
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest truncate"><?php echo $card['label']; ?></p>
                <h3 class="text-xl lg:text-2xl font-black text-slate-900 dark:text-white truncate"><?php echo $card['value']; ?></h3>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 lg:gap-8">

        <!-- Left: Charts + Activity -->
        <div class="xl:col-span-2 space-y-6">

            <!-- Revenue Chart -->
            <div class="glass-card p-6 lg:p-8">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white">Revenue Trend</h3>
                        <p class="text-xs text-slate-400 font-medium">Monthly rent collections (last 6 months)</p>
                    </div>
                    <span class="badge badge-primary">Live</span>
                </div>
                <div class="chart-wrap" style="height: 200px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Recent Maintenance -->
                <div class="glass-card p-6">
                    <div class="flex justify-between items-center mb-5">
                        <h3 class="font-black text-slate-900 dark:text-white">Recent Requests</h3>
                        <a href="maintenance.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green transition-colors uppercase tracking-widest">View All →</a>
                    </div>
                    <?php if (empty($recentMaint)): ?>
                    <p class="text-sm text-slate-400 text-center py-6">No maintenance requests yet</p>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($recentMaint as $req): ?>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                            <span class="w-2 h-2 rounded-full shrink-0 <?php echo $req['status']=='Completed' ? 'bg-green-500' : ($req['status']=='In Progress' ? 'bg-blue-500' : 'bg-orange-500'); ?>"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($req['title']); ?></p>
                                <p class="text-[10px] text-slate-400 font-medium"><?php echo $req['status']; ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Transactions -->
                <div class="glass-card p-6">
                    <div class="flex justify-between items-center mb-5">
                        <h3 class="font-black text-slate-900 dark:text-white">Recent Payments</h3>
                        <a href="financials.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green transition-colors uppercase tracking-widest">View All →</a>
                    </div>
                    <?php if (empty($recentTransactions)): ?>
                    <p class="text-sm text-slate-400 text-center py-6">No transactions recorded yet</p>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($recentTransactions as $tx): ?>
                        <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                            <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 shrink-0 font-black text-xs">K</div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($tx['full_name']); ?></p>
                                <p class="text-[10px] text-slate-400 font-medium">KSh <?php echo number_format($tx['amount']); ?></p>
                            </div>
                            <span class="badge badge-<?php echo $tx['status']=='Paid' ? 'green' : ($tx['status']=='Overdue' ? 'red' : 'orange'); ?>">
                                <?php echo $tx['status']; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- Right Column -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <div class="glass-card p-6">
                <h3 class="font-black text-slate-900 dark:text-white mb-4">Quick Actions</h3>
                <div class="space-y-2">
                    <button onclick="openModal('newPropertyModal')" class="w-full flex items-center gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl text-sm font-bold hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors text-left">
                        <span class="w-8 h-8 bg-blue-50 dark:bg-blue-900/30 rounded-lg flex items-center justify-center text-blue-500 shrink-0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 21h18"/><path d="M5 21V7"/><path d="M19 21V7"/></svg></span>
                        Add New Property
                    </button>
                    <a href="tenants.php" class="w-full flex items-center gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl text-sm font-bold hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        <span class="w-8 h-8 bg-green-50 dark:bg-green-900/30 rounded-lg flex items-center justify-center text-green-500 shrink-0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
                        Register Tenant
                    </a>
                    <a href="financials.php" class="w-full flex items-center gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl text-sm font-bold hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        <span class="w-8 h-8 bg-orange-50 dark:bg-orange-900/30 rounded-lg flex items-center justify-center text-orange-500 shrink-0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                        Record Payment
                    </a>
                    <a href="leases.php" class="w-full flex items-center gap-3 p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl text-sm font-bold hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        <span class="w-8 h-8 bg-purple-50 dark:bg-purple-900/30 rounded-lg flex items-center justify-center text-purple-500 shrink-0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                        Create Lease
                    </a>
                </div>
            </div>

            <!-- Promo card -->
            <div class="glass-card p-6 relative overflow-hidden bg-slate-900 dark:bg-slate-800 border-none">
                <div class="relative z-10">
                    <span class="badge badge-secondary mb-3 inline-block">Enterprise</span>
                    <h3 class="text-white font-black text-xl mb-2 leading-tight">Intelligent Payouts Are Live</h3>
                    <p class="text-slate-400 text-xs leading-relaxed mb-4">Automate landlord distributions with precision tracking and instant digital vouchers.</p>
                    <button class="btn-orange text-xs w-full justify-center">Setup Now</button>
                </div>
                <div class="absolute top-[-30%] right-[-10%] w-48 h-48 bg-accent-orange/15 rounded-full blur-3xl"></div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ===== TENANT DASHBOARD ===== -->
    <?php include __DIR__ . '/includes/tenant_dashboard.php'; ?>
    <?php endif; ?>
</div>

<?php if ($role !== 'tenant'): ?>
<!-- Revenue Chart Script -->
<script>
const ctx = document.getElementById('revenueChart');
if (ctx) {
    const isDark = document.documentElement.classList.contains('dark');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Revenue (KSh)',
                data: <?php echo json_encode($chartData); ?>,
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34,197,94,0.08)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#22c55e',
                pointRadius: 5,
                pointHoverRadius: 7,
                borderWidth: 2.5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => 'KSh ' + ctx.raw.toLocaleString()
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
                    ticks: { color: '#94a3b8', font: { size: 11, weight: '700' } }
                },
                y: {
                    grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)' },
                    ticks: { color: '#94a3b8', font: { size: 11, weight: '700' }, callback: v => 'KSh ' + v.toLocaleString() }
                }
            }
        }
    });
}
</script>
<?php endif; ?>

<!-- New Property Modal (shared across all admin pages) -->
<div class="modal-overlay" id="newPropertyModal" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newPropertyModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Add New Property</h2>
        <form action="actions/property_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="create">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Property Title</label><input type="text" name="title" required placeholder="Sapphire Heights" class="form-input"></div>
                <div><label class="form-label">Location</label><input type="text" name="location" required placeholder="Westlands, Nairobi" class="form-input"></div>
            </div>
            <div><label class="form-label">Description</label><textarea name="description" rows="2" class="form-input" style="resize:vertical;"></textarea></div>
            <div class="grid grid-cols-3 gap-4">
                <div><label class="form-label">Price (KSh)</label><input type="number" name="price" required class="form-input"></div>
                <div><label class="form-label">Type</label>
                    <select name="property_type" class="form-input"><option>Apartment</option><option>Villa</option><option>Office</option><option>Commercial</option></select></div>
                <div><label class="form-label">Area (sqft)</label><input type="number" name="area" class="form-input"></div>
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Save Property</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
