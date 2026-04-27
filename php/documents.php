<?php
/**
 * Document Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Documents";

// Fetch documents
$query = "SELECT d.*, t.full_name as tenant_name 
          FROM documents d 
          LEFT JOIN tenants t ON d.tenant_id = t.id";

if ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $tenant = $stmt->fetch();
    $tenantId = $tenant['id'] ?? null;
    $query .= " WHERE d.tenant_id = " . $pdo->quote($tenantId);
}

$query .= " ORDER BY d.created_at DESC";
$documents = $pdo->query($query)->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Vault & Documents</h1>
            <p class="text-slate-500 font-medium">Secure storage for leases, IDs, and financial records.</p>
        </div>
        <button class="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg hover:translate-y-[-2px] transition-all">
            + Upload File
        </button>
    </div>

    <!-- Documents Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php if (empty($documents)): ?>
            <div class="col-span-full py-20 text-center glass-card">
                <p class="text-slate-400 font-medium">No documents found.</p>
            </div>
        <?php else: ?>
            <?php foreach ($documents as $doc): ?>
            <div class="glass-card p-6 flex flex-col justify-between group hover:border-accent-green/30 transition-all">
                <div class="space-y-4">
                    <div class="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 group-hover:text-accent-green transition-colors shadow-inner">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-900 dark:text-white truncate" title="<?php echo htmlspecialchars($doc['title']); ?>"><?php echo htmlspecialchars($doc['title']); ?></h3>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($doc['category']); ?> • <?php echo $doc['file_size']; ?></p>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-between items-center">
                    <span class="text-[10px] font-bold text-slate-400"><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></span>
                    <a href="<?php echo htmlspecialchars($doc['file_url']); ?>" class="p-2 h-8 w-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-slate-900 hover:text-white dark:hover:bg-white dark:hover:text-slate-900 transition-all">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
