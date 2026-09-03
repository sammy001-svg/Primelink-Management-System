<?php
/**
 * Payment Receipt Generator
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/corrections.php';
require_once __DIR__ . '/includes/bank_accounts.php';
require_once __DIR__ . '/includes/payment_alloc.php';
ensureCorrectionSchema($pdo);
ensureBankAccountSchema($pdo);
ensurePaymentAllocSchema($pdo);

$transaction_id = $_GET['id'] ?? '';
if (empty($transaction_id)) {
    die("Transaction ID required.");
}

$stmt = $pdo->prepare("
    SELECT tr.*, t.full_name as tenant_name, t.email as tenant_email, t.phone as tenant_phone,
           p.title as property_title, p.location, u.unit_number,
           -- Fallback info from active lease if tr.lease_id is null
           al.property_id as fallback_property_id, 
           ap.title as fallback_property_title,
           au.unit_number as fallback_unit_number,
           ap.location as fallback_location
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id = t.id
    LEFT JOIN leases l ON tr.lease_id = l.id
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    -- Fallback Join: Get the tenant's current active lease if needed
    LEFT JOIN leases al ON tr.tenant_id = al.tenant_id AND al.status = 'Active'
    LEFT JOIN units au ON al.unit_id = au.id
    LEFT JOIN properties ap ON al.property_id = ap.id
    WHERE tr.id = ?
");
$stmt->execute([$transaction_id]);
$txn = $stmt->fetch();

if ($txn) {
    // Graceful fallback for property details
    if (empty($txn['property_title'])) {
        $txn['property_title'] = $txn['fallback_property_title'] ?? 'General Account';
        $txn['location'] = $txn['fallback_location'] ?? 'Managed Property';
        $txn['unit_number'] = $txn['fallback_unit_number'] ?? 'N/A';
    }
}

if (!$txn) {
    die("Receipt not found.");
}

// Security: Tenant can only view their own receipts
if ($_SESSION['role'] === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $me = $stmt->fetch();
    if ($me['id'] !== $txn['tenant_id']) {
        die("Unauthorized access.");
    }
}

$isStaff  = in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true);
$revision    = (int)($txn['revision_no'] ?? 0);
$bankAccount = getBankAccount($pdo, $txn['bank_account_id'] ?? null);
$allocLines  = paymentGroupLines($pdo, $txn['payment_group'] ?? null);
$allocTotal  = paymentGroupTotal($allocLines);
$balanceOwing = tenantOutstandingTotal($pdo, $txn['tenant_id'] ?? null);
$docNo    = docNumber(DOC_RECEIPT, $txn['id'], $revision);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($docNo); ?><?php echo $revision > 0 ? ' (Corrected)' : ''; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
            .receipt-card { border: none; box-shadow: none; }
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col items-center justify-center p-4">
    <?php echo renderCorrectionWatermark($txn); ?>

    <?php if ($revision > 0): ?>
    <div class="max-w-2xl w-full mb-4"><?php echo renderCorrectionBanner($txn, DOC_RECEIPT); ?></div>
    <?php endif; ?>

    <div class="max-w-2xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden receipt-card relative z-[2]">
        <!-- Receipt Header -->
        <div class="bg-slate-900 text-white p-8 lg:p-10 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-black tracking-tight">PRIMELINK</h1>
                <p class="text-[10px] font-bold text-green-400 uppercase tracking-widest">Official Payment Receipt</p>
            </div>
            <div class="text-right">
                <p class="text-xs font-bold text-slate-400 uppercase">Receipt No</p>
                <p class="font-mono text-sm <?php echo $revision > 0 ? 'text-red-400' : ''; ?>"><?php echo htmlspecialchars($docNo); ?></p>
                <?php if ($revision > 0): ?>
                <p class="text-[9px] font-black text-red-400 uppercase tracking-widest mt-0.5">Corrected copy</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-8 lg:p-10 space-y-8">
            <!-- Details Grid -->
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase mb-2">Issued To</p>
                    <p class="font-black text-slate-900"><?php echo htmlspecialchars($txn['tenant_name']); ?></p>
                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars($txn['tenant_email']); ?></p>
                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars($txn['tenant_phone']); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black text-slate-400 uppercase mb-2">Property Details</p>
                    <p class="font-black text-slate-900"><?php echo htmlspecialchars($txn['property_title']); ?></p>
                    <p class="text-xs text-slate-500">Unit: <?php echo htmlspecialchars($txn['unit_number']); ?></p>
                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars($txn['location']); ?></p>
                </div>
            </div>

            <!-- Transaction Info -->
            <div class="bg-slate-50 rounded-2xl p-6 border border-slate-100">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <p class="text-[9px] font-black text-slate-400 uppercase">Date</p>
                        <p class="text-xs font-bold"><?php echo date('M j, Y', strtotime($txn['transaction_date'])); ?></p>
                    </div>
                    <div>
                        <p class="text-[9px] font-black text-slate-400 uppercase">Method</p>
                        <p class="text-xs font-bold"><?php echo $txn['payment_method']; ?></p>
                    </div>
                    <div>
                        <p class="text-[9px] font-black text-slate-400 uppercase">Type</p>
                        <p class="text-xs font-bold"><?php echo $txn['transaction_type']; ?></p>
                    </div>
                    <?php if ($bankAccount): ?>
                    <div>
                        <p class="text-[9px] font-black text-slate-400 uppercase">Deposited To</p>
                        <p class="text-xs font-bold">
                            <?php echo htmlspecialchars($isStaff ? bankAccountLabel($bankAccount) : bankAccountLabelMasked($bankAccount)); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <div class="text-right">
                        <p class="text-[9px] font-black text-slate-400 uppercase">Status</p>
                        <span class="text-[10px] font-black px-2 py-0.5 rounded-full <?php echo $txn['status'] === 'Paid' ? 'bg-green-100 text-green-600' : 'bg-orange-100 text-orange-600'; ?>">
                            <?php echo strtoupper($txn['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if ($allocLines): ?>
            <!-- What this payment covered -->
            <div class="rounded-2xl overflow-hidden" style="border:1px solid #e4e7ec;">
                <div class="px-5 py-2.5" style="background:#f8fafc;border-bottom:1px solid #e4e7ec;">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Payment breakdown</p>
                </div>
                <?php foreach ($allocLines as $line): ?>
                <div class="flex items-center justify-between px-5 py-2.5" style="border-bottom:1px solid #f1f5f9;">
                    <span class="text-sm text-slate-600"><?php echo htmlspecialchars((string)$line['transaction_type']); ?></span>
                    <span class="text-sm font-semibold text-slate-900"><?php echo number_format((float)$line['amount'], 2); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Amount Section -->
            <div class="pt-8 border-t border-slate-100 flex justify-between items-end">
                <div>
                    <p class="text-xs text-slate-500 italic mb-1">Description:</p>
                    <p class="text-sm font-medium text-slate-700"><?php echo htmlspecialchars($txn['description'] ?: 'Monthly utility and rent payment'); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Amount Paid</p>
                    <h2 class="text-4xl font-black text-slate-900">KSh <?php echo number_format($allocLines ? $allocTotal : (float)$txn['amount'], 2); ?></h2>
                    <p class="text-[10px] text-slate-400 mt-2">
                        Balance outstanding:
                        <span class="font-semibold" style="color:<?php echo $balanceOwing > 0 ? '#b91c1c' : '#15803d'; ?>">
                            KSh <?php echo number_format($balanceOwing, 2); ?>
                        </span>
                        <span class="block">as at <?php echo date('d M Y'); ?></span>
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div class="pt-10 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center text-white">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Verified Payment</p>
                </div>
                <div class="flex gap-3 no-print">
                    <button onclick="window.print()" class="px-6 py-3 bg-slate-900 text-white rounded-xl text-xs font-black hover:bg-slate-800 transition-all flex items-center gap-2">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                        PRINT RECEIPT
                    </button>
                    <a href="financials.php" class="px-6 py-3 border border-slate-200 rounded-xl text-xs font-black hover:bg-slate-50 transition-all text-slate-600">BACK</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($revision > 0): ?>
    <div class="max-w-2xl w-full relative z-[2]"><?php echo renderRevisionHistory($pdo, DOC_RECEIPT, $txn['id'], $isStaff); ?></div>
    <?php endif; ?>
</body>
</html>
