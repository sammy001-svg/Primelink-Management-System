<?php
/**
 * HR Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole('staff');

$user = getCurrentUser($pdo);
$pageTitle = "Personnel (HR)";

// Fetch employees
$stmt = $pdo->query("SELECT * FROM employees ORDER BY hire_date DESC");
$employees = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm animate-in fade-in slide-in-from-top-4">
        Employee added successfully!
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">HR & Personnel</h1>
            <p class="text-slate-500 font-medium">Manage your team and staff operations.</p>
        </div>
        <button onclick="openModal('newEmployeeModal')" class="btn-primary">
            + Add Employee
        </button>
    </div>

    <!-- New Employee Modal -->
    <div id="newEmployeeModal" class="modal-overlay" style="display:none;">
        <div class="modal-card">
            <button onclick="closeModal('newEmployeeModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <h2 class="text-2xl font-black mb-8">Add New Employee</h2>
            <form action="actions/hr_actions.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="create">
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Full Name</label>
                        <input type="text" name="full_name" required placeholder="Jane Doe" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Email</label>
                        <input type="email" name="email" required placeholder="jane@primelink.com" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Role</label>
                        <input type="text" name="role_title" required placeholder="Manager" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Salary (KSh)</label>
                        <input type="number" name="salary" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>
                <button type="submit" class="btn-green w-full justify-center py-4">Add Employee</button>
            </form>
        </div>
    </div>

    <!-- Employees List -->
    <div class="glass-card overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Employee</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Role/Dept</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Salary</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="5" class="p-20 text-center text-slate-400 italic font-medium">No employees found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp): ?>
                    <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-6">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-accent-green font-black shadow-inner">
                                    <?php echo substr($emp['full_name'], 0, 1); ?>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($emp['full_name']); ?></p>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($emp['email']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="p-6">
                            <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($emp['role']); ?></p>
                            <p class="text-[10px] text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($emp['department'] ?: 'N/A'); ?></p>
                        </td>
                        <td class="p-6">
                            <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($emp['salary']); ?></span>
                        </td>
                        <td class="p-6">
                            <span class="px-3 py-1 <?php echo $emp['status'] == 'Active' ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500'; ?> rounded-full text-[10px] font-black uppercase tracking-widest">
                                <?php echo htmlspecialchars($emp['status']); ?>
                            </span>
                        </td>
                        <td class="p-6 text-right">
                            <button class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-accent-green transition-all">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
