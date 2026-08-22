<?php
/**
 * Combined / Bundle Invoice View
 * Primelink Management System
 * Displays all invoices created in a single batch as one printable document.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/corrections.php';

ensureCorrectionSchema($pdo);

$batchId = trim($_GET['batch_id'] ?? '');
if (!$batchId) die('No batch ID provided.');

// Fetch all invoices in this batch with tenant + property info
$stmt = $pdo->prepare("
    SELECT i.id, i.amount, i.due_date, i.status, i.invoice_type, i.description, i.created_at, i.revision_no,
           t.id AS tenant_id, t.full_name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone,
           u.unit_number, p.title AS property_title, p.location AS property_location
    FROM invoices i
    JOIN tenants t ON i.tenant_id = t.id
    LEFT JOIN leases l ON i.lease_id = l.id
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE i.batch_id = ?
    ORDER BY FIELD(i.invoice_type, 'Rent', 'Deposit', 'Water', 'Electricity', 'Garbage', 'Service Charge', 'Penalty', 'Other')
");
$stmt->execute([$batchId]);
$invoices = $stmt->fetchAll();

if (empty($invoices)) die('No invoices found for this batch.');

// Security: tenants can only view their own invoices
if (($_SESSION['role'] ?? '') === 'tenant') {
    $myStmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $myStmt->execute([$_SESSION['user_id']]);
    $myTenant = $myStmt->fetch();
    if (!$myTenant || $myTenant['id'] !== $invoices[0]['tenant_id']) {
        http_response_code(403);
        die("Access denied.");
    }
}

$first   = $invoices[0];
$total   = array_sum(array_column($invoices, 'amount'));
$dueDate = $first['due_date'];

// Back URL
$backTenantId = $_GET['back_tenant'] ?? '';
if (!$backTenantId && ($first['tenant_id'] ?? '')) {
    // Try to figure back URL from tenant — if admin/staff, go to tenant details
    if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'])) {
        $backTenantId = $first['tenant_id'];
    }
}

$companyName    = getSetting($pdo, 'company_name',    'Primelink Management System');
$companyAddress = getSetting($pdo, 'company_address', 'Nairobi, Kenya');
$companyEmail   = getSetting($pdo, 'company_email',   '');
$companyPhone   = getSetting($pdo, 'company_phone',   '');
$companyTagline = getSetting($pdo, 'company_tagline', '');
$currency       = getSetting($pdo, 'currency_symbol', 'KSh');
$invoiceDueDays = (int)getSetting($pdo, 'invoice_due_days', '7');
$invoiceFooter  = getSetting($pdo, 'invoice_footer',  'For inquiries and payment confirmation, please contact us at the above details.');
$mpesaShortcode = getSetting($pdo, 'mpesa_shortcode', '—');

$invoiceRef = 'BDL-' . strtoupper(substr($batchId, 0, 8));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo $invoiceRef; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .print-border { border: 1px solid #e2e8f0; }
        }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 font-sans p-4 md:p-10">
    <div class="max-w-3xl mx-auto bg-white p-8 md:p-12 shadow-2xl rounded-3xl print-border relative">

        <!-- Action bar -->
        <div class="no-print absolute top-5 right-5 flex gap-2">
            <button onclick="window.print()"
                class="px-4 py-2 bg-slate-900 text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:opacity-90 transition">
                Print Invoice
            </button>
            <?php if ($backTenantId): ?>
            <a href="tenant_details.php?id=<?php echo urlencode($backTenantId); ?>&tab=invoices"
               class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-200 transition">
                Back
            </a>
            <?php else: ?>
            <a href="<?php echo $_SESSION['role'] === 'tenant' ? 'financials.php' : 'invoices.php'; ?>"
               class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-200 transition">
                Back
            </a>
            <?php endif; ?>
        </div>

        <!-- Header: Company + Invoice meta -->
        <div class="flex justify-between items-start border-b border-slate-100 pb-10 mb-10">
            <div>
                <h1 class="text-3xl font-black tracking-tighter text-slate-900 uppercase"><?php echo htmlspecialchars($companyName); ?></h1>
                <?php if ($companyTagline): ?>
                <p class="text-slate-500 font-bold text-sm"><?php echo htmlspecialchars($companyTagline); ?></p>
                <?php endif; ?>
                <p class="text-slate-400 text-xs font-medium mt-1"><?php echo htmlspecialchars($companyAddress); ?></p>
                <?php if ($companyPhone): ?><p class="text-slate-400 text-xs font-medium"><?php echo htmlspecialchars($companyPhone); ?></p><?php endif; ?>
                <?php if ($companyEmail): ?><p class="text-slate-400 text-xs font-medium"><?php echo htmlspecialchars($companyEmail); ?></p><?php endif; ?>
            </div>
            <div class="text-right">
                <div class="inline-block px-4 py-1 bg-slate-900 text-white rounded-xl text-xs font-black uppercase tracking-widest mb-3">
                    INVOICE
                </div>
                <p class="text-sm font-black text-slate-900"><?php echo $invoiceRef; ?></p>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-3">Invoice Date</p>
                <p class="text-sm font-bold"><?php echo date('F d, Y', strtotime($first['created_at'])); ?></p>
                <p class="text-[10px] font-black text-red-500 uppercase tracking-widest mt-2">Due Date</p>
                <p class="text-sm font-black text-red-500"><?php echo date('F d, Y', strtotime($dueDate)); ?></p>
            </div>
        </div>

        <!-- Billed To + Property -->
        <div class="grid grid-cols-2 gap-10 mb-12">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Billed To:</p>
                <p class="text-lg font-black text-slate-900"><?php echo htmlspecialchars($first['tenant_name']); ?></p>
                <?php if ($first['tenant_email']): ?>
                <p class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars($first['tenant_email']); ?></p>
                <?php endif; ?>
                <?php if ($first['tenant_phone']): ?>
                <p class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars($first['tenant_phone']); ?></p>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <?php if ($first['property_title'] || $first['unit_number']): ?>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Property Details:</p>
                <?php if ($first['property_title']): ?>
                <p class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($first['property_title']); ?></p>
                <?php endif; ?>
                <?php if ($first['property_location']): ?>
                <p class="text-xs text-slate-500"><?php echo htmlspecialchars($first['property_location']); ?></p>
                <?php endif; ?>
                <?php if ($first['unit_number']): ?>
                <p class="text-xs font-medium text-slate-500">Unit: <?php echo htmlspecialchars($first['unit_number']); ?></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Invoice line items -->
        <table class="w-full mb-12">
            <thead>
                <tr class="border-b-2 border-slate-900">
                    <th class="text-left py-3 text-[10px] font-black uppercase tracking-widest text-slate-600">#</th>
                    <th class="text-left py-3 text-[10px] font-black uppercase tracking-widest text-slate-600">Description</th>
                    <th class="text-right py-3 text-[10px] font-black uppercase tracking-widest text-slate-600">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $i => $inv):
                    $lineRev = (int)($inv['revision_no'] ?? 0);
                ?>
                <tr class="border-b border-slate-100">
                    <td class="py-4 text-xs text-slate-400 font-bold pr-4 align-top"><?php echo $i + 1; ?>.</td>
                    <td class="py-4 align-top">
                        <p class="font-black text-slate-900"><?php echo htmlspecialchars($inv['invoice_type']); ?></p>
                        <?php if (!empty($inv['description'])): ?>
                        <p class="text-xs text-slate-500 font-medium italic mt-0.5"><?php echo htmlspecialchars($inv['description']); ?></p>
                        <?php else: ?>
                        <p class="text-xs text-slate-400 font-medium italic mt-0.5">Standard <?php echo strtolower(htmlspecialchars($inv['invoice_type'])); ?> charge</p>
                        <?php endif; ?>
                        <p class="text-[10px] text-slate-300 mt-0.5">REF: <?php echo htmlspecialchars(docNumber(DOC_INVOICE, $inv['id'], $lineRev)); ?></p>
                        <?php if ($lineRev > 0): ?>
                        <div class="mt-1"><?php echo correctedBadge($lineRev); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-4 text-right font-black text-slate-900 align-top whitespace-nowrap">
                        <?php echo $currency; ?> <?php echo number_format($inv['amount'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php if (count($invoices) > 1): ?>
                <tr class="border-t border-slate-200">
                    <td colspan="2" class="pt-4 pb-2 text-right text-xs font-black text-slate-500 uppercase tracking-widest">Subtotal (<?php echo count($invoices); ?> items)</td>
                    <td class="pt-4 pb-2 text-right text-sm font-black text-slate-600 whitespace-nowrap"><?php echo $currency; ?> <?php echo number_format($total, 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td colspan="2" class="pt-6 pb-10">
                        <p class="text-xs text-slate-400 italic">Payment should be made within <?php echo $invoiceDueDays; ?> day<?php echo $invoiceDueDays !== 1 ? 's' : ''; ?> of the due date to avoid penalties.</p>
                    </td>
                    <td class="pt-6 pb-10 text-right align-top">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Amount Due</p>
                        <p class="text-3xl font-black text-slate-900 whitespace-nowrap"><?php echo $currency; ?> <?php echo number_format($total, 2); ?></p>
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- Payment methods -->
        <?php if ($mpesaShortcode && $mpesaShortcode !== '—'): ?>
        <div class="p-8 bg-slate-50 rounded-3xl border border-slate-100 mb-8">
            <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-4">Payment Methods:</h4>
            <div class="grid grid-cols-2 gap-4 text-xs font-bold">
                <div>
                    <p class="text-slate-900">M-PESA Paybill / Till:</p>
                    <p class="text-slate-500 font-medium"><?php echo htmlspecialchars($mpesaShortcode); ?>
                        <?php if ($first['unit_number']): ?>(Acct: <?php echo htmlspecialchars($first['unit_number']); ?>)<?php endif; ?>
                    </p>
                </div>
                <div>
                    <p class="text-slate-900">Contact:</p>
                    <p class="text-slate-500 font-medium"><?php echo htmlspecialchars($companyPhone ?: $companyEmail); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status chips -->
        <div class="flex flex-wrap gap-2 mb-10 no-print">
            <?php foreach ($invoices as $inv): ?>
            <div class="flex items-center gap-2 px-3 py-1.5 bg-slate-50 rounded-xl border border-slate-100">
                <span class="text-[10px] font-bold text-slate-500"><?php echo htmlspecialchars($inv['invoice_type']); ?></span>
                <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                <span class="text-[10px] font-black px-1.5 py-0.5 rounded
                    <?php echo match($inv['status']) { 'Paid' => 'bg-green-100 text-green-600', 'Overdue' => 'bg-red-100 text-red-600', default => 'bg-orange-100 text-orange-600' }; ?>">
                    <?php echo $inv['status']; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Footer -->
        <div class="mt-4 text-center border-t pt-10">
            <p class="text-[11px] font-black uppercase tracking-widest text-red-500 mb-1">
                Payment due by <?php echo date('F d, Y', strtotime($dueDate)); ?>
            </p>
            <?php if ($invoiceFooter): ?>
            <p class="text-[10px] font-medium text-slate-400 mb-2"><?php echo htmlspecialchars($invoiceFooter); ?></p>
            <?php endif; ?>
            <p class="text-[8px] text-slate-300 font-medium italic">This is a computer-generated invoice. No signature required. Batch: <?php echo $invoiceRef; ?></p>
        </div>
    </div>
</body>
</html>
