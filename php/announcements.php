<?php
/**
 * Tenant Announcements — Admin Broadcast System
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin(['admin', 'staff']);

$pageTitle = "Announcements";

// Self-heal schema
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id           VARCHAR(36)  NOT NULL PRIMARY KEY,
        title        VARCHAR(255) NOT NULL,
        message      TEXT         NOT NULL,
        audience     VARCHAR(20)  NOT NULL DEFAULT 'all',
        property_id  VARCHAR(36)  DEFAULT NULL,
        urgency      VARCHAR(20)  NOT NULL DEFAULT 'Info',
        sent_by      VARCHAR(36)  NOT NULL,
        recipient_count INT       NOT NULL DEFAULT 0,
        created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {}

require_once __DIR__ . '/includes/settings.php';
$user = getCurrentUser($pdo);

// Load properties for audience picker
$properties = $pdo->query("SELECT id, title FROM properties ORDER BY title")->fetchAll();

// Load past announcements with property name + sender name
$announcements = $pdo->query("
    SELECT a.*,
           p.title        AS property_title,
           pr.full_name   AS sender_name
    FROM   announcements a
    LEFT JOIN properties p  ON a.property_id = p.id
    LEFT JOIN profiles   pr ON a.sent_by     = pr.id
    ORDER  BY a.created_at DESC
    LIMIT  50
")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Toast -->
    <?php if (isset($_GET['success'])): ?>
    <div id="toast" class="fixed top-6 right-6 z-50 bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3 font-bold text-sm animate-in">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php
        $count = (int)($_GET['count'] ?? 0);
        echo "Announcement sent to {$count} tenant" . ($count !== 1 ? 's' : '') . ".";
        ?>
    </div>
    <script>setTimeout(() => { const t = document.getElementById('toast'); if(t) t.style.display='none'; }, 4000);</script>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Announcements</h1>
            <p class="text-slate-500 font-medium">Broadcast messages to all tenants or a specific property.</p>
        </div>
        <button onclick="openModal('composeModal')"
                class="btn-primary flex items-center gap-2 self-start sm:self-auto">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Compose Announcement
        </button>
    </div>

    <!-- Stats strip -->
    <?php
    $totalSent   = count($announcements);
    $totalReach  = array_sum(array_column($announcements, 'recipient_count'));
    $urgentCount = count(array_filter($announcements, fn($a) => $a['urgency'] === 'Urgent'));
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Sent</p>
            <h3 class="text-3xl font-black mt-1"><?php echo $totalSent; ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Recipients Reached</p>
            <h3 class="text-3xl font-black mt-1"><?php echo number_format($totalReach); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-red-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Urgent Alerts Sent</p>
            <h3 class="text-3xl font-black mt-1"><?php echo $urgentCount; ?></h3>
        </div>
    </div>

    <!-- Sent Announcements Log -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-black text-lg">Sent Announcements</h3>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Last 50</span>
        </div>

        <?php if (empty($announcements)): ?>
        <div class="p-16 text-center">
            <div class="w-16 h-16 mx-auto bg-slate-100 dark:bg-slate-800 rounded-2xl flex items-center justify-center mb-4">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-slate-400"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.29 6.29l.77-.86a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.92z"/></svg>
            </div>
            <p class="text-slate-400 font-bold">No announcements sent yet.</p>
            <p class="text-slate-300 dark:text-slate-600 text-sm mt-1">Compose your first announcement above.</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($announcements as $ann):
                $urgencyColor = match($ann['urgency']) {
                    'Urgent'    => 'border-red-500 bg-red-500/5',
                    'Important' => 'border-orange-500 bg-orange-500/5',
                    default     => 'border-blue-500 bg-blue-500/5',
                };
                $urgencyBadge = match($ann['urgency']) {
                    'Urgent'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                    'Important' => 'bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400',
                    default     => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
                };
                $audienceLabel = $ann['audience'] === 'property' && $ann['property_title']
                    ? htmlspecialchars($ann['property_title'])
                    : 'All Tenants';
            ?>
            <div class="p-6 border-l-4 <?php echo $urgencyColor; ?> hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2.5 flex-wrap mb-2">
                            <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-lg <?php echo $urgencyBadge; ?>">
                                <?php echo $ann['urgency']; ?>
                            </span>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                <?php echo $audienceLabel; ?>
                            </span>
                        </div>
                        <p class="font-black text-slate-900 dark:text-white text-sm leading-snug">
                            <?php echo htmlspecialchars($ann['title']); ?>
                        </p>
                        <p class="text-sm text-slate-500 mt-1 leading-relaxed line-clamp-2">
                            <?php echo htmlspecialchars($ann['message']); ?>
                        </p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                            <?php echo date('M d, Y H:i', strtotime($ann['created_at'])); ?>
                        </p>
                        <p class="text-[10px] text-slate-400 mt-1">
                            by <?php echo htmlspecialchars($ann['sender_name'] ?? 'System'); ?>
                        </p>
                        <div class="mt-2">
                            <span class="inline-flex items-center gap-1 text-[10px] font-black text-accent-green">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <?php echo $ann['recipient_count']; ?> recipient<?php echo $ann['recipient_count'] !== 1 ? 's' : ''; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Compose Modal -->
<div id="composeModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:640px;">
        <button onclick="closeModal('composeModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-2xl flex items-center justify-center text-blue-500">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.29 6.29l.77-.86a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.92z"/></svg>
            </div>
            <div>
                <h2 class="text-2xl font-black">New Announcement</h2>
                <p class="text-sm text-slate-400">Broadcast to tenants with in-app notification and email</p>
            </div>
        </div>

        <form action="actions/announcement_actions.php" method="POST" class="space-y-5" id="announceForm">
            <input type="hidden" name="action" value="send">

            <!-- Title -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Title</label>
                <input type="text" name="title" required maxlength="255"
                       placeholder="e.g. Scheduled Water Maintenance on Saturday"
                       class="form-input">
            </div>

            <!-- Message -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Message</label>
                <textarea name="message" required rows="5" maxlength="2000"
                          placeholder="Write your message here..."
                          class="form-input resize-none leading-relaxed"></textarea>
            </div>

            <!-- Urgency -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Urgency Level</label>
                <div class="grid grid-cols-3 gap-3">
                    <?php
                    $urgencies = [
                        ['Info',      'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-700 text-blue-700 dark:text-blue-400',    'peer-checked:border-blue-500 peer-checked:bg-blue-50 dark:peer-checked:bg-blue-900/30'],
                        ['Important', 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-700 text-orange-700 dark:text-orange-400', 'peer-checked:border-orange-500 peer-checked:bg-orange-50 dark:peer-checked:bg-orange-900/30'],
                        ['Urgent',    'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700 text-red-700 dark:text-red-400',          'peer-checked:border-red-500 peer-checked:bg-red-50 dark:peer-checked:bg-red-900/30'],
                    ];
                    foreach ($urgencies as [$lvl, $baseClass, $checkedClass]):
                    ?>
                    <label class="relative cursor-pointer">
                        <input type="radio" name="urgency" value="<?php echo $lvl; ?>"
                               class="peer sr-only" <?php echo $lvl === 'Info' ? 'checked' : ''; ?>>
                        <div class="p-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-center transition-all <?php echo $checkedClass; ?> hover:border-slate-400 dark:hover:border-slate-500">
                            <p class="text-[10px] font-black uppercase tracking-widest"><?php echo $lvl; ?></p>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Audience -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Audience</label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="relative cursor-pointer">
                        <input type="radio" name="audience" value="all" class="peer sr-only" checked
                               onchange="document.getElementById('propertyRow').style.display='none'">
                        <div class="p-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-center transition-all peer-checked:border-accent-green peer-checked:bg-green-50 dark:peer-checked:bg-green-900/20 hover:border-slate-400 dark:hover:border-slate-500">
                            <svg class="mx-auto mb-1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <p class="text-[10px] font-black uppercase tracking-widest">All Tenants</p>
                        </div>
                    </label>
                    <label class="relative cursor-pointer">
                        <input type="radio" name="audience" value="property" class="peer sr-only"
                               onchange="document.getElementById('propertyRow').style.display='block'">
                        <div class="p-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-center transition-all peer-checked:border-blue-500 peer-checked:bg-blue-50 dark:peer-checked:bg-blue-900/20 hover:border-slate-400 dark:hover:border-slate-500">
                            <svg class="mx-auto mb-1" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                            <p class="text-[10px] font-black uppercase tracking-widest">By Property</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Property picker (hidden by default) -->
            <div id="propertyRow" class="space-y-2" style="display:none;">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Select Property</label>
                <select name="property_id" class="form-input">
                    <option value="">— Choose a property —</option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['id']; ?>"><?php echo htmlspecialchars($prop['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Preview box -->
            <div class="p-5 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-100 dark:border-slate-800">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Delivery Note</p>
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    This announcement will be sent as an <strong>in-app notification</strong> and an <strong>email</strong> to every qualifying tenant. Make sure your message is clear and professional.
                </p>
            </div>

            <button type="submit" class="btn-primary w-full justify-center py-4 flex items-center gap-2">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send Announcement
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
