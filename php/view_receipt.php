<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("
    SELECT tr.*, t.full_name as tenant_name, t.email as tenant_email,
           p.title as property_title, u.unit_number
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id = t.id
    JOIN leases l ON tr.lease_id = l.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE tr.id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) die("Payment record not found.");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo $payment['id']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
            .print-border { border: 1px solid #e2e8f0; }
        }
    </style>
</head>
<body class="bg-slate-100 font-sans p-4 md:p-10 flex justify-center items-center min-vh-100">
    <div class="w-full max-w-2xl bg-white p-8 md:p-12 shadow-2xl rounded-3xl print-border relative overflow-hidden">
        <!-- Logo/Acccent Background -->
        <div class="absolute top-0 right-0 w-32 h-32 bg-accent-green/5 rounded-bl-full -mr-10 -mt-10"></div>
        
        <div class="no-print absolute top-5 right-5 flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-accent-green text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:opacity-90 transition-all">Print Receipt</button>
            <a href="financials.php" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold uppercase tracking-widest hover:bg-slate-200">Close</a>
        </div>

        <div class="text-center mb-10">
            <div class="inline-block p-4 bg-accent-green/10 rounded-3xl mb-4">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <h1 class="text-3xl font-black text-slate-900 uppercase tracking-tight">Payment Receipt</h1>
            <p class="text-slate-400 font-bold text-xs uppercase tracking-widest mt-1">Primelink Management Official Document</p>
        </div>

        <div class="flex justify-between items-center bg-slate-50 p-6 rounded-2xl mb-10 border border-slate-100">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Receipt No.</p>
                <p class="text-sm font-black text-slate-900">#<?php echo strtoupper(substr($payment['id'], 0, 8)); ?></p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Date Issued</p>
                <p class="text-sm font-black text-slate-900"><?php echo date('F d, Y', strtotime($payment['transaction_date'])); ?></p>
            </div>
        </div>

        <div class="space-y-6 mb-10">
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

        <div class="p-8 bg-slate-900 text-white rounded-3xl text-center shadow-xl">
            <p class="text-[10px] font-black uppercase tracking-widest opacity-60 mb-2">Total Amount Received</p>
            <h2 class="text-4xl font-black">KSh <?php echo number_format($payment['amount'], 2); ?></h2>
        </div>

        <?php if ($payment['description']): ?>
        <div class="mt-8 p-6 bg-slate-50 rounded-2xl">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Additional Note:</p>
            <p class="text-xs font-medium text-slate-600 italic">"<?php echo htmlspecialchars((string)$payment['description']); ?>"</p>
        </div>
        <?php endif; ?>

        <div class="mt-12 text-center">
            <p class="text-[9px] text-slate-400 font-bold uppercase tracking-[0.2em]">Authorized Primelink Digital Receipt</p>
        </div>
    </div>
</body>
</html>
