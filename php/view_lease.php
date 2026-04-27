<?php
/**
 * View Lease Agreement
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$tenantId = $_GET['tenant_id'] ?? null;
if (!$tenantId) {
    die("Lease Agreement not found.");
}

// Security: Only Admin, Landlord, or the Tenant themselves can view
$userRole = $_SESSION['role'] ?? 'tenant';
if ($userRole === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ? AND id = ?");
    $stmt->execute([$_SESSION['user_id'], $tenantId]);
    if (!$stmt->fetch()) {
        die("Unauthorized access.");
    }
}

// Fetch Tenant & Signature Details
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die("Tenant records not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lease Agreement - <?php echo htmlspecialchars($tenant['full_name']); ?></title>
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
        <a href="documents.php" class="px-6 py-2 bg-slate-100 text-slate-600 rounded-lg font-bold text-sm">Return to Vault</a>
    </div>

    <div class="document-container">
        <!-- Header -->
        <div class="flex justify-between items-start border-b-2 border-slate-900 pb-8 mb-12">
            <div>
                <h1 class="text-3xl font-black uppercase tracking-tighter serif">Lease Agreement</h1>
                <p class="text-xs font-bold text-slate-500 mt-1 uppercase tracking-widest">Primelink Property Management System</p>
            </div>
            <div class="text-right">
                <p class="text-xs font-black uppercase text-slate-400">Document ID</p>
                <p class="text-sm font-bold font-mono">AG-<?php echo substr($tenant['id'], 0, 8); ?></p>
            </div>
        </div>

        <!-- Parties -->
        <div class="grid grid-cols-2 gap-12 mb-12">
            <div>
                <p class="text-[10px] font-black uppercase text-slate-400 mb-2 tracking-widest">Management Party</p>
                <div class="text-sm font-bold leading-relaxed">
                    <p>Primelink Management System</p>
                    <p>Compliance Department</p>
                    <p>system-primelinkproperties.co.ke</p>
                </div>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-slate-400 mb-2 tracking-widest">The Tenant</p>
                <div class="text-sm font-bold leading-relaxed">
                    <p><?php echo htmlspecialchars($tenant['full_name']); ?></p>
                    <p>ID: <?php echo htmlspecialchars($tenant['id_no'] ?? 'N/A'); ?></p>
                    <p><?php echo htmlspecialchars($tenant['current_address'] ?? 'N/A'); ?></p>
                </div>
            </div>
        </div>

        <!-- Terms -->
        <div class="space-y-6 text-sm text-slate-700 leading-relaxed mb-16">
            <p class="font-bold text-slate-900">This document serves as a binding electronic lease agreement and certification of occupancy terms.</p>
            
            <div class="space-y-4">
                <p><strong>1. AGREEMENT OF LEASE:</strong> The Tenant agrees to occupy the assigned property and abide by all rules set forth by the Management and the Landlord.</p>
                <p><strong>2. VERIFICATION:</strong> The Tenant certifies that all identity documents (including the uploaded ID copy) are original and valid.</p>
                <p><strong>3. UTILITY OBLIGATIONS:</strong> The Tenant agrees to purchase all Water and Electricity tokens exclusively through the PrimeLink platform.</p>
                <p><strong>4. DIGITAL EXECUTION:</strong> By registering on the PrimeLink platform, the Tenant provides a binding digital signature, which carries the same legal weight as a physical signature under the Electronic Transactions Act.</p>
            </div>
        </div>

        <!-- Execution -->
        <div class="pt-12 border-t border-slate-100 mt-20">
            <div class="flex justify-between items-end">
                <div>
                    <div class="mb-8">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Digital Signature (Tenant)</p>
                        <p class="serif italic text-2xl mb-1 text-slate-900"><?php echo htmlspecialchars($tenant['signature_name'] ?? $tenant['full_name']); ?></p>
                        <div class="signature-line w-64"></div>
                        <p class="text-[10px] font-bold text-slate-400 mt-2 italic">Digitally signed and verified via PrimeLink Security</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Signed Date</p>
                    <p class="text-sm font-bold text-slate-900"><?php echo $tenant['terms_accepted_at'] ? date('F d, Y - H:i', strtotime($tenant['terms_accepted_at'])) : 'N/A'; ?></p>
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
