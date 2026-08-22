<?php
/**
 * Employee Detail Page — Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/payroll_calc.php';
ensurePayrollTables($pdo);

$currency = getSetting($pdo, 'currency_symbol', 'KSh');

$empId = trim($_GET['id'] ?? '');
if (!$empId) { header('Location: hr.php'); exit(); }

// Schema self-heal
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
    "CREATE TABLE IF NOT EXISTS employee_documents (
        id VARCHAR(36) PRIMARY KEY, employee_id VARCHAR(36) NOT NULL, doc_type VARCHAR(50) NOT NULL,
        doc_name VARCHAR(255) NOT NULL, file_path VARCHAR(500) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, uploaded_by VARCHAR(36) NULL,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS employee_contacts (
        id VARCHAR(36) PRIMARY KEY, employee_id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL, relationship VARCHAR(100) NULL, is_next_of_kin TINYINT(1) DEFAULT 0,
        address TEXT NULL, FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS employee_salary_history (
        id VARCHAR(36) PRIMARY KEY, employee_id VARCHAR(36) NOT NULL, effective_date DATE NOT NULL,
        old_salary DECIMAL(15,2) NOT NULL, new_salary DECIMAL(15,2) NOT NULL, reason TEXT NULL,
        reviewed_by VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS employee_warnings (
        id VARCHAR(36) PRIMARY KEY, employee_id VARCHAR(36) NOT NULL, warning_date DATE NOT NULL,
        severity VARCHAR(20) NOT NULL DEFAULT 'Written', reason TEXT NOT NULL, action_taken TEXT NULL,
        issued_by VARCHAR(255) NULL, file_path VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($heals as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }

// Fetch employee
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$empId]);
$emp = $stmt->fetch();
if (!$emp) { header('Location: hr.php?error=not_found'); exit(); }

// Fetch related data
$docs = $pdo->prepare("SELECT * FROM employee_documents WHERE employee_id=? ORDER BY uploaded_at DESC");
$docs->execute([$empId]); $docs = $docs->fetchAll();

$contacts = $pdo->prepare("SELECT * FROM employee_contacts WHERE employee_id=? ORDER BY is_next_of_kin DESC, name ASC");
$contacts->execute([$empId]); $contacts = $contacts->fetchAll();

$salaryHistory = $pdo->prepare("SELECT * FROM employee_salary_history WHERE employee_id=? ORDER BY effective_date DESC");
$salaryHistory->execute([$empId]); $salaryHistory = $salaryHistory->fetchAll();

$warnings = $pdo->prepare("SELECT * FROM employee_warnings WHERE employee_id=? ORDER BY warning_date DESC");
$warnings->execute([$empId]); $warnings = $warnings->fetchAll();

// Loans & Advances
require_once __DIR__ . '/actions/loan_actions.php';
$loans = $pdo->prepare("SELECT * FROM employee_loans WHERE employee_id=? ORDER BY applied_date DESC");
$loans->execute([$empId]); $loans = $loans->fetchAll();

// Leave
require_once __DIR__ . '/actions/leave_actions.php';
$leaveYear   = (int)($_GET['leave_year'] ?? date('Y'));
$leaveTypes  = $pdo->query("SELECT * FROM leave_types ORDER BY sort_order, name")->fetchAll();
seedBalances($pdo, $empId, $leaveYear);
$leaveBalances = $pdo->prepare(
    "SELECT lb.*, lt.name lt_name, lt.color lt_color, lt.days_per_year
     FROM leave_balances lb JOIN leave_types lt ON lt.id = lb.leave_type_id
     WHERE lb.employee_id=? AND lb.year=? ORDER BY lt.sort_order, lt.name"
);
$leaveBalances->execute([$empId, $leaveYear]); $leaveBalances = $leaveBalances->fetchAll();
$leaveApps = $pdo->prepare(
    "SELECT la.*, lt.name lt_name, lt.color lt_color
     FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id
     WHERE la.employee_id=? ORDER BY la.applied_at DESC LIMIT 20"
);
$leaveApps->execute([$empId]); $leaveApps = $leaveApps->fetchAll();

// Bank details
require_once __DIR__ . '/actions/bank_actions.php';
$bankAccounts = $pdo->prepare("SELECT * FROM employee_bank_details WHERE employee_id=? ORDER BY is_primary DESC, created_at ASC");
$bankAccounts->execute([$empId]); $bankAccounts = $bankAccounts->fetchAll();

// Contracts
require_once __DIR__ . '/includes/hr_schema.php';
ensureHrSchema($pdo);
expireLapsedContracts($pdo);
$contracts      = getEmployeeContracts($pdo, $empId);
$activeContract = getActiveContract($pdo, $empId);

// Tax profile
$taxProfile = $pdo->prepare("SELECT * FROM employee_tax_profile WHERE employee_id=?");
$taxProfile->execute([$empId]);
$taxProfile = $taxProfile->fetch() ?: [];

// Recent payslips (last 6 finalised periods this employee appeared in)
$payslips = $pdo->prepare(
    "SELECT pe.id entry_id, pp.period_year, pp.period_month, pe.gross_pay, pe.net_pay, pe.paye
     FROM payroll_entries pe JOIN payroll_periods pp ON pp.id = pe.period_id
     WHERE pe.employee_id = ? AND pp.status = 'Finalised'
     ORDER BY pp.period_year DESC, pp.period_month DESC LIMIT 12"
);
$payslips->execute([$empId]); $payslips = $payslips->fetchAll();

$activeTab = $_GET['tab'] ?? 'profile';
$toast     = $_GET['success'] ?? '';
$error     = $_GET['error']   ?? '';

$toastMap = [
    'profile_updated' => 'Profile updated successfully.',
    'doc_uploaded'    => 'Document uploaded.',
    'doc_deleted'     => 'Document removed.',
    'contact_added'   => 'Contact added.',
    'contact_deleted' => 'Contact removed.',
    'salary_added'    => 'Salary review recorded.',
    'warning_added'      => 'Warning letter recorded.',
    'warning_deleted'    => 'Warning letter removed.',
    'tax_profile_saved'  => 'Tax profile saved.',
    'loan_applied'       => 'Loan/Advance application submitted.',
    'loan_approved'      => 'Loan approved.',
    'loan_rejected'      => 'Application rejected.',
    'loan_repaid'        => 'Repayment recorded.',
    'loan_closed'        => 'Loan marked as completed.',
    'leave_applied'      => 'Leave application submitted.',
    'leave_approved'     => 'Leave approved.',
    'leave_rejected'     => 'Leave rejected.',
    'leave_cancelled'    => 'Leave cancelled.',
    'balance_adjusted'   => 'Leave balance adjusted.',
    'bank_added'         => 'Bank account added.',
    'bank_updated'       => 'Bank account updated.',
    'bank_deleted'       => 'Bank account removed.',
    'bank_primary_set'   => 'Primary bank account updated.',
    'created'            => 'Employee registered.',
    'contract_added'     => 'Contract recorded.',
    'contract_renewed'   => 'Contract renewed — the previous term is kept on file.',
    'contract_ended'     => 'Contract ended.',
    'contract_deleted'   => 'Contract record removed.',
];

$statusColors = [
    'Permanent' => 'bg-green-100 dark:bg-green-900/30 text-green-600',
    'Contract'  => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600',
    'Probation' => 'bg-orange-100 dark:bg-orange-900/30 text-orange-600',
    'Casual'    => 'bg-slate-100 dark:bg-slate-800 text-slate-500',
];
$empStatusColor = $statusColors[$emp['employment_status'] ?? 'Permanent'] ?? $statusColors['Permanent'];

$pageTitle = $emp['full_name'] . ' — HR';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6 animate-in">

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

    <!-- Header card -->
    <div class="glass-card p-6 flex flex-col sm:flex-row items-start sm:items-center gap-5">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-accent-green/80 to-accent-green flex items-center justify-center text-white font-black text-2xl shadow-lg shrink-0">
            <?php echo strtoupper(substr($emp['full_name'], 0, 1)); ?>
        </div>
        <div class="flex-1 min-w-0">
            <h1 class="text-2xl font-black text-slate-900 dark:text-white tracking-tight"><?php echo htmlspecialchars($emp['full_name']); ?></h1>
            <p class="text-slate-500 font-bold text-sm"><?php echo htmlspecialchars($emp['role'] ?: '—'); ?>
                <?php if ($emp['department']): ?><span class="text-slate-300 mx-1">·</span><span class="text-slate-400"><?php echo htmlspecialchars($emp['department']); ?></span><?php endif; ?>
            </p>
            <div class="flex flex-wrap items-center gap-2 mt-2">
                <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-widest <?php echo $empStatusColor; ?>">
                    <?php echo htmlspecialchars($emp['employment_status'] ?? 'Permanent'); ?>
                </span>
                <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-widest <?php echo $emp['status'] === 'Active' ? 'bg-green-100 dark:bg-green-900/30 text-green-600' : 'bg-red-100 dark:bg-red-900/30 text-red-500'; ?>">
                    <?php echo htmlspecialchars($emp['status']); ?>
                </span>
                <?php if (!empty($emp['staff_no'])): ?>
                <span class="text-[10px] font-black text-slate-400">No: <?php echo htmlspecialchars($emp['staff_no']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <div class="text-right hidden sm:block">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Current Salary</p>
                <p class="text-xl font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format((float)$emp['salary']); ?></p>
            </div>
            <a href="hr.php" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-all">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </a>
        </div>
    </div>

    <?php
    $missingDetails = employeeMissingDetails($pdo, $emp);
    if ($missingDetails):
    ?>
    <!-- What is still outstanding on this file -->
    <div class="glass-card p-5 border-l-4 border-amber-400">
        <div class="flex items-start gap-3">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-amber-500 shrink-0 mt-0.5">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <div>
                <p class="text-xs font-black text-slate-900 dark:text-white mb-1">
                    <?php echo count($missingDetails); ?> detail<?php echo count($missingDetails) === 1 ? '' : 's'; ?> still outstanding on this file
                </p>
                <p class="text-[11.5px] text-slate-500 dark:text-slate-400 leading-relaxed">
                    <?php echo htmlspecialchars(implode(' · ', $missingDetails)); ?>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="flex gap-1 bg-slate-100 dark:bg-slate-800/50 rounded-2xl p-1 w-full overflow-x-auto">
        <?php
        $tabs = [
            'profile'   => 'Profile',
            'documents' => 'Documents (' . count($docs) . ')',
            'contacts'  => 'Contacts (' . count($contacts) . ')',
            'contracts' => 'Contracts (' . count($contracts) . ')',
            'salary'    => 'Salary History',
            'warnings'  => 'Warnings (' . count($warnings) . ')',
            'loans'     => 'Loans & Advances (' . count($loans) . ')',
            'leave'     => 'Leave',
            'payroll'   => 'Payroll',
            'bank'      => 'Bank Details (' . count($bankAccounts) . ')',
        ];
        foreach ($tabs as $key => $label): ?>
        <button onclick="switchTab('<?php echo $key; ?>')"
                id="tab-btn-<?php echo $key; ?>"
                class="tab-btn flex-1 py-2.5 px-4 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all whitespace-nowrap
                    <?php echo $activeTab === $key
                        ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm'
                        : 'text-slate-400 hover:text-slate-700 dark:hover:text-white'; ?>">
            <?php echo $label; ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ═══════════════════════ PROFILE TAB ═══════════════════════ -->
    <div id="tab-profile" class="tab-panel <?php echo $activeTab !== 'profile' ? 'hidden' : ''; ?>">
        <form action="actions/hr_actions.php" method="POST" class="glass-card p-8 space-y-8">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">

            <!-- Personal Info -->
            <div>
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-5">Personal Information</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name</label>
                        <input type="text" name="full_name" required value="<?php echo htmlspecialchars($emp['full_name']); ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Staff No.</label>
                        <input type="text" name="staff_no" value="<?php echo htmlspecialchars($emp['staff_no'] ?? ''); ?>" placeholder="e.g. EMP-001" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">National ID No.</label>
                        <input type="text" name="id_number" value="<?php echo htmlspecialchars($emp['id_number'] ?? ''); ?>" placeholder="ID number" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($emp['email']); ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($emp['phone'] ?? ''); ?>" placeholder="+254 7xx xxx xxx" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Date of Birth</label>
                        <input type="date" name="date_of_birth" value="<?php echo $emp['date_of_birth'] ?? ''; ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Gender</label>
                        <select name="gender" class="form-input w-full">
                            <option value="">— Select —</option>
                            <?php foreach (['Male','Female','Other'] as $g): ?>
                            <option value="<?php echo $g; ?>" <?php echo ($emp['gender'] ?? '') === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hometown</label>
                        <input type="text" name="hometown" value="<?php echo htmlspecialchars($emp['hometown'] ?? ''); ?>" placeholder="e.g. Kisumu" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Alternative Phone</label>
                        <input type="text" name="alt_phone" value="<?php echo htmlspecialchars($emp['alt_phone'] ?? ''); ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Marital Status</label>
                        <select name="marital_status" class="form-input w-full">
                            <option value="">— Select —</option>
                            <?php foreach (['Single','Married','Divorced','Widowed'] as $ms): ?>
                            <option value="<?php echo $ms; ?>" <?php echo ($emp['marital_status'] ?? '') === $ms ? 'selected' : ''; ?>><?php echo $ms; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Postal Address</label>
                        <input type="text" name="postal_address" value="<?php echo htmlspecialchars($emp['postal_address'] ?? ''); ?>" placeholder="P.O. Box 123 - 00100" class="form-input w-full">
                    </div>
                </div>
                <div class="mt-5 space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Physical Address</label>
                    <textarea name="physical_address" rows="2" placeholder="House No., Street, Estate, Town" class="form-input w-full resize-none"><?php echo htmlspecialchars($emp['physical_address'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- Employment Info -->
            <div class="border-t border-slate-100 dark:border-slate-800 pt-8">
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-5">Employment Details</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Job Title</label>
                        <input type="text" name="role_title" value="<?php echo htmlspecialchars($emp['role'] ?? ''); ?>" placeholder="e.g. Property Manager" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Department</label>
                        <input type="text" name="department" value="<?php echo htmlspecialchars($emp['department'] ?? ''); ?>" placeholder="e.g. Operations" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Employment Status</label>
                        <select name="employment_status" id="empStatusSel" onchange="toggleContractDate()" class="form-input w-full">
                            <?php foreach (HR_EMPLOYMENT_STATUSES as $es): ?>
                            <option value="<?php echo $es; ?>" <?php echo ($emp['employment_status'] ?? 'Permanent') === $es ? 'selected' : ''; ?>><?php echo $es; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2" id="contractDateWrap" style="<?php echo isFixedTerm($emp['employment_status'] ?? '') ? '' : 'display:none'; ?>">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Contract End Date</label>
                        <input type="date" name="contract_end_date" value="<?php echo $emp['contract_end_date'] ?? ''; ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Hire Date</label>
                        <input type="date" name="hire_date" value="<?php echo $emp['hire_date'] ? date('Y-m-d', strtotime($emp['hire_date'])) : ''; ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Contract Start</label>
                        <input type="date" name="contract_start_date" value="<?php echo $emp['contract_start_date'] ?? ''; ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Reports To</label>
                        <input type="text" name="reports_to" value="<?php echo htmlspecialchars($emp['reports_to'] ?? ''); ?>" placeholder="Line manager" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Work Location</label>
                        <input type="text" name="work_location" value="<?php echo htmlspecialchars($emp['work_location'] ?? ''); ?>" placeholder="e.g. Head Office" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Current Salary (<?php echo $currency; ?>)</label>
                        <input type="number" name="salary" step="0.01" value="<?php echo $emp['salary'] ?? ''; ?>" class="form-input w-full">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Record Status</label>
                        <select name="status" class="form-input w-full">
                            <option value="Active"   <?php echo ($emp['status'] ?? '') === 'Active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo ($emp['status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="Resigned" <?php echo ($emp['status'] ?? '') === 'Resigned' ? 'selected' : ''; ?>>Resigned</option>
                            <option value="Terminated" <?php echo ($emp['status'] ?? '') === 'Terminated' ? 'selected' : ''; ?>>Terminated</option>
                        </select>
                    </div>
                </div>
                <div class="mt-5 space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Internal Notes</label>
                    <textarea name="notes" rows="2" class="form-input w-full resize-none" placeholder="Anything worth recording about this employee…"><?php echo htmlspecialchars($emp['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="btn-primary px-10 py-3">Save Changes</button>
            </div>
        </form>
    </div>

    <!-- ═══════════════════════ DOCUMENTS TAB ═══════════════════════ -->
    <div id="tab-documents" class="tab-panel <?php echo $activeTab !== 'documents' ? 'hidden' : ''; ?> space-y-5">
        <div class="flex justify-between items-center">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Documents</h2>
            <button onclick="openModal('uploadDocModal')" class="btn-primary text-xs gap-2">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Upload Document
            </button>
        </div>

        <?php if (empty($docs)): ?>
        <div class="glass-card p-16 text-center text-slate-400 italic font-medium">No documents uploaded yet.</div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php
            $docIcons = [
                'id_copy'   => ['🪪', 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800'],
                'agreement' => ['📄', 'bg-purple-50 dark:bg-purple-900/20 border-purple-200 dark:border-purple-800'],
                'other'     => ['📎', 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700'],
            ];
            foreach ($docs as $doc):
                [$icon, $bg] = $docIcons[$doc['doc_type']] ?? $docIcons['other'];
                $ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
            ?>
            <div class="glass-card border <?php echo $bg; ?> p-5 flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <span class="text-2xl"><?php echo $icon; ?></span>
                    <div class="flex-1 min-w-0">
                        <p class="font-black text-slate-900 dark:text-white text-sm truncate"><?php echo htmlspecialchars($doc['doc_name']); ?></p>
                        <p class="text-[10px] text-slate-400 uppercase tracking-widest font-bold"><?php echo str_replace('_', ' ', $doc['doc_type']); ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-[10px] text-slate-400 font-medium">
                    <span><?php echo strtoupper($ext); ?> file</span>
                    <span>·</span>
                    <span><?php echo date('d M Y', strtotime($doc['uploaded_at'])); ?></span>
                </div>
                <div class="flex gap-2">
                    <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank"
                       class="flex-1 text-center py-2 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-[11px] font-black uppercase tracking-widest hover:bg-slate-200 dark:hover:bg-slate-600 transition-all">
                        View
                    </a>
                    <form action="actions/hr_actions.php" method="POST" class="inline"
                          onsubmit="return confirm('Remove this document?')">
                        <input type="hidden" name="action" value="delete_document">
                        <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                        <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
                        <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">
                        <button type="submit" class="py-2 px-3 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-500 text-[11px] font-black uppercase tracking-widest hover:bg-red-100 transition-all">
                            ×
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════ CONTACTS TAB ═══════════════════════ -->
    <div id="tab-contacts" class="tab-panel <?php echo $activeTab !== 'contacts' ? 'hidden' : ''; ?> space-y-5">
        <div class="flex justify-between items-center">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Contacts & Next of Kin</h2>
            <button onclick="openModal('addContactModal')" class="btn-primary text-xs gap-2">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add Contact
            </button>
        </div>

        <?php if (empty($contacts)): ?>
        <div class="glass-card p-16 text-center text-slate-400 italic font-medium">No contacts recorded yet.</div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($contacts as $c): ?>
            <div class="glass-card p-5 flex items-start gap-4">
                <?php if ($c['is_next_of_kin']): ?>
                <div class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/30 flex items-center justify-center text-red-500 font-black text-lg shrink-0">❤</div>
                <?php else: ?>
                <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 font-black text-sm shrink-0">
                    <?php echo strtoupper(substr($c['name'], 0, 1)); ?>
                </div>
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="font-black text-slate-900 dark:text-white text-sm"><?php echo htmlspecialchars($c['name']); ?></p>
                        <?php if ($c['is_next_of_kin']): ?>
                        <span class="px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-500 text-[9px] font-black uppercase tracking-widest rounded-lg">Next of Kin</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($c['relationship']): ?><p class="text-[11px] text-slate-500 font-bold mt-0.5"><?php echo htmlspecialchars($c['relationship']); ?></p><?php endif; ?>
                    <p class="text-sm font-bold text-accent-green mt-1"><?php echo htmlspecialchars($c['phone']); ?></p>
                    <?php if ($c['address']): ?><p class="text-[11px] text-slate-400 mt-1"><?php echo htmlspecialchars($c['address']); ?></p><?php endif; ?>
                </div>
                <form action="actions/hr_actions.php" method="POST" onsubmit="return confirm('Remove contact?')">
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="contact_id" value="<?php echo $c['id']; ?>">
                    <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
                    <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">
                    <button type="submit" class="text-slate-300 hover:text-red-500 transition-colors mt-1">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════ SALARY HISTORY TAB ═══════════════════════ -->
    <!-- ═══════════════════════ CONTRACTS TAB ═══════════════════════ -->
    <div id="tab-contracts" class="tab-panel <?php echo $activeTab !== 'contracts' ? 'hidden' : ''; ?> space-y-5">
        <div class="flex justify-between items-center">
            <div>
                <h2 class="text-lg font-black text-slate-900 dark:text-white">Contracts</h2>
                <p class="text-xs text-slate-400">Every term this employee has been engaged on.</p>
            </div>
            <button onclick="openContractModal()" class="btn-primary text-xs gap-2">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                <?php echo $activeContract ? 'Renew Contract' : 'Add Contract'; ?>
            </button>
        </div>

        <?php if ($activeContract): ?>
        <?php $state = contractExpiryState($activeContract['end_date']); ?>
        <div class="glass-card p-6 border-l-4 <?php
            echo match ($state['tone']) {
                'expired' => 'border-red-500',
                'urgent'  => 'border-amber-500',
                'warn'    => 'border-yellow-400',
                default   => 'border-accent-green',
            }; ?>">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Current Term</p>
                    <p class="text-lg font-black text-slate-900 dark:text-white">
                        <?php echo htmlspecialchars((string)$activeContract['contract_type']); ?>
                        <?php if ($activeContract['job_title']): ?>
                        <span class="text-slate-400 font-bold">· <?php echo htmlspecialchars((string)$activeContract['job_title']); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        <?php echo date('d M Y', strtotime((string)$activeContract['start_date'])); ?>
                        <?php if ($activeContract['end_date']): ?>
                            → <?php echo date('d M Y', strtotime((string)$activeContract['end_date'])); ?>
                        <?php else: ?>
                            → open-ended
                        <?php endif; ?>
                        <?php if ($activeContract['gross_salary'] > 0): ?>
                        · <?php echo $currency; ?> <?php echo number_format((float)$activeContract['gross_salary']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-right">
                    <span class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest <?php
                        echo match ($state['tone']) {
                            'expired' => 'bg-red-500/10 text-red-500',
                            'urgent'  => 'bg-amber-500/10 text-amber-600',
                            'warn'    => 'bg-yellow-500/10 text-yellow-600',
                            default   => 'bg-green-500/10 text-green-600',
                        }; ?>">
                        <?php echo htmlspecialchars($state['label']); ?>
                    </span>
                    <?php if ($activeContract['file_path']): ?>
                    <a href="<?php echo htmlspecialchars((string)$activeContract['file_path']); ?>" target="_blank"
                       class="block mt-2 text-[10px] font-black text-accent-green uppercase tracking-widest hover:underline">View agreement</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($activeContract['terms']): ?>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-4 pt-4 border-t border-slate-100 dark:border-slate-800 italic">
                <?php echo nl2br(htmlspecialchars((string)$activeContract['terms'])); ?>
            </p>
            <?php endif; ?>

            <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                <button onclick="openModal('endContractModal')"
                        class="px-4 py-2 bg-red-50 dark:bg-red-900/20 hover:bg-red-600 hover:text-white text-red-600 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                    End This Contract
                </button>
            </div>
        </div>
        <?php else: ?>
        <div class="glass-card py-12 text-center">
            <p class="text-slate-400 font-medium text-sm">No contract on record for this employee.</p>
        </div>
        <?php endif; ?>

        <?php
        $history = array_values(array_filter($contracts, fn($c) => !$activeContract || $c['id'] !== $activeContract['id']));
        if ($history):
        ?>
        <div class="glass-card overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Previous Terms</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-6 py-3 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                            <th class="px-6 py-3 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Period</th>
                            <th class="px-6 py-3 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Salary</th>
                            <th class="px-6 py-3 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                            <th class="px-6 py-3 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">File</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php foreach ($history as $c): ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                            <td class="px-6 py-4">
                                <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$c['contract_type']); ?></p>
                                <?php if ($c['job_title']): ?>
                                <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars((string)$c['job_title']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-500 whitespace-nowrap">
                                <?php echo date('d M Y', strtotime((string)$c['start_date'])); ?>
                                → <?php echo $c['end_date'] ? date('d M Y', strtotime((string)$c['end_date'])) : '—'; ?>
                            </td>
                            <td class="px-6 py-4 text-right text-xs font-black text-slate-900 dark:text-white">
                                <?php echo $c['gross_salary'] > 0 ? $currency . ' ' . number_format((float)$c['gross_salary']) : '—'; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="badge <?php
                                    echo match ($c['status']) {
                                        'Renewed'    => 'badge-blue',
                                        'Terminated' => 'badge-red',
                                        'Expired'    => 'badge-orange',
                                        default      => 'badge-green',
                                    }; ?> text-[9px]"><?php echo htmlspecialchars((string)$c['status']); ?></span>
                                <?php if ($c['ended_reason']): ?>
                                <p class="text-[10px] text-slate-400 mt-1 italic"><?php echo htmlspecialchars((string)$c['ended_reason']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <?php if ($c['file_path']): ?>
                                <a href="<?php echo htmlspecialchars((string)$c['file_path']); ?>" target="_blank"
                                   class="text-[10px] font-black text-accent-green uppercase tracking-widest hover:underline">Open</a>
                                <?php else: ?>
                                <span class="text-slate-300 dark:text-slate-700 text-xs">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div id="tab-salary" class="tab-panel <?php echo $activeTab !== 'salary' ? 'hidden' : ''; ?> space-y-5">
        <div class="flex justify-between items-center">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Salary Review History</h2>
            <button onclick="openModal('addSalaryModal')" class="btn-primary text-xs gap-2">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Record Review
            </button>
        </div>

        <?php if (empty($salaryHistory)): ?>
        <div class="glass-card p-16 text-center text-slate-400 italic font-medium">No salary reviews recorded yet.</div>
        <?php else: ?>
        <div class="glass-card overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/30">
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Effective Date</th>
                        <th class="px-6 py-4 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Previous</th>
                        <th class="px-6 py-4 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">New Salary</th>
                        <th class="px-6 py-4 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Change</th>
                        <th class="px-6 py-4 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Reason / Reviewed By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($salaryHistory as $sh):
                        $diff   = $sh['new_salary'] - $sh['old_salary'];
                        $pct    = $sh['old_salary'] > 0 ? round($diff / $sh['old_salary'] * 100, 1) : 0;
                        $isUp   = $diff > 0;
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                        <td class="px-6 py-4 font-bold text-slate-900 dark:text-white">
                            <?php echo date('d M Y', strtotime($sh['effective_date'])); ?>
                        </td>
                        <td class="px-6 py-4 text-right text-slate-500 font-medium">
                            <?php echo $currency; ?> <?php echo number_format((float)$sh['old_salary']); ?>
                        </td>
                        <td class="px-6 py-4 text-right font-black text-slate-900 dark:text-white">
                            <?php echo $currency; ?> <?php echo number_format((float)$sh['new_salary']); ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <span class="font-black text-sm <?php echo $isUp ? 'text-green-500' : 'text-red-500'; ?>">
                                <?php echo ($isUp ? '+' : '') . $pct; ?>%
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($sh['reason']): ?><p class="text-sm font-medium text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($sh['reason']); ?></p><?php endif; ?>
                            <?php if ($sh['reviewed_by']): ?><p class="text-[11px] text-slate-400 font-bold">By: <?php echo htmlspecialchars($sh['reviewed_by']); ?></p><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════ WARNINGS TAB ═══════════════════════ -->
    <div id="tab-warnings" class="tab-panel <?php echo $activeTab !== 'warnings' ? 'hidden' : ''; ?> space-y-5">
        <div class="flex justify-between items-center">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Warning Letters</h2>
            <button onclick="openModal('addWarningModal')" class="btn-primary text-xs gap-2">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Issue Warning
            </button>
        </div>

        <?php if (empty($warnings)): ?>
        <div class="glass-card p-16 text-center">
            <p class="text-slate-400 italic font-medium">No warnings on record. Keep it that way! 👍</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php
            $sevColors = [
                'Verbal'  => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 border-yellow-200 dark:border-yellow-800',
                'Written' => 'bg-orange-100 dark:bg-orange-900/30 text-orange-600 border-orange-200 dark:border-orange-800',
                'Final'   => 'bg-red-100 dark:bg-red-900/30 text-red-600 border-red-200 dark:border-red-800',
            ];
            foreach ($warnings as $w):
                $sc = $sevColors[$w['severity']] ?? $sevColors['Written'];
            ?>
            <div class="glass-card border <?php echo $sc; ?> p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 flex-wrap mb-2">
                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-widest <?php echo $sc; ?>">
                                <?php echo $w['severity']; ?> Warning
                            </span>
                            <span class="text-sm font-black text-slate-900 dark:text-white"><?php echo date('d M Y', strtotime($w['warning_date'])); ?></span>
                        </div>
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-300 mb-2"><?php echo htmlspecialchars($w['reason']); ?></p>
                        <?php if ($w['action_taken']): ?>
                        <p class="text-[11px] text-slate-500"><span class="font-black">Action taken:</span> <?php echo htmlspecialchars($w['action_taken']); ?></p>
                        <?php endif; ?>
                        <?php if ($w['issued_by']): ?>
                        <p class="text-[11px] text-slate-400 font-bold mt-1">Issued by: <?php echo htmlspecialchars($w['issued_by']); ?></p>
                        <?php endif; ?>
                        <?php if ($w['file_path']): ?>
                        <a href="<?php echo htmlspecialchars($w['file_path']); ?>" target="_blank"
                           class="inline-flex items-center gap-1 mt-2 text-[11px] font-black text-blue-500 hover:underline">
                            📎 View attachment
                        </a>
                        <?php endif; ?>
                    </div>
                    <form action="actions/hr_actions.php" method="POST" onsubmit="return confirm('Delete this warning letter?')">
                        <input type="hidden" name="action" value="delete_warning">
                        <input type="hidden" name="warning_id" value="<?php echo $w['id']; ?>">
                        <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
                        <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">
                        <button type="submit" class="text-slate-300 hover:text-red-500 transition-colors">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════ PAYROLL TAB ═══════════════════════ -->
<div id="tab-payroll" class="tab-panel <?php echo $activeTab !== 'payroll' ? 'hidden' : ''; ?>">

    <!-- Tax / Statutory Profile -->
    <div class="glass-card p-8 mb-6">
        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-5">Statutory & Tax Profile</h3>
        <form action="actions/payroll_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="save_tax_profile">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=payroll&success=tax_profile_saved">

            <!-- IDs -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">KRA PIN</label>
                    <input type="text" name="kra_pin" value="<?php echo htmlspecialchars($taxProfile['kra_pin'] ?? ''); ?>" placeholder="A0123456789B" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">NSSF Member No.</label>
                    <input type="text" name="nssf_number" value="<?php echo htmlspecialchars($taxProfile['nssf_number'] ?? ''); ?>" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SHIF/NHIF No.</label>
                    <input type="text" name="shif_number" value="<?php echo htmlspecialchars($taxProfile['shif_number'] ?? ''); ?>" class="form-input w-full">
                </div>
            </div>

            <!-- Deduction type toggles -->
            <div class="grid grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">NSSF Scheme</label>
                    <select name="nssf_type" class="form-input w-full">
                        <option value="new" <?php echo ($taxProfile['nssf_type'] ?? 'new') === 'new' ? 'selected' : ''; ?>>New (Tier I+II — 2023)</option>
                        <option value="old" <?php echo ($taxProfile['nssf_type'] ?? 'new') === 'old' ? 'selected' : ''; ?>>Old (KSh 200 flat)</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Health Levy</label>
                    <select name="use_shif" class="form-input w-full">
                        <option value="1" <?php echo ($taxProfile['use_shif'] ?? 1) ? 'selected' : ''; ?>>SHIF (2.75% of gross)</option>
                        <option value="0" <?php echo !($taxProfile['use_shif'] ?? 1) ? 'selected' : ''; ?>>Old NHIF (tiered)</option>
                    </select>
                </div>
            </div>

            <!-- Regular allowances -->
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Regular Allowances (added to basic salary each month)</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <?php foreach ([
                        ['house_allowance',     'House Allowance'],
                        ['transport_allowance', 'Transport Allow.'],
                        ['medical_allowance',   'Medical Allow.'],
                        ['other_allowances',    'Other Allowances'],
                    ] as [$fname, $flabel]): ?>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1"><?php echo $flabel; ?></label>
                        <input type="number" name="<?php echo $fname; ?>" step="0.01" min="0"
                               value="<?php echo number_format((float)($taxProfile[$fname] ?? 0), 2, '.', ''); ?>"
                               class="form-input w-full">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Recurring deductions -->
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Recurring Monthly Deductions</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <?php foreach ([
                        ['helb_amount',  'HELB (monthly)'],
                        ['loan_amount',  'Loan Repayment'],
                    ] as [$fname, $flabel]): ?>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1"><?php echo $flabel; ?></label>
                        <input type="number" name="<?php echo $fname; ?>" step="0.01" min="0"
                               value="<?php echo number_format((float)($taxProfile[$fname] ?? 0), 2, '.', ''); ?>"
                               class="form-input w-full">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Relief amounts -->
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Tax Relief (monthly amounts)</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Insurance Premiums (15% relief)</label>
                        <input type="number" name="insurance_premiums" step="0.01" min="0"
                               value="<?php echo number_format((float)($taxProfile['insurance_premiums'] ?? 0), 2, '.', ''); ?>"
                               class="form-input w-full" placeholder="Total premium paid">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Mortgage Interest (max KSh 25,000)</label>
                        <input type="number" name="mortgage_interest" step="0.01" min="0"
                               value="<?php echo number_format((float)($taxProfile['mortgage_interest'] ?? 0), 2, '.', ''); ?>"
                               class="form-input w-full">
                    </div>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="btn-green px-8 py-2.5">Save Tax Profile</button>
            </div>
        </form>
    </div>

    <!-- Payslip history -->
    <div class="glass-card overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
            <h3 class="font-bold text-slate-900 dark:text-white">Payslip History</h3>
            <a href="p9.php?year=<?php echo date('Y'); ?>&employee_id=<?php echo urlencode($empId); ?>" target="_blank"
               class="text-xs text-blue-500 hover:underline font-bold">📄 P9 (<?php echo date('Y'); ?>)</a>
        </div>
        <?php if (!$payslips): ?>
        <div class="p-8 text-center text-slate-400 text-sm">No finalised payslips yet.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-700 text-xs text-slate-500 uppercase tracking-wide">
                        <th class="px-5 py-2 text-left">Period</th>
                        <th class="px-5 py-2 text-right">Gross</th>
                        <th class="px-5 py-2 text-right">PAYE</th>
                        <th class="px-5 py-2 text-right font-bold">Net Pay</th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($payslips as $ps): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20">
                        <td class="px-5 py-2 font-semibold text-slate-900 dark:text-white">
                            <?php echo monthName((int)$ps['period_month']) . ' ' . $ps['period_year']; ?>
                        </td>
                        <td class="px-5 py-2 text-right text-slate-500"><?php echo number_format($ps['gross_pay'], 2); ?></td>
                        <td class="px-5 py-2 text-right text-red-500"><?php echo number_format($ps['paye'], 2); ?></td>
                        <td class="px-5 py-2 text-right font-bold text-green-600 dark:text-green-400"><?php echo number_format($ps['net_pay'], 2); ?></td>
                        <td class="px-5 py-2 text-right">
                            <a href="payslip.php?entry_id=<?php echo $ps['entry_id']; ?>" target="_blank" class="text-xs text-blue-500 hover:underline">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════ LOANS & ADVANCES TAB ═══════════════════════ -->
<?php
$colorMap = [
    'green'  => 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
    'orange' => 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400',
    'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
    'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
    'purple' => 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400',
    'pink'   => 'bg-pink-100 dark:bg-pink-900/30 text-pink-700 dark:text-pink-400',
    'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400',
    'slate'  => 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
];
$isAdmin = $_SESSION['role'] === 'admin';
?>
<div id="tab-loans" class="tab-panel <?php echo $activeTab !== 'loans' ? 'hidden' : ''; ?>">
    <!-- Summary cards -->
    <?php
    $activeLoans = array_filter($loans, fn($l) => $l['status'] === 'Active');
    $totalBalance = array_sum(array_column($activeLoans, 'balance_remaining'));
    $pendingLoans = count(array_filter($loans, fn($l) => $l['status'] === 'Pending'));
    ?>
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="glass-card p-4 text-center">
            <p class="text-xs text-slate-500 mb-1">Active Loans</p>
            <p class="text-2xl font-black text-blue-400"><?php echo count($activeLoans); ?></p>
        </div>
        <div class="glass-card p-4 text-center">
            <p class="text-xs text-slate-500 mb-1">Total Outstanding</p>
            <p class="text-xl font-black text-red-400">KSh <?php echo number_format($totalBalance, 2); ?></p>
        </div>
        <div class="glass-card p-4 text-center">
            <p class="text-xs text-slate-500 mb-1">Pending</p>
            <p class="text-2xl font-black text-orange-400"><?php echo $pendingLoans; ?></p>
        </div>
    </div>

    <div class="flex justify-end mb-4">
        <button onclick="openModal('applyLoanModal')" class="btn-primary">+ Apply Loan/Advance</button>
    </div>

    <?php if (!$loans): ?>
    <div class="glass-card p-12 text-center text-slate-400">
        <svg class="w-10 h-10 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <p>No loan or advance records yet.</p>
    </div>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($loans as $l):
            $stCls = match($l['status']) {
                'Active'    => 'badge badge-blue',
                'Completed' => 'badge badge-green',
                'Rejected'  => 'badge badge-red',
                default     => 'badge badge-orange',
            };
            $pct = ($l['approved_amount'] > 0)
                ? min(100, round((($l['approved_amount'] - $l['balance_remaining']) / $l['approved_amount']) * 100))
                : 0;
        ?>
        <div class="glass-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="badge <?php echo $l['loan_type'] === 'Advance' ? 'badge-orange' : 'badge-blue'; ?>"><?php echo $l['loan_type']; ?></span>
                        <span class="<?php echo $stCls; ?>"><?php echo $l['status']; ?></span>
                    </div>
                    <p class="font-bold text-slate-900 dark:text-white">KSh <?php echo number_format($l['amount'], 2); ?> applied<?php if ($l['approved_amount']): ?> · KSh <?php echo number_format($l['approved_amount'], 2); ?> approved<?php endif; ?></p>
                    <?php if ($l['purpose']): ?><p class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars($l['purpose']); ?></p><?php endif; ?>
                </div>
                <div class="text-right text-xs text-slate-400">
                    <p>Applied: <?php echo date('d M Y', strtotime($l['applied_date'])); ?></p>
                    <?php if ($l['disbursed_date']): ?><p>Disbursed: <?php echo date('d M Y', strtotime($l['disbursed_date'])); ?></p><?php endif; ?>
                    <?php if ($l['monthly_deduction'] > 0): ?><p class="font-semibold text-orange-500">KSh <?php echo number_format($l['monthly_deduction'], 2); ?>/month</p><?php endif; ?>
                </div>
            </div>

            <?php if ($l['status'] === 'Active'): ?>
            <div class="mb-3">
                <div class="flex justify-between text-xs text-slate-500 mb-1">
                    <span>Repaid: KSh <?php echo number_format($l['approved_amount'] - $l['balance_remaining'], 2); ?></span>
                    <span>Remaining: <strong class="text-red-500">KSh <?php echo number_format($l['balance_remaining'], 2); ?></strong></span>
                </div>
                <div class="h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                    <div class="h-full bg-green-500 rounded-full transition-all" style="width:<?php echo $pct; ?>%"></div>
                </div>
                <p class="text-[10px] text-slate-400 mt-0.5 text-right"><?php echo $pct; ?>% repaid</p>
            </div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
            <div class="flex gap-2 flex-wrap">
                <?php if ($l['status'] === 'Pending'): ?>
                <button onclick="openApproveLoan('<?php echo $l['id']; ?>', <?php echo $l['amount']; ?>)" class="text-xs font-bold text-green-500 hover:underline">Approve</button>
                <form method="POST" action="actions/loan_actions.php" onsubmit="return confirm('Reject this application?')" class="inline">
                    <input type="hidden" name="action" value="reject_loan">
                    <input type="hidden" name="loan_id" value="<?php echo $l['id']; ?>">
                    <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=loans&success=loan_rejected">
                    <button class="text-xs font-bold text-red-500 hover:underline">Reject</button>
                </form>
                <form method="POST" action="actions/loan_actions.php" onsubmit="return confirm('Delete this application?')" class="inline">
                    <input type="hidden" name="action" value="delete_loan">
                    <input type="hidden" name="loan_id" value="<?php echo $l['id']; ?>">
                    <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=loans">
                    <button class="text-xs font-bold text-slate-400 hover:text-red-500">Delete</button>
                </form>
                <?php elseif ($l['status'] === 'Active'): ?>
                <button onclick="openRepayLoan('<?php echo $l['id']; ?>', <?php echo $l['balance_remaining']; ?>)" class="text-xs font-bold text-blue-500 hover:underline">Record Repayment</button>
                <form method="POST" action="actions/loan_actions.php" onsubmit="return confirm('Mark as fully paid?')" class="inline">
                    <input type="hidden" name="action" value="close_loan">
                    <input type="hidden" name="loan_id" value="<?php echo $l['id']; ?>">
                    <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=loans&success=loan_closed">
                    <button class="text-xs font-bold text-slate-400 hover:underline">Mark Complete</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($l['notes']): ?><p class="text-[10px] text-slate-400 mt-2 italic"><?php echo htmlspecialchars($l['notes']); ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════ BANK DETAILS TAB ═══════════════════════ -->
<?php
$kenyaBanks = [
    'KCB Bank Kenya','Equity Bank Kenya','Co-operative Bank of Kenya','NCBA Bank Kenya',
    'Absa Bank Kenya','Standard Chartered Bank Kenya','Diamond Trust Bank (DTB)','I&M Bank',
    'Family Bank','Stanbic Bank Kenya','Prime Bank Kenya','Bank of Africa Kenya',
    'Sidian Bank','HF Group (Housing Finance)','Guaranty Trust Bank Kenya','SBM Bank Kenya',
    'M-Pesa Paybill','M-Pesa Till / Buy Goods','Airtel Money','T-Kash',
];
sort($kenyaBanks);
?>
<div id="tab-bank" class="tab-panel <?php echo $activeTab !== 'bank' ? 'hidden' : ''; ?>">

    <div class="flex items-center justify-between mb-5">
        <h3 class="text-lg font-black text-slate-900 dark:text-white">Bank &amp; Payment Accounts</h3>
        <?php if ($isAdmin): ?>
        <button onclick="openBankModal()" class="btn-primary text-sm">+ Add Account</button>
        <?php endif; ?>
    </div>

    <?php if (empty($bankAccounts)): ?>
    <div class="glass-card p-10 text-center">
        <svg class="w-12 h-12 mx-auto text-slate-300 dark:text-slate-600 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/>
        </svg>
        <p class="text-slate-500 font-medium">No bank accounts on record.</p>
        <?php if ($isAdmin): ?>
        <button onclick="openBankModal()" class="btn-primary mt-4 text-sm">Add Bank Account</button>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($bankAccounts as $ba): ?>
        <div class="glass-card p-5 flex flex-col gap-3 relative">
            <?php if ($ba['is_primary']): ?>
            <span class="absolute top-4 right-4 badge badge-green text-[10px]">Primary</span>
            <?php endif; ?>
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-blue-500">
                        <rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-black text-slate-900 dark:text-white text-sm leading-tight"><?php echo htmlspecialchars($ba['bank_name']); ?></p>
                    <?php if ($ba['branch_name']): ?>
                    <p class="text-[11px] text-slate-400"><?php echo htmlspecialchars($ba['branch_name']); ?> Branch</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                <div>
                    <span class="text-slate-400 font-bold uppercase tracking-wider text-[10px]">Account Name</span>
                    <p class="font-black text-slate-700 dark:text-slate-200 mt-0.5"><?php echo htmlspecialchars($ba['account_name']); ?></p>
                </div>
                <div>
                    <span class="text-slate-400 font-bold uppercase tracking-wider text-[10px]">Account No.</span>
                    <p class="font-black text-slate-700 dark:text-slate-200 mt-0.5 font-mono">
                        <?php
                        $no = $ba['account_no'];
                        echo strlen($no) > 4 ? str_repeat('*', strlen($no)-4) . substr($no, -4) : $no;
                        ?>
                    </p>
                </div>
                <div>
                    <span class="text-slate-400 font-bold uppercase tracking-wider text-[10px]">Type</span>
                    <p class="font-black text-slate-700 dark:text-slate-200 mt-0.5"><?php echo htmlspecialchars($ba['account_type']); ?></p>
                </div>
                <?php if ($ba['swift_code']): ?>
                <div>
                    <span class="text-slate-400 font-bold uppercase tracking-wider text-[10px]">SWIFT/BIC</span>
                    <p class="font-black text-slate-700 dark:text-slate-200 mt-0.5 font-mono"><?php echo htmlspecialchars($ba['swift_code']); ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($isAdmin): ?>
            <div class="flex gap-2 pt-1 border-t border-slate-100 dark:border-slate-700">
                <button onclick="openBankModal(<?php echo htmlspecialchars(json_encode($ba)); ?>)"
                        class="text-[11px] font-black text-blue-500 hover:text-blue-700 transition-colors">Edit</button>
                <?php if (!$ba['is_primary']): ?>
                <form method="POST" action="actions/bank_actions.php" class="inline">
                    <input type="hidden" name="action" value="set_primary">
                    <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
                    <input type="hidden" name="bank_id" value="<?php echo $ba['id']; ?>">
                    <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=bank&success=bank_primary_set">
                    <button type="submit" class="text-[11px] font-black text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">Set Primary</button>
                </form>
                <?php endif; ?>
                <form method="POST" action="actions/bank_actions.php" class="inline ml-auto"
                      onsubmit="return confirm('Remove this bank account?')">
                    <input type="hidden" name="action" value="delete_bank">
                    <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
                    <input type="hidden" name="bank_id" value="<?php echo $ba['id']; ?>">
                    <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=bank&success=bank_deleted">
                    <button type="submit" class="text-[11px] font-black text-red-400 hover:text-red-600 transition-colors">Remove</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════ LEAVE TAB ═══════════════════════ -->
<div id="tab-leave" class="tab-panel <?php echo $activeTab !== 'leave' ? 'hidden' : ''; ?>">

    <!-- Year selector -->
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-2">
            <a href="?id=<?php echo urlencode($empId); ?>&tab=leave&leave_year=<?php echo $leaveYear-1; ?>"
               class="w-8 h-8 flex items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white transition-all">‹</a>
            <span class="font-black text-slate-900 dark:text-white text-lg w-16 text-center"><?php echo $leaveYear; ?></span>
            <a href="?id=<?php echo urlencode($empId); ?>&tab=leave&leave_year=<?php echo $leaveYear+1; ?>"
               class="w-8 h-8 flex items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white transition-all">›</a>
        </div>
        <button onclick="openModal('applyLeaveEmpModal')" class="btn-primary text-sm">+ Apply Leave</button>
    </div>

    <!-- Leave balance cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
        <?php foreach ($leaveBalances as $b):
            $remaining = $b['entitlement'] - $b['used'];
            $pct = $b['entitlement'] > 0 ? min(100, round(($b['used'] / $b['entitlement']) * 100)) : 0;
            $ltc = $colorMap[$b['lt_color']] ?? $colorMap['slate'];
        ?>
        <div class="glass-card p-4">
            <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold <?php echo $ltc; ?> block mb-2 w-fit"><?php echo htmlspecialchars($b['lt_name']); ?></span>
            <?php if ($b['days_per_year'] > 0): ?>
            <p class="text-xs text-slate-500 mb-0.5">Entitlement: <strong class="text-slate-700 dark:text-slate-200"><?php echo $b['entitlement']; ?> days</strong></p>
            <p class="text-xs text-slate-500 mb-2">Used: <strong class="text-orange-500"><?php echo $b['used']; ?></strong> · Left: <strong class="<?php echo $remaining > 5 ? 'text-green-600 dark:text-green-400' : 'text-red-500'; ?>"><?php echo $remaining; ?></strong></p>
            <div class="h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full <?php echo $pct > 80 ? 'bg-red-500' : 'bg-green-500'; ?> rounded-full" style="width:<?php echo $pct; ?>%"></div>
            </div>
            <?php if ($isAdmin): ?>
            <button onclick="openAdjustBalance('<?php echo $b['leave_type_id']; ?>', '<?php echo htmlspecialchars($b['lt_name'], ENT_QUOTES); ?>', <?php echo $b['entitlement']; ?>)" class="text-[10px] text-blue-500 hover:underline mt-1 block font-bold">Adjust</button>
            <?php endif; ?>
            <?php else: ?>
            <p class="text-xs text-slate-500">Unpaid / Unlimited</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Leave history -->
    <div class="glass-card overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 text-sm font-bold text-slate-900 dark:text-white">
            Leave History
        </div>
        <?php if (!$leaveApps): ?>
        <div class="p-8 text-center text-slate-400 text-sm">No leave applications yet.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-700 text-[10px] text-slate-500 uppercase tracking-wide bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">From</th>
                        <th class="px-4 py-2 text-left">To</th>
                        <th class="px-4 py-2 text-right">Days</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Reviewed By</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($leaveApps as $a):
                        $ltc = $colorMap[$a['lt_color']] ?? $colorMap['slate'];
                        $stCls = match($a['status']) {
                            'Approved'  => 'badge badge-green',
                            'Rejected'  => 'badge badge-red',
                            'Cancelled' => 'badge badge-slate',
                            default     => 'badge badge-orange',
                        };
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20 transition-colors">
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-lg text-[10px] font-bold <?php echo $ltc; ?>"><?php echo htmlspecialchars($a['lt_name']); ?></span></td>
                        <td class="px-4 py-2 text-xs"><?php echo date('d M Y', strtotime($a['start_date'])); ?></td>
                        <td class="px-4 py-2 text-xs"><?php echo date('d M Y', strtotime($a['end_date'])); ?></td>
                        <td class="px-4 py-2 text-right font-bold text-xs"><?php echo $a['days_requested']; ?></td>
                        <td class="px-4 py-2"><span class="<?php echo $stCls; ?>"><?php echo $a['status']; ?></span></td>
                        <td class="px-4 py-2 text-xs text-slate-400"><?php echo htmlspecialchars($a['reviewed_by'] ?: '—'); ?></td>
                        <td class="px-4 py-2">
                            <?php if ($a['status'] === 'Pending' && $isAdmin): ?>
                            <div class="flex gap-2">
                                <form method="POST" action="actions/leave_actions.php" class="inline">
                                    <input type="hidden" name="action" value="approve_leave">
                                    <input type="hidden" name="application_id" value="<?php echo $a['id']; ?>">
                                    <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=leave&success=leave_approved">
                                    <button class="text-[10px] font-bold text-green-500 hover:underline">✓ Approve</button>
                                </form>
                                <form method="POST" action="actions/leave_actions.php" class="inline">
                                    <input type="hidden" name="action" value="reject_leave">
                                    <input type="hidden" name="application_id" value="<?php echo $a['id']; ?>">
                                    <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=leave&success=leave_rejected">
                                    <button class="text-[10px] font-bold text-red-500 hover:underline">✗ Reject</button>
                                </form>
                            </div>
                            <?php elseif (in_array($a['status'], ['Pending','Approved'])): ?>
                            <form method="POST" action="actions/leave_actions.php" onsubmit="return confirm('Cancel this leave?')" class="inline">
                                <input type="hidden" name="action" value="cancel_leave">
                                <input type="hidden" name="application_id" value="<?php echo $a['id']; ?>">
                                <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=leave&success=leave_cancelled">
                                <button class="text-[10px] font-bold text-slate-400 hover:text-red-500">Cancel</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════ LOAN APPLICATION MODAL ══════════ -->
<div id="applyLoanModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Apply Loan / Advance</h3>
            <button onclick="closeModal('applyLoanModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/loan_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="apply_loan">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=loans&success=loan_applied">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Type</label>
                    <select name="loan_type" class="form-input w-full">
                        <option value="Loan">Staff Loan</option>
                        <option value="Advance">Salary Advance</option>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Amount (KSh)</label>
                    <input type="number" name="amount" step="0.01" min="1" required class="form-input w-full" placeholder="0.00">
                </div>
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Applied Date</label>
                <input type="date" name="applied_date" class="form-input w-full" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Purpose / Reason</label>
                <textarea name="purpose" rows="3" class="form-input w-full" placeholder="Reason for the loan or advance..."></textarea>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary flex-1">Submit Application</button>
                <button type="button" onclick="closeModal('applyLoanModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ APPROVE LOAN MODAL ══════════ -->
<div id="approveLoanModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-green-600 dark:text-green-400">Approve Loan</h3>
            <button onclick="closeModal('approveLoanModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/loan_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="approve_loan">
            <input type="hidden" name="loan_id" id="approve_loan_id">
            <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=loans&success=loan_approved">
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Approved Amount (KSh)</label>
                <input type="number" name="approved_amount" id="approve_loan_amount" step="0.01" min="1" required class="form-input w-full">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Monthly Deduction</label>
                    <input type="number" name="monthly_deduction" step="0.01" min="0" class="form-input w-full" placeholder="0.00">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Disburse Date</label>
                    <input type="date" name="disbursed_date" class="form-input w-full" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Approved By</label>
                <input type="text" name="approved_by" class="form-input w-full" placeholder="Approver name">
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Notes</label>
                <textarea name="notes" rows="2" class="form-input w-full"></textarea>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-green flex-1">Approve</button>
                <button type="button" onclick="closeModal('approveLoanModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ REPAY LOAN MODAL ══════════ -->
<div id="repayLoanModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-blue-600 dark:text-blue-400">Record Repayment</h3>
            <button onclick="closeModal('repayLoanModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/loan_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="repay_loan">
            <input type="hidden" name="loan_id" id="repay_loan_id">
            <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=loans&success=loan_repaid">
            <p class="text-sm text-slate-500">Balance remaining: <strong id="repay_balance" class="text-red-500"></strong></p>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Repayment Amount (KSh)</label>
                <input type="number" name="amount" id="repay_amount" step="0.01" min="0.01" required class="form-input w-full" placeholder="0.00">
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">Record</button>
                <button type="button" onclick="closeModal('repayLoanModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ APPLY LEAVE MODAL (employee tab) ══════════ -->
<div id="applyLeaveEmpModal" class="modal-overlay hidden">
    <div class="modal-card max-w-lg w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Apply for Leave</h3>
            <button onclick="closeModal('applyLeaveEmpModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/leave_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="apply_leave">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=leave&leave_year=<?php echo $leaveYear; ?>&success=leave_applied">
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Leave Type</label>
                <select name="leave_type_id" required class="form-input w-full">
                    <option value="">— Select Type —</option>
                    <?php foreach ($leaveTypes as $lt):
                        $bal = array_values(array_filter($leaveBalances, fn($b) => $b['leave_type_id'] === $lt['id']));
                        $rem = $bal ? ($bal[0]['entitlement'] - $bal[0]['used']) : $lt['days_per_year'];
                    ?>
                    <option value="<?php echo $lt['id']; ?>">
                        <?php echo htmlspecialchars($lt['name']); ?> (<?php echo $lt['days_per_year'] > 0 ? $rem . ' days left' : 'Unpaid'; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Start Date</label>
                    <input type="date" name="start_date" required class="form-input w-full" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">End Date</label>
                    <input type="date" name="end_date" required class="form-input w-full" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Reason</label>
                <textarea name="reason" rows="3" class="form-input w-full" placeholder="Brief reason for leave..."></textarea>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary flex-1">Submit</button>
                <button type="button" onclick="closeModal('applyLeaveEmpModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ ADJUST BALANCE MODAL ══════════ -->
<?php if ($isAdmin): ?>
<div id="adjustBalanceModal" class="modal-overlay hidden">
    <div class="modal-card max-w-sm w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Adjust Leave Balance</h3>
            <button onclick="closeModal('adjustBalanceModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/leave_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="year" value="<?php echo $leaveYear; ?>">
            <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=leave&leave_year=<?php echo $leaveYear; ?>&success=balance_adjusted">
            <input type="hidden" name="leave_type_id" id="adj_type_id">
            <p class="text-sm text-slate-600 dark:text-slate-400">Adjusting <strong id="adj_type_name"></strong> entitlement for <?php echo $leaveYear; ?></p>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Entitlement (days)</label>
                <input type="number" name="entitlement" id="adj_entitlement" min="0" required class="form-input w-full">
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">Save</button>
                <button type="button" onclick="closeModal('adjustBalanceModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function openAdjustBalance(typeId, typeName, entitlement) {
    document.getElementById('adj_type_id').value    = typeId;
    document.getElementById('adj_type_name').textContent = typeName;
    document.getElementById('adj_entitlement').value = entitlement;
    openModal('adjustBalanceModal');
}
</script>
<?php endif; ?>

<script>
function openApproveLoan(loanId, amount) {
    document.getElementById('approve_loan_id').value    = loanId;
    document.getElementById('approve_loan_amount').value = amount;
    openModal('approveLoanModal');
}
function openRepayLoan(loanId, balance) {
    document.getElementById('repay_loan_id').value = loanId;
    document.getElementById('repay_balance').textContent = 'KSh ' + parseFloat(balance).toLocaleString('en-KE', {minimumFractionDigits:2});
    document.getElementById('repay_amount').value = balance;
    openModal('repayLoanModal');
}
</script>

<!-- ══════════ UPLOAD DOCUMENT MODAL ══════════ -->
<div id="uploadDocModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('uploadDocModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-6">Upload Document</h2>
        <form action="actions/hr_actions.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="action" value="upload_document">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Document Type</label>
                <select name="doc_type" id="docTypeSel" onchange="updateDocName()" class="form-input w-full">
                    <option value="id_copy">Copy of National ID</option>
                    <option value="agreement">Employment Agreement</option>
                    <option value="academic">Academic Certificates</option>
                    <option value="other">Other Document</option>
                </select>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Document Label</label>
                <input type="text" name="doc_name" id="docNameInput" value="Copy of National ID" class="form-input w-full" placeholder="e.g. Copy of National ID">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">File <span class="normal-case font-medium text-slate-400">(PDF, JPG, PNG — max 10MB)</span></label>
                <input type="file" name="document_file" required accept=".pdf,.jpg,.jpeg,.png,.webp"
                       class="w-full text-sm text-slate-700 dark:text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-accent-green file:text-white hover:file:bg-accent-green/90 cursor-pointer">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-3">Upload</button>
        </form>
    </div>
</div>

<!-- ══════════ ADD CONTACT MODAL ══════════ -->
<div id="addContactModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('addContactModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-6">Add Contact</h2>
        <form action="actions/hr_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="add_contact">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name</label>
                <input type="text" name="name" required placeholder="Jane Doe" class="form-input w-full">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone</label>
                    <input type="text" name="phone" required placeholder="+254 7xx xxx xxx" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Relationship</label>
                    <input type="text" name="relationship" placeholder="e.g. Spouse, Parent" class="form-input w-full">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Address (optional)</label>
                <input type="text" name="address" placeholder="Home address" class="form-input w-full">
            </div>
            <label class="flex items-center gap-3 cursor-pointer p-4 rounded-2xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/20">
                <input type="checkbox" name="is_next_of_kin" value="1" class="w-4 h-4 rounded accent-red-500">
                <div>
                    <p class="text-sm font-black text-slate-900 dark:text-white">Mark as Next of Kin</p>
                    <p class="text-[10px] text-slate-400 font-medium">Primary emergency contact</p>
                </div>
            </label>
            <button type="submit" class="btn-green w-full justify-center py-3">Add Contact</button>
        </form>
    </div>
</div>

<!-- ══════════ SALARY REVIEW MODAL ══════════ -->
<div id="addSalaryModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('addSalaryModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-6">Record Salary Review</h2>
        <form action="actions/hr_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="add_salary_review">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Effective Date</label>
                <input type="date" name="effective_date" required value="<?php echo date('Y-m-d'); ?>" class="form-input w-full">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Previous Salary</label>
                    <input type="number" name="old_salary" step="0.01" value="<?php echo (float)$emp['salary']; ?>" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">New Salary</label>
                    <input type="number" name="new_salary" step="0.01" required class="form-input w-full" placeholder="0.00">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Reason for Review</label>
                <textarea name="reason" rows="2" placeholder="e.g. Annual increment, promotion..." class="form-input w-full resize-none"></textarea>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Reviewed By</label>
                <input type="text" name="reviewed_by" placeholder="Name / Title" class="form-input w-full">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-3">Save Review</button>
        </form>
    </div>
</div>

<!-- ══════════ WARNING MODAL ══════════ -->
<div id="addWarningModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('addWarningModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-6">Issue Warning Letter</h2>
        <form action="actions/hr_actions.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="action" value="add_warning">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Date</label>
                    <input type="date" name="warning_date" required value="<?php echo date('Y-m-d'); ?>" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Severity</label>
                    <select name="severity" class="form-input w-full">
                        <option value="Verbal">Verbal Warning</option>
                        <option value="Written" selected>Written Warning</option>
                        <option value="Final">Final Warning</option>
                    </select>
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Reason / Misconduct</label>
                <textarea name="reason" required rows="3" placeholder="Describe the misconduct or reason for the warning..." class="form-input w-full resize-none"></textarea>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Action Taken / Expected</label>
                <textarea name="action_taken" rows="2" placeholder="Consequences if repeated, etc." class="form-input w-full resize-none"></textarea>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Issued By</label>
                <input type="text" name="issued_by" placeholder="Manager / Director name" class="form-input w-full">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Attach Letter <span class="normal-case font-medium text-slate-400">(PDF/JPG optional)</span></label>
                <input type="file" name="warning_file" accept=".pdf,.jpg,.jpeg,.png"
                       class="w-full text-sm text-slate-700 dark:text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-orange-500 file:text-white hover:file:bg-orange-600 cursor-pointer">
            </div>
            <button type="submit" class="w-full py-3 rounded-2xl bg-orange-500 hover:bg-orange-600 text-white font-black uppercase tracking-widest text-xs transition-all">
                Issue Warning
            </button>
        </form>
    </div>
</div>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('bg-white','dark:bg-slate-700','text-slate-900','dark:text-white','shadow-sm');
        b.classList.add('text-slate-400');
    });
    document.getElementById('tab-' + name).classList.remove('hidden');
    const btn = document.getElementById('tab-btn-' + name);
    btn.classList.add('bg-white','dark:bg-slate-700','text-slate-900','dark:text-white','shadow-sm');
    btn.classList.remove('text-slate-400');
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', name);
    window.history.replaceState({}, '', url);
}

function toggleContractDate() {
    const sel  = document.getElementById('empStatusSel');
    const wrap = document.getElementById('contractDateWrap');
    // Every fixed-term engagement needs an end date, not just Contract/Probation
    wrap.style.display = <?php echo json_encode(HR_FIXED_TERM_STATUSES); ?>.includes(sel.value) ? '' : 'none';
}

const docLabels = {
    id_copy: 'Copy of National ID',
    agreement: 'Employment Agreement',
    academic: 'Academic Certificates',
    other: 'Other Document',
};
function updateDocName() {
    const sel = document.getElementById('docTypeSel');
    document.getElementById('docNameInput').value = docLabels[sel.value] || '';
}
</script>

<?php if ($isAdmin): ?>
<!-- ══════════ BANK ACCOUNT MODAL ══════════ -->
<div id="bankModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-lg w-full">
        <button onclick="closeModal('bankModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-6" id="bankModalTitle">Add Bank Account</h2>
        <form method="POST" action="actions/bank_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="save_bank">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="hr_employee.php?id=<?php echo urlencode($empId); ?>&tab=bank&success=bank_added">
            <input type="hidden" name="bank_id" id="bk_id">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2 space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Bank / Institution</label>
                    <select name="bank_name" id="bk_bank_name" required class="form-input w-full">
                        <option value="">— Select bank —</option>
                        <?php foreach ($kenyaBanks as $bn): ?>
                        <option value="<?php echo htmlspecialchars($bn); ?>"><?php echo htmlspecialchars($bn); ?></option>
                        <?php endforeach; ?>
                        <option value="__other__">Other (type below)</option>
                    </select>
                </div>
                <div class="sm:col-span-2 space-y-2 hidden" id="bk_other_wrap">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Bank Name (Other)</label>
                    <input type="text" id="bk_other_name" class="form-input w-full" placeholder="Enter bank or institution name">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Branch</label>
                    <input type="text" name="branch_name" id="bk_branch" placeholder="e.g. Westlands" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Account Type</label>
                    <select name="account_type" id="bk_type" class="form-input w-full">
                        <option value="Savings">Savings</option>
                        <option value="Current">Current</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Mobile Money">Mobile Money</option>
                    </select>
                </div>
                <div class="sm:col-span-2 space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Account Holder Name</label>
                    <input type="text" name="account_name" id="bk_acname" required placeholder="Full name on account" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Account Number / Phone</label>
                    <input type="text" name="account_no" id="bk_acno" required placeholder="Account or mobile number" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">SWIFT / BIC Code <span class="font-medium normal-case text-slate-400">(optional)</span></label>
                    <input type="text" name="swift_code" id="bk_swift" placeholder="e.g. KCBLKENX" class="form-input w-full uppercase">
                </div>
            </div>

            <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-900/20">
                <input type="checkbox" name="is_primary" value="1" id="bk_primary" class="w-4 h-4 rounded accent-green-500">
                <div>
                    <p class="text-sm font-black text-slate-900 dark:text-white">Set as Primary Account</p>
                    <p class="text-[10px] text-slate-400 font-medium">Used for payroll disbursement</p>
                </div>
            </label>

            <input type="hidden" name="bank_name" id="bk_bank_name_hidden">
            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary flex-1" onclick="resolveBankName()">Save Account</button>
                <button type="button" onclick="closeModal('bankModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function openBankModal(acct) {
    document.getElementById('bankModalTitle').textContent = acct ? 'Edit Bank Account' : 'Add Bank Account';
    document.getElementById('bk_id').value         = acct ? acct.id : '';
    document.getElementById('bk_branch').value      = acct ? (acct.branch_name || '') : '';
    document.getElementById('bk_acname').value      = acct ? acct.account_name : '';
    document.getElementById('bk_acno').value        = acct ? acct.account_no : '';
    document.getElementById('bk_swift').value       = acct ? (acct.swift_code || '') : '';
    document.getElementById('bk_primary').checked   = acct ? acct.is_primary == 1 : false;
    document.getElementById('bk_other_wrap').classList.add('hidden');
    document.getElementById('bk_other_name').value  = '';

    const sel = document.getElementById('bk_bank_name');
    const typeEl = document.getElementById('bk_type');
    if (acct) {
        // Try to match existing option
        const opt = Array.from(sel.options).find(o => o.value === acct.bank_name);
        if (opt) { sel.value = acct.bank_name; }
        else { sel.value = '__other__'; document.getElementById('bk_other_name').value = acct.bank_name; document.getElementById('bk_other_wrap').classList.remove('hidden'); }
        typeEl.value = acct.account_type || 'Savings';

        // When editing show full account number
        document.getElementById('bk_acno').value = acct.account_no;
    } else {
        sel.value = '';
        typeEl.value = 'Savings';
    }
    openModal('bankModal');
}
document.getElementById('bk_bank_name').addEventListener('change', function() {
    const wrap = document.getElementById('bk_other_wrap');
    if (this.value === '__other__') { wrap.classList.remove('hidden'); }
    else { wrap.classList.add('hidden'); document.getElementById('bk_other_name').value = ''; }
});
function resolveBankName() {
    const sel = document.getElementById('bk_bank_name');
    const hidden = document.getElementById('bk_bank_name_hidden');
    if (sel.value === '__other__') {
        hidden.name = 'bank_name';
        sel.name    = '';
        hidden.value = document.getElementById('bk_other_name').value;
    } else {
        sel.name    = 'bank_name';
        hidden.name = '';
    }
}
</script>
<?php endif; ?>

<!-- ══════════ ADD / RENEW CONTRACT MODAL ══════════ -->
<div id="contractModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-xl max-h-[90vh] overflow-y-auto">
        <button onclick="closeModal('contractModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2"><?php echo $activeContract ? 'Renew Contract' : 'Add Contract'; ?></h2>
        <p class="text-sm text-slate-400 font-medium mb-6">
            <?php if ($activeContract): ?>
            The current term is kept on file and marked Renewed. A change in salary is also recorded as a pay review.
            <?php else: ?>
            Record the term this employee is engaged on.
            <?php endif; ?>
        </p>

        <form action="actions/hr_actions.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="<?php echo $activeContract ? 'renew_contract' : 'add_contract'; ?>">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Engagement Type *</label>
                    <select name="contract_type" id="ctType" onchange="ctToggleEnd()" class="form-input w-full">
                        <?php foreach (HR_EMPLOYMENT_STATUSES as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo ($activeContract['contract_type'] ?? $emp['employment_status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Job Title</label>
                    <input type="text" name="job_title" value="<?php echo htmlspecialchars($emp['role'] ?? ''); ?>" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Start Date *</label>
                    <input type="date" name="start_date" required
                           value="<?php echo $activeContract && $activeContract['end_date']
                                ? date('Y-m-d', strtotime($activeContract['end_date'] . ' +1 day'))
                                : date('Y-m-d'); ?>" class="form-input w-full">
                </div>
                <div class="space-y-2" id="ctEndWrap">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">End Date *</label>
                    <input type="date" name="end_date" id="ctEnd" class="form-input w-full">
                </div>
                <div class="col-span-2 space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Gross Salary (<?php echo $currency; ?>)</label>
                    <input type="number" name="gross_salary" step="0.01" min="0"
                           value="<?php echo $emp['salary'] ?? ''; ?>" class="form-input w-full">
                    <p class="text-[10px] text-slate-400 px-1">Changing this records a salary review automatically.</p>
                </div>
                <div class="col-span-2 space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Terms / Notes</label>
                    <textarea name="terms" rows="2" class="form-input w-full resize-none" placeholder="Notice period, probation, specific conditions…"></textarea>
                </div>
                <div class="col-span-2 space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Signed Agreement</label>
                    <input type="file" name="contract_file" accept=".pdf,.jpg,.jpeg,.png,.webp"
                           class="form-input w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[10px] file:font-black file:uppercase file:bg-slate-100 dark:file:bg-slate-800 file:text-slate-600 dark:file:text-slate-300">
                </div>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-3.5 text-xs">
                <?php echo $activeContract ? 'Renew Contract' : 'Save Contract'; ?>
            </button>
        </form>
    </div>
</div>

<?php if ($activeContract): ?>
<!-- ══════════ END CONTRACT MODAL ══════════ -->
<div id="endContractModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-lg">
        <button onclick="closeModal('endContractModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2">End Contract</h2>
        <p class="text-sm text-slate-400 font-medium mb-6">
            Closes the current term. The record stays on file with the reason you give.
        </p>

        <form action="actions/hr_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="end_contract">
            <input type="hidden" name="employee_id" value="<?php echo $empId; ?>">
            <input type="hidden" name="contract_id" value="<?php echo htmlspecialchars((string)$activeContract['id']); ?>">
            <input type="hidden" name="_redirect" value="../hr_employee.php?id=<?php echo urlencode($empId); ?>">

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Effective Date</label>
                <input type="date" name="ended_on" value="<?php echo date('Y-m-d'); ?>" class="form-input w-full">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Reason *</label>
                <textarea name="ended_reason" rows="3" required class="form-input w-full resize-none"
                          placeholder="e.g. Resigned with one month's notice / Contract not renewed"></textarea>
            </div>
            <label class="flex items-center gap-3 cursor-pointer select-none bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-4 border border-slate-100 dark:border-slate-700/60">
                <input type="checkbox" name="terminate_employee" value="1" class="w-4 h-4 rounded accent-red-500 cursor-pointer">
                <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Also mark the employee as Terminated</span>
            </label>

            <button type="submit" class="w-full py-3.5 bg-red-600 hover:bg-red-700 text-white font-black rounded-xl text-xs uppercase tracking-widest transition-all">
                End Contract
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openContractModal() {
    ctToggleEnd();
    openModal('contractModal');
}
function ctToggleEnd() {
    const fixed = <?php echo json_encode(HR_FIXED_TERM_STATUSES); ?>.includes(document.getElementById('ctType').value);
    document.getElementById('ctEndWrap').style.display = fixed ? '' : 'none';
    document.getElementById('ctEnd').required = fixed;
    if (!fixed) document.getElementById('ctEnd').value = '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
