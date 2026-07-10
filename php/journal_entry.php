<?php
/**
 * Journal Entry — Print View
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/settings.php';

$id = trim($_GET['id'] ?? '');

try {
    $stmt = $pdo->prepare("SELECT je.*, u.full_name AS created_by_name FROM journal_entries je LEFT JOIN users u ON je.created_by = u.id WHERE je.id = ?");
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
} catch (PDOException $e) { $entry = null; }

if (!$entry) die("Journal entry not found.");

// Only admin/staff can view journal entries
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'])) {
    http_response_code(403);
    die("Access denied.");
}

try {
    $ls = $pdo->prepare("
        SELECT jl.*, a.code AS account_code, a.name AS account_name, a.type AS account_type
        FROM journal_lines jl JOIN accounts a ON jl.account_id = a.id
        WHERE jl.journal_entry_id = ?
        ORDER BY jl.debit DESC, jl.credit DESC
    ");
    $ls->execute([$id]);
    $lines = $ls->fetchAll();
} catch (PDOException $e) { $lines = []; }

$totalDr = array_sum(array_column($lines, 'debit'));
$totalCr = array_sum(array_column($lines, 'credit'));

$companyName    = getSetting($pdo, 'company_name',    'Primelink Management System');
$companyAddress = getSetting($pdo, 'company_address', 'Nairobi, Kenya');
$companyEmail   = getSetting($pdo, 'company_email',   '');
$companyPhone   = getSetting($pdo, 'company_phone',   '');
$currency       = getSetting($pdo, 'currency_symbol', 'KSh');

$statusColor = match($entry['status']) {
    'Posted'   => '#22c55e',
    'Draft'    => '#3b82f6',
    'Reversed' => '#94a3b8',
    default    => '#94a3b8',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entry — <?php echo htmlspecialchars($entry['reference']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .print-wrap { box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-slate-100 p-4 md:p-10 min-h-screen">
<div class="max-w-3xl mx-auto">

    <!-- Toolbar (no print) -->
    <div class="no-print flex gap-2 mb-6 justify-end">
        <button onclick="window.print()" class="px-5 py-2.5 bg-slate-900 text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:opacity-90">
            Print
        </button>
        <a href="journals.php" class="px-5 py-2.5 bg-white text-slate-600 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-50 border border-slate-200">
            Back
        </a>
    </div>

    <!-- Document -->
    <div class="print-wrap bg-white rounded-3xl shadow-2xl overflow-hidden">

        <!-- Header band -->
        <div class="bg-slate-900 px-10 py-8 text-white">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-1">Journal Entry</p>
                    <h1 class="text-3xl font-black tracking-tighter"><?php echo htmlspecialchars($entry['reference']); ?></h1>
                    <?php if ($entry['status'] === 'Reversed'): ?>
                    <p class="text-orange-400 text-xs font-black uppercase tracking-widest mt-1">⚠ This entry has been reversed</p>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1"><?php echo htmlspecialchars($companyName); ?></p>
                    <?php if ($companyAddress): ?><p class="text-slate-400 text-xs"><?php echo htmlspecialchars($companyAddress); ?></p><?php endif; ?>
                    <?php if ($companyPhone):   ?><p class="text-slate-400 text-xs"><?php echo htmlspecialchars($companyPhone); ?></p><?php endif; ?>
                    <?php if ($companyEmail):   ?><p class="text-slate-400 text-xs"><?php echo htmlspecialchars($companyEmail); ?></p><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="px-10 py-8">

            <!-- Meta grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-8 pb-8 border-b border-slate-100">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Entry Date</p>
                    <p class="text-sm font-black text-slate-900"><?php echo date('F d, Y', strtotime($entry['entry_date'])); ?></p>
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Status</p>
                    <span style="display:inline-block;padding:3px 10px;border-radius:99px;background:<?php echo $statusColor; ?>20;color:<?php echo $statusColor; ?>;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;">
                        <?php echo $entry['status']; ?>
                    </span>
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Posted On</p>
                    <p class="text-sm font-black text-slate-900"><?php echo $entry['posted_at'] ? date('M d, Y', strtotime($entry['posted_at'])) : '—'; ?></p>
                </div>
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Prepared By</p>
                    <p class="text-sm font-black text-slate-900"><?php echo htmlspecialchars($entry['created_by_name'] ?? 'System'); ?></p>
                </div>
            </div>

            <!-- Narration -->
            <div class="mb-8">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Narration</p>
                <p class="text-slate-700 font-medium"><?php echo htmlspecialchars($entry['narration']); ?></p>
            </div>

            <!-- Lines table -->
            <table class="w-full mb-8">
                <thead>
                    <tr class="border-b-2 border-slate-900">
                        <th class="text-left py-3 text-[10px] font-black uppercase tracking-widest text-slate-500 w-24">Code</th>
                        <th class="text-left py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Account Name</th>
                        <th class="text-left py-3 text-[10px] font-black uppercase tracking-widest text-slate-500">Description</th>
                        <th class="text-right py-3 text-[10px] font-black uppercase tracking-widest text-slate-500 w-32">Debit</th>
                        <th class="text-right py-3 text-[10px] font-black uppercase tracking-widest text-slate-500 w-32">Credit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $line): ?>
                <tr class="border-b border-slate-100">
                    <td class="py-4 text-xs font-black text-slate-400"><?php echo htmlspecialchars($line['account_code']); ?></td>
                    <td class="py-4">
                        <p class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($line['account_name']); ?></p>
                        <p class="text-[10px] text-slate-400 font-medium"><?php echo $line['account_type']; ?></p>
                    </td>
                    <td class="py-4 text-xs text-slate-500 italic"><?php echo htmlspecialchars($line['description'] ?? ''); ?></td>
                    <td class="py-4 text-right font-black text-sm <?php echo $line['debit'] > 0 ? 'text-slate-900' : 'text-slate-200'; ?>">
                        <?php echo $line['debit'] > 0 ? $currency . ' ' . number_format($line['debit'], 2) : '—'; ?>
                    </td>
                    <td class="py-4 text-right font-black text-sm <?php echo $line['credit'] > 0 ? 'text-slate-900' : 'text-slate-200'; ?>">
                        <?php echo $line['credit'] > 0 ? $currency . ' ' . number_format($line['credit'], 2) : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-900">
                        <td colspan="3" class="pt-4 text-xs font-black text-slate-500 uppercase tracking-widest">Totals</td>
                        <td class="pt-4 text-right font-black text-slate-900"><?php echo $currency; ?> <?php echo number_format($totalDr, 2); ?></td>
                        <td class="pt-4 text-right font-black text-slate-900"><?php echo $currency; ?> <?php echo number_format($totalCr, 2); ?></td>
                    </tr>
                    <?php $diff = abs($totalDr - $totalCr); ?>
                    <?php if ($diff > 0.005): ?>
                    <tr>
                        <td colspan="5" class="pt-2 text-right text-red-500 text-xs font-black">⚠ Imbalance: <?php echo $currency . ' ' . number_format($diff, 2); ?></td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td colspan="5" class="pt-2 text-right text-green-500 text-xs font-black">✓ Entry is balanced</td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>

            <!-- Footer -->
            <div class="border-t border-slate-100 pt-8 grid grid-cols-3 gap-6 text-center">
                <div>
                    <div class="h-px bg-slate-300 mb-2 mt-8"></div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Prepared By</p>
                </div>
                <div>
                    <div class="h-px bg-slate-300 mb-2 mt-8"></div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Reviewed By</p>
                </div>
                <div>
                    <div class="h-px bg-slate-300 mb-2 mt-8"></div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Approved By</p>
                </div>
            </div>

            <p class="text-center text-[9px] text-slate-300 font-medium mt-8">
                Generated by <?php echo htmlspecialchars($companyName); ?> · <?php echo date('F d, Y \a\t H:i'); ?> · Reference: <?php echo htmlspecialchars($entry['reference']); ?>
            </p>
        </div>
    </div>
</div>
</body>
</html>
