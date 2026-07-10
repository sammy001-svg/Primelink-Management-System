<?php
/**
 * Staff Permissions Management — Primelink Management System
 * Admin only.
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/audit.php';

_ensurePermissionsTable($pdo);

$pageTitle = "Staff Permissions";

$toast = $_GET['success'] ?? '';
$error = $_GET['error']   ?? '';

// Fetch all staff users
$staffUsers = $pdo->query("
    SELECT u.id, u.role, u.status, p.full_name, p.job_title, u.email
    FROM users u
    LEFT JOIN profiles p ON p.id = u.id
    WHERE u.role IN ('admin','staff')
    ORDER BY u.role ASC, p.full_name ASC
")->fetchAll();

// Selected user
$selectedUserId = trim($_GET['user_id'] ?? '');
$selectedUser   = null;
$savedPerms     = null;
$hasRecord      = false;

if ($selectedUserId) {
    foreach ($staffUsers as $u) {
        if ($u['id'] === $selectedUserId) { $selectedUser = $u; break; }
    }
    if ($selectedUser) {
        $row = $pdo->prepare("SELECT permissions FROM staff_permissions WHERE user_id = ?");
        $row->execute([$selectedUserId]);
        $row = $row->fetch();
        if ($row) {
            $savedPerms = json_decode($row['permissions'], true) ?? [];
            $hasRecord  = true;
        }
    }
}

function isGranted(?array $perms, string $key): bool {
    if ($perms === null) return false; // no record = nothing pre-ticked in the UI (starts clean)
    return !empty($perms[$key]);
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6 animate-in">

    <?php if ($toast === 'saved'): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-400 rounded-2xl text-sm font-bold animate-in">
        Permissions saved successfully. Changes take effect on the user's next page load.
    </div>
    <?php elseif ($toast === 'reset'): ?>
    <div class="p-4 bg-blue-500/10 border border-blue-500/20 text-blue-500 rounded-2xl text-sm font-bold animate-in">
        Permissions reset. This staff member now has unrestricted access.
    </div>
    <?php elseif ($error): ?>
    <div class="p-4 bg-red-500/10 border border-red-500/20 text-red-500 rounded-2xl text-sm font-bold">
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div>
        <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Staff Permissions</h1>
        <p class="text-slate-500 font-medium text-sm mt-1">Control what each staff member can view, create, edit, or delete.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[280px,1fr] gap-6 items-start">

        <!-- ── Staff list ───────────────────────────────── -->
        <div class="glass-card p-4 space-y-1.5 sticky top-6">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2 mb-3">Select Staff Member</p>
            <?php foreach ($staffUsers as $u):
                $isAdmin    = $u['role'] === 'admin';
                $isSelected = $u['id'] === $selectedUserId;
                $initial    = strtoupper(substr($u['full_name'] ?? $u['email'], 0, 1));
                $hasPerms   = $pdo->prepare("SELECT 1 FROM staff_permissions WHERE user_id = ?");
                $hasPerms->execute([$u['id']]);
                $isRestricted = (bool)$hasPerms->fetchColumn();
            ?>
            <a href="permissions.php?user_id=<?php echo urlencode($u['id']); ?>"
               class="flex items-center gap-3 px-3 py-3 rounded-xl transition-all
                   <?php echo $isSelected
                       ? 'bg-accent-green/10 border border-accent-green/20'
                       : 'hover:bg-slate-50 dark:hover:bg-slate-800/50 border border-transparent'; ?>">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center font-black text-sm shrink-0
                    <?php echo $isAdmin
                        ? 'bg-red-100 dark:bg-red-900/30 text-red-500'
                        : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300'; ?>">
                    <?php echo $initial; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-black text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($u['full_name'] ?? $u['email']); ?></p>
                    <p class="text-[10px] font-bold text-slate-400 truncate">
                        <?php echo $u['job_title'] ? htmlspecialchars($u['job_title']) : ucfirst($u['role']); ?>
                    </p>
                </div>
                <?php if ($isAdmin): ?>
                <span class="shrink-0 text-[9px] font-black uppercase tracking-widest text-red-500 bg-red-50 dark:bg-red-900/20 px-1.5 py-0.5 rounded-lg">Admin</span>
                <?php elseif ($isRestricted): ?>
                <span class="shrink-0 w-2 h-2 rounded-full bg-orange-400 shrink-0" title="Custom permissions set"></span>
                <?php else: ?>
                <span class="shrink-0 w-2 h-2 rounded-full bg-green-400" title="Full access"></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
            <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center gap-3 px-2 text-[10px] text-slate-400 font-medium">
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-green-400 inline-block"></span> Full access</span>
                <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span> Restricted</span>
            </div>
        </div>

        <!-- ── Permissions matrix ────────────────────────── -->
        <?php if (!$selectedUser): ?>
        <div class="glass-card p-16 flex flex-col items-center justify-center text-center">
            <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-400"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <p class="text-lg font-black text-slate-900 dark:text-white">Select a staff member</p>
            <p class="text-slate-400 font-medium text-sm mt-1">Choose a user from the list to manage their permissions.</p>
        </div>

        <?php elseif ($selectedUser['role'] === 'admin'): ?>
        <div class="glass-card p-12 flex flex-col items-center justify-center text-center">
            <div class="text-4xl mb-4">🔑</div>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($selectedUser['full_name'] ?? $selectedUser['email']); ?> is an Admin</p>
            <p class="text-slate-400 font-medium text-sm mt-2">Administrators always have full access to all modules. Permissions cannot be restricted for admin accounts.</p>
            <p class="text-xs text-slate-300 mt-4 font-medium">To limit access, first change this user's role to Staff.</p>
        </div>

        <?php else: ?>
        <div class="space-y-4">
            <!-- User header -->
            <div class="glass-card p-5 flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-slate-100 dark:bg-slate-700 flex items-center justify-center font-black text-lg text-slate-600 dark:text-slate-200">
                        <?php echo strtoupper(substr($selectedUser['full_name'] ?? $selectedUser['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($selectedUser['full_name'] ?? $selectedUser['email']); ?></p>
                        <p class="text-[11px] text-slate-400 font-medium">
                            <?php echo $selectedUser['job_title'] ? htmlspecialchars($selectedUser['job_title']) : 'Staff'; ?>
                            · <?php echo htmlspecialchars($selectedUser['email']); ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($hasRecord): ?>
                    <span class="text-[10px] font-black uppercase tracking-widest text-orange-500 bg-orange-50 dark:bg-orange-900/20 px-2.5 py-1 rounded-xl">Custom permissions</span>
                    <?php else: ?>
                    <span class="text-[10px] font-black uppercase tracking-widest text-green-600 bg-green-50 dark:bg-green-900/20 px-2.5 py-1 rounded-xl">Full access (unrestricted)</span>
                    <?php endif; ?>
                    <?php if ($hasRecord): ?>
                    <form action="actions/permission_actions.php" method="POST" onsubmit="return confirm('Reset to full unrestricted access?')">
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="target_user_id" value="<?php echo $selectedUserId; ?>">
                        <input type="hidden" name="_redirect" value="../permissions.php">
                        <button type="submit" class="text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-red-500 transition-colors px-3 py-2 rounded-xl hover:bg-red-50 dark:hover:bg-red-900/10">
                            Reset to Full Access
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick tools -->
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Quick set:</span>
                <button onclick="grantAll()" class="px-3 py-1.5 bg-green-50 dark:bg-green-900/20 text-green-600 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-green-100 transition-all">✓ Grant All</button>
                <button onclick="revokeAll()" class="px-3 py-1.5 bg-red-50 dark:bg-red-900/20 text-red-500 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-red-100 transition-all">✕ Revoke All</button>
                <button onclick="viewOnly()" class="px-3 py-1.5 bg-blue-50 dark:bg-blue-900/20 text-blue-500 rounded-xl text-[11px] font-black uppercase tracking-widest hover:bg-blue-100 transition-all">👁 View Only</button>
            </div>

            <!-- Matrix form -->
            <form action="actions/permission_actions.php" method="POST" id="permForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="target_user_id" value="<?php echo $selectedUserId; ?>">
                <input type="hidden" name="_redirect" value="../permissions.php">

                <div class="glass-card overflow-hidden">
                    <!-- Column headers -->
                    <div class="grid perm-grid bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                        <div class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Module</div>
                        <?php foreach (['View', 'Create', 'Edit', 'Delete'] as $col): ?>
                        <div class="px-3 py-3 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo $col; ?></div>
                        <?php endforeach; ?>
                        <div class="px-3 py-3 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">All</div>
                    </div>

                    <?php foreach (PERM_GROUPS as $groupName => $modules): ?>
                    <!-- Group header -->
                    <div class="px-5 py-2.5 bg-slate-50/50 dark:bg-slate-800/20 border-b border-t border-slate-100 dark:border-slate-800">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest"><?php echo $groupName; ?></p>
                    </div>

                    <?php foreach ($modules as $mod):
                        $modActions = PERM_MODULES[$mod] ?? [];
                        $modLabel   = PERM_MODULE_LABELS[$mod] ?? $mod;
                        $allActions = ['view', 'create', 'edit', 'delete'];
                    ?>
                    <div class="grid perm-grid border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50/30 dark:hover:bg-slate-800/10 transition-colors" data-module="<?php echo $mod; ?>">
                        <div class="px-5 py-3.5">
                            <p class="text-sm font-bold text-slate-800 dark:text-slate-200"><?php echo $modLabel; ?></p>
                        </div>
                        <?php foreach ($allActions as $act):
                            $key       = "{$mod}.{$act}";
                            $available = in_array($act, $modActions);
                            $checked   = $available && isGranted($savedPerms, $key);
                        ?>
                        <div class="flex items-center justify-center py-3.5">
                            <?php if ($available): ?>
                            <label class="relative cursor-pointer group">
                                <input type="checkbox"
                                       name="perms[]"
                                       value="<?php echo $key; ?>"
                                       <?php echo $checked ? 'checked' : ''; ?>
                                       data-mod="<?php echo $mod; ?>"
                                       data-act="<?php echo $act; ?>"
                                       class="perm-cb sr-only">
                                <span class="w-5 h-5 rounded-lg border-2 flex items-center justify-center transition-all
                                    <?php echo $checked
                                        ? 'bg-accent-green border-accent-green'
                                        : 'border-slate-300 dark:border-slate-600 group-hover:border-accent-green/60'; ?>">
                                    <svg class="<?php echo $checked ? '' : 'hidden'; ?> check-icon w-3 h-3 text-white" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m1.5 6 3 3 6-6"/></svg>
                                </span>
                            </label>
                            <?php else: ?>
                            <span class="w-5 h-5 flex items-center justify-center text-slate-200 dark:text-slate-700 text-lg">—</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <!-- Row "All" toggle -->
                        <div class="flex items-center justify-center py-3.5">
                            <button type="button" onclick="toggleRow(this)"
                                    data-module="<?php echo $mod; ?>"
                                    class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 text-[10px] font-black text-slate-500 hover:bg-accent-green/10 hover:text-accent-green transition-all uppercase tracking-widest">
                                All
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <div class="flex justify-end mt-5 gap-3">
                    <button type="button" onclick="viewOnly()" class="px-6 py-3 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-2xl font-black text-sm uppercase tracking-widest hover:bg-slate-200 dark:hover:bg-slate-600 transition-all">
                        View Only
                    </button>
                    <button type="submit" class="btn-primary px-10 py-3">
                        Save Permissions
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.perm-grid {
    display: grid;
    grid-template-columns: 1fr repeat(4, 60px) 56px;
    align-items: center;
}
</style>

<script>
// Styled checkbox toggle
document.querySelectorAll('.perm-cb').forEach(cb => {
    cb.addEventListener('change', function () {
        const span = this.nextElementSibling;
        const icon = span.querySelector('.check-icon');
        if (this.checked) {
            span.classList.add('bg-accent-green', 'border-accent-green');
            span.classList.remove('border-slate-300', 'dark:border-slate-600');
            icon.classList.remove('hidden');
        } else {
            span.classList.remove('bg-accent-green', 'border-accent-green');
            span.classList.add('border-slate-300', 'dark:border-slate-600');
            icon.classList.add('hidden');
        }
    });
});

function setCbs(query, state) {
    document.querySelectorAll(query).forEach(cb => {
        if (!cb.disabled && cb.closest('.perm-grid')) {
            cb.checked = state;
            cb.dispatchEvent(new Event('change'));
        }
    });
}

function grantAll()  { setCbs('.perm-cb', true);  }
function revokeAll() { setCbs('.perm-cb', false); }
function viewOnly()  {
    revokeAll();
    document.querySelectorAll('.perm-cb[data-act="view"]').forEach(cb => {
        cb.checked = true;
        cb.dispatchEvent(new Event('change'));
    });
}

function toggleRow(btn) {
    const mod = btn.dataset.module;
    const cbs = document.querySelectorAll(`.perm-cb[data-mod="${mod}"]`);
    const anyUnchecked = [...cbs].some(cb => !cb.checked);
    cbs.forEach(cb => { cb.checked = anyUnchecked; cb.dispatchEvent(new Event('change')); });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
