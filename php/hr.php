<?php
/**
 * HR Management — Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole('staff');

$user = getCurrentUser($pdo);
$pageTitle = "Personnel (HR)";

// Self-heal: profiles.job_title
try { $pdo->exec("ALTER TABLE profiles ADD COLUMN job_title VARCHAR(100) NULL"); } catch (PDOException $e) {}

// Self-heal: employee extended columns
$heals = [
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS staff_no VARCHAR(50) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS id_number VARCHAR(50) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS gender VARCHAR(20) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS hometown VARCHAR(150) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS physical_address TEXT NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS employment_status VARCHAR(30) NOT NULL DEFAULT 'Permanent'",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS contract_end_date DATE NULL",
];
foreach ($heals as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }

// Fetch employees with linked system account info
$stmt = $pdo->query("
    SELECT e.*,
           p.job_title   AS system_job_title,
           u.role        AS system_role
    FROM employees e
    LEFT JOIN users u ON u.email = e.email
    LEFT JOIN profiles p ON p.email = e.email
    ORDER BY e.hire_date DESC
");
$employees = $stmt->fetchAll();

$toast = $_GET['success'] ?? '';
$error = $_GET['error']   ?? '';
$toastMap = ['created' => 'Employee added successfully.'];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <?php if ($toast && isset($toastMap[$toast])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-400 rounded-2xl text-sm font-bold animate-in fade-in">
        <?php echo $toastMap[$toast]; ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="p-4 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 rounded-2xl text-sm font-bold">
        <?php echo htmlspecialchars(urldecode($error)); ?>
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">HR & Personnel</h1>
            <p class="text-slate-500 font-medium"><?php echo count($employees); ?> staff member<?php echo count($employees) !== 1 ? 's' : ''; ?> on record.</p>
        </div>
        <button onclick="openModal('newEmployeeModal')" class="btn-primary gap-2">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Add Employee
        </button>
    </div>

    <!-- Stats row -->
    <?php
    $active     = count(array_filter($employees, fn($e) => $e['status'] === 'Active'));
    $perm       = count(array_filter($employees, fn($e) => ($e['employment_status'] ?? '') === 'Permanent'));
    $contract   = count(array_filter($employees, fn($e) => ($e['employment_status'] ?? '') === 'Contract'));
    $totalSal   = array_sum(array_column($employees, 'salary'));
    $statCards  = [
        ['Active Staff',   $active,                 'text-green-500',  'bg-green-50 dark:bg-green-900/20'],
        ['Permanent',      $perm,                   'text-blue-500',   'bg-blue-50 dark:bg-blue-900/20'],
        ['Contract',       $contract,               'text-orange-500', 'bg-orange-50 dark:bg-orange-900/20'],
        ['Monthly Payroll','KSh ' . number_format($totalSal), 'text-accent-green', 'bg-accent-green/10'],
    ];
    ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <?php foreach ($statCards as [$label, $val, $color, $bg]): ?>
        <div class="glass-card p-5 flex items-center gap-4">
            <div class="w-10 h-10 rounded-xl <?php echo $bg; ?> <?php echo $color; ?> flex items-center justify-center font-black text-sm shrink-0"><?php echo $val; ?></div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-tight"><?php echo $label; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Employees Table -->
    <div class="glass-card overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Employee</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden md:table-cell">Staff No</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Job Title / Dept</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden sm:table-cell">Employment</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Salary</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="p-5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="7" class="p-20 text-center text-slate-400 italic font-medium">No employees found. Add your first employee to get started.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp):
                        $jobTitle = !empty($emp['system_job_title']) ? $emp['system_job_title'] : ($emp['role'] ?? '—');
                        $empStatusColors = [
                            'Permanent' => 'bg-green-100 dark:bg-green-900/30 text-green-600',
                            'Contract'  => 'bg-blue-100 dark:bg-blue-900/30 text-blue-500',
                            'Probation' => 'bg-orange-100 dark:bg-orange-900/30 text-orange-500',
                            'Casual'    => 'bg-slate-100 dark:bg-slate-800 text-slate-500',
                        ];
                        $esc = $empStatusColors[$emp['employment_status'] ?? 'Permanent'] ?? $empStatusColors['Permanent'];
                    ?>
                    <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-5">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-accent-green/10 flex items-center justify-center text-accent-green font-black text-sm shadow-inner shrink-0">
                                    <?php echo strtoupper(substr($emp['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($emp['full_name']); ?></p>
                                    <p class="text-[10px] font-bold text-slate-400"><?php echo htmlspecialchars($emp['email']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="p-5 hidden md:table-cell">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($emp['staff_no'] ?? '—'); ?></span>
                        </td>
                        <td class="p-5">
                            <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($jobTitle); ?></p>
                            <div class="flex items-center gap-2 mt-0.5">
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($emp['department'] ?: 'N/A'); ?></p>
                                <?php if (!empty($emp['system_role'])): ?>
                                <span class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-widest <?php echo $emp['system_role'] === 'admin' ? 'bg-red-100 dark:bg-red-900/30 text-red-500' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-500'; ?>">
                                    <?php echo ucfirst($emp['system_role']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="p-5 hidden sm:table-cell">
                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-widest <?php echo $esc; ?>">
                                <?php echo htmlspecialchars($emp['employment_status'] ?? 'Permanent'); ?>
                            </span>
                            <?php if (!empty($emp['contract_end_date']) && ($emp['employment_status'] ?? '') === 'Contract'): ?>
                            <p class="text-[10px] text-slate-400 mt-1 font-medium">Ends <?php echo date('d M Y', strtotime($emp['contract_end_date'])); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="p-5 text-right">
                            <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format((float)$emp['salary']); ?></span>
                        </td>
                        <td class="p-5">
                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-widest <?php echo $emp['status'] === 'Active' ? 'bg-green-100 dark:bg-green-900/30 text-green-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'; ?>">
                                <?php echo htmlspecialchars($emp['status']); ?>
                            </span>
                        </td>
                        <td class="p-5 text-right">
                            <a href="hr_employee.php?id=<?php echo urlencode($emp['id']); ?>"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-accent-green/10 hover:text-accent-green text-[10px] font-black uppercase tracking-widest transition-all">
                                View →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══════════ ADD EMPLOYEE MODAL ══════════ -->
<div id="newEmployeeModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-2xl max-h-[90vh] overflow-y-auto">
        <button onclick="closeModal('newEmployeeModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2">Add New Employee</h2>
        <p class="text-sm text-slate-400 font-medium mb-8">Complete details can be added from the employee profile after creation.</p>

        <form action="actions/hr_actions.php" method="POST" class="space-y-8">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="_redirect" value="../hr.php">

            <!-- Personal Info -->
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Personal Information</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2 space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name *</label>
                        <input type="text" name="full_name" required placeholder="Jane Doe" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Staff No.</label>
                        <input type="text" name="staff_no" placeholder="e.g. EMP-001" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">National ID No.</label>
                        <input type="text" name="id_number" placeholder="National ID number" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Email *</label>
                        <input type="email" name="email" required placeholder="jane@example.com" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="phone" placeholder="+254 7xx xxx xxx" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Gender</label>
                        <select name="gender" class="form-input w-full">
                            <option value="">— Select —</option>
                            <option>Male</option><option>Female</option><option>Other</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hometown</label>
                        <input type="text" name="hometown" placeholder="e.g. Kisumu" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Physical Address</label>
                        <input type="text" name="physical_address" placeholder="Street, Estate, Town" class="form-input w-full">
                    </div>
                </div>
            </div>

            <!-- Employment Details -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-6">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Employment Details</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Job Title *</label>
                        <input type="text" name="role_title" required placeholder="e.g. Property Manager" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Department</label>
                        <input type="text" name="department" placeholder="e.g. Operations" value="Operations" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Employment Status</label>
                        <select name="employment_status" id="newEmpStatus" onchange="toggleNewContractDate()" class="form-input w-full">
                            <option value="Permanent">Permanent</option>
                            <option value="Contract">Contract</option>
                            <option value="Probation">Probation</option>
                            <option value="Casual">Casual</option>
                        </select>
                    </div>
                    <div class="space-y-2" id="newContractDateWrap" style="display:none;">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Contract End Date</label>
                        <input type="date" name="contract_end_date" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Gross Salary (KSh) *</label>
                        <input type="number" name="salary" required step="0.01" placeholder="0.00" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Record Status</label>
                        <select name="status" class="form-input w-full">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">Add Employee →</button>
        </form>
    </div>
</div>

<script>
function toggleNewContractDate() {
    const sel  = document.getElementById('newEmpStatus');
    const wrap = document.getElementById('newContractDateWrap');
    wrap.style.display = ['Contract','Probation'].includes(sel.value) ? '' : 'none';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
