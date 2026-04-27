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

    if (!empty($fullName) && !empty($email) && !empty($password)) {
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
                
                // If role is tenant, insert into tenants
                if ($role === 'tenant') {
                    $tenantId = generateUUID();
                    $stmt = $pdo->prepare("INSERT INTO tenants (id, user_id, full_name, email, phone, status) VALUES (?, ?, ?, ?, ?, 'Active')");
                    $stmt->execute([$tenantId, $userId, $fullName, $email, $phone]);
                } elseif ($role === 'landlord') {
                    $landlordId = generateUUID();
                    $stmt = $pdo->prepare("INSERT INTO landlords (id, user_id, full_name, email, phone) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$landlordId, $userId, $fullName, $email, $phone]);
                }
                
                $pdo->commit();
                
                $suffix = $role === 'tenant' ? 'T' : ($role === 'landlord' ? 'L' : 'U');
                $generatedId = "PRM-" . substr($userId, 0, 4) . "-" . $suffix;
                $success = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error during registration: " . $e->getMessage();
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
</head>
<body class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-50 min-h-screen font-sans antialiased selection:bg-accent-gold/30">
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-12">
        <!-- Marketing Side -->
        <div class="hidden lg:flex lg:col-span-7 xl:col-span-8 relative overflow-hidden bg-slate-900">
            <div class="absolute inset-0 z-0">
                <img src="https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1200" alt="Real Estate" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-linear-to-br from-emerald-600/20 to-teal-600/20 mix-blend-multiply opacity-60"></div>
                <div class="absolute inset-0 bg-linear-to-t from-slate-950 via-slate-900/40 to-transparent"></div>
            </div>
            <div class="relative z-10 w-full h-full flex flex-col justify-between p-16">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-white/10 backdrop-blur-xl rounded-2xl border border-white/20 text-white">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <span class="text-3xl font-black text-white tracking-widest uppercase">PrimeLink</span>
                </div>
                <div class="max-w-xl space-y-6">
                    <h2 class="text-6xl font-black text-white leading-tight animate-in slide-in-from-bottom-8 duration-700">
                        Join the Ecosystem
                    </h2>
                    <p class="text-xl text-slate-300 font-medium leading-relaxed max-w-lg">
                        PrimeLink ensures trust and security at every step for landlords, tenants, and utility users.
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
        <div class="col-span-1 lg:col-span-5 xl:col-span-4 flex items-center justify-center p-8 sm:p-12 relative">
            <div class="w-full max-w-md space-y-8">
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
                        <div class="text-center space-y-2">
                            <h2 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Create Account</h2>
                            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">Join PrimeLink today</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-red-500 text-xs font-bold text-center">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form action="register.php" method="POST" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Full Name</label>
                                    <input type="text" name="full_name" required placeholder="John Doe" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Role</label>
                                    <select name="role" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                        <option value="tenant">Tenant</option>
                                        <option value="landlord">Landlord</option>
                                        <option value="utility">Utility</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Email</label>
                                    <input type="email" name="email" required placeholder="john@example.com" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Phone</label>
                                    <input type="text" name="phone" placeholder="+254 7XX..." class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                </div>
                                <div class="sm:col-span-2 space-y-1">
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Password</label>
                                    <input type="password" name="password" required placeholder="••••••••" class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-gold/20">
                                </div>
                            </div>

                            <button type="submit" class="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest shadow-xl mt-6">
                                Register
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
