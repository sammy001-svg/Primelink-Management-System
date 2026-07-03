<?php
/**
 * Digital Payout Voucher
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$ref = $_GET['ref'] ?? '';
$stmt = $pdo->prepare("
    SELECT p.*, l.full_name as landlord_name, l.email as landlord_email, l.phone as landlord_phone
    FROM landlord_payouts p 
    JOIN landlords l ON p.landlord_id = l.id 
    WHERE p.reference_code = ?
");
$stmt->execute([$ref]);
$payout = $stmt->fetch();

if (!$payout) {
    die("Voucher not found.");
}

// Basic security: landlords can only see their own vouchers
if ($_SESSION['role'] === 'landlord') {
    $landlordId = getLandlordId($pdo);
    if ($payout['landlord_id'] !== $landlordId) {
        die("Unauthorized access.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payout Voucher - <?php echo $ref; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            .no-print { display: none; }
            body { background: white; }
        }
    </style>
</head>
<body class="bg-slate-50 py-12 px-4">
    <div class="max-w-3xl mx-auto bg-white p-12 rounded-3xl shadow-2xl border border-slate-100 relative overflow-hidden">
        <!-- Brand Accents -->
        <div class="absolute top-0 left-0 w-full h-2 bg-linear-to-r from-accent-green via-accent-orange to-accent-green"></div>
        
        <div class="flex justify-between items-start mb-12">
            <div>
                <h1 class="text-3xl font-black tracking-tighter text-slate-900">PRIMELINK<span class="text-accent-green">PROPERTIES</span></h1>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-1">Management Distribution Voucher</p>
            </div>
            <div class="text-right">
                <div class="bg-slate-900 text-white px-4 py-2 rounded-xl inline-block mb-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Reference No</p>
                    <p class="text-sm font-mono font-bold"><?php echo $payout['reference_code']; ?></p>
                </div>
                <p class="text-xs font-bold text-slate-500"><?php echo date('M d, Y | H:i', strtotime($payout['payout_date'])); ?></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-12 mb-12">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Recipient Information</p>
                <div class="space-y-1">
                    <p class="text-lg font-black text-slate-900"><?php echo htmlspecialchars($payout['landlord_name']); ?></p>
                    <p class="text-sm font-medium text-slate-500"><?php echo htmlspecialchars($payout['landlord_email']); ?></p>
                    <p class="text-sm font-medium text-slate-500"><?php echo htmlspecialchars($payout['landlord_phone']); ?></p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Payout Method</p>
                <p class="text-lg font-black text-slate-900"><?php echo htmlspecialchars($payout['payment_method'] ?? $payout['method'] ?? 'Bank Transfer'); ?></p>
                <p class="text-xs font-bold text-accent-green uppercase mt-1">Transaction Completed</p>
            </div>
        </div>

        <?php
        // Detect whether this payout has enriched breakdown columns
        $hasBreakdown = !empty($payout['gross_amount']) && (float)$payout['gross_amount'] > 0;
        $gross     = $hasBreakdown ? (float)$payout['gross_amount']            : ((float)$payout['amount'] + (float)$payout['fee_deducted']);
        $feeDed    = (float)$payout['fee_deducted'];
        $maintDed  = (float)($payout['maintenance_deduction'] ?? 0);
        $expDed    = (float)($payout['expense_deduction']     ?? 0);
        $advDed    = (float)($payout['advance_deduction']     ?? 0);
        $net       = (float)$payout['amount'];
        // Recalculate fee % for display
        $feeRate   = $gross > 0 ? round($feeDed / $gross * 100, 1) : 10.0;

        // Period label
        $monthNames = ['1'=>'January','2'=>'February','3'=>'March','4'=>'April',
                       '5'=>'May','6'=>'June','7'=>'July','8'=>'August',
                       '9'=>'September','10'=>'October','11'=>'November','12'=>'December'];
        $periodLabel = '';
        if (!empty($payout['period_month']) && !empty($payout['period_year'])) {
            $periodLabel = ($monthNames[$payout['period_month']] ?? '') . ' ' . $payout['period_year'];
        }
        ?>
        <div class="bg-slate-50 rounded-3xl p-8 mb-12 border border-slate-100">
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-200">
                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">Remittance Breakdown</h3>
                <?php if ($periodLabel): ?>
                <span class="text-[10px] font-black text-accent-green uppercase tracking-widest"><?php echo $periodLabel; ?></span>
                <?php endif; ?>
            </div>
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-bold text-slate-600">Gross Rent Collected</span>
                    <span class="text-sm font-black text-slate-900">KSh <?php echo number_format($gross); ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-bold text-slate-600">Management Fee (<?php echo $feeRate; ?>%)</span>
                    <span class="text-sm font-black text-orange-500">– KSh <?php echo number_format($feeDed); ?></span>
                </div>
                <?php if ($maintDed > 0): ?>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-bold text-slate-600">Maintenance Costs</span>
                    <span class="text-sm font-black text-red-500">– KSh <?php echo number_format($maintDed); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($expDed > 0): ?>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-bold text-slate-600">Property Expenses</span>
                    <span class="text-sm font-black text-red-500">– KSh <?php echo number_format($expDed); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($advDed > 0): ?>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-bold text-slate-600">Advance Recovery</span>
                    <span class="text-sm font-black text-purple-500">– KSh <?php echo number_format($advDed); ?></span>
                </div>
                <?php endif; ?>
                <div class="pt-4 border-t border-slate-200 flex justify-between items-center">
                    <span class="text-xl font-black text-slate-900">Net Payout Amount</span>
                    <span class="text-xl font-black text-accent-green">KSh <?php echo number_format($net); ?></span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-8 mb-12">
            <div class="border-t border-slate-200 pt-6">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-8">Authorized Signatory</p>
                <div class="h-12 border-b border-slate-100 mb-2 italic text-slate-400 font-medium">Primelink System Verified</div>
                <p class="text-xs font-bold text-slate-900 uppercase">Management Authority</p>
            </div>
            <div class="border-t border-slate-200 pt-6">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-8">Recipient Signature</p>
                <div class="h-12 border-b border-slate-100 mb-2"></div>
                <p class="text-xs font-bold text-slate-900 uppercase">Date of Receipt</p>
            </div>
        </div>

        <div class="text-center p-6 bg-slate-900 rounded-2xl">
            <p class="text-[10px] text-slate-500 font-bold leading-relaxed italic">
                This is a computer-generated distribution voucher. No physical signature is required for digital verification.
                All disputes should be reported within 24 hours of generation.
            </p>
        </div>

        <div class="no-print fixed bottom-8 right-8 flex gap-4">
            <button onclick="window.print()" class="bg-slate-900 text-white px-6 py-4 rounded-2xl font-black shadow-2xl hover:scale-105 transition-all text-sm flex items-center gap-2">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Voucher
            </button>
            <button onclick="window.close()" class="bg-white text-slate-900 px-6 py-4 rounded-2xl font-black shadow-2xl border border-slate-200 hover:scale-105 transition-all text-sm">
                Close
            </button>
        </div>
    </div>
</body>
</html>
