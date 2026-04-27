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
$success = null;
$generatedId = null;

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
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already registered.";
        } else {
            try {
                $pdo->beginTransaction();
                
                $userId = generateUUID();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Handle File Uploads
                $idCopyUrl = null;
                $spouseIdCopyUrl = null;
                
                if ($role === 'tenant') {
                    if (isset($_FILES['id_copy']) && $_FILES['id_copy']['error'] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($_FILES['id_copy']['name'], PATHINFO_EXTENSION);
                        $fileName = "id_" . substr($userId, 0, 8) . "_" . time() . "." . $ext;
                        move_uploaded_file($_FILES['id_copy']['tmp_name'], __DIR__ . "/uploads/ids/" . $fileName);
                        $idCopyUrl = "uploads/ids/" . $fileName;
                    }
                    
                    if (isset($_FILES['spouse_id_copy']) && $_FILES['spouse_id_copy']['error'] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($_FILES['spouse_id_copy']['name'], PATHINFO_EXTENSION);
                        $fileName = "spouse_id_" . substr($userId, 0, 8) . "_" . time() . "." . $ext;
                        move_uploaded_file($_FILES['spouse_id_copy']['tmp_name'], __DIR__ . "/uploads/ids/" . $fileName);
                        $spouseIdCopyUrl = "uploads/ids/" . $fileName;
                    }
                }

                // Insert into users
                $stmt = $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $email, $hashedPassword, $role]);
                
                // Insert into profiles (with physical address)
                try {
                    $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role, address) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $fullName, $email, $phone, $role, $address]);
                } catch (PDOException $e) {
                    if ($e->getCode() == '42S22' && strpos($e->getMessage(), 'address') !== false) {
                        $pdo->exec("ALTER TABLE `profiles` ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone` ");
                        // Retry
                        $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role, address) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$userId, $fullName, $email, $phone, $role, $address]);
                    } else {
                        throw $e;
                    }
                }
                
                // Insert into tenants
                if ($role === 'tenant') {
                    $tenantId = generateUUID();
                    $stmt = $pdo->prepare("INSERT INTO tenants (
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
                    )");
                    $stmt->execute([
                        $tenantId, $userId, $fullName, $email, $phone,
                        $fullName, // Signature Name
                        $spouseName, $idNo, $spouseIdNo, $idCopyUrl, $spouseIdCopyUrl,
                        $spousePhone, $maritalStatus, $hasKids, $address,
                        $spouseEmail, $altContact, $spouseAltContact,
                        $profession, $spouseProfession, $employerName, $spouseEmployerName,
                        $occupationType, $businessName, $businessNature, $businessLocation,
                        $nokName, $nokContact, $nokRelationship
                    ]);

                    // AUTOMATIC DOCUMENT GENERATION
                    $now = date('Y-m-d H:i:s');
                    
                    // 1. Lease Agreement Record
                    $leaseDocId = generateUUID();
                    $stmt = $pdo->prepare("INSERT INTO documents (id, tenant_id, title, category, file_url, file_size) VALUES (?, ?, ?, 'Lease', ?, 'Generated')");
                    $stmt->execute([$leaseDocId, $tenantId, "Signed Lease Agreement - " . $fullName, "view_lease.php?tenant_id=" . $tenantId]);

                    // 2. ID Document Record
                    if ($idCopyUrl) {
                        $idDocId = generateUUID();
                        $stmt = $pdo->prepare("INSERT INTO documents (id, tenant_id, title, category, file_url, file_size) VALUES (?, ?, ?, 'ID', ?, 'Upload')");
                        $stmt->execute([$idDocId, $tenantId, "ID Verification Copy - " . $fullName, $idCopyUrl]);
                    }
                }
                
                $pdo->commit();
                $suffix = $role === 'tenant' ? 'T' : ($role === 'utility' ? 'U' : 'X');
                $generatedId = "PRM-" . substr($userId, 0, 4) . "-" . $suffix;
                $success = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Registration failed: " . $e->getMessage();
            }
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
                            <div class="tos-box h-48 overflow-y-auto p-6 bg-slate-50 dark:bg-slate-900/50 rounded-3xl text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed space-y-4 font-medium">
                                <p class="text-slate-900 dark:text-white font-black uppercase text-[10px] tracking-widest">Digital Lease Agreement & Terms of Service</p>
                                <p>1. <strong>Agreement of Lease:</strong> By checking the box below, you (the Tenant) agree to enter into a binding lease agreement with PrimeLink Management System and the respective Landlord of the assigned property.</p>
                                <p>2. <strong>Verification:</strong> You certify that all provided documents (ID copies, employment info) are valid and accurate under the penalty of perjury.</p>
                                <p>3. <strong>Payment:</strong> You agree to fulfill all rental and utility payments (Electricity & Water Tokens) via the PrimeLink platform on the stipulated dates.</p>
                                <p>4. <strong>Maintenance:</strong> All maintenance requests must be logged via the portal for formal tracking and resolution.</p>
                                <p>5. <strong>Execution:</strong> Checking "Accept Terms" constitutes your digital signature. Your legal name and the timestamp of this action will be embedded into the official lease document stored in our vault.</p>
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
        function toggleTenantFields(show) {
            document.getElementById('tenant-fields').classList.toggle('hidden', !show);
            document.getElementById('terms-section').classList.toggle('hidden', !show);
            
            // Dynamic numbering for Login Credentials
            const loginHeader = document.getElementById('login-section-header');
            loginHeader.innerText = show ? "5. Login Credentials" : "2. Login Credentials";
            
            // Toggle required attributes for tenant-only fields
            const tenantInputs = document.querySelectorAll('.tenant-required');
            tenantInputs.forEach(input => {
                input.required = show;
            });

            // Toggle Required on Checkbox
            document.getElementById('terms').required = show;
        }
        function toggleSpouseFields(status) {
            document.getElementById('spouse-fields').classList.toggle('hidden', status !== 'Married');
        }
        function toggleBusinessFields(type) {
            document.getElementById('business-fields').classList.toggle('hidden', type !== 'Commercial');
        }
        window.onload = () => toggleTenantFields(true);
    </script>
</body>
</html>
