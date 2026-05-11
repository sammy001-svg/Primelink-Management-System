<?php
/**
 * Registration Page - Digital Lease & Signature
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = null;
$generatedId = null;

// Proactive Schema Repair for Units table
try {
    $pdo->query("SELECT monthly_rent FROM units LIMIT 1");
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        try {
            $pdo->exec("ALTER TABLE `units` CHANGE COLUMN `rent_amount` `monthly_rent` DECIMAL(15,2) NOT NULL DEFAULT 0");
        } catch (Exception $ex) {
            $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `monthly_rent` DECIMAL(15,2) NOT NULL DEFAULT 0");
        }
        $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `monthly_rent` ");
    }
}

// Fetch Available Properties and Units
$allProperties = $pdo->query("
    SELECT p.*, l.full_name as landlord_name 
    FROM properties p 
    LEFT JOIN landlords l ON p.landlord_id = l.id 
    ORDER BY p.title
")->fetchAll();

$allUnits = $pdo->query("
    SELECT u.*, p.title as property_title, p.location as property_location, p.property_code, l.full_name as landlord_name
    FROM units u 
    JOIN properties p ON u.property_id = p.id 
    LEFT JOIN landlords l ON p.landlord_id = l.id
    WHERE u.status = 'Available' 
    ORDER BY u.unit_number
")->fetchAll();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $role = $_POST['role'] ?? 'tenant';
    $terms = $_POST['terms'] ?? '';

    // Advanced Fields
    $spouseName = $_POST['spouse_name'] ?? null;
    $idNo = $_POST['id_no'] ?? null;
    $spouseIdNo = $_POST['spouse_id_no'] ?? null;
    $spousePhone = $_POST['spouse_phone'] ?? null;
    $maritalStatus = $_POST['marital_status'] ?? 'Single';
    $hasKids = isset($_POST['has_kids']) ? 1 : 0;
    $currentAddress = $_POST['current_address'] ?? null;
    $spouseEmail = $_POST['spouse_email'] ?? null;
    $altContact = $_POST['alt_contact'] ?? null;
    $spouseAltContact = $_POST['spouse_alt_contact'] ?? null;
    $profession = $_POST['profession'] ?? null;
    $spouseProfession = $_POST['spouse_profession'] ?? null;
    $employerName = $_POST['employer_name'] ?? null;
    $spouseEmployerName = $_POST['spouse_employer_name'] ?? null;
    $occupationType = $_POST['occupation_type'] ?? 'Residential';
    $businessName = $_POST['business_name'] ?? null;
    $businessNature = $_POST['business_nature'] ?? null;
    $businessLocation = $_POST['business_location'] ?? null;
    $nokName = $_POST['nok_name'] ?? null;
    $nokContact = $_POST['nok_contact'] ?? null;
    $nokRelationship = $_POST['nok_relationship'] ?? null;
    $address = $_POST['address'] ?? ''; // Global address for all roles

    if ($role === 'tenant' && empty($terms)) {
        $error = "You must accept the Lease Agreement (Terms and Conditions).";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif (!empty($fullName) && !empty($email) && !empty($password)) {
        try {
            // 0. PRE-FLIGHT: Self-Healing (Outside transaction to avoid implicit commit)
            if ($role === 'tenant') {
                try {
                    $pdo->query("SELECT id_no FROM tenants LIMIT 1");
                } catch (PDOException $e) {
                    if ($e->getCode() == '42S22') {
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `id_no` VARCHAR(100) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `id_copy_url` TEXT NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `terms_accepted_at` TIMESTAMP NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `marital_status` VARCHAR(50) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `has_kids` TINYINT(1) DEFAULT 0");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `current_address` TEXT NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_name` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_phone` VARCHAR(50) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_id_no` VARCHAR(100) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_email` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `profession` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `employer_name` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `occupation_type` VARCHAR(100) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `business_name` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `business_nature` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_name` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_contact` VARCHAR(255) NULL");
                        $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_relationship` VARCHAR(100) NULL");
                    }
                }
            }

            // 1. Check for existing email & Resolve Orphan status
            $stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch();
            
            if ($existingUser) {
                $userId = $existingUser['id'];
                if ($role === 'tenant') {
                    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ? OR email = ?");
                    $stmt->execute([$userId, $email]);
                    if ($stmt->fetch()) {
                        $error = "This email is already registered and your account is active. Please login.";
                    }
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM profiles WHERE id = ?");
                    $stmt->execute([$userId]);
                    if ($stmt->fetch()) {
                        $error = "Email already registered. Please login.";
                    }
                }
                if ($error) throw new Exception($error);
            } else {
                $userId = generateUUID();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $email, $hashedPassword, $role]);
                $pdo->commit();
            }

            $pdo->beginTransaction();
            
            // Handle File Uploads
            $idCopyUrl = null;
            $spouseIdCopyUrl = null;
            
            if ($role === 'tenant') {
                if (isset($_FILES['id_copy']) && $_FILES['id_copy']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['id_copy']['name'], PATHINFO_EXTENSION);
                    $fileName = "id_" . substr($userId, 0, 8) . "_" . time() . "." . $ext;
                    if (!is_dir(__DIR__ . "/uploads/ids")) mkdir(__DIR__ . "/uploads/ids", 0777, true);
                    move_uploaded_file($_FILES['id_copy']['tmp_name'], __DIR__ . "/uploads/ids/" . $fileName);
                    $idCopyUrl = "php/uploads/ids/" . $fileName;
                }
                
                if (isset($_FILES['spouse_id_copy']) && $_FILES['spouse_id_copy']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['spouse_id_copy']['name'], PATHINFO_EXTENSION);
                    $fileName = "spouse_id_" . substr($userId, 0, 8) . "_" . time() . "." . $ext;
                    if (!is_dir(__DIR__ . "/uploads/ids")) mkdir(__DIR__ . "/uploads/ids", 0777, true);
                    move_uploaded_file($_FILES['spouse_id_copy']['tmp_name'], __DIR__ . "/uploads/ids/" . $fileName);
                    $spouseIdCopyUrl = "php/uploads/ids/" . $fileName;
                }
            }

            // 1. Create Profile
            $stmt = $pdo->prepare("SELECT id FROM profiles WHERE id = ?");
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role, address) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $fullName, $email, $phone, $role, $address]);
                } catch (PDOException $e) {
                    if ($e->getCode() == '42S22') {
                        $pdo->exec("ALTER TABLE `profiles` ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone` ");
                        $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role, address) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$userId, $fullName, $email, $phone, $role, $address]);
                    } else { throw $e; }
                }
            }
            
            // 2. Create Tenant Record
            if ($role === 'tenant') {
                $tenantId = generateUUID();
                $tenantSql = "INSERT INTO tenants (
                    id, user_id, full_name, email, phone, status,
                    terms_accepted_at, signature_name,
                    spouse_name, id_no, spouse_id_no, id_copy_url, spouse_id_copy_url,
                    spouse_phone, marital_status, has_kids, current_address,
                    spouse_email, alt_contact, spouse_alt_contact,
                    profession, spouse_profession, employer_name, spouse_employer_name,
                    occupation_type, business_name, business_nature, business_location,
                    next_of_kin_name, next_of_kin_contact, next_of_kin_relationship
                ) VALUES (
                    ?, ?, ?, ?, ?, 'Pending',
                    NOW(), ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?
                )";
                $stmt = $pdo->prepare($tenantSql);
                $stmt->execute([
                    $tenantId, $userId, $fullName, $email, $phone,
                    $fullName, $spouseName, $idNo, $spouseIdNo, $idCopyUrl, $spouseIdCopyUrl,
                    $spousePhone, $maritalStatus, $hasKids, $address,
                    $spouseEmail, $altContact, $spouseAltContact,
                    $profession, $spouseProfession, $employerName, $spouseEmployerName,
                    $occupationType, $businessName, $businessNature, $businessLocation,
                    $nokName, $nokContact, $nokRelationship
                ]);

                // AUTOMATIC DOCUMENT GENERATION
                $leaseDocId = generateUUID();
                $stmt = $pdo->prepare("INSERT INTO documents (id, tenant_id, title, category, file_url, file_size) VALUES (?, ?, ?, 'Lease', ?, 'Generated')");
                $stmt->execute([$leaseDocId, $tenantId, "Signed Lease Agreement - " . $fullName, "view_lease.php?tenant_id=" . $tenantId]);

                if ($idCopyUrl) {
                    $idDocId = generateUUID();
                    $stmt = $pdo->prepare("INSERT INTO documents (id, tenant_id, title, category, file_url, file_size) VALUES (?, ?, ?, 'ID', ?, 'Upload')");
                    $stmt->execute([$idDocId, $tenantId, "ID Verification Copy - " . $fullName, $idCopyUrl]);
                }

                // LEASE ASSIGNMENT (STEP 0 DATA)
                $propertyId = $_POST['property_id'] ?? null;
                $unitId = $_POST['unit_id'] ?? null;

                if ($propertyId && $unitId) {
                    $leaseId = generateUUID();
                    $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit_amount, status) 
                                         SELECT ?, ?, ?, id, NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), monthly_rent, deposit_amount, 'Active' 
                                         FROM units WHERE id = ?");
                    $stmt->execute([$leaseId, $tenantId, $propertyId, $unitId]);
                    
                    $stmt = $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE id = ?");
                    $stmt->execute([$unitId]);

                    // --- IMMEDIATE BILLING ON REGISTRATION ---
                    require_once __DIR__ . '/includes/automated_billing.php';
                    generateInitialInvoices($pdo, $tenantId, $leaseId, $unitId);
                }
            }
            
            if ($pdo->inTransaction()) $pdo->commit();
            $suffix = $role === 'tenant' ? 'T' : ($role === 'utility' ? 'U' : 'X');
            $generatedId = "PRM-" . substr($userId, 0, 4) . "-" . $suffix;
            $success = true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Registration failed: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Primelink Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { 
                        'accent-green': '#22c55e',
                        'accent-orange': '#f97316'
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Outfit', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        .role-option input:checked + .role-card { border-color: #22c55e; background-color: rgba(34, 197, 94, 0.1); }
        .section-header::after { content: ''; display: block; width: 40px; height: 3px; background: #22c55e; margin-top: 4px; border-radius: 2px; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
        .tos-box { scroll-behavior: smooth; border: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-50 min-h-screen font-sans antialiased selection:bg-accent-green/30">
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-12">
        <!-- Marketing Side -->
        <div class="hidden lg:flex lg:col-span-5 xl:col-span-6 overflow-hidden bg-slate-900 h-screen sticky top-0">
            <div class="absolute inset-0 z-0">
                <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=1200" alt="Real Estate" class="w-full h-full object-cover opacity-40">
                <div class="absolute inset-0 bg-linear-to-br from-green-600/20 to-orange-600/20 mix-blend-multiply opacity-60"></div>
                <div class="absolute inset-0 bg-linear-to-t from-slate-950 via-slate-900/60 to-transparent"></div>
            </div>
            <div class="relative z-10 w-full h-full flex flex-col justify-between p-16">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-white/10 backdrop-blur-xl rounded-2xl border border-white/20 text-white shadow-2xl">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <span class="text-3xl font-black text-white tracking-widest uppercase">PrimeLink</span>
                </div>
                <div class="max-w-xl space-y-6">
                    <h2 class="text-6xl font-black text-white leading-tight drop-shadow-xl">Digital <br>Leasing <br>Simplified.</h2>
                    <p class="text-xl text-slate-300 font-medium leading-relaxed max-w-lg">Your residency is just a signature away. Secure, transparent, and fully digital lease management.</p>
                </div>
                <div class="flex items-center gap-8 text-[10px] font-black text-white/50 uppercase tracking-[0.4em]"><span>Legal</span><span>Digital</span><span>Unified</span></div>
            </div>
        </div>

        <!-- Auth Form Side -->
        <div class="col-span-1 lg:col-span-7 xl:col-span-6 flex items-start justify-center p-8 sm:p-12 relative bg-white dark:bg-slate-950 overflow-y-auto pt-24">
            <div class="w-full max-w-2xl space-y-8">
                <?php if ($success): ?>
                    <div class="text-center space-y-6 animate-in zoom-in duration-500 py-20">
                        <div class="w-24 h-24 bg-green-500/10 text-green-500 rounded-full flex items-center justify-center mx-auto mb-4 ring-8 ring-green-500/5">
                            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <h2 class="text-4xl font-black text-slate-900 dark:text-white tracking-tight">Lease Signed!</h2>
                        <p class="text-slate-500 dark:text-slate-400 font-medium text-lg text-center max-w-md mx-auto">Your digital lease has been executed. Access your documents in the vault.</p>
                        
                        <div class="p-8 bg-slate-900 rounded-3xl border-2 border-accent-green shadow-2xl space-y-2 relative overflow-hidden max-w-sm mx-auto">
                            <p class="text-[10px] font-black text-accent-green uppercase tracking-[0.3em]">Unique ID</p>
                            <p class="text-4xl font-black text-white tracking-widest font-mono"><?php echo $generatedId; ?></p>
                        </div>
                        <a href="login.php" class="inline-block px-12 py-5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-xs uppercase tracking-widest text-center shadow-lg transform transition hover:scale-105 active:scale-95">Continue to Portal</a>
                    </div>
                <?php else: ?>
                    <form action="register.php" method="POST" enctype="multipart/form-data" class="space-y-12">
                        <div class="space-y-4">
                            <h2 class="text-4xl font-black text-slate-900 dark:text-white tracking-tight">Tenant Registry</h2>
                            <p class="text-slate-500 dark:text-slate-400 font-medium">Capture details for your digital lease agreement</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="p-4 bg-red-500/10 border border-red-500/20 rounded-2xl text-red-500 text-sm font-bold text-center">
                                <?php echo htmlspecialchars((string)($error ?? '')); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Role Selector -->
                        <div class="grid grid-cols-2 gap-4">
                            <label class="role-option cursor-pointer group"><input type="radio" name="role" value="tenant" checked class="hidden" onclick="toggleTenantFields(true)"><div class="role-card p-6 rounded-3xl border-2 border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 transition-all duration-300 text-center space-y-3"><div class="w-12 h-12 bg-emerald-500/10 text-emerald-500 rounded-2xl flex items-center justify-center mx-auto transition-transform group-hover:scale-110"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div><p class="text-md font-black text-slate-900 dark:text-white">Tenant</p><p class="text-[10px] text-slate-500 uppercase tracking-tighter">Full Lease Profile</p></div></div></label>
                            <label class="role-option cursor-pointer group"><input type="radio" name="role" value="utility" class="hidden" onclick="toggleTenantFields(false)"><div class="role-card p-6 rounded-3xl border-2 border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 transition-all duration-300 text-center space-y-3"><div class="w-12 h-12 bg-blue-500/10 text-blue-500 rounded-2xl flex items-center justify-center mx-auto transition-transform group-hover:scale-110"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v2"/><path d="M12 20v2"/><circle cx="12" cy="12" r="4"/></svg></div><div><p class="text-md font-black text-slate-900 dark:text-white">Utility User</p><p class="text-[10px] text-slate-500 uppercase tracking-tighter">Fast Tokens</p></div></div></label>
                        </div>

                        <!-- Step 0: Unit Selection -->
                        <div id="unit-selection-step" class="space-y-8 animate-in slide-in-from-top-4">
                            <div class="space-y-4">
                                <h3 class="text-lg font-black text-slate-900 dark:text-white section-header">0. Select Your Unit</h3>
                                <p class="text-xs font-bold text-slate-400">Choose the property and unit you wish to lease</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Property</label>
                                    <select name="property_id" id="property_select" onchange="filterUnits(this.value)" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-green/20">
                                        <option value="">Select Property</option>
                                        <?php foreach ($allProperties as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Available Units</label>
                                    <select name="unit_id" id="unit_select" onchange="updateUnitInfo(this.value)" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-green/20">
                                        <option value="">Select Unit</option>
                                    </select>
                                </div>
                            </div>
                            <div id="unit_info_card" class="hidden p-6 bg-accent-green/5 border border-accent-green/10 rounded-3xl animate-in zoom-in duration-300">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-1">Monthly Rent</p>
                                        <p id="info_rent" class="text-2xl font-black text-slate-900 dark:text-white">KSh 0</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-[10px] font-black text-accent-green uppercase tracking-widest mb-1">Security Deposit</p>
                                        <p id="info_deposit" class="text-2xl font-black text-slate-900 dark:text-white">KSh 0</p>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <!-- Step 1: Basic Information (Common to all) -->
                        <div class="space-y-6">
                            <h3 class="text-lg font-black text-slate-900 dark:text-white section-header">1. Profile Details</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name</label><input type="text" name="full_name" required placeholder="John Doe" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Primary Contacts</label><input type="text" name="phone" required placeholder="+254 7XX..." class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                <div class="space-y-1 sm:col-span-2"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Physical Address</label><input type="text" name="address" required placeholder="Apartment Name, Estate, City" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                            </div>
                        </div>

                        <!-- Step 2: Extended Tenant Information (Hidden for Utility) -->
                        <div id="tenant-fields" class="space-y-12 animate-in slide-in-from-top-4">
                            <!-- Section: ID & Primary -->
                            <div class="space-y-6">
                                <h3 class="text-lg font-black text-slate-900 dark:text-white section-header">2. Identity Verification</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">ID Number</label><input type="text" name="id_no" class="tenant-required w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Upload ID Copy</label><input type="file" name="id_copy" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold text-slate-400"></div>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <h3 class="text-lg font-black text-slate-900 dark:text-white section-header">3. Marital & Family</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Status</label><select name="marital_status" onchange="toggleSpouseFields(this.value)" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"><option value="Single">Single</option><option value="Married">Married</option></select></div>
                                    <div class="flex items-center gap-3 px-2 pt-4"><input type="checkbox" name="has_kids" id="has_kids" class="w-5 h-5 accent-green rounded"><label for="has_kids" class="text-sm font-bold text-slate-500">I have children</label></div>
                                </div>
                                <div id="spouse-fields" class="hidden grid-cols-1 sm:grid-cols-2 gap-6 pt-4 animate-in fade-in slide-in-from-top-4">
                                    <div class="space-y-1 sm:col-span-2"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Spouse Full Name</label><input type="text" name="spouse_name" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Spouse Phone</label><input type="text" name="spouse_phone" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Spouse ID</label><input type="text" name="spouse_id_no" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Spouse Email</label><input type="email" name="spouse_email" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Spouse ID Copy</label><input type="file" name="spouse_id_copy" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold text-slate-400"></div>
                                </div>
                            </div>

                            <!-- Section: Professional & Occupation -->
                            <div class="space-y-6">
                                <h3 class="text-lg font-black text-slate-900 dark:text-white section-header">4. Work & Purpose</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Your Profession</label><input type="text" name="profession" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Employer</label><input type="text" name="employer_name" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    <div class="space-y-1 sm:col-span-2"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Occupation Type</label><select name="occupation_type" onchange="toggleBusinessFields(this.value)" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"><option value="Residential">Residential</option><option value="Commercial">Commercial</option></select></div>
                                </div>
                                <div id="business-fields" class="hidden space-y-6 animate-in slide-in-from-bottom-4 pt-4">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                        <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Business Name</label><input type="text" name="business_name" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                        <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Nature of Business</label><input type="text" name="business_nature" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Login Security -->
                        <div class="space-y-8 pt-8 border-t border-slate-100 dark:border-slate-800">
                            <h3 id="login-section-header" class="text-lg font-black text-slate-900 dark:text-white section-header">5. Login Credentials</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div class="space-y-1 sm:col-span-2"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Active Email Address</label><input type="email" name="email" required placeholder="name@example.com" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-green/20"></div>
                                <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Create Password</label><input type="password" name="password" required placeholder="••••••••" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                                <div class="space-y-1"><label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Confirm Password</label><input type="password" name="confirm_password" required placeholder="••••••••" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-sm font-bold focus:outline-none"></div>
                            </div>
                        </div>

                        <!-- Step 3: Terms as Lease -->
                        <div id="terms-section" class="space-y-6 pt-8 border-t border-slate-100 dark:border-slate-800 animate-in slide-in-from-bottom-4">
                            <h3 class="text-lg font-black text-slate-900 dark:text-white section-header">6. Lease Execution (Legal)</h3>
                            <div class="tos-box h-80 overflow-y-auto p-8 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-3xl text-[12px] text-slate-600 dark:text-slate-300 leading-relaxed space-y-6 font-medium shadow-inner">
                                <div class="text-center space-y-2 mb-8">
                                    <h2 class="text-lg font-black text-slate-900 dark:text-white uppercase tracking-tighter">TENANCY AGREEMENT</h2>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Official Legal Document - PrimeLink Management System</p>
                                </div>

                                <p>This tenancy agreement is made on this <span id="ag_day" class="font-black text-accent-green underline">________</span> day of <span id="ag_month" class="font-black text-accent-green underline">___________</span> year <span id="ag_year" class="font-black text-accent-green underline">__________</span>, between <strong>Prime Link Properties Ltd</strong> as the Agent of the Landlord Mr/Ms <span id="ag_landlord" class="font-black text-accent-green underline">________________________________</span> hereinafter called “the Landlord” which the expression shall where the context so admits include his/her successors, assigns and agents of one party and Mr/Ms <span id="ag_tenant_name" class="font-black text-accent-green underline">________________________________</span> of ID No <span id="ag_tenant_id" class="font-black text-accent-green underline">____________</span>, Tel <span id="ag_tenant_phone" class="font-black text-accent-green underline">_____________________</span> and postal address No <span id="ag_tenant_address" class="font-black text-accent-green underline">________________________________</span> Email <span id="ag_tenant_email" class="font-black text-accent-green underline">________________________________</span> hereinafter called “the Tenant” which expression shall where the context so admits include his/her successors, assigns and agents of the other part.</p>
                                
                                <p><strong>Tenant Spouse:</strong> <span id="ag_spouse_name" class="font-black text-accent-green underline">………………………………………………</span> <strong>ID:</strong> <span id="ag_spouse_id" class="font-black text-accent-green underline">………………………</span> <strong>Tel:</strong> <span id="ag_spouse_phone" class="font-black text-accent-green underline">………………………</span></p>

                                <p><strong>WHEREAS:</strong><br>
                                A. The Landlord is the legitimate owner of the property known as LR/No <span id="ag_property_code" class="font-black text-accent-green underline">________________________________</span> Named <span id="ag_property_name" class="font-black text-accent-green underline">________________________________</span> located at <span id="ag_property_location" class="font-black text-accent-green underline">________________________________</span><br>
                                B. Prime Link Properties Ltd is a Limited Liability Company and it is the authorized agent of the landlord/landlady.<br>
                                C. The Landlord has agreed to let unit no <span id="ag_unit_number" class="font-black text-accent-green underline">_________</span> of the property to the Tenant through his/her agent under the following terms and conditions.</p>

                                <p><strong>Now it is hereby agreed as follows:</strong></p>
                                <div class="space-y-3 pl-4">
                                    <p>1. That the rented unit is rented together with part corridors, staircases, lobbies, toilets otherwise known as common areas where applicable.</p>
                                    <p>2. Rent is payable per month in advance but before 5th day of each month. The rent payable exclusive of VAT, service charge, electricity and other charges is <strong>Ksh <span id="ag_rent" class="font-black text-accent-green underline">______________</span></strong>. Electricity & water will be paid separately/ together with rent as per the consumption.</p>
                                    <p>3. Tenant to deposit interest free deposit with the Landlord amount equivalent to <span id="ag_deposit_months" class="font-black text-accent-green underline">_______</span> month rent and <strong>Ksh <span id="ag_electricity_deposit" class="font-black text-accent-green underline">_____________</span></strong> for electricity deposit and <strong>ksh <span id="ag_water_deposit" class="font-black text-accent-green underline">______________</span></strong> for water deposit which the landlord shall hold until the expiry or termination of this agreement.</p>
                                    <p>4. Rent payments are due in advance but before 5th day of each month. Provided always and it is hereby agreed that if the rent or any other payment due hereunder or any part thereof shall remain unpaid after 7th shall attract a compounded 10% late payment penalty charged of the total amount subject to a minimum of ksh 500. The tenant will also be liable for all other expenses should further legal or otherwise action such as distress for rent be taken against the tenant.</p>
                                    <p>5. Rent is payable to Prime Link Properties Ltd bank account or through the company Mpesa Paybill and Airtel Money Paybill. Rent is paid together with other bills for water, garbage, security etc (where applicable). For any payments the tenant must include the tenant code. The original deposit slip should be remitted to Prime link Properties Ltd office or deposited in the deposit slip collection box provided for in your estate.</p>
                                    <p>6. The purpose of the rented premises is for <strong><span id="ag_purpose" class="font-black text-accent-green underline">Residential/Commercial</span></strong> use only and not to be used for any other purpose. Change of use will not be allowed without express consent from the landlord/agent in writing.</p>
                                    <p>7. Tenant is required to take responsibility by maintaining cleanliness at all times. Garbage should only be deposited at the designated area, drains both interior and exterior should be kept free from obstructions and in proper working order as well as keep the premises in a sanitary condition satisfactory to the management.</p>
                                    <p>8. That If the rent shall be in arrears for more than Fourteen (14) days after the same have become due and payable, or if the Tenant shall fail to perform and observe any of the agreements herein contained or implied and has not complied with any notices in respect of such breach or non-payment, it shall be lawful for the Landlord/agent at any time thereafter to enter into the Property and to again repossess the same without prejudice to any right of action or remedy of the Landlord/agent in respect of any antecedent breach of any of the covenants herein contained or implied.</p>
                                    <p>9. That If the Tenant has substantially complied with the terms of this Agreement the Landlord on expiry of this agreement may give the Tenant an option to extend the tenancy for a further <span id="ag_extension" class="font-black text-accent-green underline">_____</span> years subject to the rent being revised. The notice must be given by the tenant in writing not later than two (2) months prior to the end of the Term if the Tenant wishes to take up the option.</p>
                                    <p>10. The tenant shall: Pay all charges incurred for electricity, water, garbage collection, security & other agreed charges. No tampering of utility meters. A minimum penalty of Ksh 3,000 shall be charged for any utility meter tampered with. Ensure the gate is locked all the time to maintain security. Permit the landlord or his agent during the said tenancy to enter upon and examine the condition of the said premises.</p>
                                    <p>11. The Landlord shall: Allow the tenant have the right to quiet possession and enjoyment of the Property. Respond timely to requests for repairs and maintenance of the premises.</p>
                                    <p>12. This agreement may be terminated by giving one (1) calendar month notice in advance, or pay 1 month rent in lieu of notice. Submission of a vague deposit slip by a tenant terminates the tenancy agreement henceforth and requires the tenant to vacate the premises immediately with No further notice.</p>
                                </div>

                                <div class="pt-10 border-t border-slate-100 dark:border-slate-800 space-y-6">
                                    <div class="flex justify-between items-end">
                                        <div class="space-y-1">
                                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Digital Signature of Tenant</p>
                                            <p id="ag_sig_name" class="text-xl font-black italic font-serif text-slate-900 dark:text-white">__________________________</p>
                                        </div>
                                        <div class="text-right space-y-1">
                                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Date & Timestamp</p>
                                            <p id="ag_sig_date" class="text-xs font-bold text-slate-900 dark:text-white"><?php echo date('d M, Y H:i:s'); ?></p>
                                        </div>
                                    </div>
                                    <div class="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl">
                                        <p class="text-[8px] leading-relaxed text-slate-400">By checking the "Accept" box below, you electronically sign this agreement. A copy will be generated and stored in your PrimeLink vault for your records and legal use.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-4 px-2">
                                <input type="checkbox" name="terms" id="terms" class="w-6 h-6 text-accent-green bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 rounded-lg focus:ring-accent-green/20">
                                <label for="terms" class="text-xs font-bold text-slate-600 dark:text-slate-300">I certify my identity and agree to the digital lease terms stated above. My acceptance serves as my legal signature.</label>
                            </div>
                        </div>

                        <button type="submit" class="w-full py-6 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.3em] shadow-2xl transition-all hover:bg-slate-800 dark:hover:bg-slate-200 active:scale-95">Execute Registration</button>
                    </form>
                    <p class="text-center text-xs font-bold text-slate-500 pb-16">Already have an account? <a href="login.php" class="text-accent-green hover:underline">Sign In</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const allUnits = <?php echo json_encode($allUnits); ?>;
        const allProperties = <?php echo json_encode($allProperties); ?>;

        function filterUnits(propertyId) {
            const unitSelect = document.getElementById('unit_select');
            const infoCard = document.getElementById('unit_info_card');
            unitSelect.innerHTML = '<option value="">Select Unit</option>';
            infoCard.classList.add('hidden');
            
            if (!propertyId) return;
            
            const filtered = allUnits.filter(u => u.property_id === propertyId);
            filtered.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u.id;
                opt.innerText = u.unit_number;
                unitSelect.appendChild(opt);
            });
            syncAgreement();
        }

        function updateUnitInfo(unitId) {
            const infoCard = document.getElementById('unit_info_card');
            if (!unitId) {
                infoCard.classList.add('hidden');
                syncAgreement();
                return;
            }
            
            const unit = allUnits.find(u => u.id === unitId);
            if (unit) {
                document.getElementById('info_rent').innerText = 'KSh ' + parseInt(unit.monthly_rent).toLocaleString();
                document.getElementById('info_deposit').innerText = 'KSh ' + parseInt(unit.deposit_amount).toLocaleString();
                infoCard.classList.remove('hidden');
            }
            syncAgreement();
        }

        function syncAgreement() {
            // Helper to update text by ID
            const up = (id, val, def = "________________") => {
                const el = document.getElementById(id);
                if (el) el.innerText = val || def;
            };

            const now = new Date();
            const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            
            up('ag_day', now.getDate());
            up('ag_month', months[now.getMonth()]);
            up('ag_year', now.getFullYear());

            // Tenant Details
            const name = document.querySelector('input[name="full_name"]').value;
            up('ag_tenant_name', name);
            up('ag_sig_name', name);
            up('ag_tenant_id', document.querySelector('input[name="id_no"]').value);
            up('ag_tenant_phone', document.querySelector('input[name="phone"]').value);
            up('ag_tenant_address', document.querySelector('input[name="address"]').value);
            up('ag_tenant_email', document.querySelector('input[name="email"]').value);

            // Spouse Details
            up('ag_spouse_name', document.querySelector('input[name="spouse_name"]').value, "N/A");
            up('ag_spouse_id', document.querySelector('input[name="spouse_id_no"]').value, "N/A");
            up('ag_spouse_phone', document.querySelector('input[name="spouse_phone"]').value, "N/A");

            // Property & Unit
            const propId = document.getElementById('property_select').value;
            const unitId = document.getElementById('unit_select').value;
            
            const prop = allProperties.find(p => p.id === propId);
            const unit = allUnits.find(u => u.id === unitId);

            if (prop) {
                up('ag_property_name', prop.title);
                up('ag_property_location', prop.location);
                up('ag_property_code', prop.property_code || "________________");
                up('ag_landlord', prop.landlord_name || "________________");
            } else {
                up('ag_property_name', "");
                up('ag_property_location', "");
                up('ag_property_code', "");
            }

            if (unit) {
                up('ag_unit_number', unit.unit_number);
                up('ag_rent', parseInt(unit.monthly_rent).toLocaleString());
                up('ag_electricity_deposit', "2,500");
                up('ag_water_deposit', "1,500");
                up('ag_deposit_months', "1");
                up('ag_extension', "1");
            } else {
                up('ag_unit_number', "");
                up('ag_rent', "");
            }

            // Purpose
            const occ = document.querySelector('select[name="occupation_type"]').value;
            up('ag_purpose', occ);
        }

        // Add event listeners for real-time sync
        document.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('input', syncAgreement);
        });

        function toggleTenantFields(show) {
            document.getElementById('tenant-fields').classList.toggle('hidden', !show);
            document.getElementById('unit-selection-step').classList.toggle('hidden', !show);
            document.getElementById('terms-section').classList.toggle('hidden', !show);
            
            const loginHeader = document.getElementById('login-section-header');
            loginHeader.innerText = show ? "5. Login Credentials" : "2. Login Credentials";
            
            const tenantInputs = document.querySelectorAll('.tenant-required');
            tenantInputs.forEach(input => {
                input.required = show;
            });

            document.getElementById('terms').required = show;
            syncAgreement();
        }

        function toggleSpouseFields(status) {
            document.getElementById('spouse-fields').classList.toggle('hidden', status !== 'Married');
            syncAgreement();
        }
        function toggleBusinessFields(type) {
            document.getElementById('business-fields').classList.toggle('hidden', type !== 'Commercial');
            syncAgreement();
        }
        window.onload = () => {
            toggleTenantFields(true);
            syncAgreement();
        };
    </script>
</body>
</html>
