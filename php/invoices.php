<?php
/**
 * Invoice Management
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/corrections.php';
require_once __DIR__ . '/includes/tenant_notify.php';
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$invPrefix = getSetting($pdo, 'invoice_prefix', 'INV');
$pageTitle = 'Invoice Management';

// ── Schema self-heal ──────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE invoices MODIFY COLUMN status ENUM('Unpaid','Paid','Partial','Overdue','Cancelled') NOT NULL DEFAULT 'Unpaid'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS description TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS description TEXT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reference_number VARCHAR(255) NULL"); } catch (PDOException $e) {}
ensureCorrectionSchema($pdo);

// ── Invoices with payment totals ──────────────────────────────────────
$invoices = $pdo->query("
    SELECT
        i.id, i.amount, i.due_date, i.status, i.invoice_type, i.created_at, i.lease_id, i.description,
        i.revision_no, i.last_corrected_at, i.last_correction_reason,
        t.id AS tenant_id, t.full_name AS tenant_name, t.phone AS tenant_phone,
        u.unit_number,
        p.title AS property_title, p.id AS property_id,
        COALESCE(pay.total_paid, 0) AS amount_paid,
        GREATEST(0, i.amount - COALESCE(pay.total_paid, 0)) AS balance
    FROM invoices i
    JOIN tenants t ON i.tenant_id = t.id
    LEFT JOIN leases l ON i.lease_id = l.id
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount) AS total_paid
        FROM transactions
        WHERE status = 'Paid'
        GROUP BY invoice_id
    ) pay ON i.id = pay.invoice_id
    WHERE i.status NOT IN ('Cancelled')
    ORDER BY FIELD(i.status,'Overdue','Unpaid','Partial','Paid'), i.due_date ASC
    LIMIT 600
")->fetchAll();

// ── KPIs ─────────────────────────────────────────────────────────────
$totalOutstanding = 0;
$overdueCount     = 0;
$partialCount     = 0;
foreach ($invoices as $inv) {
    if (!in_array($inv['status'], ['Paid', 'Cancelled'])) {
        $totalOutstanding += (float)$inv['balance'];
    }
    if ($inv['status'] === 'Overdue') $overdueCount++;
    if ($inv['status'] === 'Partial') $partialCount++;
}
$collectedMonth = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE status = 'Paid' AND YEAR(transaction_date) = YEAR(CURDATE()) AND MONTH(transaction_date) = MONTH(CURDATE())"
)->fetchColumn();

// ── Properties for filter ─────────────────────────────────────────────
$invProperties = [];
foreach ($invoices as $inv) {
    if (!empty($inv['property_id'])) {
        $invProperties[$inv['property_id']] = $inv['property_title'];
    }
}

// ── JS data keyed by invoice ID ───────────────────────────────────────
$invPanelData = [];
foreach ($invoices as $inv) {
    $invPanelData[$inv['id']] = [
        'id'           => $inv['id'],
        'tenant_id'    => $inv['tenant_id'],
        'tenant_name'  => $inv['tenant_name'],
        'lease_id'     => $inv['lease_id'] ?? '',
        'invoice_type' => $inv['invoice_type'],
        'amount'       => (float)$inv['amount'],
        'amount_paid'  => (float)$inv['amount_paid'],
        'balance'      => (float)$inv['balance'],
        'status'       => $inv['status'],
        'due_date'     => $inv['due_date'],
        'unit'         => $inv['unit_number'] ?? '',
        'property'     => $inv['property_title'] ?? '',
        'description'  => $inv['description'] ?? '',
        'revision_no'  => (int)($inv['revision_no'] ?? 0),
        'doc_no_next'  => docNumber(DOC_INVOICE, $inv['id'], (int)($inv['revision_no'] ?? 0) + 1),
    ];
}

// ── Active leases for Create Invoice modal ────────────────────────────
$activeLeasesRaw = $pdo->query("
    SELECT t.id AS tenant_id, t.full_name,
           l.id AS lease_id, l.monthly_rent,
           u.unit_number, p.title AS property_title, p.id AS property_id
    FROM tenants t
    JOIN leases l ON l.tenant_id = t.id AND l.status = 'Active'
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    ORDER BY t.full_name
")->fetchAll();

$createLeaseJS = [];
foreach ($activeLeasesRaw as $r) {
    $createLeaseJS[$r['tenant_id']] = [
        'lease_id'  => $r['lease_id'],
        'rent'      => (float)$r['monthly_rent'],
        'unit'      => $r['unit_number'],
        'property'  => $r['property_title'],
    ];
}

// ── Flash messages ────────────────────────────────────────────────────
$flash = $flashErr = '';
$successMap = [
    'payment_recorded' => 'Payment recorded and invoice status updated.',
    'marked_paid'      => 'Invoice marked as Paid.',
    'reverted'         => 'Invoice reverted to Unpaid.',
    'marked_overdue'   => 'Invoice marked as Overdue.',
    'invoice_created'  => 'Invoice created successfully.',
    'invoice_edited'   => 'Invoice corrected successfully.',
    'payment_edited'   => 'Payment receipt corrected successfully.',
];
if (!empty($_GET['success'])) {
    $raw   = urldecode((string)$_GET['success']);
    $flash = $successMap[$_GET['success']] ?? (strlen($raw) > 20 ? $raw : 'Action completed.');
}
if (!empty($_GET['info']))    $flash    = urldecode((string)$_GET['info']);
if (!empty($_GET['error']))   $flashErr = htmlspecialchars(urldecode((string)$_GET['error']));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';

// Invoice type → color classes
$typeColors = [
    'Rent'           => 'bg-blue-50 dark:bg-blue-900/20 text-blue-600',
    'Water'          => 'bg-cyan-50 dark:bg-cyan-900/20 text-cyan-600',
    'Garbage'        => 'bg-orange-50 dark:bg-orange-900/20 text-orange-600',
    'Electricity'    => 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-600',
    'Deposit'        => 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600',
    'Service Charge' => 'bg-purple-50 dark:bg-purple-900/20 text-purple-600',
    'Penalty'        => 'bg-red-50 dark:bg-red-900/20 text-red-600',
];
?>

<div class="space-y-8 animate-in">

    <!-- ── Toasts ───────────────────────────────────────────────────── -->
    <?php if ($flash): ?>
    <div id="invToast" class="fixed bottom-6 right-6 z-50 bg-green-500 text-white px-6 py-3.5 rounded-2xl shadow-2xl font-black text-sm flex items-center gap-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($flash); ?>
    </div>
    <script>setTimeout(() => document.getElementById('invToast')?.remove(), 4500);</script>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div id="invErrToast" class="fixed bottom-6 right-6 z-50 bg-red-500 text-white px-6 py-3.5 rounded-2xl shadow-2xl font-black text-sm flex items-center gap-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <?php echo $flashErr; ?>
    </div>
    <script>setTimeout(() => document.getElementById('invErrToast')?.remove(), 5500);</script>
    <?php endif; ?>

    <!-- ── Page Header ──────────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white">Invoice Management</h1>
            <p class="text-slate-400 font-medium text-sm mt-1">Record payments, track balances, and manage all tenant invoices.</p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <button onclick="openModal('createInvoiceModal')" class="btn-green gap-2 text-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Create Invoice
            </button>
            <a href="bulk_invoices.php" class="inline-flex items-center gap-2 px-4 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-xl font-black text-sm text-slate-700 dark:text-slate-300 transition-all">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg>
                Bulk Invoices
            </a>
        </div>
    </div>

    <!-- ── KPI Strip ────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-red-400">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <h3 class="text-xl font-black text-red-500"><?php echo $currency; ?> <?php echo number_format($totalOutstanding); ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Outstanding</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-green-400">
            <div class="w-9 h-9 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h3 class="text-xl font-black text-green-500"><?php echo $currency; ?> <?php echo number_format($collectedMonth); ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Collected <?php echo date('M Y'); ?></p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-red-400">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <h3 class="text-2xl font-black text-red-500"><?php echo $overdueCount; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Overdue Invoices</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-yellow-400">
            <div class="w-9 h-9 rounded-xl bg-yellow-50 dark:bg-yellow-900/30 flex items-center justify-center text-yellow-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
            </div>
            <h3 class="text-2xl font-black text-yellow-500"><?php echo $partialCount; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Partial Payments</p>
        </div>
    </div>

    <!-- ── Invoice Table ─────────────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <!-- Section Header + Filters -->
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <!-- Status tabs -->
                <div class="flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl w-fit shrink-0">
                    <?php
                    $tabs = ['All' => '', 'Unpaid' => 'Unpaid', 'Partial' => 'Partial', 'Overdue' => 'Overdue', 'Paid' => 'Paid'];
                    foreach ($tabs as $label => $val):
                    ?>
                    <button onclick="filterInvStatus('<?php echo $val; ?>')" id="itab_<?php echo strtolower($label); ?>"
                        class="inv-tab px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wide transition-all <?php echo $label === 'All' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">
                        <?php echo $label; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <!-- Filters row -->
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="invSearch" oninput="applyInvFilters()" placeholder="Search tenant, ref…"
                            class="pl-8 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-green/30 w-44 transition">
                    </div>
                    <select id="invTypeFilter" onchange="applyInvFilters()"
                        class="py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-bold text-slate-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-green/30 transition">
                        <option value="">All Types</option>
                        <option value="Rent">Rent</option>
                        <option value="Water">Water</option>
                        <option value="Garbage">Garbage</option>
                        <option value="Electricity">Electricity</option>
                        <option value="Deposit">Deposit</option>
                        <option value="Penalty">Penalty</option>
                        <option value="Service Charge">Service Charge</option>
                    </select>
                    <?php if (count($invProperties) > 1): ?>
                    <select id="invPropFilter" onchange="applyInvFilters()"
                        class="py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-bold text-slate-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-green/30 transition">
                        <option value="">All Properties</option>
                        <?php foreach ($invProperties as $pid => $ptitle): ?>
                        <option value="<?php echo htmlspecialchars($pid); ?>"><?php echo htmlspecialchars($ptitle); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (empty($invoices)): ?>
        <div class="text-center py-16">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-300 mx-auto mb-4">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <p class="text-slate-400 font-bold text-sm">No invoices found.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table" id="invTable">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Tenant</th>
                        <th>Property / Unit</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv):
                        $invRev    = (int)($inv['revision_no'] ?? 0);
                        $balance   = (float)$inv['balance'];
                        $amtPaid   = (float)$inv['amount_paid'];
                        $isPaid    = $inv['status'] === 'Paid';
                        $isOverdue = $inv['status'] === 'Overdue';
                        $isPartial = $inv['status'] === 'Partial';
                        $isPastDue = !$isPaid && strtotime($inv['due_date']) < time();

                        $statusBadge = match($inv['status']) {
                            'Paid'    => 'badge-green',
                            'Overdue' => 'badge-red',
                            'Partial' => 'badge-yellow',
                            default   => 'badge-orange',
                        };
                        $tc = $typeColors[$inv['invoice_type']] ?? 'bg-slate-50 dark:bg-slate-800 text-slate-500';
                        $rowClass = $isOverdue ? 'border-l-[3px] border-red-400' : ($isPartial ? 'border-l-[3px] border-yellow-400' : '');
                    ?>
                    <tr class="inv-row <?php echo $rowClass; ?>"
                        data-status="<?php echo htmlspecialchars($inv['status']); ?>"
                        data-type="<?php echo htmlspecialchars($inv['invoice_type']); ?>"
                        data-propid="<?php echo htmlspecialchars($inv['property_id'] ?? ''); ?>"
                        data-search="<?php echo strtolower(htmlspecialchars($inv['tenant_name'] . ' ' . $inv['unit_number'] . ' ' . $inv['invoice_type'] . ' ' . $invPrefix . '-' . substr($inv['id'], 0, 8))); ?>">
                        <td>
                            <a href="view_invoice.php?id=<?php echo $inv['id']; ?>" target="_blank" class="font-black text-slate-900 dark:text-white hover:text-accent-green transition-colors text-sm">
                                <?php echo htmlspecialchars(docNumber(DOC_INVOICE, $inv['id'], $invRev)); ?>
                            </a>
                            <div class="text-[10px] text-slate-400"><?php echo date('M j, Y', strtotime($inv['created_at'])); ?></div>
                            <?php if ($invRev > 0): ?>
                            <div class="mt-1"><?php echo correctedBadge($invRev); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="tenant_details.php?id=<?php echo $inv['tenant_id']; ?>" class="font-bold text-slate-900 dark:text-white hover:text-accent-green transition-colors">
                                <?php echo htmlspecialchars($inv['tenant_name']); ?>
                            </a>
                            <?php if ($inv['tenant_phone']): ?>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($inv['tenant_phone']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-slate-600 dark:text-slate-300">
                            <?php if ($inv['property_title']): ?>
                            <div class="font-bold"><?php echo htmlspecialchars($inv['property_title']); ?></div>
                            <?php endif; ?>
                            <?php if ($inv['unit_number']): ?>
                            <div class="text-xs text-slate-400">Unit <?php echo htmlspecialchars($inv['unit_number']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase <?php echo $tc; ?>">
                                <?php echo htmlspecialchars($inv['invoice_type']); ?>
                            </span>
                        </td>
                        <td class="font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($inv['amount']); ?></td>
                        <td class="text-sm <?php echo $amtPaid > 0 ? 'text-green-600 dark:text-green-400 font-bold' : 'text-slate-400'; ?>">
                            <?php echo $currency; ?> <?php echo number_format($amtPaid); ?>
                        </td>
                        <td class="font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-slate-400'; ?>">
                            <?php echo $currency; ?> <?php echo number_format($balance); ?>
                        </td>
                        <td>
                            <div class="text-sm font-bold <?php echo ($isPastDue && !$isPaid) ? 'text-red-500' : 'text-slate-600 dark:text-slate-300'; ?>">
                                <?php echo date('M j, Y', strtotime($inv['due_date'])); ?>
                            </div>
                            <?php if ($isPastDue && !$isPaid): ?>
                            <div class="text-[10px] text-red-500 font-black">
                                <?php echo (int)ceil((time() - strtotime($inv['due_date'])) / 86400); ?>d overdue
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $inv['status']; ?></span></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <?php if (!$isPaid): ?>
                                <button onclick="openPaymentModal('<?php echo $inv['id']; ?>')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-accent-green/10 text-accent-green hover:bg-accent-green hover:text-white rounded-lg text-[10px] font-black uppercase transition-all whitespace-nowrap">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M2 12h20"/></svg>
                                    Pay
                                </button>
                                <form action="actions/payment_actions.php" method="POST" class="inline" onsubmit="return confirm('Mark this invoice as fully paid?')">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                    <input type="hidden" name="_redirect" value="../invoices.php">
                                    <button type="submit" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-green-50 dark:hover:bg-green-900/20 text-slate-600 dark:text-slate-300 hover:text-green-600 rounded-lg text-[10px] font-black uppercase transition-all whitespace-nowrap">
                                        Mark Paid
                                    </button>
                                </form>
                                <?php if ($isPastDue && $inv['status'] === 'Unpaid'): ?>
                                <form action="actions/payment_actions.php" method="POST" class="inline">
                                    <input type="hidden" name="action" value="mark_overdue">
                                    <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                    <input type="hidden" name="_redirect" value="../invoices.php">
                                    <button type="submit" class="px-3 py-1.5 bg-red-50 dark:bg-red-900/10 hover:bg-red-100 text-red-500 rounded-lg text-[10px] font-black uppercase transition-all whitespace-nowrap">
                                        Overdue
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?>
                                <a href="view_invoice.php?id=<?php echo $inv['id']; ?>" target="_blank"
                                   class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-[10px] font-black uppercase transition-all">
                                    View
                                </a>
                                <form action="actions/payment_actions.php" method="POST" class="inline">
                                    <input type="hidden" name="action" value="revert_unpaid">
                                    <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                    <input type="hidden" name="_redirect" value="../invoices.php">
                                    <button type="submit" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-orange-50 dark:hover:bg-orange-900/20 text-slate-500 hover:text-orange-500 rounded-lg text-[10px] font-black uppercase transition-all">
                                        Revert
                                    </button>
                                </form>
                                <?php endif; ?>
                                <button onclick="openEditModal('<?php echo $inv['id']; ?>')"
                                    class="px-3 py-1.5 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 text-blue-500 rounded-lg text-[10px] font-black uppercase transition-all whitespace-nowrap">
                                    Correct
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="invEmpty" class="hidden text-center py-10">
            <p class="text-slate-400 font-bold text-sm">No invoices match your current filter.</p>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /space-y-8 -->

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- CREATE INVOICE MODAL                                              -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="createInvoiceModal" style="display:none;" onclick="if(event.target===this)closeModal('createInvoiceModal')">
    <div class="modal-card max-w-lg" onclick="event.stopPropagation()">
        <button onclick="closeModal('createInvoiceModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center text-emerald-500 shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 dark:text-white">Create Invoice</h2>
                <p class="text-slate-400 text-sm font-medium">Issue a new invoice to a tenant.</p>
            </div>
        </div>

        <!-- Tenant info preview -->
        <div id="ci_tenant_info" class="hidden bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-3 mb-5 border border-slate-100 dark:border-slate-700/60">
            <p id="ci_tenant_detail" class="text-xs font-black text-slate-600 dark:text-slate-300"></p>
        </div>

        <form action="actions/payment_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_invoice">
            <input type="hidden" name="lease_id" id="ci_lease_id">
            <input type="hidden" name="_redirect" value="../invoices.php?success=invoice_created">

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Tenant *</label>
                <select name="tenant_id" id="ci_tenant_sel" required onchange="ciPickTenant(this.value)" class="form-input">
                    <option value="">— Select active tenant —</option>
                    <?php foreach ($activeLeasesRaw as $r): ?>
                    <option value="<?php echo $r['tenant_id']; ?>">
                        <?php echo htmlspecialchars($r['full_name']); ?> · Unit <?php echo htmlspecialchars($r['unit_number']); ?> · <?php echo htmlspecialchars($r['property_title']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Invoice Type *</label>
                    <select name="invoice_type" id="ci_type" required onchange="ciTypeChanged(this.value)" class="form-input">
                        <option value="Rent">Rent</option>
                        <option value="Water">Water</option>
                        <option value="Garbage">Garbage</option>
                        <option value="Electricity">Electricity</option>
                        <option value="Deposit">Deposit</option>
                        <option value="Service Charge">Service Charge</option>
                        <option value="Penalty">Penalty</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Amount *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold pointer-events-none"><?php echo htmlspecialchars($currency); ?></span>
                        <input type="number" name="amount" id="ci_amount" required min="1" step="0.01" placeholder="0.00" class="form-input pl-10">
                    </div>
                </div>
            </div>

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Due Date *</label>
                <input type="date" name="due_date" id="ci_due_date" required class="form-input"
                       value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
            </div>

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Notes <span class="normal-case font-normal text-slate-400">(optional)</span></label>
                <input type="text" name="notes" class="form-input" placeholder="Optional invoice note…">
            </div>

            <button type="submit" class="btn-green w-full justify-center py-3.5 mt-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Generate Invoice
            </button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- RECORD PAYMENT MODAL                                              -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="paymentModal" style="display:none;" onclick="if(event.target===this)closeModal('paymentModal')">
    <div class="modal-card max-w-md" onclick="event.stopPropagation()">
        <button onclick="closeModal('paymentModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-accent-green/10 flex items-center justify-center text-accent-green shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 dark:text-white">Record Payment</h2>
                <p class="text-slate-400 text-sm font-medium">Apply a payment to the selected invoice.</p>
            </div>
        </div>

        <!-- Invoice summary -->
        <div class="bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-4 mb-6 border border-slate-100 dark:border-slate-700/60">
            <div class="text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Invoice Details</div>
            <div id="pm_inv_label" class="font-black text-slate-900 dark:text-white text-sm mb-3">—</div>
            <div class="grid grid-cols-3 gap-3 text-center">
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase mb-1">Invoiced</div>
                    <div id="pm_inv_amount" class="font-black text-slate-900 dark:text-white text-sm">—</div>
                </div>
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase mb-1">Paid So Far</div>
                    <div id="pm_inv_paid" class="font-black text-green-500 text-sm">—</div>
                </div>
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase mb-1">Balance Due</div>
                    <div id="pm_inv_balance" class="font-black text-red-500 text-sm">—</div>
                </div>
            </div>
        </div>

        <form action="actions/payment_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="invoice_id" id="pm_invoice_id">
            <input type="hidden" name="tenant_id" id="pm_tenant_id">
            <input type="hidden" name="lease_id" id="pm_lease_id">
            <input type="hidden" name="_redirect" value="../invoices.php">

            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Amount to Pay</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold pointer-events-none"><?php echo htmlspecialchars($currency); ?></span>
                    <input type="number" name="amount" id="pm_amount" required class="form-input pl-12" step="0.01" min="1">
                </div>
            </div>

            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Payment Method</label>
                <select name="payment_method" class="form-input">
                    <option value="Cash">Cash</option>
                    <option value="M-Pesa">M-Pesa</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">
                    Reference / Transaction Code <span class="normal-case font-normal text-slate-400">(optional)</span>
                </label>
                <input type="text" name="reference" id="pm_reference" class="form-input" placeholder="e.g. RCX1234567 or CHQ-001">
            </div>

            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">
                    Notes <span class="normal-case font-normal text-slate-400">(optional)</span>
                </label>
                <input type="text" name="notes" class="form-input" placeholder="Any additional notes…">
            </div>

            <button type="submit"
                class="w-full py-4 bg-accent-green hover:bg-green-600 text-white font-black rounded-xl shadow-xl shadow-green-500/20 transition-all flex items-center justify-center gap-2">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Confirm Payment
            </button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- EDIT INVOICE MODAL                                                -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editInvoiceModal" style="display:none;" onclick="if(event.target===this)closeModal('editInvoiceModal')">
    <div class="modal-card max-w-lg" onclick="event.stopPropagation()">
        <button onclick="closeModal('editInvoiceModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 dark:text-white">Correct Invoice</h2>
                <p id="ei_subtitle" class="text-slate-400 text-sm font-medium">Edit invoice details.</p>
            </div>
        </div>

        <div id="ei_notice" class="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/40 rounded-2xl px-4 py-3 mb-4">
            <p class="text-[11.5px] text-amber-800 dark:text-amber-300 leading-relaxed">
                This invoice has already been issued. Saving creates <strong id="ei_next_rev">a new revision</strong>,
                stamps every copy as <strong>CORRECTED</strong>, and records the change on the audit trail.
            </p>
            <p id="ei_paid_warning" class="hidden text-[11.5px] text-amber-800 dark:text-amber-300 mt-1.5"></p>
        </div>

        <form action="actions/invoice_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit_invoice">
            <input type="hidden" name="invoice_id" id="ei_invoice_id">
            <input type="hidden" name="_redirect" value="../invoices.php">

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Invoice Type</label>
                    <select name="invoice_type" id="ei_type" class="form-input">
                        <option value="Rent">Rent</option>
                        <option value="Water">Water</option>
                        <option value="Garbage">Garbage</option>
                        <option value="Electricity">Electricity</option>
                        <option value="Deposit">Deposit</option>
                        <option value="Service Charge">Service Charge</option>
                        <option value="Penalty">Penalty</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Status</label>
                    <select name="status" id="ei_status" class="form-input">
                        <option value="Unpaid">Unpaid</option>
                        <option value="Paid">Paid</option>
                        <option value="Partial">Partial</option>
                        <option value="Overdue">Overdue</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Amount *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold pointer-events-none"><?php echo htmlspecialchars($currency); ?></span>
                        <input type="number" name="amount" id="ei_amount" required min="1" step="0.01" class="form-input pl-10">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Due Date *</label>
                    <input type="date" name="due_date" id="ei_due_date" required class="form-input">
                </div>
            </div>

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Notes <span class="normal-case font-normal text-slate-400">(optional)</span></label>
                <textarea name="description" id="ei_description" rows="2" class="form-input resize-none" placeholder="Invoice notes or description…"></textarea>
            </div>

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">
                    Reason for Correction * <span class="normal-case font-normal tracking-normal">(audit trail &amp; tenant notice)</span>
                </label>
                <textarea name="correction_reason" id="ei_reason" rows="2" required minlength="5" class="form-input resize-none"
                          placeholder="e.g. Water charge billed at the wrong meter reading."></textarea>
            </div>

<?php
            echo renderNotifyChannels($pdo, [
                'target_label'  => 'The tenant on this invoice',
                'email_checked' => true,
                'sms_checked'   => true,
            ]);
            ?>

            <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-xl shadow-lg shadow-blue-500/20 transition-all flex items-center justify-center gap-2 mt-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Save Correction
            </button>
        </form>
    </div>
</div>

<script>
const invData   = <?php echo json_encode($invPanelData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const invCur    = <?php echo json_encode($currency); ?>;
let currentInvStatus = '';

function openPaymentModal(id) {
    const inv = invData[id];
    if (!inv) return;
    document.getElementById('pm_invoice_id').value = inv.id;
    document.getElementById('pm_tenant_id').value  = inv.tenant_id;
    document.getElementById('pm_lease_id').value   = inv.lease_id;
    document.getElementById('pm_inv_label').textContent   = inv.tenant_name + ' · ' + inv.invoice_type + (inv.unit ? ' · Unit ' + inv.unit : '');
    document.getElementById('pm_inv_amount').textContent  = invCur + ' ' + inv.amount.toLocaleString();
    document.getElementById('pm_inv_paid').textContent    = invCur + ' ' + inv.amount_paid.toLocaleString();
    document.getElementById('pm_inv_balance').textContent = invCur + ' ' + inv.balance.toLocaleString();
    document.getElementById('pm_amount').value = inv.balance > 0 ? inv.balance : inv.amount;
    document.getElementById('pm_reference').value = '';
    openModal('paymentModal');
}

function filterInvStatus(status) {
    currentInvStatus = status;
    applyInvFilters();
    document.querySelectorAll('.inv-tab').forEach(t => {
        t.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
        t.classList.add('text-slate-500');
    });
    const activeId = 'itab_' + (status || 'all').toLowerCase().replace(' ', '');
    const activeEl = document.getElementById(activeId);
    if (activeEl) {
        activeEl.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
        activeEl.classList.remove('text-slate-500');
    }
}

function applyInvFilters() {
    const q    = (document.getElementById('invSearch')?.value || '').toLowerCase().trim();
    const type = document.getElementById('invTypeFilter')?.value || '';
    const prop = document.getElementById('invPropFilter')?.value || '';
    const rows = document.querySelectorAll('.inv-row');
    let visible = 0;
    rows.forEach(row => {
        const match =
            (!currentInvStatus || row.dataset.status === currentInvStatus) &&
            (!q    || (row.dataset.search || '').includes(q)) &&
            (!type || row.dataset.type   === type) &&
            (!prop || row.dataset.propid === prop);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const emptyEl = document.getElementById('invEmpty');
    if (emptyEl) emptyEl.classList.toggle('hidden', visible > 0);
}

function openEditModal(id) {
    const inv = invData[id];
    if (!inv) return;
    document.getElementById('ei_invoice_id').value  = inv.id;
    document.getElementById('ei_subtitle').textContent = inv.tenant_name + ' · ' + inv.invoice_type + (inv.unit ? ' · Unit ' + inv.unit : '');
    document.getElementById('ei_type').value         = inv.invoice_type;
    document.getElementById('ei_status').value       = inv.status;
    document.getElementById('ei_amount').value       = inv.amount;
    document.getElementById('ei_due_date').value     = inv.due_date;
    document.getElementById('ei_description').value  = inv.description || '';
    document.getElementById('ei_reason').value       = '';

    // Revision context — what this correction will produce
    const nextRev = (inv.revision_no || 0) + 1;
    document.getElementById('ei_next_rev').textContent = 'revision ' + nextRev + ' (' + inv.doc_no_next + ')';

    // Already-receipted amounts set the floor for a correction
    const paidWarn = document.getElementById('ei_paid_warning');
    const amountEl = document.getElementById('ei_amount');
    if (inv.amount_paid > 0) {
        paidWarn.textContent = invCur + ' ' + inv.amount_paid.toLocaleString()
            + ' has already been receipted against it — the amount cannot be reduced below this.';
        paidWarn.classList.remove('hidden');
        amountEl.min = inv.amount_paid;
    } else {
        paidWarn.classList.add('hidden');
        amountEl.min = 1;
    }

    openModal('editInvoiceModal');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('paymentModal');
        closeModal('createInvoiceModal');
        closeModal('editInvoiceModal');
    }
});

// ── Create Invoice modal helpers ──────────────────────────────────────
const ciLeaseData = <?php echo json_encode($createLeaseJS, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function ciPickTenant(tenantId) {
    const info = document.getElementById('ci_tenant_info');
    const detail = document.getElementById('ci_tenant_detail');
    const leaseInput = document.getElementById('ci_lease_id');
    if (!tenantId || !ciLeaseData[tenantId]) {
        info.classList.add('hidden');
        leaseInput.value = '';
        return;
    }
    const d = ciLeaseData[tenantId];
    leaseInput.value = d.lease_id;
    detail.textContent = 'Unit ' + d.unit + ' · ' + d.property + ' · Rent: ' + invCur + ' ' + d.rent.toLocaleString();
    info.classList.remove('hidden');
    // Auto-fill amount if Rent type is selected
    if (document.getElementById('ci_type').value === 'Rent') {
        document.getElementById('ci_amount').value = d.rent;
    }
}

function ciTypeChanged(type) {
    const tenantId = document.getElementById('ci_tenant_sel').value;
    if (type === 'Rent' && tenantId && ciLeaseData[tenantId]) {
        document.getElementById('ci_amount').value = ciLeaseData[tenantId].rent;
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
