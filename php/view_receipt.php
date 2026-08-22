<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/corrections.php';
require_once __DIR__ . '/includes/tenant_notify.php';

ensureCorrectionSchema($pdo);

$id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("
    SELECT tr.*, t.full_name as tenant_name, t.email as tenant_email, t.phone as tenant_phone,
           t.user_id as tenant_user_id,
           p.title as property_title, u.unit_number
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id = t.id
    LEFT JOIN leases l ON tr.lease_id = l.id
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE tr.id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) die("Payment record not found.");

// Security: tenants can only view their own receipts
if (($_SESSION['role'] ?? '') === 'tenant') {
    if (($payment['tenant_user_id'] ?? '') !== ($_SESSION['user_id'] ?? '')) {
        http_response_code(403);
        die("Access denied.");
    }
}

$isStaff  = in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true);
$revision = (int)($payment['revision_no'] ?? 0);
$docNo    = docNumber(DOC_RECEIPT, $payment['id'], $revision);

$flashSuccess = $_GET['success'] ?? '';
$flashError   = $_GET['error']   ?? '';
$flashInfo    = $_GET['info']    ?? '';

$companyName = getSetting($pdo, 'company_name',    'Primelink Management System');
$currency    = getSetting($pdo, 'currency_symbol', 'KSh');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($docNo); ?><?php echo $revision > 0 ? ' (Corrected)' : ''; ?> &mdash; <?php echo htmlspecialchars($companyName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
            .print-border { border: 1px solid #e2e8f0; }
            .correction-banner { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-slate-100 font-sans p-4 md:p-10 flex flex-col justify-center items-center min-vh-100">
    <?php echo renderCorrectionWatermark($payment); ?>

    <?php if ($flashSuccess || $flashError || $flashInfo): ?>
    <div class="no-print w-full max-w-2xl mb-4 space-y-2">
        <?php if ($flashSuccess): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-2xl px-5 py-3 text-sm font-bold"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-2xl px-5 py-3 text-sm font-bold"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>
        <?php if ($flashInfo): ?>
        <div class="bg-slate-100 border border-slate-200 text-slate-700 rounded-2xl px-5 py-3 text-sm font-bold"><?php echo htmlspecialchars($flashInfo); ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="w-full max-w-2xl bg-white p-8 md:p-12 shadow-2xl rounded-3xl print-border relative overflow-hidden">
        <!-- Logo/Acccent Background -->
        <div class="absolute top-0 right-0 w-32 h-32 bg-accent-green/5 rounded-bl-full -mr-10 -mt-10"></div>
        
        <div class="no-print absolute top-5 right-5 flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-accent-green text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:opacity-90 transition-all">Print Receipt</button>
            <?php if ($isStaff): ?>
            <button onclick="document.getElementById('vrEditModal').style.display='flex'"
                class="px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-blue-700">Correct</button>
            <?php if ($revision > 0 && !empty($payment['tenant_email'])): ?>
            <form action="actions/invoice_actions.php" method="POST" class="inline"
                  onsubmit="return confirm('Re-send corrected receipt <?php echo htmlspecialchars($docNo, ENT_QUOTES); ?> to the tenant?')">
                <input type="hidden" name="action" value="resend_correction">
                <input type="hidden" name="doc_type" value="receipt">
                <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($payment['id']); ?>">
                <input type="hidden" name="_redirect" value="../view_receipt.php?id=<?php echo urlencode($payment['id']); ?>">
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-red-700">Re-issue</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
            <a href="<?php echo ($_SESSION['role'] ?? '') === 'tenant' ? 'my_payments.php' : 'invoices.php'; ?>"
               class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-200">Close</a>
        </div>

        <?php echo renderCorrectionBanner($payment, DOC_RECEIPT); ?>

        <div class="text-center mb-10 relative z-[2]">
            <div class="inline-block p-4 bg-accent-green/10 rounded-3xl mb-4">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <h1 class="text-3xl font-black text-slate-900 uppercase tracking-tight">Payment Receipt</h1>
            <p class="text-slate-400 font-bold text-xs uppercase tracking-widest mt-1"><?php echo htmlspecialchars($companyName); ?> — Official Document</p>
        </div>

        <div class="flex justify-between items-center bg-slate-50 p-6 rounded-2xl mb-10 border border-slate-100 relative z-[2]">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Receipt No.</p>
                <p class="text-sm font-black <?php echo $revision > 0 ? 'text-red-600' : 'text-slate-900'; ?>"><?php echo htmlspecialchars($docNo); ?></p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Date Issued</p>
                <p class="text-sm font-black text-slate-900"><?php echo date('F d, Y', strtotime($payment['transaction_date'])); ?></p>
            </div>
        </div>

        <div class="space-y-6 mb-10 relative z-[2]">
            <div class="flex justify-between border-b border-slate-100 pb-4">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest">Received From</span>
                <span class="text-sm font-black text-slate-900"><?php echo htmlspecialchars((string)$payment['tenant_name']); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-4">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest">Property / Unit</span>
                <span class="text-sm font-black text-slate-900"><?php echo htmlspecialchars((string)$payment['property_title']); ?> (<?php echo htmlspecialchars((string)$payment['unit_number']); ?>)</span>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-4">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest">Payment For</span>
                <span class="text-sm font-black text-slate-900"><?php echo htmlspecialchars((string)$payment['transaction_type']); ?></span>
            </div>
            <div class="flex justify-between border-b border-slate-100 pb-4">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest">Payment Method</span>
                <span class="text-sm font-black text-slate-900"><?php echo htmlspecialchars((string)$payment['payment_method']); ?></span>
            </div>
        </div>

        <div class="p-8 bg-slate-900 text-white rounded-3xl text-center shadow-xl relative z-[2]">
            <p class="text-[10px] font-black uppercase tracking-widest opacity-60 mb-2">Total Amount Received</p>
            <h2 class="text-4xl font-black"><?php echo $currency; ?> <?php echo number_format($payment['amount'], 2); ?></h2>
        </div>

        <?php if ($payment['description']): ?>
        <div class="mt-8 p-6 bg-slate-50 rounded-2xl">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Additional Note:</p>
            <p class="text-xs font-medium text-slate-600 italic">"<?php echo htmlspecialchars((string)$payment['description']); ?>"</p>
        </div>
        <?php endif; ?>

        <div class="mt-12 text-center relative z-[2]">
            <p class="text-[9px] text-slate-400 font-bold uppercase tracking-[0.2em]">Authorized <?php echo htmlspecialchars($companyName); ?> Digital Receipt</p>
            <p class="text-[9px] text-slate-300 font-medium mt-1">Please quote <?php echo htmlspecialchars($docNo); ?> in any correspondence about this payment.</p>
        </div>

        <?php if ($revision > 0): ?>
        <?php echo renderRevisionHistory($pdo, DOC_RECEIPT, $payment['id'], $isStaff); ?>
        <?php endif; ?>
    </div>

<?php if ($isStaff): ?>
<!-- Correct Receipt Modal -->
<div id="vrEditModal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:1.5rem;padding:2rem;max-width:500px;width:100%;position:relative;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);" onclick="event.stopPropagation()">
        <button onclick="document.getElementById('vrEditModal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;cursor:pointer;color:#94a3b8;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;">
            <div style="width:40px;height:40px;border-radius:.75rem;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#3b82f6;flex-shrink:0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>
            </div>
            <div>
                <h3 style="font-weight:900;font-size:1.1rem;color:#0f172a;margin:0;">Correct Receipt</h3>
                <p style="font-size:.8rem;color:#94a3b8;margin:.2rem 0 0;">
                    <?php echo htmlspecialchars($docNo); ?> &middot;
                    <?php echo htmlspecialchars((string)$payment['tenant_name']); ?>
                    <?php if ($payment['unit_number']): ?> · Unit <?php echo htmlspecialchars((string)$payment['unit_number']); ?><?php endif; ?>
                </p>
            </div>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:.9rem;padding:.85rem 1rem;margin-bottom:1.1rem;">
            <p style="margin:0;font-size:11.5px;color:#92400e;line-height:1.55;">
                This receipt has already been issued. Saving a correction creates
                <strong>revision <?php echo $revision + 1; ?></strong> (<?php echo htmlspecialchars(docNumber(DOC_RECEIPT, $payment['id'], $revision + 1)); ?>),
                stamps every copy as <strong>CORRECTED</strong>, and re-applies the payment to the linked invoice balance.
            </p>
        </div>
        <form action="actions/invoice_actions.php" method="POST" style="display:flex;flex-direction:column;gap:1rem;">
            <input type="hidden" name="action" value="edit_payment">
            <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($payment['id']); ?>">
            <input type="hidden" name="_redirect" value="../view_receipt.php?id=<?php echo urlencode($payment['id']); ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Amount *</label>
                    <input type="number" name="amount" required min="1" step="0.01"
                           value="<?php echo htmlspecialchars((string)$payment['amount']); ?>"
                           style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;outline:none;">
                </div>
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Payment Method</label>
                    <select name="payment_method" style="width:100%;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;background:#fff;outline:none;">
                        <?php foreach (['Cash','M-Pesa','Bank Transfer','Cheque','Other'] as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($payment['payment_method'] ?? '') === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Transaction Date *</label>
                    <input type="date" name="transaction_date" required
                           value="<?php echo htmlspecialchars($payment['transaction_date']); ?>"
                           style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;outline:none;">
                </div>
                <div>
                    <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Reference No.</label>
                    <input type="text" name="reference_number"
                           value="<?php echo htmlspecialchars($payment['reference_number'] ?? ''); ?>"
                           placeholder="e.g. RCX1234567"
                           style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;outline:none;">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">Notes</label>
                <input type="text" name="description"
                       value="<?php echo htmlspecialchars($payment['description'] ?? ''); ?>"
                       placeholder="Additional note…"
                       style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;font-weight:700;color:#0f172a;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;">
                    Reason for Correction * <span style="text-transform:none;letter-spacing:0;font-weight:600;">(recorded on the audit trail and shown to the tenant)</span>
                </label>
                <textarea name="correction_reason" rows="2" required minlength="5"
                    style="width:100%;box-sizing:border-box;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;font-size:.85rem;color:#0f172a;resize:none;outline:none;"
                    placeholder="e.g. M-Pesa code captured against the wrong amount…"></textarea>
            </div>
<?php
            echo renderNotifyChannels($pdo, [
                'email'         => $payment['tenant_email'] ?? '',
                'phone'         => $payment['tenant_phone'] ?? '',
                'email_checked' => true,
                'sms_checked'   => true,
                'sms_preview'   => 'CORRECTED: Dear Tenant, your Receipt ' . $docNo . ' has been revised to '
                                 . docNumber(DOC_RECEIPT, $payment['id'], $revision + 1)
                                 . '. Amount is now ' . $currency . ' 00,000.00. This replaces the earlier copy. - '
                                 . smsSignature($pdo),
            ]);
            ?>
            <button type="submit" style="width:100%;padding:.9rem;background:#2563eb;color:#fff;font-weight:900;font-size:.85rem;border:none;border-radius:.75rem;cursor:pointer;letter-spacing:.05em;text-transform:uppercase;">
                Save Correction
            </button>
        </form>
    </div>
</div>
<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('vrEditModal').style.display = 'none';
});
</script>
<?php endif; ?>

</body>
</html>
