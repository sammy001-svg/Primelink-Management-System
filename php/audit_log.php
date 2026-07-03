<?php
/**
 * Audit Log Viewer
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

$pageTitle = "Audit Log";

// Self-heal: create audit_logs if it doesn't exist yet
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id          VARCHAR(36)  NOT NULL PRIMARY KEY,
        user_id     VARCHAR(36)  DEFAULT NULL,
        user_name   VARCHAR(120) DEFAULT NULL,
        action      VARCHAR(100) NOT NULL,
        module      VARCHAR(60)  DEFAULT NULL,
        record_id   VARCHAR(36)  DEFAULT NULL,
        description TEXT         DEFAULT NULL,
        ip_address  VARCHAR(45)  DEFAULT NULL,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {}

$perPage  = 30;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;
$module   = $_GET['module'] ?? '';
$search   = trim($_GET['q'] ?? '');

// Build query
$where  = [];
$params = [];

if ($module) {
    $where[]  = "module = ?";
    $params[] = $module;
}
if ($search) {
    $where[]  = "(user_name LIKE ? OR description LIKE ? OR action LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $whereClause");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("SELECT * FROM audit_logs $whereClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Distinct modules for filter
$modules = $pdo->query("SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

$actionBadge = [
    'payment_recorded'         => 'badge-green',
    'payment_submitted'        => 'badge-blue',
    'invoice_generated'        => 'badge-orange',
    'maintenance_created'      => 'badge-orange',
    'maintenance_status_updated'=> 'badge-blue',
    'maintenance_assigned'     => 'badge-blue',
    'lease_created'            => 'badge-green',
    'lease_renewed'            => 'badge-green',
    'lease_terminated'         => 'badge-red',
    'document_uploaded'        => 'badge-primary',
    'document_deleted'         => 'badge-red',
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Audit Log</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">Complete record of all system actions.</p>
        </div>
        <span class="badge badge-primary"><?php echo number_format($totalRows); ?> events</span>
    </div>

    <!-- Filters -->
    <form method="GET" class="glass-card p-5 flex flex-wrap gap-4 items-end">
        <div class="flex-1 min-w-[180px] space-y-1">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Search</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="User, action, description…"
                   class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl text-sm font-bold border-none outline-none focus:ring-2 focus:ring-accent-green/20">
        </div>
        <div class="min-w-[160px] space-y-1">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Module</label>
            <select name="module" class="w-full px-4 py-2.5 bg-slate-100 dark:bg-slate-800/50 rounded-xl text-sm font-bold border-none outline-none focus:ring-2 focus:ring-accent-green/20">
                <option value="">All modules</option>
                <?php foreach ($modules as $m): ?>
                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $module === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary text-xs px-5">Filter</button>
            <?php if ($module || $search): ?>
            <a href="audit_log.php" class="btn-ghost text-xs px-5">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Table -->
    <div class="glass-card overflow-hidden">
        <?php if (empty($logs)): ?>
        <div class="py-20 text-center">
            <p class="text-slate-400 font-medium">No audit events yet. Actions will appear here once recorded.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800">
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Timestamp</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">User</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Action</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Module</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest hidden xl:table-cell">Description</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest hidden lg:table-cell">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($logs as $log): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-xs font-bold text-slate-900 dark:text-white"><?php echo date('M d, Y', strtotime($log['created_at'])); ?></p>
                            <p class="text-[10px] text-slate-400 font-medium"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-600 font-black text-[10px] shrink-0">
                                    <?php echo strtoupper(substr($log['user_name'] ?? 'S', 0, 1)); ?>
                                </div>
                                <span class="text-xs font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="badge <?php echo $actionBadge[$log['action']] ?? 'badge-primary'; ?> text-[10px]">
                                <?php echo str_replace('_', ' ', $log['action']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-xs font-bold text-slate-500 dark:text-slate-400"><?php echo htmlspecialchars($log['module'] ?? '—'); ?></span>
                        </td>
                        <td class="px-6 py-4 hidden xl:table-cell max-w-xs">
                            <p class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($log['description'] ?? ''); ?></p>
                        </td>
                        <td class="px-6 py-4 hidden lg:table-cell whitespace-nowrap">
                            <span class="text-[10px] font-mono text-slate-400"><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <p class="text-xs text-slate-400 font-medium">
                Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $perPage, $totalRows)); ?> of <?php echo number_format($totalRows); ?>
            </p>
            <div class="flex gap-2">
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <a href="?page=<?php echo $p; ?>&module=<?php echo urlencode($module); ?>&q=<?php echo urlencode($search); ?>"
                   class="w-8 h-8 rounded-lg flex items-center justify-center text-xs font-black transition-colors <?php echo $p === $page ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200'; ?>">
                    <?php echo $p; ?>
                </a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
