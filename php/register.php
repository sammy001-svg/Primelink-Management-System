<?php
/**
 * Registration Page
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
    $phone = $_POST['phone'] ?? '';
    $role = $_POST['role'] ?? 'tenant';
    $terms = $_POST['terms'] ?? '';

    // Basic validation
    if (empty($terms)) {
        $error = "You must accept the Terms and Conditions.";
    } elseif (!empty($fullName) && !empty($email) && !empty($password)) {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already registered.";
        } else {
            try {
                $pdo->beginTransaction();
                
                $userId = generateUUID();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert into users
                $stmt = $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $email, $hashedPassword, $role]);
                
                // Insert into profiles
                $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $fullName, $email, $phone, $role]);
                
                // Role-specific initialization
                if ($role === 'tenant') {
                    $tenantId = generateUUID();
                    $stmt = $pdo->prepare("INSERT INTO tenants (id, user_id, full_name, email, phone, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
                    $stmt->execute([$tenantId, $userId, $fullName, $email, $phone]);
                } 
                // Utility users stay in users/profiles for now
                
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
                        'accent-gold': '#D4AF37',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        heading: ['Outfit', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        .role-option input:checked + .role-card {
            border-color: #D4AF37;
            background-color: rgba(212, 175, 55, 0.1);
            ring: 4px;
            ring-color: rgba(212, 175, 55, 0.1);
        }
    </style>
</head>
<body class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-50 min-h-screen font-sans antialiased selection:bg-accent-gold/30">
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-12">
        <!-- Marketing Side -->
        <div class="hidden lg:flex lg:col-span-6 xl:col-span-7 relative overflow-hidden bg-slate-900">
            <div class="absolute inset-0 z-0">
                <img src="https://images.unsplash.com/photo-1560518883-ce09059eeffa?q=80&w=1200" alt="Real Estate" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-linear-to-br from-emerald-600/20 to-teal-600/20 mix-blend-multiply opacity-60"></div>
                <div class="absolute inset-0 bg-linear-to-t from-slate-950 via-slate-900/60 to-transparent"></div>
            </div>
            <div class="relative z-10 w-full h-full flex flex-col justify-between p-16">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-white/10 backdrop-blur-xl rounded-2xl border border-white/20 text-white">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <span class="text-3xl font-black text-white tracking-widest uppercase text-shadow-glow">PrimeLink</span>
                </div>
                <div class="max-w-xl space-y-6">
                    <h2 class="text-6xl font-black text-white leading-tight drop-shadow-2xl">
                        Empowering <br>Your Property.
                    </h2>
                    <p class="text-xl text-slate-300 font-medium leading-relaxed max-w-lg">
                        Choose your journey with PrimeLink. Secure management for tenants and seamless token services for utility users.
                    </p>
                </div>
                <div class="flex items-center gap-8 text-[10px] font-black text-white/50 uppercase tracking-[0.4em]">
                    <span>Secure</span>
                    <span>Transparent</span>
                    <span>Modern</span>
                </div>
            </div>
        </div>

        <!-- Auth Form Side -->
        <div class="col-span-1 lg:col-span-6 xl:col-span-5 flex items-center justify-center p-8 sm:p-12 relative bg-white dark:bg-slate-950">
            <div class="w-full max-w-lg space-y-8">
                <?php if ($success): ?>
                    <div class="text-center space-y-6 animate-in zoom-in duration-500">
                        <div class="w-20 h-20 bg-green-500/10 text-green-500 rounded-full flex items-center justify-center mx-auto mb-4 ring-8 ring-green-500/5">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <h2 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Success!</h2>
                        <p class="text-slate-500 dark:text-slate-400 font-medium text-sm text-center">Your account has been created. Save your User ID for records.</p>
                        
                        <div class="p-6 bg-slate-900 rounded-2xl border-2 border-accent-gold shadow-2xl space-y-2 relative overflow-hidden">
                            <p class="text-[10px] font-black text-accent-gold uppercase tracking-[0.2em]">Unique User ID</p>
                            <p class="text-3xl font-black text-white tracking-widest font-mono"><?php echo $generatedId; ?></p>
                        </div>

                        <a href="login.php" class="block w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest text-center shadow-lg">
                            Continue to Login
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-6 animate-in fade-in slide-in-from-right-4 duration-500">
                        <div class="space-y-2">
                            <h2 class="text-4xl font-black text-slate-900 dark:text-white tracking-tight">Get Started</h2>
                            <p class="text-slate-500 dark:text-slate-400 font-medium">Select your account type to proceed</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="p-4 bg-red-500/10 border border-red-500/20 rounded-xl text-red-500 text-xs font-bold text-center">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form action="register.php" method="POST" class="space-y-6">
                            <!-- Role Selection -->
                            <div class="grid grid-cols-2 gap-4">
                                <label class="role-option cursor-pointer group">
                                    <input type="radio" name="role" value="tenant" checked class="hidden">
                                    <div class="role-card p-4 rounded-2xl border-2 border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 transition-all duration-300 text-center space-y-3">
                                        <div class="w-10 h-10 bg-emerald-500/10 text-emerald-500 rounded-xl flex items-center justify-center mx-auto transition-transform group-hover:scale-110">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-black text-slate-900 dark:text-white">Tenant</p>
                                            <p class="text-[10px] text-slate-500 uppercase tracking-tighter">Properties & Leases</p>
                                        </div>
                                    </div>
                                </label>

                                <label class="role-option cursor-pointer group">
                                    <input type="radio" name="role" value="utility" class="hidden">
                                    <div class="role-card p-4 rounded-2xl border-2 border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 transition-all duration-300 text-center space-y-3">
                                        <div class="w-10 h-10 bg-blue-500/10 text-blue-500 rounded-xl flex items-center justify-center mx-auto transition-transform group-hover:scale-110">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/><circle cx="12" cy="12" r="4"/></svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-black text-slate-900 dark:text-white">Utility User</p>
                                            <p class="text-[10px] text-slate-500 uppercase tracking-tighter">Tokens & Utilities</p>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <div class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="space-y-1 sm:col-span-2">
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name</label>
                                        <input type="text" name="full_name" required placeholder="Enter your full name" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Email Address</label>
                                        <input type="email" name="email" required placeholder="name@example.com" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone Number</label>
                                        <input type="text" name="phone" required placeholder="+254 7XX..." class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                    </div>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Create Password</label>
                                    <input type="password" name="password" required placeholder="••••••••" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                </div>

                                <!-- Terms and Conditions -->
                                <div class="flex items-start gap-3 mt-4">
                                    <div class="flex items-center h-5">
                                        <input id="terms" name="terms" type="checkbox" required class="w-4 h-4 text-accent-gold border-slate-300 dark:border-slate-700 rounded focus:ring-accent-gold/20 bg-white dark:bg-slate-900">
                                    </div>
                                    <div class="text-xs">
                                        <label for="terms" class="font-bold text-slate-700 dark:text-slate-300">I agree to the <a href="#" class="text-accent-gold hover:underline">Terms of Service</a> and <a href="#" class="text-accent-gold hover:underline">Privacy Policy</a></label>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-2xl transition-transform active:scale-95 group relative overflow-hidden">
                                <span class="relative z-10 transition-colors duration-300">Register Now</span>
                            </button>
                        </form>

                        <p class="text-center text-xs font-bold text-slate-500 pt-4">
                            Already have an account? <a href="login.php" class="text-accent-gold hover:underline">Sign In</a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
