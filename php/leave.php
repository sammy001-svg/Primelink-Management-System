<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/includes/audit.php';

// Boot leave tables + seed defaults (functions defined in action file)
require_once __DIR__ . '/actions/leave_actions.php';

$msg    = htmlspecialchars($_GET['success'] ?? '');
$err    = htmlspecialchars($_GET['error']   ?? '');
$isAdmin = $_SESSION['role'] === 'admin';
$year   = (int)($_GET['year'] ?? date('Y'));
$empFilter = trim($_GET['employee_id'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// ── Pending queue (for approval banner) ────────────────────────────────
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM leave_applications WHERE status='Pending'")->fetchColumn();

// ── Applications list ─────────────────────────────────────────────────
$where = ['YEAR(la.start_date) = ' . $year];
$params = [];
if ($empFilter)    { $where[] = 'la.employee_id = ?'; $params[] = $empFilter; }
if ($statusFilter) { $where[] = 'la.status = ?';      $params[] = $statusFilter; }

$sql = "SELECT la.*, e.full_name, e.staff_no, lt.name lt_name, lt.color lt_color
        FROM leave_applications la
        JOIN employees e ON e.id = la.employee_id
        JOIN leave_types lt ON lt.id = la.leave_type_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY la.applied_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

// ── Leave types ────────────────────────────────────────────────────────
$leaveTypes = $pdo->query("SELECT * FROM leave_types ORDER BY sort_order, name")->fetchAll();

// ── Employee list for dropdown ─────────────────────────────────────────
$employees = $pdo->query("SELECT id, full_name, staff_no FROM employees WHERE status='Active' ORDER BY full_name")->fetchAll();

// ── Stats for this year ────────────────────────────────────────────────
$statsRow = $pdo->prepare(
    "SELECT SUM(status='Pending') pending, SUM(status='Approved') approved,
            SUM(status='Rejected') rejected, SUM(status='Cancelled') cancelled,
            SUM(CASE WHEN status='Approved' THEN days_requested ELSE 0 END) total_days
     FROM leave_applications WHERE YEAR(start_date)=?"
);
$statsRow->execute([$year]); $statsRow = $statsRow->fetch();

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

$pageTitle = 'Leave Management';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6 animate-in">

<?php if ($msg): ?><div class="p-4 bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-400 rounded-2xl text-sm font-bold"><?php echo $msg; ?></div><?php endif; ?>
<?php if ($err): ?><div class="p-4 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 rounded-2xl text-sm font-bold"><?php echo $err; ?></div><?php endif; ?>

<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black text-slate-900 dark:text-white">Leave Management</h1>
        <p class="text-sm text-slate-500 mt-0.5">Track leave applications, approvals and balances</p>
    </div>
    <div class="flex gap-2">
        <?php if ($isAdmin): ?>
        <button onclick="openModal('leaveTypeModal')" class="btn-secondary text-sm">⚙ Leave Types</button>
        <?php endif; ?>
        <button onclick="openModal('applyLeaveModal')" class="btn-primary">+ Apply Leave</button>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4">
    <?php foreach ([
        ['Pending',       $statsRow['pending']    ?? 0, 'text-orange-400'],
        ['Approved',      $statsRow['approved']   ?? 0, 'text-green-400'],
        ['Rejected',      $statsRow['rejected']   ?? 0, 'text-red-400'],
        ['Cancelled',     $statsRow['cancelled']  ?? 0, 'text-slate-400'],
        ['Days Approved', $statsRow['total_days'] ?? 0, 'text-blue-400'],
    ] as [$lbl, $val, $col]): ?>
    <div class="glass-card p-4">
        <p class="text-xs text-slate-500 mb-1"><?php echo $lbl; ?></p>
        <p class="text-2xl font-black <?php echo $col; ?>"><?php echo number_format((int)$val); ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pending queue (admin notice) -->
<?php if ($isAdmin && $pendingCount > 0): ?>
<div class="p-4 bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800/30 rounded-2xl flex items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <span class="text-orange-500 text-xl">⏳</span>
        <div>
            <p class="font-bold text-orange-700 dark:text-orange-400 text-sm"><?php echo $pendingCount; ?> leave application<?php echo $pendingCount !== 1 ? 's' : ''; ?> awaiting approval</p>
            <p class="text-xs text-orange-500 dark:text-orange-500">Review and approve or reject below</p>
        </div>
    </div>
    <a href="?status=Pending&year=<?php echo $year; ?>" class="text-xs font-bold text-orange-600 hover:underline whitespace-nowrap">View Pending →</a>
</div>
<?php endif; ?>

<!-- Filters -->
<form method="GET" class="glass-card p-4 flex flex-wrap gap-3 items-end">
    <div>
        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Year</label>
        <select name="year" class="form-input py-1.5 text-sm">
            <?php for ($y = date('Y') + 1; $y >= date('Y') - 2; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Employee</label>
        <select name="employee_id" class="form-input py-1.5 text-sm">
            <option value="">All Employees</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?php echo $e['id']; ?>" <?php echo $empFilter === $e['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['full_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Status</label>
        <select name="status" class="form-input py-1.5 text-sm">
            <option value="">All Statuses</option>
            <?php foreach (['Pending','Approved','Rejected','Cancelled'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-primary text-sm py-1.5">Filter</button>
    <a href="leave.php" class="btn-secondary text-sm py-1.5">Clear</a>
</form>

<!-- Applications table -->
<div class="glass-card overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 text-sm font-bold text-slate-900 dark:text-white">
        Applications — <?php echo $year; ?> (<?php echo count($applications); ?>)
    </div>
    <?php if (!$applications): ?>
    <div class="p-12 text-center text-slate-400">
        <svg class="w-10 h-10 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
        <p>No leave applications found for the selected filters.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-[10px] text-slate-500 uppercase tracking-wide bg-slate-50 dark:bg-slate-800/50">
                    <th class="px-5 py-2 text-left">Employee</th>
                    <th class="px-5 py-2 text-left">Leave Type</th>
                    <th class="px-5 py-2 text-left">From</th>
                    <th class="px-5 py-2 text-left">To</th>
                    <th class="px-5 py-2 text-right">Days</th>
                    <th class="px-5 py-2 text-left">Status</th>
                    <th class="px-5 py-2 text-left">Applied</th>
                    <th class="px-5 py-2 text-left">Reviewed By</th>
                    <th class="px-5 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($applications as $a):
                    $ltCls = $colorMap[$a['lt_color']] ?? $colorMap['slate'];
                    $stCls = match($a['status']) {
                        'Approved'  => 'badge badge-green',
                        'Rejected'  => 'badge badge-red',
                        'Cancelled' => 'badge badge-slate',
                        default     => 'badge badge-orange',
                    };
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20 transition-colors">
                    <td class="px-5 py-2.5">
                        <p class="font-semibold text-slate-900 dark:text-white text-xs"><?php echo htmlspecialchars($a['full_name']); ?></p>
                        <?php if ($a['staff_no']): ?><p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($a['staff_no']); ?></p><?php endif; ?>
                    </td>
                    <td class="px-5 py-2.5">
                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold <?php echo $ltCls; ?>"><?php echo htmlspecialchars($a['lt_name']); ?></span>
                    </td>
                    <td class="px-5 py-2.5 text-xs"><?php echo date('d M Y', strtotime($a['start_date'])); ?></td>
                    <td class="px-5 py-2.5 text-xs"><?php echo date('d M Y', strtotime($a['end_date'])); ?></td>
                    <td class="px-5 py-2.5 text-right font-bold text-slate-900 dark:text-white text-xs"><?php echo $a['days_requested']; ?></td>
                    <td class="px-5 py-2.5"><span class="<?php echo $stCls; ?>"><?php echo $a['status']; ?></span></td>
                    <td class="px-5 py-2.5 text-xs text-slate-500"><?php echo date('d M', strtotime($a['applied_at'])); ?></td>
                    <td class="px-5 py-2.5 text-xs text-slate-400"><?php echo htmlspecialchars($a['reviewed_by'] ?: '—'); ?></td>
                    <td class="px-5 py-2.5">
                        <div class="flex gap-2 justify-end">
                            <?php if ($a['status'] === 'Pending' && $isAdmin): ?>
                            <button onclick="openApprove('<?php echo $a['id']; ?>')" class="text-[10px] font-bold text-green-500 hover:underline">Approve</button>
                            <button onclick="openReject('<?php echo $a['id']; ?>')" class="text-[10px] font-bold text-red-500 hover:underline">Reject</button>
                            <?php elseif (in_array($a['status'], ['Pending','Approved'])): ?>
                            <form method="POST" action="actions/leave_actions.php" onsubmit="return confirm('Cancel this leave?')">
                                <input type="hidden" name="action" value="cancel_leave">
                                <input type="hidden" name="application_id" value="<?php echo $a['id']; ?>">
                                <input type="hidden" name="_redirect" value="leave.php?year=<?php echo $year; ?>">
                                <button class="text-[10px] font-bold text-slate-400 hover:text-red-500">Cancel</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($a['reason']): ?>
                            <button onclick="alert('<?php echo htmlspecialchars(addslashes($a['reason'])); ?>')" class="text-[10px] text-slate-400 hover:text-slate-600">ℹ</button>
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

</div><!-- .space-y-6 -->

<!-- Apply Leave Modal -->
<div id="applyLeaveModal" class="modal-overlay hidden">
    <div class="modal-card max-w-lg w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Apply for Leave</h3>
            <button onclick="closeModal('applyLeaveModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/leave_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="apply_leave">
            <input type="hidden" name="_redirect" value="leave.php?year=<?php echo $year; ?>">
            <?php if ($isAdmin): ?>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Employee</label>
                <select name="employee_id" required class="form-input w-full">
                    <option value="">— Select Employee —</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else:
                // Staff applying for themselves
                $myEmp = $pdo->prepare("SELECT id FROM employees WHERE email=? LIMIT 1");
                $myEmp->execute([$_SESSION['email'] ?? '']); $myEmp = $myEmp->fetchColumn();
            ?>
            <input type="hidden" name="employee_id" value="<?php echo $myEmp ?? ''; ?>">
            <?php endif; ?>
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Leave Type</label>
                <select name="leave_type_id" required class="form-input w-full">
                    <option value="">— Select Type —</option>
                    <?php foreach ($leaveTypes as $lt): ?>
                    <option value="<?php echo $lt['id']; ?>"><?php echo htmlspecialchars($lt['name']); ?> (<?php echo $lt['days_per_year'] > 0 ? $lt['days_per_year'] . ' days/yr' : 'Unpaid'; ?>)</option>
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
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Reason / Notes</label>
                <textarea name="reason" rows="3" class="form-input w-full" placeholder="Brief reason for leave..."></textarea>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="btn-primary flex-1">Submit Application</button>
                <button type="button" onclick="closeModal('applyLeaveModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveLeaveModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-green-600 dark:text-green-400">✓ Approve Leave</h3>
            <button onclick="closeModal('approveLeaveModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/leave_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="approve_leave">
            <input type="hidden" name="_redirect" value="leave.php?year=<?php echo $year; ?>">
            <input type="hidden" name="application_id" id="approve_app_id">
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Approval Note (optional)</label>
                <textarea name="review_notes" rows="2" class="form-input w-full" placeholder="Any notes for the employee..."></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-green flex-1">Approve</button>
                <button type="button" onclick="closeModal('approveLeaveModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectLeaveModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-red-600 dark:text-red-400">✗ Reject Application</h3>
            <button onclick="closeModal('rejectLeaveModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/leave_actions.php" class="space-y-4">
            <input type="hidden" name="action" value="reject_leave">
            <input type="hidden" name="_redirect" value="leave.php?year=<?php echo $year; ?>">
            <input type="hidden" name="application_id" id="reject_app_id">
            <div>
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Reason for Rejection <span class="text-red-500">*</span></label>
                <textarea name="review_notes" rows="3" required class="form-input w-full" placeholder="Explain why the leave is being rejected..."></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-danger flex-1">Reject</button>
                <button type="button" onclick="closeModal('rejectLeaveModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Leave Types Modal (Admin) -->
<?php if ($isAdmin): ?>
<div id="leaveTypeModal" class="modal-overlay hidden">
    <div class="modal-card max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Leave Types</h3>
            <button onclick="closeModal('leaveTypeModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>

        <!-- Existing types -->
        <div class="space-y-2 mb-6">
            <?php foreach ($leaveTypes as $lt):
                $ltc = $colorMap[$lt['color']] ?? $colorMap['slate'];
            ?>
            <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold <?php echo $ltc; ?> shrink-0"><?php echo htmlspecialchars($lt['name']); ?></span>
                <span class="text-xs text-slate-500 flex-1"><?php echo $lt['days_per_year'] > 0 ? $lt['days_per_year'] . ' days/year' : 'Unlimited/Unpaid'; ?><?php echo $lt['carry_forward'] ? ' · Carry fwd' : ''; ?></span>
                <button onclick="fillTypeForm(<?php echo htmlspecialchars(json_encode($lt), ENT_QUOTES); ?>)" class="text-xs text-blue-500 hover:underline font-bold">Edit</button>
                <form method="POST" action="actions/leave_actions.php" onsubmit="return confirm('Delete this leave type? All related applications will be lost.')" class="inline">
                    <input type="hidden" name="action" value="delete_leave_type">
                    <input type="hidden" name="type_id" value="<?php echo $lt['id']; ?>">
                    <input type="hidden" name="_redirect" value="leave.php">
                    <button class="text-xs text-red-400 hover:underline font-bold">Delete</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Add / Edit form -->
        <form method="POST" action="actions/leave_actions.php" class="space-y-4 border-t border-slate-200 dark:border-slate-700 pt-4">
            <input type="hidden" name="action" value="save_leave_type">
            <input type="hidden" name="_redirect" value="leave.php">
            <input type="hidden" name="type_id" id="ltype_id" value="">
            <h4 class="text-sm font-black text-slate-700 dark:text-slate-300" id="ltypeFormTitle">Add Leave Type</h4>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Name</label>
                    <input type="text" name="name" id="ltype_name" required class="form-input w-full" placeholder="e.g. Annual Leave">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Days/Year (0 = unlimited)</label>
                    <input type="number" name="days_per_year" id="ltype_days" min="0" class="form-input w-full" value="21">
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Colour</label>
                    <select name="color" id="ltype_color" class="form-input w-full">
                        <?php foreach (['green','orange','blue','red','purple','pink','indigo','slate'] as $c): ?>
                        <option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Sort Order</label>
                    <input type="number" name="sort_order" id="ltype_sort" min="0" class="form-input w-full" value="0">
                </div>
            </div>
            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="carry_forward" id="ltype_carry" value="1" class="w-4 h-4 accent-green-500">
                    <span class="font-semibold text-slate-700 dark:text-slate-300">Allow carry-forward</span>
                </label>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="requires_approval" id="ltype_approval" value="1" checked class="w-4 h-4 accent-green-500">
                    <span class="font-semibold text-slate-700 dark:text-slate-300">Requires approval</span>
                </label>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1" id="ltypeSaveBtn">Add Leave Type</button>
                <button type="button" onclick="resetTypeForm()" class="btn-secondary">Clear</button>
            </div>
        </form>
    </div>
</div>
<script>
function fillTypeForm(lt) {
    document.getElementById('ltype_id').value    = lt.id;
    document.getElementById('ltype_name').value  = lt.name;
    document.getElementById('ltype_days').value  = lt.days_per_year;
    document.getElementById('ltype_color').value = lt.color;
    document.getElementById('ltype_sort').value  = lt.sort_order;
    document.getElementById('ltype_carry').checked   = lt.carry_forward == 1;
    document.getElementById('ltype_approval').checked= lt.requires_approval == 1;
    document.getElementById('ltypeFormTitle').textContent = 'Edit: ' + lt.name;
    document.getElementById('ltypeSaveBtn').textContent   = 'Save Changes';
}
function resetTypeForm() {
    document.getElementById('ltype_id').value   = '';
    document.getElementById('ltype_name').value = '';
    document.getElementById('ltype_days').value = '21';
    document.getElementById('ltypeFormTitle').textContent = 'Add Leave Type';
    document.getElementById('ltypeSaveBtn').textContent   = 'Add Leave Type';
}
</script>
<?php endif; ?>

<script>
function openApprove(id) { document.getElementById('approve_app_id').value = id; openModal('approveLeaveModal'); }
function openReject(id)  { document.getElementById('reject_app_id').value  = id; openModal('rejectLeaveModal'); }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
