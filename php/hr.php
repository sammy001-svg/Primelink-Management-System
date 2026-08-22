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

require_once __DIR__ . '/includes/hr_schema.php';
ensureHrSchema($pdo);
expireLapsedContracts($pdo);

// Contracts running out, so nobody lapses unnoticed
$expiring = expiringContracts($pdo, 60);
$expiredNow = array_filter($expiring, fn($c) => contractDaysRemaining($c['end_date']) < 0);
$dueSoon    = array_filter($expiring, fn($c) => contractDaysRemaining($c['end_date']) >= 0);

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
$toastMap = [
    'created'           => 'Employee registered.',
    'contract_added'    => 'Contract recorded.',
    'contract_renewed'  => 'Contract renewed.',
    'contract_ended'    => 'Contract ended.',
];

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
                            <?php if (!empty($emp['contract_end_date']) && isFixedTerm($emp['employment_status'] ?? '')):
                                $state = contractExpiryState($emp['contract_end_date']);
                                $tone  = match ($state['tone']) {
                                    'expired' => 'text-red-500',
                                    'urgent'  => 'text-amber-600',
                                    'warn'    => 'text-yellow-600',
                                    default   => 'text-slate-400',
                                };
                            ?>
                            <p class="text-[10px] mt-1 font-bold <?php echo $tone; ?>">
                                Ends <?php echo date('d M Y', strtotime($emp['contract_end_date'])); ?>
                                &middot; <?php echo htmlspecialchars($state['label']); ?>
                            </p>
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

