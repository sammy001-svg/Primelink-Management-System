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
               t.phone as tenant_phone, t.email as tenant_email,
               t.spouse_name, t.spouse_id_no, t.spouse_phone,
               p.title as property_title, p.location as property_location, p.property_code,
               u.unit_number, u.monthly_rent, u.deposit_amount,
               lr.full_name as landlord_name
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN units u ON l.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        LEFT JOIN landlords lr ON p.landlord_id = lr.id
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
        $lease['property_title'] = "________________";
        $lease['property_location'] = "________________";
        $lease['property_code'] = "________________";
        $lease['landlord_name'] = "________________";
        $lease['unit_number'] = "____";
        $lease['monthly_rent'] = 0;
        $lease['deposit_amount'] = 0;
        $lease['start_date'] = date('Y-m-d');
        $lease['end_date'] = date('Y-m-d', strtotime('+1 year'));
        $lease['terms_accepted_at'] = null;
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
    <title>Tenancy Agreement - <?php echo htmlspecialchars($lease['full_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #1e293b; }
        .document-container { max-width: 900px; margin: 40px auto; background: #fff; padding: 80px; box-shadow: 0 20px 50px rgba(0,0,0,0.05); border-radius: 8px; position: relative; }
        .serif { font-family: 'Playfair Display', serif; }
        .clause-title { font-weight: 800; text-transform: uppercase; font-size: 11px; letter-spacing: 0.1em; color: #64748b; margin-top: 2rem; margin-bottom: 0.75rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.25rem; }
        p { margin-bottom: 1rem; line-height: 1.7; font-size: 13px; text-align: justify; }
        .highlight { font-weight: 700; color: #0f172a; text-decoration: underline; }
        @media print {
            body { background: #fff; padding: 0; }
            .document-container { box-shadow: none; margin: 0; padding: 40px; width: 100% !important; max-width: 100% !important; }
            .no-print { display: none; }
        }
    </style>
</head>
<body class="p-4 sm:p-8">
    <div class="no-print flex justify-center mb-10 gap-4">
        <button onclick="window.print()" class="px-8 py-3 bg-slate-900 text-white rounded-2xl font-bold text-sm shadow-xl hover:bg-slate-800 transition-all flex items-center gap-2">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
            Print Tenancy Agreement
        </button>
        <a href="javascript:history.back()" class="px-8 py-3 bg-white text-slate-600 rounded-2xl font-bold text-sm border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
             Back
        </a>
    </div>

    <div class="document-container">
        <!-- Logo/Header -->
        <div class="text-center mb-16">
            <h1 class="text-4xl font-black serif tracking-tight text-slate-900 mb-2">TENANCY AGREEMENT</h1>
            <p class="text-[10px] font-black uppercase tracking-[0.4em] text-slate-400">PrimeLink Properties Ltd • Authorized Real Estate Agency</p>
        </div>

        <div class="space-y-6">
            <p>This tenancy agreement is made on this <span class="highlight"><?php echo date('jS', strtotime($lease['terms_accepted_at'] ?? 'now')); ?></span> day of <span class="highlight"><?php echo date('F', strtotime($lease['terms_accepted_at'] ?? 'now')); ?></span> year <span class="highlight"><?php echo date('Y', strtotime($lease['terms_accepted_at'] ?? 'now')); ?></span>, between <strong>Prime Link Properties Ltd</strong> as the Agent of the Landlord Mr/Ms <span class="highlight"><?php echo htmlspecialchars($lease['landlord_name'] ?? '________________'); ?></span> hereinafter called “the Landlord” which the expression shall where the context so admits include his/her successors, assigns and agents of one party and <strong>Mr/Ms <span class="highlight"><?php echo htmlspecialchars($lease['full_name']); ?></span></strong> of ID No <span class="highlight"><?php echo htmlspecialchars($lease['id_no']); ?></span>, Tel <span class="highlight"><?php echo htmlspecialchars($lease['tenant_phone'] ?? 'N/A'); ?></span> and postal address No <span class="highlight"><?php echo htmlspecialchars($lease['current_address'] ?? 'N/A'); ?></span> Email <span class="highlight"><?php echo htmlspecialchars($lease['tenant_email'] ?? 'N/A'); ?></span> hereinafter called “the Tenant” which expression shall where the context so admits include his/her successors, assigns and agents of the other part.</p>

            <p><strong>Tenant Spouse:</strong> <span class="highlight"><?php echo htmlspecialchars($lease['spouse_name'] ?? 'N/A'); ?></span> <strong>ID:</strong> <span class="highlight"><?php echo htmlspecialchars($lease['spouse_id_no'] ?? 'N/A'); ?></span> <strong>Tel:</strong> <span class="highlight"><?php echo htmlspecialchars($lease['spouse_phone'] ?? 'N/A'); ?></span></p>

            <p><strong>WHEREAS:</strong><br>
            A. The Landlord is the legitimate owner of the property known as LR/No <span class="highlight"><?php echo htmlspecialchars($lease['property_code'] ?? '________________'); ?></span> Named <span class="highlight"><?php echo htmlspecialchars($lease['property_title']); ?></span> located at <span class="highlight"><?php echo htmlspecialchars($lease['property_location']); ?></span><br>
            B. Prime Link Properties Ltd is a Limited Liability Company and it is the authorized agent of the landlord/landlady.<br>
            C. The Landlord has agreed to let unit no <span class="highlight"><?php echo htmlspecialchars($lease['unit_number']); ?></span> of the property to the Tenant through his/her agent under the following terms and conditions.</p>

            <div class="clause-title">Section 1: Rental Terms & Financials</div>
            <p>1. That the rented unit is rented together with part corridors, staircases, lobbies, toilets otherwise known as common areas where applicable.</p>
            <p>2. Rent is payable per month in advance but before 5th day of each month. The rent payable exclusive of VAT, service charge, electricity and other charges is <strong>Ksh <span class="highlight"><?php echo number_format($lease['monthly_rent']); ?></span></strong>. Electricity & water will be paid separately/ together with rent as per the consumption.</p>
            <p>3. Tenant to deposit interest free deposit with the Landlord amount equivalent to <span class="highlight">1</span> month rent and <strong>Ksh <span class="highlight">2,500</span></strong> for electricity deposit and <strong>ksh <span class="highlight">1,500</span></strong> for water deposit which the landlord shall hold until the expiry or termination of this agreement.</p>
            <p>4. Rent payments are due in advance but before 5th day of each month. Any payment after the 7th shall attract a compounded 10% late payment penalty subject to a minimum of ksh 500.</p>

            <div class="clause-title">Section 2: Maintenance & Care</div>
            <p>7. Tenant is required to take responsibility by maintaining cleanliness at all times. Garbage should only be deposited at the designated area, drains both interior and exterior should be kept free from obstructions and in proper working order.</p>
            <p>10.4. Not make any alterations to any of the said premises or to erect any fixtures thereon or drive nails, screws, bolts or wedges in the floor, walls or ceiling unless with written consent of the landlord. Each nail, screw, bolt or wedge put on the walls, floor or ceiling will be charged at ksh two hundred each (ksh 200).</p>

            <div class="clause-title">Section 3: Possession & Quiet Enjoyment</div>
            <p>11.1. The Landlord shall allow the tenant have the right to quiet possession and enjoyment of the Property, subject to the Tenant paying the rent and observing the obligations set out in this Agreement.</p>

            <div class="clause-title">Section 4: Termination & Vacation</div>
            <p>12.2. If the tenant voluntarily wishes to terminate this agreement he/she will be required to give in writing one (1) calendar month notice in advance, or pay 1 month rent in lieu of notice.</p>
            <p>14.5. To qualify for deposit refund you must also have stayed in the premises for a minimum of 3 months.</p>

            <div class="clause-title">Section 5: Execution & Digital Signature</div>
            <p>This tenancy agreement is for a period of <span class="highlight">ONE (1)</span> year from the date of agreement and will only be renewed on expiry if the Landlord is satisfied with the tenant's performance.</p>

            <div class="mt-20 grid grid-cols-2 gap-16">
                <div class="space-y-2">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Digitally Signed By (Tenant)</p>
                    <p class="serif italic text-3xl text-slate-900"><?php echo htmlspecialchars($lease['signature_name'] ?? $lease['full_name']); ?></p>
                    <div class="h-0.5 bg-slate-900 w-full mt-2"></div>
                    <p class="text-[10px] font-bold text-slate-400 mt-2">Authenticated on <?php echo $lease['terms_accepted_at'] ? date('F d, Y - H:i', strtotime($lease['terms_accepted_at'])) : 'Pending Signature'; ?></p>
                </div>
                <div class="space-y-2">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Authorized Agent (PrimeLink)</p>
                    <div class="h-16 flex items-center">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/3/3a/Jon_Kirsch_Signature.png" class="h-full opacity-80 grayscale contrast-125" alt="Agent Signature">
                    </div>
                    <div class="h-0.5 bg-slate-900 w-full mt-2"></div>
                    <p class="text-[10px] font-bold text-slate-400 mt-2">Prime Link Properties Ltd - Management Stamp</p>
                </div>
            </div>
        </div>

        <div class="mt-32 pt-8 border-t border-slate-100 text-center">
            <p class="text-[8px] font-bold text-slate-300 uppercase tracking-[0.5em]">This document is electronically generated and holds full legal weight under the Laws of Kenya.</p>
        </div>
    </div>
</body>
</html>
