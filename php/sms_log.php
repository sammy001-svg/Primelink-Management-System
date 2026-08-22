<?php
/**
 * SMS Log
 * Primelink Management System
 *
 * Every message Primelink has pushed through Shanfix Bulk SMS, with its cost,
 * delivery outcome, and the reason it was sent. SMS is billed per unit, so
 * this doubles as the spend record.
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/sms.php';

ensureSmsSchema($pdo);

$pageTitle = 'SMS Log';

// ── Filters ───────────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$status  = in_array($_GET['status'] ?? '', ['Sent', 'Failed'], true) ? $_GET['status'] : '';
$context = trim($_GET['context'] ?? '');

$where  = [];
$params = [];
if ($status) {
    $where[]  = 's.status = ?';
    $params[] = $status;
}
if ($context) {
    $where[]  = 's.context = ?';
    $params[] = $context;
}
if ($search) {
    $where[]  = '(s.phone LIKE ? OR s.message LIKE ? OR t.full_name LIKE ?)';
    $like     = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 30;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$messages   = [];
$totalRows  = 0;
$contexts   = [];
$stats      = ['sent' => 0, 'failed' => 0, 'units' => 0.0, 'month_units' => 0.0];

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sms_log s LEFT JOIN tenants t ON s.tenant_id = t.id {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT s.*, t.full_name AS tenant_name
        FROM sms_log s
        LEFT JOIN tenants t ON s.tenant_id = t.id
        {$whereSql}
        ORDER BY s.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    $contexts = $pdo->query("SELECT DISTINCT context FROM sms_log WHERE context IS NOT NULL AND context <> '' ORDER BY context")
                    ->fetchAll(PDO::FETCH_COLUMN);

    foreach ($pdo->query("SELECT status, COUNT(*) c, COALESCE(SUM(units),0) u FROM sms_log GROUP BY status") as $row) {
        if ($row['status'] === 'Sent')   $stats['sent']   = (int)$row['c'];
        if ($row['status'] === 'Failed') $stats['failed'] = (int)$row['c'];
        $stats['units'] += (float)$row['u'];
    }
    $stats['month_units'] = (float)$pdo->query(
        "SELECT COALESCE(SUM(units),0) FROM sms_log
         WHERE status = 'Sent' AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
    )->fetchColumn();
} catch (PDOException $e) {
    // Table may not exist on a fresh install — the page renders empty
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$smsOn         = smsIsActive($pdo);
$smsConfigured = smsIsConfigured($pdo);

// Friendly names for the internal context tags
$contextLabels = [
    'invoice_issued'      => 'Invoice issued',
    'bundle_issued'       => 'Combined invoice issued',
    'bulk_invoice_issued' => 'Bulk invoice run',
    'document_corrected'  => 'Document corrected',
    'settings_test'       => 'Settings test',
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">SMS Log</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">
                Every message sent through Shanfix Bulk SMS, with its cost and outcome.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <?php if (!$smsConfigured): ?>
            <span class="badge badge-red">Not configured</span>
            <?php elseif (!$smsOn): ?>
            <span class="badge badge-orange">Sending off</span>
            <?php else: ?>
            <span class="badge badge-green">Active</span>
            <?php endif; ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a href="settings.php#sms" class="btn-ghost text-xs px-4">SMS Settings</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Sent</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo number_format($stats['sent']); ?></p>
        </div>
        <div class="glass-card p-5 <?php echo $stats['failed'] > 0 ? 'bg-red-50 dark:bg-red-900/10' : ''; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Failed</p>
            <p class="text-2xl font-black <?php echo $stats['failed'] > 0 ? 'text-red-500' : 'text-slate-300 dark:text-slate-700'; ?> mt-1">
                <?php echo number_format($stats['failed']); ?>
            </p>
        </div>
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Units This Month</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo rtrim(rtrim(number_format($stats['month_units'], 2), '0'), '.') ?: '0'; ?></p>
        </div>
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Units All Time</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo rtrim(rtrim(number_format($stats['units'], 2), '0'), '.') ?: '0'; ?></p>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="glass-card p-5 flex flex-wrap gap-4 items-end">
        <div class="flex-1 min-w-[200px] space-y-1">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Search</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Tenant, phone number, message text…"
                   class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl text-sm font-bold border-none outline-none focus:ring-2 focus:ring-accent-green/20">
        </div>
        <div class="min-w-[140px] space-y-1">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</label>
            <select name="status" class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl text-sm font-bold border-none outline-none focus:ring-2 focus:ring-accent-green/20">
                <option value="">All</option>
                <option value="Sent"   <?php echo $status === 'Sent'   ? 'selected' : ''; ?>>Sent</option>
                <option value="Failed" <?php echo $status === 'Failed' ? 'selected' : ''; ?>>Failed</option>
            </select>
        </div>
        <?php if ($contexts): ?>
        <div class="min-w-[180px] space-y-1">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Sent For</label>
            <select name="context" class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl text-sm font-bold border-none outline-none focus:ring-2 focus:ring-accent-green/20">
                <option value="">All reasons</option>
                <?php foreach ($contexts as $c): ?>
                <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $context === $c ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($contextLabels[$c] ?? $c); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary text-xs px-5">Filter</button>
            <?php if ($search || $status || $context): ?>
            <a href="sms_log.php" class="btn-ghost text-xs px-5">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Log -->
    <div class="glass-card overflow-hidden">
        <?php if (empty($messages)): ?>
        <div class="py-20 text-center">
            <p class="text-slate-400 font-medium">
                <?php if (!$smsConfigured): ?>
                    No messages yet — SMS is not configured.
                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                    <a href="settings.php#sms" class="text-accent-green font-bold hover:underline">Set it up</a>.
                    <?php endif; ?>
                <?php else: ?>
                    No messages found<?php echo ($search || $status || $context) ? ' for this filter' : ' yet'; ?>.
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800">
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">When</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Recipient</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Message</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest hidden lg:table-cell">Sent For</th>
                        <th class="px-6 py-4 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Units</th>
                        <th class="px-6 py-4 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($messages as $m): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors align-top">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-xs font-bold text-slate-900 dark:text-white"><?php echo date('M d, Y', strtotime((string)$m['created_at'])); ?></p>
                            <p class="text-[10px] text-slate-400 font-medium"><?php echo date('H:i', strtotime((string)$m['created_at'])); ?></p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if (!empty($m['tenant_name'])): ?>
                            <p class="text-xs font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$m['tenant_name']); ?></p>
                            <?php endif; ?>
                            <p class="text-[11px] text-slate-400 font-mono"><?php echo htmlspecialchars((string)$m['phone']); ?></p>
                        </td>
                        <td class="px-6 py-4 min-w-[280px] max-w-lg">
                            <p class="text-[11.5px] text-slate-600 dark:text-slate-400 leading-relaxed"><?php echo htmlspecialchars((string)$m['message']); ?></p>
                            <?php if (!empty($m['error'])): ?>
                            <p class="text-[10.5px] text-red-500 font-bold mt-1"><?php echo htmlspecialchars((string)$m['error']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden lg:table-cell whitespace-nowrap text-[11px] text-slate-500">
                            <?php $ctx = (string)($m['context'] ?? ''); ?>
                            <?php echo $ctx ? htmlspecialchars($contextLabels[$ctx] ?? $ctx) : '—'; ?>
                        </td>
                        <td class="px-6 py-4 text-center whitespace-nowrap">
                            <p class="text-xs font-black text-slate-900 dark:text-white">
                                <?php echo $m['units'] !== null ? rtrim(rtrim(number_format((float)$m['units'], 2), '0'), '.') : '—'; ?>
                            </p>
                            <p class="text-[10px] text-slate-400"><?php echo (int)$m['parts']; ?> part<?php echo (int)$m['parts'] !== 1 ? 's' : ''; ?></p>
                        </td>
                        <td class="px-6 py-4 text-center whitespace-nowrap">
                            <span class="badge <?php echo $m['status'] === 'Sent' ? 'badge-green' : 'badge-red'; ?>">
                                <?php echo htmlspecialchars((string)$m['status']); ?>
                            </span>
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
                <?php $qs = fn(int $p) => '?' . http_build_query(array_filter([
                    'q' => $search, 'status' => $status, 'context' => $context, 'page' => $p,
                ])); ?>
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
