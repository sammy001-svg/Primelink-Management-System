<?php
/**
 * Tenant Detail Hub — Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);   // FIX: was requireRole(['staff'])

$tenantId = $_GET['id'] ?? null;
if (!$tenantId) { header('Location: tenants.php'); exit(); }

require_once __DIR__ . '/includes/settings.php';
$currency = getSetting($pdo, 'currency_symbol', 'KSh');

// ── Tenant + profile ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.*, u.email AS user_email, p.address AS physical_address
    FROM tenants t
    JOIN users u  ON t.user_id = u.id
    JOIN profiles p ON t.user_id = p.id
    WHERE t.id = ?
");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();
if (!$tenant) { header('Location: tenants.php?toast_msg=Tenant+not+found&toast_type=error'); exit(); }

// ── Active leases (all) ───────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT l.*, u.unit_number, u.unit_type,
           pr.id AS property_id, pr.title AS property_title, pr.location AS property_location
    FROM leases l
    JOIN units u       ON l.unit_id = u.id
    JOIN properties pr ON u.property_id = pr.id
    WHERE l.tenant_id = ? AND l.status = 'Active'
    ORDER BY l.start_date DESC
");
$stmt->execute([$tenantId]);
$activeLeases = $stmt->fetchAll();
$primaryLease = $activeLeases[0] ?? null;
$activeLease  = $primaryLease; // backward compat alias for modals

// ── All leases (history) ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT l.*, u.unit_number, pr.title AS property_title
    FROM leases l
    JOIN units u ON l.unit_id = u.id
    JOIN properties pr ON u.property_id = pr.id
    WHERE l.tenant_id = ?
    ORDER BY l.created_at DESC
");
$stmt->execute([$tenantId]);
$allLeases = $stmt->fetchAll();

// Schema self-heal: ensure invoice columns exist before querying
foreach ([
    "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS batch_id    VARCHAR(36) NULL",
    "ALTER TABLE invoices ADD COLUMN IF NOT EXISTS description TEXT NULL",
] as $_ddl) {
    try { $pdo->exec($_ddl); } catch (PDOException $_e) {}
}

// ── Invoices ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT i.*
    FROM invoices i
    WHERE i.tenant_id = ?
    ORDER BY i.created_at DESC
");
$stmt->execute([$tenantId]);
$invoices = $stmt->fetchAll();

$invoiceSummary = ['total' => 0, 'paid' => 0, 'unpaid' => 0, 'overdue' => 0, 'paid_amount' => 0.0, 'outstanding' => 0.0];
foreach ($invoices as $inv) {
    $invoiceSummary['total']++;
    $invoiceSummary[strtolower($inv['status'])]++;
    if ($inv['status'] === 'Paid')    $invoiceSummary['paid_amount']   += $inv['amount'];
    else                               $invoiceSummary['outstanding']   += $inv['amount'];
}

// ── Transactions ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE tenant_id = ? ORDER BY transaction_date DESC");
$stmt->execute([$tenantId]);
$transactions = $stmt->fetchAll();
$totalPaid    = array_sum(array_column(array_filter($transactions, fn($t) => $t['status'] === 'Paid'), 'amount'));

// ── Maintenance requests ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT m.*, u.unit_number, pr.title AS property_title
    FROM maintenance_requests m
    LEFT JOIN units u       ON m.unit_id = u.id
    LEFT JOIN properties pr ON u.property_id = pr.id
    WHERE m.tenant_id = ?
    ORDER BY m.created_at DESC
");
$stmt->execute([$tenantId]);
$maintenanceRequests = $stmt->fetchAll();

// ── Available units for assign-unit modal ─────────────────────────
$allProperties = $pdo->query("SELECT id, title FROM properties ORDER BY title")->fetchAll();
$availableUnits = $pdo->query("SELECT id, property_id, unit_number, monthly_rent, deposit_amount FROM units WHERE status='Available' ORDER BY unit_number")->fetchAll();

// ── Documents ─────────────────────────────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT 'Other',
    file_url TEXT,
    file_size INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); } catch (PDOException $_e) {}
$stmt = $pdo->prepare("SELECT * FROM documents WHERE tenant_id = ? ORDER BY created_at DESC");
$stmt->execute([$tenantId]);
$documents = $stmt->fetchAll();

// ── 6-month payment trend ─────────────────────────────────────────
$_trendRaw = [];
try {
    $_ts = $pdo->prepare("
        SELECT DATE_FORMAT(transaction_date,'%Y-%m') AS ym, SUM(amount) AS tot
        FROM transactions WHERE tenant_id = ? AND status='Paid'
        AND transaction_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY ym
    ");
    $_ts->execute([$tenantId]);
    foreach ($_ts->fetchAll() as $_tr) $_trendRaw[$_tr['ym']] = (float)$_tr['tot'];
} catch (PDOException $_e) {}
$trendLabels = $trendValues = [];
for ($i = 5; $i >= 0; $i--) {
    $trendLabels[] = date('M', strtotime("-$i months"));
    $trendValues[] = $_trendRaw[date('Y-m', strtotime("-$i months"))] ?? 0;
}

// ── Active tab ────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'leases', 'invoices', 'statement', 'maintenance', 'documents', 'profile', 'security'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'overview';

$isActive = ($tenant['status'] ?? 'Active') === 'Active';
$pageTitle = htmlspecialchars($tenant['full_name']) . ' — Tenant';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-7 animate-in">

    <!-- ── Hero Header ───────────────────────────────────────── -->
    <div class="glass-card p-6 lg:p-8 bg-slate-900 dark:bg-slate-900 text-white border-none relative overflow-hidden">
        <!-- Back -->
        <a href="tenants.php" class="inline-flex items-center gap-1.5 text-[10px] font-black text-slate-400 hover:text-accent-green uppercase tracking-widest mb-5 transition-colors">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m15 18-6-6 6-6"/></svg>
            Back to Registry
        </a>
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
            <div class="flex items-center gap-5">
                <div class="w-16 h-16 lg:w-20 lg:h-20 rounded-2xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-2xl lg:text-3xl font-black shadow-xl ring-4 ring-white/10 shrink-0">
                    <?php echo strtoupper(substr($tenant['full_name'], 0, 1)); ?>
                </div>
                <div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-2xl lg:text-3xl font-black tracking-tight"><?php echo htmlspecialchars($tenant['full_name']); ?></h1>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border
                            <?php echo $isActive
                                ? 'bg-green-500/20 text-green-400 border-green-500/30'
                                : 'bg-slate-700 text-slate-400 border-slate-600'; ?>">
                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                            <?php echo htmlspecialchars($tenant['status'] ?? 'Active'); ?>
                        </span>
                    </div>
                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                        <span class="text-[10px] font-black text-slate-500 uppercase bg-white/5 px-2 py-0.5 rounded">PRM-<?php echo substr($tenant['id'], 0, 6); ?></span>
                        <span class="text-sm text-slate-400"><?php echo htmlspecialchars($tenant['user_email'] ?? $tenant['email']); ?></span>
                        <?php if (!empty($tenant['phone'])): ?>
                        <span class="text-sm text-slate-400"><?php echo htmlspecialchars($tenant['phone']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick stats -->
            <div class="flex gap-6 shrink-0">
                <div class="text-center">
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1">Outstanding</p>
                    <p class="text-xl font-black <?php echo $invoiceSummary['outstanding'] > 0 ? 'text-red-400' : 'text-slate-300'; ?>">
                        <?php echo $currency; ?> <?php echo number_format($invoiceSummary['outstanding']); ?>
                    </p>
                </div>
                <div class="w-px h-12 bg-white/10 self-center"></div>
                <div class="text-center">
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1">Total Paid</p>
                    <p class="text-xl font-black text-green-400"><?php echo $currency; ?> <?php echo number_format($invoiceSummary['paid_amount']); ?></p>
                </div>
                <div class="w-px h-12 bg-white/10 self-center hidden sm:block"></div>
                <div class="text-center hidden sm:block">
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1">Active Units</p>
                    <p class="text-xl font-black <?php echo count($activeLeases) > 0 ? 'text-green-400' : 'text-slate-400'; ?>"><?php echo count($activeLeases); ?></p>
                </div>
            </div>
        </div>
        <!-- Decorative -->
        <div class="absolute -right-16 -top-16 w-64 h-64 bg-accent-green/5 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -left-8 -bottom-8 w-48 h-48 bg-blue-500/5 rounded-full blur-3xl pointer-events-none"></div>
    </div>

    <!-- ── Tabs ──────────────────────────────────────────────── -->
    <div class="flex gap-1 p-1.5 bg-slate-100 dark:bg-slate-900 rounded-2xl w-fit border border-slate-200 dark:border-slate-800 overflow-x-auto">
        <?php
        $tabs = [
            'overview'    => ['Overview',     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
            'leases'      => ['Leases' . (count($activeLeases) > 0 ? ' <span class="ml-1 px-1.5 py-0.5 bg-green-500 text-white rounded text-[8px]">' . count($activeLeases) . '</span>' : ''),
                              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>'],
            'invoices'    => ['Invoices' . ($invoiceSummary['overdue'] > 0 ? ' <span class="ml-1 px-1.5 py-0.5 bg-red-500 text-white rounded text-[8px]">' . $invoiceSummary['overdue'] . '</span>' : ''),
                              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>'],
            'statement'   => ['Statement',    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
            'maintenance' => ['Maintenance' . (!empty($maintenanceRequests) ? ' <span class="ml-1 px-1.5 py-0.5 bg-slate-400 dark:bg-slate-700 text-white dark:text-slate-300 rounded text-[8px]">' . count($maintenanceRequests) . '</span>' : ''),
                              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
            'documents'   => ['Documents' . (count($documents) > 0 ? ' <span class="ml-1 px-1.5 py-0.5 bg-blue-500 text-white rounded text-[8px]">' . count($documents) . '</span>' : ''),
                              '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>'],
            'profile'     => ['Profile',      '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'],
            'security'    => ['Security',     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'],
        ];
        foreach ($tabs as $key => [$label, $icon]):
            $isT = $key === $activeTab;
        ?>
        <button onclick="switchTab('<?php echo $key; ?>')" id="tab-<?php echo $key; ?>"
            class="tab-btn <?php echo $isT ? 'active' : ''; ?> flex items-center gap-2 px-5 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest whitespace-nowrap transition-all
                <?php echo $isT ? 'bg-white dark:bg-slate-800 shadow-md text-accent-green' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">
            <?php echo $icon; ?>
            <?php echo $label; ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: OVERVIEW
    ══════════════════════════════════════════════════════ -->
    <div id="content-overview" class="tab-content <?php echo $activeTab !== 'overview' ? 'hidden' : ''; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Left (2/3): Lease + identity -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Active Residency — multi-lease -->
                <div class="glass-card p-6">
                    <div class="flex items-center justify-between mb-5">
                        <div class="flex items-center gap-2">
                            <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest">Active Leases</h3>
                            <?php if (!empty($activeLeases)): ?>
                            <span class="px-2 py-0.5 rounded-full bg-green-500/10 text-green-500 text-[9px] font-black"><?php echo count($activeLeases); ?></span>
                            <?php endif; ?>
                        </div>
                        <button onclick="openModal('assignUnitModal')" class="btn-green text-xs gap-1.5 py-2 px-4">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Add Unit / Lease
                        </button>
                    </div>
                    <?php if (!empty($activeLeases)): ?>
                    <div class="space-y-4">
                    <?php foreach ($activeLeases as $al):
                        $daysLeft   = !empty($al['end_date']) ? (int)ceil((strtotime($al['end_date']) - time()) / 86400) : null;
                        $nearExpiry = $daysLeft !== null && $daysLeft <= 60;
                    ?>
                    <div class="flex flex-col sm:flex-row gap-4 items-start p-4 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800">
                        <div class="w-full sm:w-28 h-20 rounded-xl bg-slate-100 dark:bg-slate-800 shrink-0 flex items-center justify-center">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" class="text-slate-300 dark:text-slate-600"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-[10px] font-black text-accent-green uppercase tracking-widest">Unit <?php echo htmlspecialchars($al['unit_number']); ?></p>
                                    <h4 class="text-base font-black text-slate-900 dark:text-white mt-0.5"><?php echo htmlspecialchars($al['property_title']); ?></h4>
                                    <?php if (!empty($al['property_location'])): ?>
                                    <p class="text-xs text-slate-400 mt-0.5"><?php echo htmlspecialchars($al['property_location']); ?><?php if (!empty($al['unit_type'])): ?> · <?php echo htmlspecialchars($al['unit_type']); ?><?php endif; ?></p>
                                    <?php endif; ?>
                                </div>
                                <form action="actions/tenant_actions.php" method="POST" class="shrink-0"
                                      onsubmit="return confirm('End lease for Unit <?php echo htmlspecialchars(addslashes($al['unit_number'])); ?>? The unit will be freed.')">
                                    <input type="hidden" name="action" value="end_lease">
                                    <input type="hidden" name="lease_id" value="<?php echo $al['id']; ?>">
                                    <input type="hidden" name="_redirect" value="tenant_details.php?id=<?php echo $tenantId; ?>&tab=overview">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-wide text-red-500 border border-red-200 dark:border-red-900/40 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all">
                                        End Lease
                                    </button>
                                </form>
                            </div>
                            <div class="grid grid-cols-3 gap-2 mt-3">
                                <div class="bg-white dark:bg-slate-800 rounded-lg p-2.5">
                                    <p class="text-[9px] font-black text-slate-400 uppercase mb-1">Monthly Rent</p>
                                    <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format((float)$al['monthly_rent']); ?></p>
                                </div>
                                <div class="bg-white dark:bg-slate-800 rounded-lg p-2.5">
                                    <p class="text-[9px] font-black text-slate-400 uppercase mb-1">Start</p>
                                    <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo date('M d, Y', strtotime($al['start_date'])); ?></p>
                                </div>
                                <div class="bg-white dark:bg-slate-800 rounded-lg p-2.5">
                                    <p class="text-[9px] font-black text-slate-400 uppercase mb-1">Expiry</p>
                                    <p class="text-xs font-black <?php echo $nearExpiry ? 'text-orange-500' : 'text-slate-900 dark:text-white'; ?>">
                                        <?php echo !empty($al['end_date']) ? date('M d, Y', strtotime($al['end_date'])) : 'Open'; ?>
                                    </p>
                                </div>
                            </div>
                            <?php if ($nearExpiry): ?>
                            <p class="text-[10px] font-bold mt-2 <?php echo $daysLeft <= 0 ? 'text-red-500' : 'text-orange-500'; ?>">
                                <?php echo $daysLeft <= 0 ? '⚠ Lease has expired' : "⚠ Expires in {$daysLeft} days"; ?>
                            </p>
                            <?php endif; ?>
                            <div class="flex gap-3 flex-wrap mt-2">
                                <a href="property_details.php?id=<?php echo $al['property_id']; ?>"
                                   class="text-[11px] font-black text-accent-green hover:underline">View Property →</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state py-10">
                        <div class="empty-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg></div>
                        <p class="font-bold text-slate-400 text-sm mt-3">No active unit assigned.</p>
                        <button onclick="openModal('assignUnitModal')" class="btn-green text-xs mt-4 gap-1.5">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Assign First Unit
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Info grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <!-- Identity -->
                    <div class="glass-card p-5">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Identity Verification</h4>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-[11px] font-bold text-slate-500">ID Number</span>
                                <span class="text-[11px] font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenant['id_no'] ?? '—'); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[11px] font-bold text-slate-500">Marital Status</span>
                                <span class="text-[11px] font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenant['marital_status'] ?? '—'); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[11px] font-bold text-slate-500">Children</span>
                                <span class="text-[11px] font-black text-slate-900 dark:text-white"><?php echo ($tenant['has_kids'] ?? 0) ? 'Yes' : 'No'; ?></span>
                            </div>
                            <?php if (!empty($tenant['id_copy_url'])): ?>
                            <a href="/<?php echo htmlspecialchars($tenant['id_copy_url']); ?>" target="_blank"
                               class="text-[11px] font-black text-accent-green hover:underline block pt-1">Download ID Copy →</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Professional -->
                    <div class="glass-card p-5">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Professional Info</h4>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-[11px] font-bold text-slate-500">Profession</span>
                                <span class="text-[11px] font-black text-slate-900 dark:text-white truncate max-w-[140px]"><?php echo htmlspecialchars($tenant['profession'] ?? '—'); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[11px] font-bold text-slate-500">Employer</span>
                                <span class="text-[11px] font-black text-slate-900 dark:text-white truncate max-w-[140px]"><?php echo htmlspecialchars($tenant['employer_name'] ?? '—'); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[11px] font-bold text-slate-500">Type</span>
                                <span class="text-[11px] font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenant['occupation_type'] ?? '—'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lease history moved to Leases tab -->
            </div>

            <!-- Right (1/3): NOK + quick actions -->
            <div class="space-y-5">

                <!-- Next of Kin -->
                <div class="glass-card p-5 bg-slate-900 dark:bg-slate-900 text-white border-none">
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Next of Kin</h3>
                        <button onclick="switchTab('profile')" class="text-[9px] font-black text-accent-green hover:underline uppercase tracking-widest">Edit</button>
                    </div>
                    <?php if (!empty($tenant['next_of_kin_name'])): ?>
                    <div class="space-y-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center text-sm font-black shrink-0">
                                <?php echo strtoupper(substr($tenant['next_of_kin_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <p class="text-sm font-black"><?php echo htmlspecialchars($tenant['next_of_kin_name']); ?></p>
                                <p class="text-[10px] text-accent-green font-black uppercase"><?php echo htmlspecialchars($tenant['next_of_kin_relationship'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        <?php if (!empty($tenant['next_of_kin_contact'])): ?>
                        <a href="tel:<?php echo htmlspecialchars($tenant['next_of_kin_contact']); ?>"
                           class="w-full py-2.5 bg-white/5 border border-white/10 rounded-xl flex items-center justify-center gap-2 text-xs font-black uppercase tracking-widest hover:bg-white/10 transition-all">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.07 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 2.92 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            Emergency Call
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-sm text-slate-500 italic">No next of kin on file.</p>
                    <button onclick="switchTab('profile')" class="btn-green mt-3 text-xs w-full justify-center">Add Next of Kin</button>
                    <?php endif; ?>
                </div>

                <!-- Quick actions -->
                <div class="glass-card p-5">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Quick Actions</h3>
                    <div class="space-y-2">
                        <button onclick="openModal('generateInvoiceModal')"
                            class="w-full flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all text-xs font-black uppercase tracking-widest text-left">
                            <div class="w-8 h-8 rounded-lg bg-green-500/10 text-green-500 flex items-center justify-center shrink-0">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            </div>
                            Generate Invoice
                        </button>
                        <button onclick="switchTab('invoices')"
                            class="w-full flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all text-xs font-black uppercase tracking-widest text-left">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-500 flex items-center justify-center shrink-0">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            </div>
                            Record Payment
                        </button>
                        <button onclick="switchTab('statement')"
                            class="w-full flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all text-xs font-black uppercase tracking-widest text-left">
                            <div class="w-8 h-8 rounded-lg bg-purple-500/10 text-purple-500 flex items-center justify-center shrink-0">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                            </div>
                            View Statement
                        </button>
                        <button onclick="openModal('assignUnitModal')"
                            class="w-full flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all text-xs font-black uppercase tracking-widest text-left">
                            <div class="w-8 h-8 rounded-lg bg-orange-500/10 text-orange-500 flex items-center justify-center shrink-0">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg>
                            </div>
                            Add Unit / Lease
                        </button>
                    </div>
                </div>

                <!-- Spouse info (if married) -->
                <?php if (!empty($tenant['spouse_name'])): ?>
                <div class="glass-card p-5">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Spouse</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between"><span class="text-[11px] text-slate-500">Name</span><span class="text-[11px] font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenant['spouse_name']); ?></span></div>
                        <?php if (!empty($tenant['spouse_phone'])): ?>
                        <div class="flex justify-between"><span class="text-[11px] text-slate-500">Phone</span><span class="text-[11px] font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenant['spouse_phone']); ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: LEASES
    ══════════════════════════════════════════════════════ -->
    <div id="content-leases" class="tab-content <?php echo $activeTab !== 'leases' ? 'hidden' : ''; ?>">
        <?php
        $activeLeasesCount = count($activeLeases);
        $totalLeases       = count($allLeases);
        $totalActiveRent   = array_sum(array_column($activeLeases, 'monthly_rent'));
        $endedCount        = $totalLeases - $activeLeasesCount;
        ?>
        <!-- KPI bar -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="glass-card p-5 bg-green-50 dark:bg-green-900/20">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Active Leases</p>
                <h3 class="text-2xl font-black text-green-500"><?php echo $activeLeasesCount; ?></h3>
            </div>
            <div class="glass-card p-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Leases</p>
                <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $totalLeases; ?></h3>
            </div>
            <div class="glass-card p-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Monthly Rent</p>
                <h3 class="text-xl font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($totalActiveRent); ?></h3>
            </div>
            <div class="glass-card p-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Ended</p>
                <h3 class="text-2xl font-black text-slate-500"><?php echo $endedCount; ?></h3>
            </div>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white">Lease History</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">All <?php echo $totalLeases; ?> lease<?php echo $totalLeases !== 1 ? 's' : ''; ?></p>
                </div>
                <button onclick="openModal('assignUnitModal')" class="btn-green text-xs gap-1.5 py-2 px-4">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    Add Unit / Lease
                </button>
            </div>
            <?php if (empty($allLeases)): ?>
            <div class="empty-state py-16">
                <div class="empty-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg></div>
                <p class="font-bold text-slate-400 text-sm mt-3">No leases on record for this tenant.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left data-table">
                    <thead><tr class="bg-slate-50 dark:bg-slate-800/30">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit &amp; Property</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden sm:table-cell">Period</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Rent / mo</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Deposit</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($allLeases as $lh): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/10 transition-all">
                        <td class="px-5 py-3">
                            <p class="text-sm font-black text-slate-900 dark:text-white">Unit <?php echo htmlspecialchars($lh['unit_number']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($lh['property_title']); ?></p>
                        </td>
                        <td class="px-5 py-3 hidden sm:table-cell text-xs text-slate-500">
                            <?php echo date('M d, Y', strtotime($lh['start_date'])); ?> –
                            <?php echo !empty($lh['end_date']) ? date('M d, Y', strtotime($lh['end_date'])) : 'Open'; ?>
                        </td>
                        <td class="px-5 py-3 font-bold text-slate-700 dark:text-slate-300 text-sm"><?php echo $currency; ?> <?php echo number_format((float)$lh['monthly_rent']); ?></td>
                        <td class="px-5 py-3 text-xs text-slate-500"><?php echo !empty($lh['deposit_amount']) ? $currency . ' ' . number_format((float)$lh['deposit_amount']) : '—'; ?></td>
                        <td class="px-5 py-3 text-center">
                            <span class="px-2 py-1 rounded-full text-[9px] font-black uppercase
                                <?php echo match($lh['status']) {
                                    'Active'             => 'bg-green-500/10 text-green-500',
                                    'Terminated','Ended' => 'bg-red-500/10 text-red-500',
                                    default              => 'bg-slate-100 dark:bg-slate-800 text-slate-500',
                                }; ?>"><?php echo htmlspecialchars($lh['status']); ?></span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <?php if ($lh['status'] === 'Active'): ?>
                            <form action="actions/tenant_actions.php" method="POST" class="inline"
                                  onsubmit="return confirm('End lease for Unit <?php echo htmlspecialchars(addslashes($lh['unit_number'])); ?>?')">
                                <input type="hidden" name="action" value="end_lease">
                                <input type="hidden" name="lease_id" value="<?php echo $lh['id']; ?>">
                                <input type="hidden" name="_redirect" value="tenant_details.php?id=<?php echo $tenantId; ?>&tab=leases">
                                <button type="submit" class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-wide text-red-500 border border-red-200 dark:border-red-900/40 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all">
                                    End Lease
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-[10px] text-slate-300 dark:text-slate-600">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: INVOICES
    ══════════════════════════════════════════════════════ -->
    <div id="content-invoices" class="tab-content <?php echo $activeTab !== 'invoices' ? 'hidden' : ''; ?>">

        <!-- Invoice summary stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <?php
            $invStats = [
                ['label'=>'Total',       'val'=>$invoiceSummary['total'],       'color'=>'text-slate-900 dark:text-white', 'bg'=>'bg-slate-50 dark:bg-slate-800/50'],
                ['label'=>'Paid',        'val'=>$invoiceSummary['paid'],        'color'=>'text-green-500',                 'bg'=>'bg-green-50 dark:bg-green-900/20'],
                ['label'=>'Unpaid',      'val'=>$invoiceSummary['unpaid'],      'color'=>'text-orange-500',                'bg'=>'bg-orange-50 dark:bg-orange-900/20'],
                ['label'=>'Overdue',     'val'=>$invoiceSummary['overdue'],     'color'=>'text-red-500',                   'bg'=>'bg-red-50 dark:bg-red-900/20'],
            ];
            foreach ($invStats as $s): ?>
            <div class="glass-card p-5 <?php echo $s['bg']; ?>">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1"><?php echo $s['label']; ?></p>
                <h3 class="text-2xl font-black <?php echo $s['color']; ?>"><?php echo $s['val']; ?></h3>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Outstanding + Generate Invoice + Record Payment -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="xl:col-span-2 glass-card overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h3 class="font-black text-slate-900 dark:text-white">Invoice Ledger</h3>
                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">All invoices for this tenant</p>
                    </div>
                    <button onclick="openModal('generateInvoiceModal')" class="btn-green text-xs gap-1.5 py-2 px-4">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                        Generate Invoice
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left data-table">
                        <thead><tr class="bg-slate-50 dark:bg-slate-800/30">
                            <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Invoice</th>
                            <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                            <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                            <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Due Date</th>
                            <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                            <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">View</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if (empty($invoices)): ?>
                        <tr><td colspan="6" class="p-10 text-center text-slate-400 italic text-sm">No invoices generated yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/10 transition-all">
                            <td class="px-5 py-3">
                                <p class="text-xs font-black text-slate-900 dark:text-white">INV-<?php echo strtoupper(substr($inv['id'], 0, 8)); ?></p>
                                <p class="text-[10px] text-slate-400"><?php echo date('M d, Y', strtotime($inv['created_at'])); ?></p>
                                <?php if (!empty($inv['batch_id'])): ?>
                                <p class="text-[9px] text-slate-300">Bundle</p>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($inv['invoice_type'] ?? 'Rent'); ?></td>
                            <td class="px-5 py-3 text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format((float)$inv['amount']); ?></td>
                            <td class="px-5 py-3 text-xs text-slate-500"><?php echo !empty($inv['due_date']) ? date('M d, Y', strtotime($inv['due_date'])) : '—'; ?></td>
                            <td class="px-5 py-3 text-center">
                                <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border
                                    <?php echo match($inv['status'] ?? '') {
                                        'Paid'    => 'bg-green-500/10 text-green-500 border-green-500/20',
                                        'Overdue' => 'bg-red-500/10 text-red-500 border-red-500/20',
                                        default   => 'bg-orange-500/10 text-orange-500 border-orange-500/20',
                                    }; ?>">
                                    <?php echo htmlspecialchars($inv['status'] ?? 'Unpaid'); ?>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <?php if (!empty($inv['batch_id'])): ?>
                                <a href="view_combined_invoice.php?batch_id=<?php echo urlencode($inv['batch_id']); ?>&back_tenant=<?php echo urlencode($tenantId); ?>"
                                   target="_blank"
                                   class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 text-[10px] font-black uppercase tracking-wide transition-all">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    Bundle
                                </a>
                                <?php else: ?>
                                <a href="view_invoice.php?id=<?php echo urlencode($inv['id']); ?>&back_tenant=<?php echo urlencode($tenantId); ?>"
                                   target="_blank"
                                   class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 text-[10px] font-black uppercase tracking-wide transition-all">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    View
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Record Payment + STK Push -->
            <div class="space-y-5">
                <div class="glass-card p-5">
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Record Payment</h3>
                    <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="record_payment">
                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</label>
                            <input type="number" name="amount" required placeholder="0.00" step="0.01"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Payment Type</label>
                            <select name="transaction_type"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                                <option value="Rent">Rent</option>
                                <option value="Deposit">Deposit</option>
                                <option value="Water">Water</option>
                                <option value="Service Charge">Service Charge</option>
                                <option value="Penalty">Penalty</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Reference / M-Pesa Code</label>
                            <input type="text" name="reference_code" placeholder="e.g. QHG1234ABC"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</label>
                            <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <button type="submit" class="btn-green w-full justify-center py-3 text-xs">Record Payment</button>
                    </form>
                </div>

                <?php if ($invoiceSummary['outstanding'] > 0): ?>
                <div class="glass-card p-5 bg-slate-900 dark:bg-slate-900 text-white border-none">
                    <p class="text-[10px] font-black text-accent-orange uppercase tracking-widest mb-2">STK Push Request</p>
                    <p class="text-2xl font-black mb-1"><?php echo $currency; ?> <?php echo number_format($invoiceSummary['outstanding']); ?></p>
                    <p class="text-[10px] text-slate-500 mb-4">Outstanding balance on <strong class="text-slate-300"><?php echo htmlspecialchars($tenant['phone'] ?? 'No phone'); ?></strong></p>
                    <button onclick="alert('STK Push sent to <?php echo htmlspecialchars($tenant['phone'] ?? ''); ?>')"
                        class="w-full py-3 bg-accent-orange text-white rounded-xl font-black text-xs uppercase tracking-widest hover:scale-[1.02] transition-all">
                        ⚡ Send STK Push
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: STATEMENT
    ══════════════════════════════════════════════════════ -->
    <div id="content-statement" class="tab-content <?php echo $activeTab !== 'statement' ? 'hidden' : ''; ?>">
        <?php
        $stmtPeriod   = $_GET['stmt_period'] ?? 'all';
        $validPeriods = ['month', '3m', 'ytd', 'all'];
        if (!in_array($stmtPeriod, $validPeriods)) $stmtPeriod = 'all';
        $periodMap  = ['month' => 'This Month', '3m' => 'Last 3 Months', 'ytd' => 'Year to Date', 'all' => 'All Time'];
        $stmtTxArr  = array_values(array_filter($transactions, function($tx) use ($stmtPeriod) {
            $txTime = strtotime($tx['transaction_date'] ?? $tx['created_at'] ?? 'now');
            if ($stmtPeriod === 'month') return $txTime >= strtotime(date('Y-m-01'));
            if ($stmtPeriod === '3m')    return $txTime >= strtotime('-3 months');
            if ($stmtPeriod === 'ytd')   return $txTime >= strtotime(date('Y-01-01'));
            return true;
        }));
        $stmtPaid   = array_sum(array_column(array_filter($stmtTxArr, fn($t) => ($t['status'] ?? '') === 'Paid'), 'amount'));
        $stmtCount  = count($stmtTxArr);
        ?>

        <!-- Period filter -->
        <div class="flex gap-2 flex-wrap mb-6">
        <?php foreach ($periodMap as $pk => $pl): ?>
            <a href="?id=<?php echo urlencode($tenantId); ?>&tab=statement&stmt_period=<?php echo $pk; ?>"
               class="px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all
                   <?php echo $stmtPeriod === $pk ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 shadow' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">
                <?php echo $pl; ?>
            </a>
        <?php endforeach; ?>
        </div>

        <!-- Summary KPIs -->
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
            <div class="glass-card p-5 bg-green-50 dark:bg-green-900/20">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Paid</p>
                <h3 class="text-2xl font-black text-green-500"><?php echo $currency; ?> <?php echo number_format($stmtPaid); ?></h3>
            </div>
            <div class="glass-card p-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Transactions</p>
                <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $stmtCount; ?></h3>
            </div>
            <div class="glass-card p-5 bg-red-50 dark:bg-red-900/20">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Outstanding</p>
                <h3 class="text-2xl font-black text-red-500"><?php echo $currency; ?> <?php echo number_format($invoiceSummary['outstanding']); ?></h3>
            </div>
        </div>

        <!-- Transactions table -->
        <div class="glass-card overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white">Payment Transactions — <?php echo $periodMap[$stmtPeriod]; ?></h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><?php echo $stmtCount; ?> transaction<?php echo $stmtCount !== 1 ? 's' : ''; ?></p>
                </div>
            </div>
            <?php if (empty($stmtTxArr)): ?>
            <div class="empty-state py-16">
                <div class="empty-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <p class="font-bold text-slate-400 text-sm mt-3">No payment transactions for this period.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left data-table">
                    <thead><tr class="bg-slate-50 dark:bg-slate-800/30">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden md:table-cell">Reference</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($stmtTxArr as $tx): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/10 transition-all">
                        <td class="px-5 py-3 text-xs text-slate-600 dark:text-slate-400"><?php echo date('M d, Y', strtotime($tx['transaction_date'] ?? $tx['created_at'] ?? 'now')); ?></td>
                        <td class="px-5 py-3 text-xs font-bold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($tx['transaction_type'] ?? 'Payment'); ?></td>
                        <td class="px-5 py-3 text-xs text-slate-500 font-mono hidden md:table-cell"><?php echo htmlspecialchars($tx['reference_code'] ?? '—'); ?></td>
                        <td class="px-5 py-3 text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format((float)$tx['amount']); ?></td>
                        <td class="px-5 py-3 text-right">
                            <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase border
                                <?php echo ($tx['status'] ?? '') === 'Paid' ? 'bg-green-500/10 text-green-500 border-green-500/20' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 border-slate-200 dark:border-slate-700'; ?>">
                                <?php echo htmlspecialchars($tx['status'] ?? 'Recorded'); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: MAINTENANCE
    ══════════════════════════════════════════════════════ -->
    <div id="content-maintenance" class="tab-content <?php echo $activeTab !== 'maintenance' ? 'hidden' : ''; ?>">
        <div class="glass-card overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white">Maintenance History</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><?php echo count($maintenanceRequests); ?> request<?php echo count($maintenanceRequests) !== 1 ? 's' : ''; ?> total</p>
                </div>
            </div>
            <?php if (empty($maintenanceRequests)): ?>
            <div class="empty-state py-16">
                <div class="empty-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
                <p class="font-bold text-slate-400 text-sm mt-3">No maintenance requests from this tenant.</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left data-table">
                    <thead><tr class="bg-slate-50 dark:bg-slate-800/30">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Issue</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden sm:table-cell">Unit</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden md:table-cell">Priority</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden lg:table-cell">Date</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($maintenanceRequests as $req): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/10 transition-all">
                        <td class="px-5 py-3">
                            <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($req['title'] ?? $req['issue_type'] ?? 'Maintenance'); ?></p>
                            <?php if (!empty($req['description'])): ?>
                            <p class="text-[10px] text-slate-400 mt-0.5 truncate max-w-[240px]"><?php echo htmlspecialchars($req['description']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 hidden sm:table-cell text-xs font-bold text-slate-600 dark:text-slate-400">
                            <?php if (!empty($req['unit_number'])): ?>
                            Unit <?php echo htmlspecialchars($req['unit_number']); ?><br>
                            <span class="text-[10px] text-slate-400"><?php echo htmlspecialchars($req['property_title'] ?? ''); ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="px-5 py-3 hidden md:table-cell">
                            <?php $priority = $req['priority'] ?? 'Normal'; ?>
                            <span class="px-2 py-0.5 rounded text-[9px] font-black uppercase
                                <?php echo match(strtolower($priority)) {
                                    'urgent', 'high' => 'bg-red-50 dark:bg-red-900/20 text-red-500',
                                    'low'            => 'bg-slate-100 dark:bg-slate-800 text-slate-500',
                                    default          => 'bg-orange-50 dark:bg-orange-900/20 text-orange-500',
                                }; ?>"><?php echo htmlspecialchars($priority); ?></span>
                        </td>
                        <td class="px-5 py-3 hidden lg:table-cell text-xs text-slate-500"><?php echo date('M d, Y', strtotime($req['created_at'])); ?></td>
                        <td class="px-5 py-3 text-right">
                            <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase border
                                <?php echo match($req['status'] ?? '') {
                                    'Completed', 'Resolved' => 'bg-green-500/10 text-green-500 border-green-500/20',
                                    'In Progress'           => 'bg-blue-500/10 text-blue-500 border-blue-500/20',
                                    default                 => 'bg-orange-500/10 text-orange-500 border-orange-500/20',
                                }; ?>"><?php echo htmlspecialchars($req['status'] ?? 'Pending'); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: DOCUMENTS
    ══════════════════════════════════════════════════════ -->
    <div id="content-documents" class="tab-content <?php echo $activeTab !== 'documents' ? 'hidden' : ''; ?>">
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

            <!-- Documents list -->
            <div class="xl:col-span-2 glass-card overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="font-black text-slate-900 dark:text-white">Tenant Documents</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><?php echo count($documents); ?> file<?php echo count($documents) !== 1 ? 's' : ''; ?> on record</p>
                </div>
                <?php if (empty($documents)): ?>
                <div class="empty-state py-16">
                    <div class="empty-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                    <p class="font-bold text-slate-400 text-sm mt-3">No documents uploaded yet.</p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php
                $docCatColors = ['Lease' => 'bg-blue-500/10 text-blue-500', 'ID' => 'bg-purple-500/10 text-purple-500', 'Termination' => 'bg-red-500/10 text-red-500', 'Other' => 'bg-slate-100 dark:bg-slate-800 text-slate-500'];
                foreach ($documents as $doc):
                    $catClass = $docCatColors[$doc['category'] ?? 'Other'] ?? $docCatColors['Other'];
                    $sizeLabel = $doc['file_size'] > 0 ? round($doc['file_size'] / 1024, 1) . ' KB' : '';
                ?>
                <div class="px-6 py-4 flex items-center gap-4">
                    <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center shrink-0 text-slate-400">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($doc['title']); ?></p>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase <?php echo $catClass; ?>"><?php echo htmlspecialchars($doc['category'] ?? 'Other'); ?></span>
                            <?php if ($sizeLabel): ?><span class="text-[10px] text-slate-400"><?php echo $sizeLabel; ?></span><?php endif; ?>
                            <span class="text-[10px] text-slate-400"><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($doc['file_url'])): ?>
                    <a href="/<?php echo htmlspecialchars($doc['file_url']); ?>" target="_blank"
                       class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-wide bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-all shrink-0">
                        Download
                    </a>
                    <?php endif; ?>
                    <form action="actions/tenant_detail_actions.php" method="POST" class="shrink-0"
                          onsubmit="return confirm('Delete this document?')">
                        <input type="hidden" name="action" value="delete_document">
                        <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                        <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                        <button type="submit" class="w-7 h-7 rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all flex items-center justify-center">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Upload Document -->
            <div class="glass-card p-5">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4">Upload Document</h3>
                <form action="actions/tenant_detail_actions.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload_document">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Title / Label</label>
                        <input type="text" name="title" required placeholder="e.g. National ID Copy"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Category</label>
                        <select name="category"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                            <option value="Lease">Lease Agreement</option>
                            <option value="ID">ID / Passport</option>
                            <option value="Termination">Termination Notice</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">File</label>
                        <input type="file" name="document_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            class="w-full text-sm text-slate-600 dark:text-slate-400
                                file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0
                                file:text-[11px] file:font-black file:uppercase file:bg-slate-200 dark:file:bg-slate-700
                                file:text-slate-700 dark:file:text-slate-300 file:cursor-pointer hover:file:bg-slate-300 dark:hover:file:bg-slate-600">
                    </div>
                    <button type="submit" class="btn-green w-full justify-center py-3 text-xs">Upload Document</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: PROFILE
    ══════════════════════════════════════════════════════ -->
    <div id="content-profile" class="tab-content <?php echo $activeTab !== 'profile' ? 'hidden' : ''; ?>">
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

            <!-- Personal info form -->
            <div class="glass-card p-6">
                <h3 class="font-black text-slate-900 dark:text-white mb-5">Edit Profile</h3>
                <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($tenant['full_name'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Phone</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($tenant['phone'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5 col-span-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Address</label>
                            <input type="text" name="address" value="<?php echo htmlspecialchars($tenant['physical_address'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Profession</label>
                            <input type="text" name="profession" value="<?php echo htmlspecialchars($tenant['profession'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Employer</label>
                            <input type="text" name="employer_name" value="<?php echo htmlspecialchars($tenant['employer_name'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Marital Status</label>
                            <select name="marital_status"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                                <option value="Single"  <?php echo ($tenant['marital_status'] ?? '') === 'Single'  ? 'selected' : ''; ?>>Single</option>
                                <option value="Married" <?php echo ($tenant['marital_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                                <option value="Divorced"<?php echo ($tenant['marital_status'] ?? '') === 'Divorced'? 'selected' : ''; ?>>Divorced</option>
                                <option value="Widowed" <?php echo ($tenant['marital_status'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">ID Number</label>
                            <input type="text" name="id_no" value="<?php echo htmlspecialchars($tenant['id_no'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                    </div>
                    <button type="submit" class="btn-green px-8 py-3 text-xs">Save Profile Changes</button>
                </form>
            </div>

            <!-- Next of Kin form -->
            <div class="glass-card p-6">
                <h3 class="font-black text-slate-900 dark:text-white mb-5">Next of Kin</h3>
                <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="update_nok">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <div class="grid grid-cols-1 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Full Name</label>
                            <input type="text" name="nok_name" value="<?php echo htmlspecialchars($tenant['next_of_kin_name'] ?? ''); ?>"
                                placeholder="Jane Doe"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Relationship</label>
                                <input type="text" name="nok_relationship" value="<?php echo htmlspecialchars($tenant['next_of_kin_relationship'] ?? ''); ?>"
                                    placeholder="Spouse, Parent…"
                                    class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Phone</label>
                                <input type="text" name="nok_contact" value="<?php echo htmlspecialchars($tenant['next_of_kin_contact'] ?? ''); ?>"
                                    placeholder="+254 7XX…"
                                    class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn-green px-8 py-3 text-xs">Save Next of Kin</button>
                </form>

                <!-- Spouse edit (if married) -->
                <?php if (!empty($tenant['spouse_name']) || ($tenant['marital_status'] ?? '') === 'Married'): ?>
                <hr class="border-slate-100 dark:border-slate-800 my-5">
                <h3 class="font-black text-slate-900 dark:text-white mb-5">Spouse Details</h3>
                <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_spouse">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5 col-span-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Spouse Name</label>
                            <input type="text" name="spouse_name" value="<?php echo htmlspecialchars($tenant['spouse_name'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Spouse Phone</label>
                            <input type="text" name="spouse_phone" value="<?php echo htmlspecialchars($tenant['spouse_phone'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Spouse ID No.</label>
                            <input type="text" name="spouse_id_no" value="<?php echo htmlspecialchars($tenant['spouse_id_no'] ?? ''); ?>"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary text-xs px-8 py-3">Save Spouse Info</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TAB: SECURITY
    ══════════════════════════════════════════════════════ -->
    <div id="content-security" class="tab-content <?php echo $activeTab !== 'security' ? 'hidden' : ''; ?>">
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 max-w-3xl">

            <!-- Password reset -->
            <div class="glass-card p-6">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-red-500 shrink-0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 dark:text-white">Force Password Reset</h3>
                        <p class="text-[10px] text-slate-400 font-medium">Override tenant's portal login credentials</p>
                    </div>
                </div>
                <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">New Password</label>
                        <input type="password" name="new_password" required placeholder="••••••••" minlength="8"
                            class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-red-500/20 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Confirm Password</label>
                        <input type="password" name="confirm_password" required placeholder="••••••••"
                            class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-red-500/20 outline-none">
                    </div>
                    <button type="submit" class="w-full py-3 bg-red-500 hover:bg-red-600 text-white rounded-xl font-black text-xs uppercase tracking-widest transition-all">
                        Reset Access Credentials
                    </button>
                </form>
            </div>

            <!-- Activate / Deactivate -->
            <div class="glass-card p-6">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-9 h-9 rounded-xl <?php echo $isActive ? 'bg-orange-50 dark:bg-orange-900/20 text-orange-500' : 'bg-green-50 dark:bg-green-900/20 text-green-500'; ?> flex items-center justify-center shrink-0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 dark:text-white"><?php echo $isActive ? 'Deactivate Tenant' : 'Reactivate Tenant'; ?></h3>
                        <p class="text-[10px] text-slate-400 font-medium">Current status: <strong><?php echo htmlspecialchars($tenant['status'] ?? 'Active'); ?></strong></p>
                    </div>
                </div>
                <p class="text-sm text-slate-500 mb-5 leading-relaxed">
                    <?php if ($isActive): ?>
                    Deactivating will mark this tenant as Inactive. Their portal access will be blocked. Existing lease and financial records are preserved.
                    <?php else: ?>
                    Reactivating will restore this tenant to Active status and re-enable their portal access.
                    <?php endif; ?>
                </p>
                <form action="actions/tenant_detail_actions.php" method="POST">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                    <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($tenant['status'] ?? 'Active'); ?>">
                    <input type="hidden" name="redirect" value="tenant_details.php?id=<?php echo $tenantId; ?>">
                    <button type="submit" class="w-full py-3 rounded-xl font-black text-xs uppercase tracking-widest transition-all text-white
                        <?php echo $isActive ? 'bg-orange-500 hover:bg-orange-600' : 'bg-green-500 hover:bg-green-600'; ?>">
                        <?php echo $isActive ? 'Deactivate This Tenant' : 'Reactivate This Tenant'; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

</div><!-- end space-y-7 -->

<!-- ── Generate Invoice Modal ────────────────────────────────────── -->
<div id="generateInvoiceModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:520px">
        <button onclick="closeModal('generateInvoiceModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-1">Generate Invoice</h2>
        <p class="text-xs text-slate-400 mb-5">For <strong class="text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($tenant['full_name']); ?></strong></p>

        <!-- Mode toggle -->
        <div class="flex gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl mb-6 w-fit">
            <button id="invModeBtn_single" onclick="switchInvMode('single')"
                class="px-4 py-2 rounded-lg text-[11px] font-black uppercase tracking-wide transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm">
                Single Invoice
            </button>
            <button id="invModeBtn_bundle" onclick="switchInvMode('bundle')"
                class="px-4 py-2 rounded-lg text-[11px] font-black uppercase tracking-wide transition-all text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
                Full Bundle
            </button>
        </div>

        <!-- ─── SINGLE MODE ─── -->
        <div id="invMode_single">
            <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="generate_invoice">
                <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Invoice Type</label>
                    <select name="invoice_type" id="singleInvType" onchange="prefillSingleAmount(this.value)"
                        class="form-input w-full">
                        <option value="Rent">Rent</option>
                        <option value="Water">Water</option>
                        <option value="Electricity">Electricity</option>
                        <option value="Garbage">Garbage</option>
                        <option value="Service Charge">Service Charge</option>
                        <option value="Deposit">Security Deposit</option>
                        <option value="Penalty">Penalty / Late Fee</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" id="singleInvAmount" required placeholder="0.00" min="0" step="0.01"
                        value="<?php echo $primaryLease ? (float)$primaryLease['monthly_rent'] : ''; ?>"
                        class="form-input w-full">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Due Date</label>
                    <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" class="form-input w-full">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Note / Description (optional)</label>
                    <input type="text" name="description" placeholder="e.g. July 2026 Rent" class="form-input w-full">
                </div>
                <button type="submit" class="btn-green w-full justify-center py-3 text-xs">
                    Generate Invoice &amp; Notify Tenant
                </button>
            </form>
        </div>

        <!-- ─── BUNDLE MODE ─── -->
        <div id="invMode_bundle" class="hidden">
            <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="generate_bundle">
                <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Charges to include</p>
                <?php
                $bundleItems = [
                    ['type' => 'Rent',           'label' => 'Monthly Rent',     'default' => $primaryLease ? (float)$primaryLease['monthly_rent'] : '', 'checked' => !!$primaryLease],
                    ['type' => 'Water',           'label' => 'Water',            'default' => '', 'checked' => false],
                    ['type' => 'Electricity',     'label' => 'Electricity',      'default' => '', 'checked' => false],
                    ['type' => 'Garbage',         'label' => 'Garbage / Waste',  'default' => '', 'checked' => false],
                    ['type' => 'Service Charge',  'label' => 'Service Charge',   'default' => '', 'checked' => false],
                    ['type' => 'Deposit',         'label' => 'Security Deposit', 'default' => $primaryLease ? (float)$primaryLease['deposit_amount'] : '', 'checked' => false],
                    ['type' => 'Penalty',         'label' => 'Penalty / Late Fee','default' => '', 'checked' => false],
                ];
                foreach ($bundleItems as $item): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                    <input type="checkbox" name="bundle_type[]" value="<?php echo $item['type']; ?>"
                        id="btype_<?php echo str_replace(' ', '_', $item['type']); ?>"
                        <?php echo $item['checked'] ? 'checked' : ''; ?>
                        class="w-4 h-4 rounded accent-green-500 cursor-pointer">
                    <label for="btype_<?php echo str_replace(' ', '_', $item['type']); ?>"
                        class="flex-1 text-sm font-bold text-slate-700 dark:text-slate-300 cursor-pointer">
                        <?php echo $item['label']; ?>
                    </label>
                    <div class="relative w-32">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-[10px] font-black text-slate-400"><?php echo $currency; ?></span>
                        <input type="number" name="bundle_amount[<?php echo htmlspecialchars($item['type']); ?>]"
                            value="<?php echo htmlspecialchars((string)$item['default']); ?>"
                            min="0" step="0.01" placeholder="0.00"
                            class="w-full pl-8 pr-2 py-2 text-right text-sm font-black bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-accent-green/20 outline-none transition">
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="space-y-1.5 pt-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Due Date</label>
                    <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" class="form-input w-full">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">General Note (optional)</label>
                    <input type="text" name="description" placeholder="e.g. July 2026 charges" class="form-input w-full">
                </div>
                <button type="submit" class="btn-green w-full justify-center py-3 text-xs">
                    Generate All &amp; View Combined Invoice
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ── Assign Unit Modal ──────────────────────────────────────────── -->
<div id="assignUnitModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-2xl">
        <button onclick="closeModal('assignUnitModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all hover:rotate-90 transform">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-1">Add Unit / Lease</h2>
        <p class="text-xs text-slate-400 mb-6">Create a new lease for <strong class="text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($tenant['full_name']); ?></strong><?php if (!empty($activeLeases)): ?> <span class="text-orange-500">(already has <?php echo count($activeLeases); ?> active)</span><?php endif; ?></p>
        <form action="actions/tenant_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="assign_unit">
            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
            <input type="hidden" name="_redirect" value="tenant_details.php?id=<?php echo $tenantId; ?>&tab=leases">
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Property</label>
                    <select name="property_id" required onchange="filterAssignUnits(this.value)"
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        <option value="">Select Property</option>
                        <?php foreach ($allProperties as $p): ?>
                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit</label>
                    <select name="unit_id" id="assign_unit_select" required onchange="fillLeaseTerms(this)"
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        <option value="">Select Unit</option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Rent</label>
                    <input type="number" name="monthly_rent" id="assign_monthly_rent" required
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Security Deposit</label>
                    <input type="number" name="deposit_amount" id="assign_deposit"
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">End Date</label>
                    <input type="date" name="end_date" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal('assignUnitModal')" class="px-6 py-3 text-xs font-black uppercase text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors">Cancel</button>
                <button type="submit" class="btn-green text-xs px-8 py-3">Add Unit &amp; Create Lease →</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── Tab switching ───────────────────────── */
