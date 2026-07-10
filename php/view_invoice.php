<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/settings.php';

$id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("
    SELECT i.*, t.full_name as tenant_name, t.email as tenant_email, t.phone as tenant_phone, t.id as tenant_db_id,
           p.title as property_title, u.unit_number
    FROM invoices i
    JOIN tenants t ON i.tenant_id = t.id
    LEFT JOIN leases l ON i.lease_id = l.id
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) die("Invoice not found.");

// Security: tenants can only view their own invoices
if (($_SESSION['role'] ?? '') === 'tenant') {
    $myStmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $myStmt->execute([$_SESSION['user_id']]);
    $myTenant = $myStmt->fetch();
    if (!$myTenant || $myTenant['id'] !== $invoice['tenant_db_id']) {
        http_response_code(403);
        die("Access denied.");
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $invoice['id']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
            .print-border { border: 1px solid #e2e8f0; }
        }
    </style>
</head>
<body class="bg-slate-50 font-sans p-4 md:p-10">
    <div class="max-w-3xl mx-auto bg-white p-8 md:p-12 shadow-2xl rounded-3xl print-border relative">
        <div class="no-print absolute top-5 right-5 flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-slate-900 text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:opacity-90">Print Invoice</button>
            <?php if (($_SESSION['role'] ?? '') !== 'tenant'): ?>
            <button onclick="document.getElementById('viEditModal').style.display='flex'"
                class="px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-blue-700">Edit</button>
            <?php endif; ?>
            <?php
            $backTenantId = $_GET['back_tenant'] ?? '';
            if ($backTenantId) {
                $backUrl = 'tenant_details.php?id=' . urlencode($backTenantId) . '&tab=invoices';
            } else {
                $backUrl = match($_SESSION['role'] ?? '') {
                    'tenant' => 'financials.php',
                    default  => 'invoices.php',
                };
            }
            ?>
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-200">Back</a>
        </div>

        <div class="flex justify-between items-start border-b pb-10 mb-10">
            <div>
                <h1 class="text-3xl font-black tracking-tighter text-slate-900 uppercase"><?php echo htmlspecialchars($companyName); ?></h1>
                <?php if ($companyTagline): ?><p class="text-slate-500 font-bold text-sm"><?php echo htmlspecialchars($companyTagline); ?></p><?php endif; ?>
                <p class="text-slate-400 text-xs font-medium"><?php echo htmlspecialchars($companyAddress); ?></p>
                <?php if ($companyPhone): ?><p class="text-slate-400 text-xs font-medium"><?php echo htmlspecialchars($companyPhone); ?></p><?php endif; ?>
                <?php if ($companyEmail): ?><p class="text-slate-400 text-xs font-medium"><?php echo htmlspecialchars($companyEmail); ?></p><?php endif; ?>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Invoice Date</p>
                <p class="text-sm font-black mb-4"><?php echo date('F d, Y', strtotime($invoice['created_at'])); ?></p>
                <p class="text-[10px] font-black text-red-500 uppercase tracking-widest">Due Date</p>
                <p class="text-sm font-black text-red-500"><?php echo date('F d, Y', strtotime($invoice['due_date'])); ?></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-10 mb-12">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Billed To:</p>
                <p class="text-lg font-black text-slate-900"><?php echo htmlspecialchars((string)$invoice['tenant_name']); ?></p>
                <p class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars((string)$invoice['tenant_email']); ?></p>
                <p class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars((string)$invoice['tenant_phone']); ?></p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Property Details:</p>
                <p class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars((string)$invoice['property_title']); ?></p>
                <p class="text-xs font-medium text-slate-500">Unit: <?php echo htmlspecialchars((string)$invoice['unit_number']); ?></p>
            </div>
        </div>

        <table class="w-full mb-12">
            <thead>
                <tr class="border-b-2 border-slate-900">
                    <th class="text-left py-4 text-[10px] font-black uppercase tracking-widest">Description</th>
                    <th class="text-right py-4 text-[10px] font-black uppercase tracking-widest">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-slate-100">
                    <td class="py-6">
                        <p class="font-black text-slate-900"><?php echo htmlspecialchars((string)$invoice['invoice_type']); ?></p>
                        <p class="text-xs text-slate-500 font-medium italic">
                            <?php echo !empty($invoice['description']) ? htmlspecialchars($invoice['description']) : 'Standard monthly charge for ' . htmlspecialchars((string)$invoice['invoice_type']) . ' billing.'; ?>
                        </p>
                    </td>
                    <td class="py-6 text-right font-black text-slate-900"><?php echo $currency; ?> <?php echo number_format($invoice['amount'], 2); ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td class="py-10 text-slate-400 text-xs font-medium italic">Payment should be made within <?php echo $invoiceDueDays; ?> day<?php echo $invoiceDueDays !== 1 ? 's' : ''; ?> of the due date to avoid penalties.</td>
                    <td class="py-10 text-right">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Amount Due</p>
                        <p class="text-3xl font-black text-slate-900"><?php echo $currency; ?> <?php echo number_format($invoice['amount'], 2); ?></p>
                    </td>
                </tr>
            </tfoot>
        </table>

        <?php if ($mpesaShortcode && $mpesaShortcode !== '—'): ?>
        <div class="p-8 bg-slate-50 rounded-3xl border border-slate-100">
            <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-4">Payment Methods:</h4>
            <div class="grid grid-cols-2 gap-4 text-xs font-bold">
                <div>
                    <p class="text-slate-900">M-PESA Paybill / Till:</p>
                    <p class="text-slate-500 font-medium"><?php echo htmlspecialchars($mpesaShortcode); ?> (Acct: <?php echo htmlspecialchars((string)$invoice['unit_number']); ?>)</p>
                </div>
                <div>
                    <p class="text-slate-900">Contact:</p>
                    <p class="text-slate-500 font-medium"><?php echo htmlspecialchars($companyPhone ?: $companyEmail); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-12 text-center border-t pt-10">
            <p class="text-[11px] font-black uppercase tracking-widest text-red-500 mb-1">
                Payment due by <?php echo date('F d, Y', strtotime($invoice['due_date'])); ?>
            </p>
            <?php if ($invoiceFooter): ?>
            <p class="text-[10px] font-medium text-slate-400 mb-2"><?php echo htmlspecialchars($invoiceFooter); ?></p>
            <?php endif; ?>
            <p class="text-[8px] text-slate-300 font-medium italic">This is a computer-generated invoice. No signature required.</p>
        </div>
    </div>

<?php if (($_SESSION['role'] ?? '') !== 'tenant'): ?>
<!-- Edit Invoice Modal -->
<div id="viEditModal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:1.5rem;padding:2rem;max-width:520px;width:100%;position:relative;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);" onclick="event.stopPropagation()">
        <button onclick="document.getElementById('viEditModal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;cursor:pointer;color:#94a3b8;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;">
            <div style="width:40px;height:40px;border-radius:.75rem;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#3b82f6;flex-shrink:0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>
            </div>
            <div>
                <h3 style="font-weight:900;font-size:1.1rem;color:#0f172a;margin:0;">Correct Invoice</h3>
                <p style="font-size:.8rem;color:#94a3b8;margin:.2rem 0 0;">
                    <?php echo htmlspecialchars((string)$invoice['tenant_name']); ?> ·
                    <?php echo htmlspecialchars((string)$invoice['invoice_type']); ?>
                    <?php if ($invoice['unit_number']): ?> · Unit <?php echo htmlspecialchars((string)$invoice['unit_number']); ?><?php endif; ?>
                </p>
            </div>
        </div>
        <form action="actions/invoice_actions.php" method="POST" style="display:flex;flex-direction:column;gap:1rem;">
            <input type="hidden" name="action" value="edit_invoice">
            <input type="hidden" name="invoice_id" value="<?php echo htmlspecialchars($invoice['id']); ?>">
            <input type="hidden" name="_redirect" value="../view_invoice.php?id=<?php echo urlencode($invoice['id']); ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Invoice Type</label>
                    <select name="invoice_type" style="width:100%;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;background:#fff;outline:none;">
                        <?php foreach (['Rent','Water','Garbage','Electricity','Deposit','Service Charge','Penalty','Other'] as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $invoice['invoice_type'] === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Status</label>
                    <select name="status" style="width:100%;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;background:#fff;outline:none;">
                        <?php foreach (['Unpaid','Paid','Partial','Overdue','Cancelled'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $invoice['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Amount *</label>
                    <input type="number" name="amount" required min="1" step="0.01"
                           value="<?php echo htmlspecialchars((string)$invoice['amount']); ?>"
                           style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;outline:none;">
                </div>
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Due Date *</label>
                    <input type="date" name="due_date" required
                           value="<?php echo htmlspecialchars($invoice['due_date']); ?>"
                           style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;outline:none;">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Notes</label>
                <textarea name="description" rows="2"
                    style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;color:#0f172a;resize:none;outline:none;"
                    placeholder="Invoice notes…"><?php echo htmlspecialchars($invoice['description'] ?? ''); ?></textarea>
            </div>
            <div style="background:#f8fafc;border-radius:1rem;padding:1rem;border:1px solid #e2e8f0;">
                <label style="display:flex;align-items:center;gap:.65rem;cursor:pointer;">
                    <input type="checkbox" name="notify_tenant" value="1" id="vi_notify" onchange="document.getElementById('vi_reason_wrap').style.display=this.checked?'block':'none'"
                        style="width:16px;height:16px;accent-color:#3b82f6;cursor:pointer;">
                    <span style="font-size:.85rem;font-weight:700;color:#334155;">Email tenant a corrected invoice notification</span>
                </label>
                <div id="vi_reason_wrap" style="display:none;margin-top:.75rem;">
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Reason for Correction</label>
                    <textarea name="correction_reason" rows="2"
                        style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;color:#0f172a;resize:none;outline:none;"
                        placeholder="e.g. Incorrect amount entered…"></textarea>
                </div>
            </div>
            <button type="submit" style="width:100%;padding:.9rem;background:#2563eb;color:#fff;font-weight:900;font-size:.85rem;border:none;border-radius:.75rem;cursor:pointer;letter-spacing:.05em;text-transform:uppercase;">
                Save Correction
            </button>
        </form>
    </div>
</div>
<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('viEditModal').style.display = 'none';
});
</script>
<?php endif; ?>

</body>
</html>
