<?php
/**
 * View Lease Agreement
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$leaseId = $_GET['lease_id'] ?? null;
$tenantId = $_GET['tenant_id'] ?? null;

if (!$leaseId && !$tenantId) {
    die("Lease Agreement not found.");
}

if ($leaseId) {
    // Fetch Specific Lease Details
    $stmt = $pdo->prepare("
        SELECT l.*, t.full_name, t.id_no, t.current_address, t.signature_name, t.terms_accepted_at, t.user_id as tenant_user_id,
               p.title as property_title, p.location as property_location
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN properties p ON l.property_id = p.id
        WHERE l.id = ?
    ");
    $stmt->execute([$leaseId]);
    $lease = $stmt->fetch();
} else {
    // Fetch Draft based on Tenant only
    $stmt = $pdo->prepare("SELECT *, user_id as tenant_user_id FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $lease = $stmt->fetch();
    if ($lease) {
        $lease['property_title'] = "TBD (Draft)";
        $lease['property_location'] = "Pending unit assignment";
        $lease['monthly_rent'] = 0;
        $lease['deposit_amount'] = 0;
        $lease['start_date'] = date('Y-m-d');
        $lease['end_date'] = date('Y-m-d', strtotime('+1 year'));
        $lease['terms'] = "Standard draft terms apply.";
    }
}

if (!$lease) {
    die("Lease records not found.");
}

// Security: Only Admin, Landlord, or the Tenant themselves can view
$userRole = $_SESSION['role'] ?? 'tenant';
if ($userRole === 'tenant') {
    if ($lease['tenant_user_id'] != $_SESSION['user_id']) {
        die("Unauthorized access.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lease Agreement - <?php echo htmlspecialchars($lease['full_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; }
        .document-container { max-width: 800px; margin: 40px auto; background: #fff; padding: 60px; box-shadow: 0 10px 40px rgba(0,0,0,0.05); border-radius: 4px; }
        .serif { font-family: 'Playfair Display', serif; }
        @media print {
            body { background: #fff; padding: 0; }
            .document-container { box-shadow: none; margin: 0; padding: 40px; }
            .no-print { display: none; }
        }
        .signature-line { border-bottom: 2px solid #000; display: inline-block; min-width: 250px; padding-bottom: 4px; }
    </style>
</head>
<body class="p-4 sm:p-8">
    <div class="no-print flex justify-center mb-10 gap-4">
        <button onclick="window.print()" class="px-6 py-2 bg-slate-900 text-white rounded-lg font-bold text-sm shadow-lg hover:bg-slate-800 transition-all">Print Agreement</button>
        <a href="leases.php" class="px-6 py-2 bg-slate-100 text-slate-600 rounded-lg font-bold text-sm">Return to Leases</a>
    </div>

    <div class="document-container">
        <!-- Header -->
        <div class="flex justify-between items-start border-b-2 border-slate-900 pb-8 mb-12">
            <div>
                <h1 class="text-3xl font-black uppercase tracking-tighter serif text-slate-900">Lease Agreement</h1>
                <p class="text-xs font-bold text-slate-500 mt-1 uppercase tracking-widest">Primelink Property Management System</p>
            </div>
            <div class="text-right">
                <p class="text-xs font-black uppercase text-slate-400">Document ID</p>
                <p class="text-sm font-bold font-mono">LS-<?php echo substr($lease['id'], 0, 8); ?></p>
            </div>
        </div>

        <!-- Parties -->
        <div class="grid grid-cols-2 gap-12 mb-12">
            <div>
                <p class="text-[10px] font-black uppercase text-slate-400 mb-2 tracking-widest">Landlord / Management</p>
                <div class="text-sm font-bold leading-relaxed">
                    <p>Primelink Management System</p>
                    <p>acting on behalf of the owner of:</p>
                    <p class="text-accent-green"><?php echo htmlspecialchars($lease['property_title']); ?></p>
                    <p class="text-slate-500 text-xs mt-1"><?php echo htmlspecialchars($lease['property_location']); ?></p>
                </div>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-slate-400 mb-2 tracking-widest">The Tenant</p>
                <div class="text-sm font-bold leading-relaxed">
                    <p><?php echo htmlspecialchars($lease['full_name']); ?></p>
                    <p>ID: <?php echo htmlspecialchars($lease['id_no'] ?? 'N/A'); ?></p>
                    <p><?php echo htmlspecialchars($lease['current_address'] ?? 'N/A'); ?></p>
                </div>
            </div>
        </div>

        <!-- Financial Summary -->
        <div class="bg-slate-50 p-6 rounded-lg mb-12 border border-slate-100">
            <h3 class="text-[10px] font-black uppercase text-slate-400 mb-4 tracking-widest">Financial Terms</h3>
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <p class="text-xs text-slate-400">Monthly Rent</p>
                    <p class="text-xl font-black text-slate-900">KSh <?php echo number_format($lease['monthly_rent']); ?></p>
                </div>
                <div>
                    <p class="text-xs text-slate-400">Security Deposit</p>
                    <p class="text-xl font-black text-slate-900">KSh <?php echo number_format($lease['deposit_amount']); ?></p>
                </div>
            </div>
        </div>

        <!-- Terms -->
        <div class="space-y-6 text-sm text-slate-700 leading-relaxed mb-16">
            <p class="font-bold text-slate-900">This agreement commences on <u><?php echo date('F d, Y', strtotime($lease['start_date'])); ?></u> and shall terminate on <u><?php echo date('F d, Y', strtotime($lease['end_date'])); ?></u> unless renewed or terminated earlier under the laws of the Republic of Kenya.</p>
            
            <div class="space-y-4">
                <p><strong>1. AGREEMENT OF LEASE:</strong> The Tenant agrees to occupy the assigned property and abide by all rules set forth by the Management and the Landlord.</p>
                <p><strong>2. RENT PAYMENT:</strong> Rent is payable on or before the 5th day of every month through the PrimeLink platform.</p>
                <p><strong>3. UTILITY OBLIGATIONS:</strong> The Tenant agrees to purchase all Water and Electricity tokens exclusively through the PrimeLink platform.</p>
                <p><strong>4. ADDITIONAL TERMS:</strong> <?php echo nl2br(htmlspecialchars($lease['terms'] ?? 'Standard management terms apply.')); ?></p>
            </div>
        </div>

        <!-- Execution -->
        <div class="pt-12 border-t border-slate-100 mt-20">
            <div class="flex justify-between items-end">
                <div>
                    <div class="mb-8">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Digital Signature (Tenant)</p>
                        <p class="serif italic text-2xl mb-1 text-slate-900"><?php echo htmlspecialchars($lease['signature_name'] ?? $lease['full_name']); ?></p>
                        <div class="signature-line w-64"></div>
                        <p class="text-[10px] font-bold text-slate-400 mt-2 italic">Digitally signed and verified via PrimeLink Security</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Signed Date</p>
                    <p class="text-sm font-bold text-slate-900"><?php echo $lease['terms_accepted_at'] ? date('F d, Y - H:i', strtotime($lease['terms_accepted_at'])) : 'Draft Document'; ?></p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-24 pt-8 border-t border-slate-100 text-center">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-[0.3em]">Confidential Property Document • PrimeLink Ecosystem</p>
        </div>
    </div>
</body>
</html>
