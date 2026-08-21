<?php
/**
 * Corrections Register
 * Primelink Management System
 *
 * Every correction ever made to a posted invoice or receipt, with the exact
 * field changes, the stated reason, who made it, and whether the tenant was
 * issued a corrected copy. This is the register an auditor asks for.
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/corrections.php';

ensureCorrectionSchema($pdo);

$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$pageTitle = 'Corrections Register';

$flash    = !empty($_GET['success']) ? urldecode((string)$_GET['success']) : '';
$flashErr = !empty($_GET['error'])   ? urldecode((string)$_GET['error'])   : '';

// ── Filters ───────────────────────────────────────────────────────────
$typeFilter   = in_array($_GET['type'] ?? '', [DOC_INVOICE, DOC_RECEIPT], true) ? $_GET['type'] : '';
$search       = trim($_GET['q'] ?? '');
$notifiedOnly = !empty($_GET['unnotified']);

$where  = [];
$params = [];
if ($typeFilter) {
    $where[]  = 'r.doc_type = ?';
    $params[] = $typeFilter;
}
if ($notifiedOnly) {
    $where[] = 'r.tenant_notified = 0';
}
if ($search) {
    $where[]  = '(r.reason LIKE ? OR r.changed_by_name LIKE ? OR r.doc_id LIKE ? OR t.full_name LIKE ?)';
    $like     = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Tenant name is resolved through whichever document the revision belongs to
$joinSql = "
    LEFT JOIN invoices     i  ON r.doc_type = 'invoice' AND r.doc_id = i.id
    LEFT JOIN transactions tx ON r.doc_type = 'receipt' AND r.doc_id = tx.id
    LEFT JOIN tenants      t  ON t.id = COALESCE(i.tenant_id, tx.tenant_id)
";

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM document_revisions r {$joinSql} {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT r.*,
               t.full_name AS tenant_name,
               COALESCE(i.amount, tx.amount)   AS current_amount,
               i.invoice_type                  AS invoice_type,
               tx.payment_method               AS payment_method
        FROM document_revisions r
        {$joinSql}
        {$whereSql}
        ORDER BY r.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $revisions = $stmt->fetchAll();
} catch (PDOException $e) {
    $totalRows = 0;
    $revisions = [];
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── Headline figures ──────────────────────────────────────────────────
$stats = ['invoice' => 0, 'receipt' => 0, 'unnotified' => 0, 'last30' => 0];
try {
    foreach ($pdo->query("SELECT doc_type, COUNT(*) c FROM document_revisions GROUP BY doc_type") as $row) {
        $stats[$row['doc_type']] = (int)$row['c'];
    }
    $stats['unnotified'] = (int)$pdo->query("SELECT COUNT(*) FROM document_revisions WHERE tenant_notified = 0")->fetchColumn();
    $stats['last30']     = (int)$pdo->query("SELECT COUNT(*) FROM document_revisions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
} catch (PDOException $e) {}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Corrections Register</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">
                Every correction made to a posted invoice or receipt — what changed, why, and whether the tenant was told.
            </p>
        </div>
        <span class="badge badge-primary"><?php echo number_format($totalRows); ?> revisions</span>
    </div>

    <?php if ($flash): ?>
    <div class="glass-card p-4 border-l-4 border-green-500 text-sm font-bold text-green-700 dark:text-green-400"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div class="glass-card p-4 border-l-4 border-red-500 text-sm font-bold text-red-600"><?php echo htmlspecialchars($flashErr); ?></div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Invoices Corrected</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo number_format($stats['invoice']); ?></p>
        </div>
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Receipts Corrected</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo number_format($stats['receipt']); ?></p>
        </div>
        <div class="glass-card p-5 <?php echo $stats['unnotified'] > 0 ? 'bg-red-50 dark:bg-red-900/10' : ''; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant Not Notified</p>
            <p class="text-2xl font-black <?php echo $stats['unnotified'] > 0 ? 'text-red-500' : 'text-slate-300 dark:text-slate-700'; ?> mt-1">
                <?php echo number_format($stats['unnotified']); ?>
            </p>
        </div>
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Last 30 Days</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo number_format($stats['last30']); ?></p>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="glass-card p-5 flex flex-wrap gap-4 items-end">
        <div class="flex-1 min-w-[200px] space-y-1">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Search</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Tenant, reason, staff member, document ID…"
                   class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl text-sm font-bold border-none outline-none focus:ring-2 focus:ring-accent-green/20">
        </div>
        <div class="min-w-[150px] space-y-1">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Document</label>
            <select name="type" class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl text-sm font-bold border-none outline-none focus:ring-2 focus:ring-accent-green/20">
                <option value="">All documents</option>
                <option value="invoice" <?php echo $typeFilter === DOC_INVOICE ? 'selected' : ''; ?>>Invoices</option>
                <option value="receipt" <?php echo $typeFilter === DOC_RECEIPT ? 'selected' : ''; ?>>Receipts</option>
            </select>
        </div>
        <label class="flex items-center gap-2 cursor-pointer select-none pb-2.5">
            <input type="checkbox" name="unnotified" value="1" <?php echo $notifiedOnly ? 'checked' : ''; ?>
                   class="w-4 h-4 rounded border-slate-300 accent-red-500 cursor-pointer">
            <span class="text-xs font-bold text-slate-600 dark:text-slate-300">Tenant not notified</span>
        </label>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary text-xs px-5">Filter</button>
            <?php if ($typeFilter || $search || $notifiedOnly): ?>
            <a href="corrections_register.php" class="btn-ghost text-xs px-5">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Register -->
    <div class="glass-card overflow-hidden">
        <?php if (empty($revisions)): ?>
        <div class="py-20 text-center">
            <p class="text-slate-400 font-medium">No corrections recorded<?php echo ($typeFilter || $search || $notifiedOnly) ? ' for this filter' : ' yet'; ?>.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800">
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">When</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Document</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">What Changed</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest hidden lg:table-cell">By</th>
                        <th class="px-6 py-4 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant Told</th>
                        <th class="px-6 py-4 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Open</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($revisions as $r):
                        $docType  = $r['doc_type'];
                        $rev      = (int)$r['revision_no'];
                        $docNo    = docNumber($docType, $r['doc_id'], $rev);
                        $changes  = json_decode($r['changes_json'] ?? '[]', true) ?: [];
                        $viewPage = $docType === DOC_RECEIPT ? 'view_receipt.php' : 'view_invoice.php';
                        $orphan   = $r['tenant_name'] === null;
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors align-top">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-xs font-bold text-slate-900 dark:text-white"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></p>
                            <p class="text-[10px] text-slate-400 font-medium"><?php echo date('H:i', strtotime($r['created_at'])); ?></p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($docNo); ?></p>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                                <?php echo htmlspecialchars(docTypeLabel($docType)); ?>
                                <?php if ($docType === DOC_INVOICE && $r['invoice_type']): ?>
                                    &middot; <?php echo htmlspecialchars((string)$r['invoice_type']); ?>
                                <?php elseif ($docType === DOC_RECEIPT && $r['payment_method']): ?>
                                    &middot; <?php echo htmlspecialchars((string)$r['payment_method']); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                        <td class="px-6 py-4 text-xs font-bold text-slate-700 dark:text-slate-300 whitespace-nowrap">
                            <?php echo $orphan ? '<span class="text-slate-300 dark:text-slate-700 italic">deleted</span>' : htmlspecialchars((string)$r['tenant_name']); ?>
                        </td>
                        <td class="px-6 py-4 min-w-[260px]">
                            <?php if ($changes): ?>
                            <?php foreach ($changes as $c): ?>
                            <div class="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                                <span class="font-bold"><?php echo htmlspecialchars((string)$c['label']); ?>:</span>
                                <span class="line-through text-slate-400"><?php echo htmlspecialchars((string)$c['from']); ?></span>
                                &rarr; <span class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$c['to']); ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <span class="text-[11px] text-slate-400 italic">Minor correction</span>
                            <?php endif; ?>
                            <?php if (!empty($r['reason'])): ?>
                            <p class="text-[10.5px] text-slate-400 italic mt-1.5">Reason: <?php echo htmlspecialchars((string)$r['reason']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden lg:table-cell whitespace-nowrap">
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars((string)($r['changed_by_name'] ?? 'System')); ?></p>
                            <?php if (!empty($r['ip_address'])): ?>
                            <p class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars((string)$r['ip_address']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center whitespace-nowrap">
                            <?php if ((int)$r['tenant_notified'] === 1): ?>
                            <span class="badge badge-green">Notified</span>
                            <?php if (!empty($r['notified_at'])): ?>
                            <p class="text-[9px] text-slate-400 mt-1"><?php echo date('M d, H:i', strtotime((string)$r['notified_at'])); ?></p>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="badge badge-red">Not sent</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <?php if (!$orphan): ?>
                            <a href="<?php echo $viewPage; ?>?id=<?php echo urlencode($r['doc_id']); ?>" target="_blank"
                               class="inline-flex items-center px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                View
                            </a>
                            <?php if ((int)$r['tenant_notified'] === 0): ?>
                            <form action="actions/invoice_actions.php" method="POST" class="inline"
                                  onsubmit="return confirm('Issue the tenant the corrected copy <?php echo htmlspecialchars($docNo, ENT_QUOTES); ?>?')">
                                <input type="hidden" name="action" value="resend_correction">
                                <input type="hidden" name="doc_type" value="<?php echo htmlspecialchars($docType); ?>">
                                <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($r['doc_id']); ?>">
                                <input type="hidden" name="_redirect" value="../corrections_register.php">
                                <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-red-50 dark:bg-red-900/20 text-red-600 hover:bg-red-600 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                    Issue
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-slate-300 dark:text-slate-700 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between px-6 py-4 border-t border-slate-100 dark:border-slate-800">
            <p class="text-xs font-bold text-slate-400">Page <?php echo $page; ?> of <?php echo $totalPages; ?></p>
            <div class="flex gap-2">
                <?php
                $qs = fn(int $p) => '?' . http_build_query(array_filter([
                    'q' => $search, 'type' => $typeFilter, 'unnotified' => $notifiedOnly ? 1 : '', 'page' => $p,
                ]));
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $qs($page - 1); ?>" class="btn-ghost text-xs px-4">← Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $qs($page + 1); ?>" class="btn-ghost text-xs px-4">Next →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