<?php if ($expiring): ?>
<!-- ══════════ CONTRACTS NEEDING ATTENTION ══════════ -->
<div class="glass-card p-6 border-l-4 <?php echo $expiredNow ? 'border-red-500' : 'border-amber-400'; ?> mb-8">
    <div class="flex items-center justify-between gap-4 mb-4">
        <div>
            <h2 class="text-sm font-black text-slate-900 dark:text-white">Contracts Needing Attention</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                <?php if ($expiredNow): ?>
                <span class="text-red-500 font-black"><?php echo count($expiredNow); ?> lapsed</span>
                <?php endif; ?>
                <?php if ($expiredNow && $dueSoon): ?> · <?php endif; ?>
                <?php if ($dueSoon): ?>
                <span class="text-amber-600 font-black"><?php echo count($dueSoon); ?> ending within 60 days</span>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-800">
                    <th class="px-3 py-2 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Employee</th>
                    <th class="px-3 py-2 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                    <th class="px-3 py-2 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Ends</th>
                    <th class="px-3 py-2 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="px-3 py-2 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($expiring as $c):
                    $state = contractExpiryState($c['end_date']);
                    $tone  = match ($state['tone']) {
                        'expired' => 'bg-red-500/10 text-red-500',
                        'urgent'  => 'bg-amber-500/10 text-amber-600',
                        'warn'    => 'bg-yellow-500/10 text-yellow-600',
                        default   => 'bg-slate-500/10 text-slate-500',
                    };
                ?>
                <tr>
                    <td class="px-3 py-3">
                        <a href="hr_employee.php?id=<?php echo urlencode($c['employee_id']); ?>&tab=contracts"
                           class="text-xs font-black text-slate-900 dark:text-white hover:text-accent-green transition-colors">
                            <?php echo htmlspecialchars((string)$c['full_name']); ?>
                        </a>
                        <?php if ($c['staff_no']): ?>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars((string)$c['staff_no']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-xs font-bold text-slate-500"><?php echo htmlspecialchars((string)$c['contract_type']); ?></td>
                    <td class="px-3 py-3 text-xs font-bold text-slate-600 dark:text-slate-300 whitespace-nowrap">
                        <?php echo date('d M Y', strtotime((string)$c['end_date'])); ?>
                    </td>
                    <td class="px-3 py-3">
                        <span class="px-2 py-1 rounded-lg text-[10px] font-black uppercase <?php echo $tone; ?>">
                            <?php echo htmlspecialchars($state['label']); ?>
                        </span>
                    </td>
                    <td class="px-3 py-3 text-right">
                        <a href="hr_employee.php?id=<?php echo urlencode($c['employee_id']); ?>&tab=contracts"
                           class="inline-flex px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            Renew
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══════════ ADD EMPLOYEE MODAL ══════════ -->
<div id="newEmployeeModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-3xl max-h-[92vh] overflow-y-auto">
        <button onclick="closeModal('newEmployeeModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2">Register Employee</h2>
        <p class="text-sm text-slate-400 font-medium mb-8">
            Capture the full file now — person, post, contract, next of kin, statutory numbers and paperwork.
            Anything left blank is listed as outstanding on the employee's profile.
        </p>

        <form action="actions/hr_actions.php" method="POST" enctype="multipart/form-data" class="space-y-8">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="_redirect" value="../hr.php">

            <!-- ── 1. Personal ─────────────────────────────────────── -->
            <div>
                <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-4">1 · Personal Details</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2 space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name *</label>
                        <input type="text" name="full_name" required placeholder="As written on the national ID" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Staff No.</label>
                        <input type="text" name="staff_no" placeholder="e.g. EMP-001" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">National ID No.</label>
                        <input type="text" name="id_number" placeholder="e.g. 12345678" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Date of Birth</label>
                        <input type="date" name="date_of_birth" max="<?php echo date('Y-m-d', strtotime('-16 years')); ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Gender</label>
                        <select name="gender" class="form-input w-full">
                            <option value="">— Select —</option>
                            <option>Male</option><option>Female</option><option>Other</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Marital Status</label>
                        <select name="marital_status" class="form-input w-full">
                            <option value="">— Select —</option>
                            <option>Single</option><option>Married</option><option>Divorced</option><option>Widowed</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hometown</label>
                        <input type="text" name="hometown" placeholder="e.g. Kisumu" class="form-input w-full">
                    </div>
                </div>
            </div>

            <!-- ── 2. Contact ──────────────────────────────────────── -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-6">
                <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-4">2 · Contact</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Email</label>
                        <input type="email" name="email" placeholder="jane@example.com" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="phone" placeholder="07xx xxx xxx" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Alternative Phone</label>
                        <input type="text" name="alt_phone" placeholder="Optional second number" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Postal Address</label>
                        <input type="text" name="postal_address" placeholder="e.g. P.O. Box 123 - 00100" class="form-input w-full">
                    </div>
                    <div class="col-span-2 space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Physical Address</label>
                        <input type="text" name="physical_address" placeholder="Estate, street, house/apartment, town" class="form-input w-full">
                    </div>
                </div>
            </div>

            <!-- ── 3. Employment ───────────────────────────────────── -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-6">
                <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-4">3 · Employment</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Job Title *</label>
                        <input type="text" name="role_title" required placeholder="e.g. Property Manager" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Department</label>
                        <input type="text" name="department" value="Operations" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Reports To</label>
                        <input type="text" name="reports_to" placeholder="Line manager" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Work Location</label>
                        <input type="text" name="work_location" placeholder="e.g. Head Office" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Gross Salary (KSh) *</label>
                        <input type="number" name="salary" required step="0.01" min="0" placeholder="0.00" class="form-input w-full">
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

            <!-- ── 4. Contract ─────────────────────────────────────── -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-6">
                <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-4">4 · Contract</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Engagement Type *</label>
                        <select name="employment_status" id="newEmpStatus" onchange="toggleNewContractDate()" class="form-input w-full">
                            <?php foreach (HR_EMPLOYMENT_STATUSES as $st): ?>
                            <option value="<?php echo $st; ?>"><?php echo $st; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hire Date</label>
                        <input type="date" name="hire_date" value="<?php echo date('Y-m-d'); ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Contract Start</label>
                        <input type="date" name="contract_start_date" value="<?php echo date('Y-m-d'); ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2" id="newContractDateWrap" style="display:none;">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Contract End *</label>
                        <input type="date" name="contract_end_date" id="newContractEnd" class="form-input w-full">
                        <p class="text-[10px] text-slate-400 px-1">Fixed-term staff appear on the expiry watchlist.</p>
                    </div>
                    <div class="col-span-2 space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Contract Terms / Notes</label>
                        <textarea name="contract_terms" rows="2" class="form-input w-full resize-none"
                                  placeholder="Probation length, notice period, anything specific to this engagement…"></textarea>
                    </div>
                </div>
            </div>

            <!-- ── 5. Next of Kin ──────────────────────────────────── -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-6">
                <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-4">5 · Next of Kin</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name</label>
                        <input type="text" name="kin_name" placeholder="Who to call first" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Relationship</label>
                        <input type="text" name="kin_relationship" placeholder="e.g. Spouse, Parent" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="kin_phone" placeholder="07xx xxx xxx" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Alternative Phone</label>
                        <input type="text" name="kin_alt_phone" class="form-input w-full">
                    </div>
                    <div class="col-span-2 space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Address</label>
                        <input type="text" name="kin_address" class="form-input w-full">
                    </div>
                </div>

                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-6 mb-3">Emergency Contact <span class="normal-case font-medium">(if different)</span></p>
                <div class="grid grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Name</label>
                        <input type="text" name="emergency_name" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Relationship</label>
                        <input type="text" name="emergency_relationship" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="emergency_phone" class="form-input w-full">
                    </div>
                </div>
            </div>

            <!-- ── 6. Statutory & Bank ─────────────────────────────── -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-6">
                <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-4">6 · Statutory &amp; Salary Account</p>
                <div class="grid grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">KRA PIN</label>
                        <input type="text" name="kra_pin" placeholder="A0123456789B" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">NSSF No.</label>
                        <input type="text" name="nssf_number" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SHIF / NHIF No.</label>
                        <input type="text" name="shif_number" class="form-input w-full">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Bank</label>
                        <input type="text" name="bank_name" list="hr_bank_list" placeholder="e.g. Equity Bank" class="form-input w-full">
                        <datalist id="hr_bank_list">
                            <?php foreach ([
                                'Co-operative Bank', 'KCB Bank', 'Equity Bank', 'ABSA Bank', 'Standard Chartered',
                                'NCBA Bank', 'Diamond Trust Bank', 'I&M Bank', 'Family Bank', 'Stanbic Bank',
                                'National Bank', 'Sidian Bank', 'Prime Bank',
                            ] as $b): ?>
                            <option value="<?php echo $b; ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                        <input type="text" name="bank_branch" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Account Name</label>
                        <input type="text" name="bank_account_name" placeholder="Defaults to the employee's name" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Account Number</label>
                        <input type="text" name="bank_account_no" class="form-input w-full">
                    </div>
                </div>
            </div>

            <!-- ── 7. Paperwork ────────────────────────────────────── -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-6">
                <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-4">7 · Paperwork</p>
                <div class="grid grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Copy of ID</label>
                        <input type="file" name="id_copy_file" accept=".pdf,.jpg,.jpeg,.png,.webp"
                               class="form-input w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[10px] file:font-black file:uppercase file:bg-slate-100 dark:file:bg-slate-800 file:text-slate-600 dark:file:text-slate-300">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Signed Agreement</label>
                        <input type="file" name="agreement_file" accept=".pdf,.jpg,.jpeg,.png,.webp"
                               class="form-input w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[10px] file:font-black file:uppercase file:bg-slate-100 dark:file:bg-slate-800 file:text-slate-600 dark:file:text-slate-300">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Passport Photo</label>
                        <input type="file" name="photo_file" accept=".jpg,.jpeg,.png,.webp"
                               class="form-input w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[10px] file:font-black file:uppercase file:bg-slate-100 dark:file:bg-slate-800 file:text-slate-600 dark:file:text-slate-300">
                    </div>
                </div>
                <p class="text-[10px] text-slate-400 mt-3 px-1">
                    PDF or image, up to your server's upload limit. More documents — certificates, good conduct,
                    KRA certificate — can be added from the employee's Documents tab.
                </p>
                <div class="space-y-2 mt-4">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Internal Notes</label>
                    <textarea name="notes" rows="2" class="form-input w-full resize-none" placeholder="Anything else worth recording…"></textarea>
                </div>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">Register Employee →</button>
        </form>
    </div>
</div>

<script>
function toggleNewContractDate() {
    const sel   = document.getElementById('newEmpStatus');
    const wrap  = document.getElementById('newContractDateWrap');
    const input = document.getElementById('newContractEnd');
    // Anything other than a permanent post is time-bound and needs an end date
    const fixed = <?php echo json_encode(HR_FIXED_TERM_STATUSES); ?>.includes(sel.value);
    wrap.style.display = fixed ? '' : 'none';
    input.required = fixed;
    if (!fixed) input.value = '';
}
toggleNewContractDate();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
