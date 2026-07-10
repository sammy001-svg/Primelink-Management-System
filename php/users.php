<?php
/**
 * User Management
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

$pageTitle = "User Management";

// Self-heal: add columns if missing
try { $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE profiles ADD COLUMN job_title VARCHAR(100) NULL"); } catch (PDOException $e) {}

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';

// Fetch all admin/staff users with their profile
$users = $pdo->query(
    "SELECT u.id, u.email, u.role, u.status, u.created_at,
            p.full_name, p.phone, p.profile_image, p.job_title
     FROM users u
     LEFT JOIN profiles p ON p.email = u.email
     WHERE u.role IN ('admin','staff')
     ORDER BY u.role ASC, p.full_name ASC"
)->fetchAll();

$roleBadge = [
    'admin' => 'badge-red',
    'staff' => 'badge-blue',
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <?php if ($success): ?>
    <div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-2xl text-green-700 dark:text-green-400 text-sm font-bold">
        <?php echo $success === 'created' ? 'User created successfully.' : ($success === 'updated' ? 'User updated.' : 'User status changed.'); ?>
    </div>
    <?php elseif ($error): ?>
    <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl text-red-700 dark:text-red-400 text-sm font-bold">
        <?php echo $error === 'email_exists' ? 'A user with that email already exists.' : htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">User Management</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">Manage admin and staff accounts.</p>
        </div>
        <button onclick="openModal('newUserModal')" class="btn-green text-xs gap-2">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Add User
        </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
        <?php
        $adminCount  = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
        $staffCount  = count(array_filter($users, fn($u) => $u['role'] === 'staff'));
        $activeCount = count(array_filter($users, fn($u) => $u['status'] === 'Active'));
        $statCards = [
            ['Admins',       $adminCount,  'text-red-500',  'bg-red-50 dark:bg-red-900/20'],
            ['Staff',        $staffCount,  'text-blue-500', 'bg-blue-50 dark:bg-blue-900/20'],
            ['Active Users', $activeCount, 'text-green-500','bg-green-50 dark:bg-green-900/20'],
        ];
        foreach ($statCards as [$label, $val, $color, $bg]): ?>
        <div class="glass-card p-5 flex items-center gap-4">
            <div class="w-10 h-10 rounded-xl <?php echo $bg; ?> <?php echo $color; ?> flex items-center justify-center font-black text-lg shrink-0"><?php echo $val; ?></div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo $label; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Users Table -->
    <div class="glass-card overflow-hidden">
        <?php if (empty($users)): ?>
        <div class="py-20 text-center">
            <p class="text-slate-400 font-medium">No admin or staff users found.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800">
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">User</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Role</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest hidden sm:table-cell">Joined</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                        <th class="px-6 py-4 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($users as $u):
                        $initial = strtoupper(substr($u['full_name'] ?? $u['email'], 0, 1));
                        $isSelf  = $u['id'] === $_SESSION['user_id'];
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-linear-to-br from-green-500 to-green-700 flex items-center justify-center text-white font-black text-sm shrink-0">
                                    <?php echo $initial; ?>
                                </div>
                                <div>
                                    <p class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($u['full_name'] ?? '—'); ?></p>
                                    <?php if (!empty($u['job_title'])): ?>
                                    <p class="text-[10px] text-accent-green font-black uppercase tracking-widest"><?php echo htmlspecialchars($u['job_title']); ?></p>
                                    <?php else: ?>
                                    <p class="text-[10px] text-slate-400 font-medium"><?php echo htmlspecialchars($u['email']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge <?php echo $roleBadge[$u['role']] ?? 'badge-primary'; ?>"><?php echo ucfirst($u['role']); ?></span>
                            <?php if ($isSelf): ?><span class="ml-1 text-[9px] text-slate-400 font-black">(you)</span><?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden sm:table-cell text-xs text-slate-500 font-medium">
                            <?php echo date('M d, Y', strtotime($u['created_at'])); ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge <?php echo $u['status'] === 'Active' ? 'badge-green' : 'badge-orange'; ?>">
                                <?php echo $u['status']; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <?php if ($u['role'] === 'staff'): ?>
                                <a href="permissions.php?user_id=<?php echo urlencode($u['id']); ?>"
                                   class="text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-accent-green px-3 py-1.5 rounded-lg hover:bg-accent-green/10 transition-all flex items-center gap-1">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    Permissions
                                </a>
                                <?php endif; ?>
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u)); ?>)"
                                        class="text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-slate-900 dark:hover:text-white px-3 py-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                                    Edit
                                </button>
                                <?php if (!$isSelf): ?>
                                <form action="actions/user_actions.php" method="POST" class="inline"
                                      onsubmit="return confirm('<?php echo $u['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?> this user?')">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="text-[10px] font-black uppercase tracking-widest px-3 py-1.5 rounded-lg transition-all <?php echo $u['status'] === 'Active' ? 'text-red-400 hover:bg-red-50 dark:hover:bg-red-900/10 hover:text-red-600' : 'text-green-500 hover:bg-green-50 dark:hover:bg-green-900/10'; ?>">
                                        <?php echo $u['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== NEW USER MODAL ===== -->
<div class="modal-overlay" id="newUserModal" style="display:none;">
    <div class="modal-card max-w-lg px-8 py-10">
        <button onclick="closeModal('newUserModal')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all transform hover:rotate-90">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Add New User</h2>
        <form action="actions/user_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="create">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Full Name</label>
                <input type="text" name="full_name" required placeholder="John Doe"
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Email</label>
                <input type="email" name="email" required placeholder="john@primelink.co.ke"
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Job Title</label>
                <input type="text" name="job_title" placeholder="e.g. Property Manager, Accounts Clerk"
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">System Role</label>
                    <select name="role" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone</label>
                    <input type="text" name="phone" placeholder="+254 700 000000"
                           class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Password</label>
                <input type="password" name="password" required placeholder="Min. 8 characters" minlength="8"
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4 font-black">Create User →</button>
        </form>
    </div>
</div>

<!-- ===== EDIT USER MODAL ===== -->
<div class="modal-overlay" id="editUserModal" style="display:none;">
    <div class="modal-card max-w-lg px-8 py-10">
        <button onclick="closeModal('editUserModal')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all transform hover:rotate-90">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Edit User</h2>
        <form action="actions/user_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Full Name</label>
                <input type="text" name="full_name" id="edit_full_name" required
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Job Title</label>
                <input type="text" name="job_title" id="edit_job_title" placeholder="e.g. Property Manager, Accounts Clerk"
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">System Role</label>
                    <select name="role" id="edit_role" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone</label>
                    <input type="text" name="phone" id="edit_phone"
                           class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">New Password <span class="normal-case font-medium">(leave blank to keep current)</span></label>
                <input type="password" name="password" placeholder="Leave blank to keep current" minlength="8"
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4 font-black">Save Changes →</button>
        </form>
    </div>
</div>

<script>
function openEditModal(user) {
    document.getElementById('edit_user_id').value   = user.id;
    document.getElementById('edit_full_name').value = user.full_name || '';
    document.getElementById('edit_job_title').value = user.job_title || '';
    document.getElementById('edit_phone').value     = user.phone || '';
    document.getElementById('edit_role').value      = user.role;
    openModal('editUserModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
