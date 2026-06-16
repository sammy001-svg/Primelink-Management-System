<?php
/**
 * Admin Command Center — Primelink Management System
 * Centralised triage: overdue invoices, pending maintenance,
 * expiring leases (≤30d), and vacant units — all actionable in one view.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';

$pageTitle = "Command Center";
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

// ── Toast param ──────────────────────────────────────────────────────
$success = $_GET['success'] ?? '';

// ── 1. Overdue invoices — grouped by tenant ──────────────────────────
$overdueStmt = $pdo->query("
    SELECT t.id as tenant_id, t.full_name, t.email, t.phone,
           u.id as user_id,
           COUNT(i.id)     AS invoice_count,
           SUM(i.amount)   AS total_amount,
           MAX(DATEDIFF(NOW(), i.due_date)) AS max_days_overdue,
           MIN(i.due_date) AS oldest_due
    FROM invoices i
    JOIN tenants t ON i.tenant_id = t.id
    JOIN users u   ON t.user_id = u.id
    WHERE i.status = 'Overdue'
    GROUP BY t.id
    ORDER BY total_amount DESC
");
$overdueRows = $overdueStmt->fetchAll();

// ── 2. Pending / In-Progress maintenance ─────────────────────────────
$maintStmt = $pdo->query("
    SELECT m.id, m.title, m.priority, m.status, m.assigned_staff_id,
           DATEDIFF(NOW(), m.created_at) AS days_open,
           p.title AS property_title,
           u.unit_number,
           t.full_name AS tenant_name
    FROM maintenance_requests m
    LEFT JOIN properties p ON m.property_id = p.id
    LEFT JOIN units u      ON m.unit_id = u.id
    LEFT JOIN tenants t    ON m.tenant_id = t.id
    WHERE m.status IN ('Pending', 'In Progress')
    ORDER BY FIELD(m.priority, 'Critical', 'Urgent', 'High', 'Normal', 'Low'),
             m.created_at ASC
    LIMIT 25
");
$maintRows = $maintStmt->fetchAll();

// ── 3. Expiring leases ≤ 30 days ─────────────────────────────────────
$expiringStmt = $pdo->query("
    SELECT l.id, l.end_date, l.renewal_status,
           DATEDIFF(l.end_date, CURDATE()) AS days_left,
           t.full_name,
           p.title AS property_title,
           un.unit_number
    FROM leases l
    JOIN tenants t    ON l.tenant_id = t.id
    JOIN units un     ON l.unit_id = un.id
    JOIN properties p ON un.property_id = p.id
    WHERE l.status = 'Active'
      AND DATEDIFF(l.end_date, CURDATE()) BETWEEN 0 AND 30
    ORDER BY days_left ASC
");
$expiringRows = $expiringStmt->fetchAll();

// ── 4. Vacant units ──────────────────────────────────────────────────
$vacantStmt = $pdo->query("
    SELECT un.id, un.unit_number, un.monthly_rent,
           p.title AS property_title, p.id AS property_id
    FROM units un
    JOIN properties p ON un.property_id = p.id
    WHERE un.status = 'Available'
    ORDER BY p.title, un.unit_number
    LIMIT 25
");
$vacantRows = $vacantStmt->fetchAll();

// ── 5. Employees list (for maint quick-assign dropdown) ──────────────
$employees = $pdo->query("SELECT id, full_name, role_title FROM employees ORDER BY full_name")->fetchAll();

// ── KPI totals ───────────────────────────────────────────────────────
$kpiOverdueAmt     = array_sum(array_column($overdueRows, 'total_amount'));
$kpiOverdueTenants = count($overdueRows);
$kpiMaint          = count($maintRows);
$kpiExpiring       = count($expiringRows);
$kpiVacant         = count($vacantRows);
$totalAlerts       = $kpiOverdueTenants + $kpiMaint + $kpiExpiring + $kpiVacant;

// ── Priority helpers ─────────────────────────────────────────────────
$priorityBadge = [
    'Critical' => 'badge-red',
    'Urgent'   => 'badge-red',
    'High'     => 'badge-orange',
    'Normal'   => 'badge-blue',
    'Low'      => 'badge',
];
$renewalBadge = [
    'Offered'  => 'badge-blue',
    'Accepted' => 'badge-green',
    'Declined' => 'badge-red',
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Page header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Command Center</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-0.5">
                <?php if ($totalAlerts === 0): ?>
                    All systems clear &mdash; nothing needs attention right now.
                <?php else: ?>
                    <span class="text-red-500 font-black"><?php echo $totalAlerts; ?> item<?php echo $totalAlerts !== 1 ? 's' : ''; ?></span> need your attention.
                <?php endif; ?>
            </p>
        </div>
        <a href="dashboard.php" class="text-[10px] font-black text-slate-400 hover:text-slate-900 dark:hover:text-white uppercase tracking-widest transition-colors">← Dashboard</a>
    </div>

    <?php if ($success === 'reminded'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const t = document.createElement('div');
            t.className = 'fixed bottom-6 right-6 z-50 px-5 py-3 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl font-black text-xs shadow-2xl animate-in slide-in-from-bottom-4';
            t.textContent = 'Reminder sent to tenant.';
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3500);
        });
    </script>
    <?php elseif ($success === 'assigned'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const t = document.createElement('div');
            t.className = 'fixed bottom-6 right-6 z-50 px-5 py-3 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl font-black text-xs shadow-2xl animate-in slide-in-from-bottom-4';
            t.textContent = 'Maintenance request assigned.';
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3500);
        });
    </script>
    <?php endif; ?>

    <!-- KPI Strip -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="glass-card p-5 border-l-4 <?php echo $kpiOverdueTenants > 0 ? 'border-red-500' : 'border-slate-200 dark:border-slate-700'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Overdue Rent</p>
            <p class="text-xl font-black <?php echo $kpiOverdueTenants > 0 ? 'text-red-500' : 'text-slate-400'; ?>">
                <?php echo $kpiOverdueTenants; ?> tenant<?php echo $kpiOverdueTenants !== 1 ? 's' : ''; ?>
            </p>
            <?php if ($kpiOverdueAmt > 0): ?>
            <p class="text-[11px] font-bold text-slate-500 mt-0.5"><?php echo $currency; ?> <?php echo number_format($kpiOverdueAmt); ?></p>
            <?php endif; ?>
        </div>
        <div class="glass-card p-5 border-l-4 <?php echo $kpiMaint > 0 ? 'border-orange-500' : 'border-slate-200 dark:border-slate-700'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Open Maintenance</p>
            <p class="text-xl font-black <?php echo $kpiMaint > 0 ? 'text-orange-500' : 'text-slate-400'; ?>">
                <?php echo $kpiMaint; ?> request<?php echo $kpiMaint !== 1 ? 's' : ''; ?>
            </p>
        </div>
        <div class="glass-card p-5 border-l-4 <?php echo $kpiExpiring > 0 ? 'border-yellow-500' : 'border-slate-200 dark:border-slate-700'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Expiring ≤ 30d</p>
            <p class="text-xl font-black <?php echo $kpiExpiring > 0 ? 'text-yellow-500' : 'text-slate-400'; ?>">
                <?php echo $kpiExpiring; ?> lease<?php echo $kpiExpiring !== 1 ? 's' : ''; ?>
            </p>
        </div>
        <div class="glass-card p-5 border-l-4 <?php echo $kpiVacant > 0 ? 'border-blue-500' : 'border-slate-200 dark:border-slate-700'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Vacant Units</p>
            <p class="text-xl font-black <?php echo $kpiVacant > 0 ? 'text-blue-500' : 'text-slate-400'; ?>">
                <?php echo $kpiVacant; ?> unit<?php echo $kpiVacant !== 1 ? 's' : ''; ?>
            </p>
        </div>
    </div>

    <?php if ($totalAlerts === 0): ?>
    <!-- All-clear state -->
    <div class="glass-card p-16 text-center">
        <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-green-100 dark:bg-green-900/20 flex items-center justify-center">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-accent-green"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-white mb-2">All Clear</h2>
        <p class="text-slate-500 font-medium text-sm">No overdue invoices, no pending maintenance, no leases expiring within 30 days, and no vacant units.</p>
    </div>
    <?php endif; ?>

    <!-- ── Section 1: Overdue Invoices ── -->
    <?php if (!empty($overdueRows)): ?>
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse"></span>
                <h2 class="font-black text-slate-900 dark:text-white">Overdue Invoices</h2>
                <span class="badge badge-red"><?php echo $kpiOverdueTenants; ?></span>
            </div>
            <a href="tenant_payments.php?filter=overdue" class="text-[10px] font-black text-slate-400 hover:text-red-500 uppercase tracking-widest transition-colors">View All →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Tenant</th>
                    <th>Contact</th>
                    <th class="text-center">Invoices</th>
                    <th class="text-right">Amount Due</th>
                    <th class="text-center">Oldest Due</th>
                    <th class="text-right">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($overdueRows as $row):
                    $daysOld = (int)$row['max_days_overdue'];
                    $urgencyClass = $daysOld >= 30 ? 'text-red-500' : 'text-orange-500';
                ?>
                <tr>
                    <td>
                        <p class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($row['full_name']); ?></p>
                        <p class="text-[11px] text-slate-400"><?php echo htmlspecialchars($row['email'] ?? ''); ?></p>
                    </td>
                    <td class="text-slate-500 font-medium text-sm"><?php echo htmlspecialchars($row['phone'] ?? '—'); ?></td>
                    <td class="text-center">
                        <span class="badge badge-red"><?php echo $row['invoice_count']; ?></span>
                    </td>
                    <td class="text-right font-black <?php echo $urgencyClass; ?>">
                        <?php echo $currency; ?> <?php echo number_format($row['total_amount']); ?>
                    </td>
                    <td class="text-center">
                        <span class="text-[11px] font-black <?php echo $daysOld >= 30 ? 'text-red-500' : 'text-orange-500'; ?>">
                            <?php echo $daysOld; ?>d overdue
                        </span>
                    </td>
                    <td class="text-right">
                        <form method="POST" action="actions/financial_actions.php" class="inline">
                            <input type="hidden" name="action" value="send_reminder">
                            <input type="hidden" name="tenant_id" value="<?php echo $row['tenant_id']; ?>">
                            <button type="submit"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 dark:bg-red-900/20 hover:bg-red-500 text-red-600 dark:text-red-400 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.1a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.24h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.1 6.1l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.92 17z"/></svg>
                                Remind
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Section 2: Pending Maintenance ── -->
    <?php if (!empty($maintRows)): ?>
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-orange-500"></span>
                <h2 class="font-black text-slate-900 dark:text-white">Pending Maintenance</h2>
                <span class="badge badge-orange"><?php echo $kpiMaint; ?></span>
            </div>
            <a href="maintenance.php" class="text-[10px] font-black text-slate-400 hover:text-orange-500 uppercase tracking-widest transition-colors">View All →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Request</th>
                    <th>Location</th>
                    <th class="text-center">Priority</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Days Open</th>
                    <th class="text-right">Quick Assign</th>
                </tr></thead>
                <tbody>
                <?php foreach ($maintRows as $m):
                    $badgeClass  = $priorityBadge[$m['priority']] ?? 'badge';
                    $daysOpen    = (int)$m['days_open'];
                    $daysClass   = $daysOpen >= 7 ? 'text-red-500' : ($daysOpen >= 3 ? 'text-orange-500' : 'text-slate-500');
                    $statusBadge = $m['status'] === 'In Progress' ? 'badge-blue' : 'badge-orange';
                ?>
                <tr>
                    <td>
                        <p class="font-black text-slate-900 dark:text-white leading-snug"><?php echo htmlspecialchars($m['title']); ?></p>
                        <?php if ($m['tenant_name']): ?>
                        <p class="text-[11px] text-slate-400"><?php echo htmlspecialchars($m['tenant_name']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-slate-500 font-medium">
                        <?php echo htmlspecialchars($m['property_title'] ?? '—'); ?>
                        <?php if ($m['unit_number']): ?><br><span class="text-[11px]">Unit <?php echo htmlspecialchars($m['unit_number']); ?></span><?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge <?php echo $badgeClass; ?>"><?php echo $m['priority']; ?></span></td>
                    <td class="text-center"><span class="badge <?php echo $statusBadge; ?>"><?php echo $m['status']; ?></span></td>
                    <td class="text-center font-black text-sm <?php echo $daysClass; ?>"><?php echo $daysOpen; ?>d</td>
                    <td class="text-right">
                        <?php if (!empty($employees)): ?>
                        <form method="POST" action="actions/maintenance_actions.php" class="inline-flex items-center gap-2">
                            <input type="hidden" name="action" value="assign_agent">
                            <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                            <input type="hidden" name="redirect" value="../command_center.php">
                            <select name="staff_id" class="px-2 py-1.5 bg-slate-100 dark:bg-slate-800 rounded-lg text-[11px] font-bold border-none outline-none">
                                <option value="">— Select —</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo $m['assigned_staff_id'] === $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit"
                                class="px-3 py-1.5 bg-orange-50 dark:bg-orange-900/20 hover:bg-orange-500 text-orange-600 dark:text-orange-400 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                Assign
                            </button>
                        </form>
                        <?php else: ?>
                        <a href="hr.php" class="text-[10px] font-bold text-slate-400 hover:text-orange-500 transition-colors">Add staff first</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Section 3: Expiring Leases ── -->
    <?php if (!empty($expiringRows)): ?>
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-yellow-500"></span>
                <h2 class="font-black text-slate-900 dark:text-white">Expiring Within 30 Days</h2>
                <span class="badge badge-orange"><?php echo $kpiExpiring; ?></span>
            </div>
            <a href="vacancies.php" class="text-[10px] font-black text-slate-400 hover:text-yellow-500 uppercase tracking-widest transition-colors">Full Forecast →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Tenant</th>
                    <th>Property / Unit</th>
                    <th class="text-center">Expires</th>
                    <th class="text-center">Days Left</th>
                    <th class="text-center">Renewal</th>
                    <th class="text-right">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($expiringRows as $lx):
                    $dl = (int)$lx['days_left'];
                    $urgClass = $dl <= 7 ? 'text-red-500' : ($dl <= 14 ? 'text-orange-500' : 'text-yellow-600 dark:text-yellow-400');
                    $rs = $lx['renewal_status'] ?? null;
                    $rsBadge = $renewalBadge[$rs] ?? null;
                ?>
                <tr>
                    <td class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($lx['full_name']); ?></td>
                    <td class="text-sm text-slate-500 font-medium">
                        <?php echo htmlspecialchars($lx['property_title']); ?>
                        <span class="text-[11px]">/ Unit <?php echo htmlspecialchars($lx['unit_number']); ?></span>
                    </td>
                    <td class="text-center text-sm font-bold text-slate-500"><?php echo date('d M Y', strtotime($lx['end_date'])); ?></td>
                    <td class="text-center">
                        <span class="font-black text-sm <?php echo $urgClass; ?>"><?php echo $dl; ?>d</span>
                    </td>
                    <td class="text-center">
                        <?php if ($rsBadge): ?>
                        <span class="badge <?php echo $rsBadge; ?>"><?php echo $rs; ?></span>
                        <?php else: ?>
                        <span class="text-slate-300 dark:text-slate-700 text-[11px]">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if (!$rs): ?>
                        <form method="POST" action="actions/lease_actions.php" class="inline">
                            <input type="hidden" name="action" value="mark_renewal_status">
                            <input type="hidden" name="lease_id" value="<?php echo $lx['id']; ?>">
                            <input type="hidden" name="renewal_status" value="Offered">
                            <input type="hidden" name="redirect" value="../command_center.php">
                            <button type="submit"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-500 text-blue-600 dark:text-blue-400 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                Mark Offered
                            </button>
                        </form>
                        <?php elseif ($rs === 'Offered'): ?>
                        <a href="vacancies.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-accent-green hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            Manage →
                        </a>
                        <?php elseif ($rs === 'Accepted'): ?>
                        <a href="leases.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-50 dark:bg-green-900/20 hover:bg-accent-green hover:text-white text-accent-green rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            Renew Now
                        </a>
                        <?php else: ?>
                        <a href="vacancies.php" class="text-[10px] font-bold text-slate-400 hover:text-slate-700 transition-colors">Review</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Section 4: Vacant Units ── -->
    <?php if (!empty($vacantRows)): ?>
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                <h2 class="font-black text-slate-900 dark:text-white">Vacant Units</h2>
                <span class="badge badge-blue"><?php echo $kpiVacant; ?></span>
            </div>
            <a href="properties.php" class="text-[10px] font-black text-slate-400 hover:text-blue-500 uppercase tracking-widest transition-colors">All Properties →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Unit</th>
                    <th>Property</th>
                    <th class="text-right">Monthly Rent</th>
                    <th class="text-right">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($vacantRows as $vu): ?>
                <tr>
                    <td class="font-black text-slate-900 dark:text-white">Unit <?php echo htmlspecialchars($vu['unit_number']); ?></td>
                    <td class="text-sm text-slate-500 font-medium"><?php echo htmlspecialchars($vu['property_title']); ?></td>
                    <td class="text-right font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($vu['monthly_rent']); ?></td>
                    <td class="text-right">
                        <a href="property_details.php?id=<?php echo $vu['property_id']; ?>"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-500 text-blue-600 dark:text-blue-400 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            View Property
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /animate-in -->

<?php include __DIR__ . '/includes/footer.php'; ?>
