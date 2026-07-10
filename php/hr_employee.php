<?php
/**
 * Employee Detail Page — Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/includes/settings.php';

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
    'warning_added'   => 'Warning letter recorded.',
    'warning_deleted' => 'Warning letter removed.',
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

    <!-- Tabs -->
    <div class="flex gap-1 bg-slate-100 dark:bg-slate-800/50 rounded-2xl p-1 w-full overflow-x-auto">
        <?php
        $tabs = [
            'profile'   => 'Profile',
            'documents' => 'Documents (' . count($docs) . ')',
            'contacts'  => 'Contacts (' . count($contacts) . ')',
            'salary'    => 'Salary History',
            'warnings'  => 'Warnings (' . count($warnings) . ')',
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
                            <?php foreach (['Permanent','Contract','Probation','Casual'] as $es): ?>
                            <option value="<?php echo $es; ?>" <?php echo ($emp['employment_status'] ?? 'Permanent') === $es ? 'selected' : ''; ?>><?php echo $es; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2" id="contractDateWrap" style="<?php echo in_array($emp['employment_status'] ?? '', ['Contract','Probation']) ? '' : 'display:none'; ?>">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Contract End Date</label>
                        <input type="date" name="contract_end_date" value="<?php echo $emp['contract_end_date'] ?? ''; ?>" class="form-input w-full">
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
    const sel = document.getElementById('empStatusSel');
    const wrap = document.getElementById('contractDateWrap');
    wrap.style.display = ['Contract','Probation'].includes(sel.value) ? '' : 'none';
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

<?php include __DIR__ . '/includes/footer.php'; ?>
