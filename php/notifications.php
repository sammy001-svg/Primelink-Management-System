<?php
/**
 * Notifications Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = "Notifications";
$userId = $_SESSION['user_id'];

// Mark all as read when visiting this page
try {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);
} catch (PDOException $e) {
    // Notifications table may not exist yet — catch silently
}

// Fetch notifications
$notifs = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $notifs = $stmt->fetchAll();
} catch (PDOException $e) {
    // silently handle if table doesn't exist
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-3xl mx-auto space-y-6 animate-in">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white">Notifications</h1>
            <p class="text-slate-400 text-sm font-medium">Your system activity and alerts</p>
        </div>
        <span class="badge badge-blue"><?php echo count($notifs); ?> total</span>
    </div>

    <!-- System Alerts as placeholders if no DB notifications -->
    <?php
    $displayItems = $notifs;
    if (empty($displayItems)) {
        // Show sample system events from real DB data
        $displayItems = [];
        $role = $_SESSION['role'] ?? 'tenant';
        if ($role !== 'tenant') {
            $pending = $pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status='Pending'")->fetchColumn();
            if ($pending > 0) {
                $displayItems[] = ['title' => 'Pending Maintenance', 'message' => "$pending requests are pending assignment.", 'type' => 'warning', 'created_at' => date('Y-m-d H:i:s'), 'is_read' => 0];
            }
            $newTenants = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status='Pending'")->fetchColumn();
            if ($newTenants > 0) {
                $displayItems[] = ['title' => 'Tenant Approval', 'message' => "$newTenants tenants are awaiting activation.", 'type' => 'info', 'created_at' => date('Y-m-d H:i:s'), 'is_read' => 0];
            }
        }
        if (empty($displayItems)) {
            $displayItems[] = ['title' => 'Welcome to Primelink!', 'message' => 'Your account is set up and ready. Add your first property to get started.', 'type' => 'success', 'created_at' => date('Y-m-d H:i:s'), 'is_read' => 1];
        }
    }
    ?>

    <?php if (empty($displayItems)): ?>
    <div class="glass-card p-16 text-center">
        <svg class="mx-auto text-slate-200 dark:text-slate-800 mb-4" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <p class="text-slate-400 font-bold">All caught up!</p>
        <p class="text-slate-400 text-sm mt-1">No new notifications at this time.</p>
    </div>
    <?php else: ?>
    <div class="glass-card overflow-hidden divide-y divide-slate-100 dark:divide-slate-800">
        <?php
        $iconMap = [
            'info'    => ['color' => 'text-blue-500',   'bg' => 'bg-blue-50 dark:bg-blue-900/20',   'icon' => '<path d="M12 16v-4"/><path d="M12 8h.01"/>'],
            'success' => ['color' => 'text-green-500',  'bg' => 'bg-green-50 dark:bg-green-900/20', 'icon' => '<polyline points="20 6 9 17 4 12"/>'],
            'warning' => ['color' => 'text-orange-500', 'bg' => 'bg-orange-50 dark:bg-orange-900/20','icon' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>'],
            'alert'   => ['color' => 'text-red-500',    'bg' => 'bg-red-50 dark:bg-red-900/20',     'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>'],
        ];
        foreach ($displayItems as $n):
            $t = $n['type'] ?? 'info';
            $ic = $iconMap[$t] ?? $iconMap['info'];
        ?>
        <div class="flex items-start gap-4 p-5 <?php echo $n['is_read'] ? '' : 'bg-accent-green/5 dark:bg-accent-green/10'; ?> hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
            <div class="w-10 h-10 rounded-xl <?php echo $ic['bg']; ?> <?php echo $ic['color']; ?> flex items-center justify-center shrink-0 mt-0.5">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?php echo $ic['icon']; ?></svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <p class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($n['title']); ?></p>
                    <?php if (!$n['is_read']): ?><span class="w-2 h-2 bg-accent-green rounded-full shrink-0"></span><?php endif; ?>
                </div>
                <p class="text-sm text-slate-500 mt-0.5 leading-relaxed"><?php echo htmlspecialchars($n['message']); ?></p>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wide mt-2"><?php echo date('M j, Y · g:i A', strtotime($n['created_at'])); ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