function switchTab(tab) {
    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('active', 'bg-white', 'dark:bg-slate-800', 'shadow-md', 'text-accent-green');
        b.classList.add('text-slate-500');
    });
    document.getElementById('content-' + tab)?.classList.remove('hidden');
    const btn = document.getElementById('tab-' + tab);
    if (btn) {
        btn.classList.add('active', 'bg-white', 'dark:bg-slate-800', 'shadow-md', 'text-accent-green');
        btn.classList.remove('text-slate-500');
    }
    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState({}, '', url);
}

/* ── Assign unit unit-select ─────────────── */
const availableUnits = <?php echo json_encode($availableUnits); ?>;
function filterAssignUnits(propertyId) {
    const sel = document.getElementById('assign_unit_select');
    sel.innerHTML = '<option value="">Select Unit</option>';
    if (!propertyId) return;
    availableUnits.filter(u => u.property_id === propertyId).forEach(u => {
        const o = document.createElement('option');
        o.value = u.id;
        o.textContent = `Unit ${u.unit_number} — <?php echo $currency; ?> ${parseInt(u.monthly_rent).toLocaleString()}/mo`;
        o.dataset.rent = u.monthly_rent;
        o.dataset.deposit = u.deposit_amount;
        sel.appendChild(o);
    });
}
function fillLeaseTerms(sel) {
    const o = sel.options[sel.selectedIndex];
    if (o?.dataset.rent) {
        document.getElementById('assign_monthly_rent').value = o.dataset.rent;
        document.getElementById('assign_deposit').value = o.dataset.deposit || '';
    }
}

// Set initial active tab
switchTab('<?php echo $activeTab; ?>');

/* ── Invoice modal: mode toggle ───────────── */
function switchInvMode(mode) {
    document.getElementById('invMode_single').classList.toggle('hidden', mode !== 'single');
    document.getElementById('invMode_bundle').classList.toggle('hidden', mode !== 'bundle');
    ['single', 'bundle'].forEach(m => {
        const btn = document.getElementById('invModeBtn_' + m);
        if (m === mode) {
            btn.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
            btn.classList.remove('text-slate-500');
        } else {
            btn.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
            btn.classList.add('text-slate-500');
        }
    });
}

/* ── Single invoice: prefill amount by type ─ */
const leaseRent    = <?php echo $primaryLease ? (float)$primaryLease['monthly_rent'] : 0; ?>;
const leaseDeposit = <?php echo $primaryLease ? (float)$primaryLease['deposit_amount'] : 0; ?>;

function prefillSingleAmount(type) {
    const amtInput = document.getElementById('singleInvAmount');
    if (!amtInput) return;
    if (type === 'Rent'    && leaseRent    > 0) amtInput.value = leaseRent;
    else if (type === 'Deposit' && leaseDeposit > 0) amtInput.value = leaseDeposit;
    else if (['Water','Electricity','Garbage','Service Charge','Penalty','Other'].includes(type)) amtInput.value = '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
