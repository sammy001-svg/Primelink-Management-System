<?php
/**
 * Login Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid email or password.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Primelink Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/style.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'accent-green': '#22c55e',
                        'accent-orange': '#f97316',
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
<body class="bg-white dark:bg-slate-950 text-slate-900 dark:text-slate-50 min-h-screen font-sans antialiased selection:bg-accent-green/30">
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-12">
        <!-- Marketing Side (Hidden on Mobile) -->
        <div class="hidden lg:flex lg:col-span-7 xl:col-span-8 relative overflow-hidden bg-slate-900">
            <div class="absolute inset-0 z-0">
                <img src="https://images.unsplash.com/photo-1613490493576-7fde63acd811?q=80&w=1200" alt="Real Estate" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-linear-to-br from-accent-green/20 to-orange-600/20 mix-blend-multiply opacity-60"></div>
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
                        Modern Living, Simplified
                    </h2>
                    <p class="text-xl text-slate-300 font-medium leading-relaxed max-w-lg">
                        PrimeLink simplifies your real estate operations with state-of-the-art automation and management tools.
                    </p>
                </div>

                <div class="flex items-center gap-8 text-[10px] font-black text-white/50 uppercase tracking-[0.4em]">
                    <span>Real Estate</span>
                    <span>Utilities</span>
                    <span>Trust</span>
                </div>
            </div>
        </div>

        <!-- Auth Form Side -->
        <div class="col-span-1 lg:col-span-5 xl:col-span-4 flex items-center justify-center p-8 sm:p-12 relative">
            <div class="absolute top-1/4 right-1/4 w-64 h-64 bg-accent-green/10 rounded-full blur-[100px] -z-10"></div>
            <div class="absolute bottom-1/4 left-1/4 w-64 h-64 bg-blue-500/10 rounded-full blur-[100px] -z-10"></div>
            
            <div class="w-full max-w-sm space-y-8 text-center">
                <div class="lg:hidden text-center mb-12">
                    <div class="inline-flex items-center justify-center p-3 rounded-2xl bg-slate-900 text-white shadow-xl mb-4">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight uppercase">PrimeLink</h1>
                </div>

                <div class="space-y-6 animate-in fade-in slide-in-from-right-4 duration-500">
                    <div class="text-center space-y-2">
                        <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Welcome Back</h1>
                        <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">Sign in to manage your property lifestyle</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-red-500 text-xs font-bold text-center">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form action="login.php" method="POST" class="space-y-4">
                        <div class="space-y-2 text-left">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Email Address</label>
                            <div class="relative group">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-green transition-colors">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10Z"/><path d="m22 7-10 7L2 7"/></svg>
                                </span>
                                <input 
                                    type="email" 
                                    name="email"
                                    placeholder="name@company.com" 
                                    required
                                    class="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-green/20 transition-all placeholder:text-slate-300 dark:placeholder:text-slate-600 shadow-sm"
                                />
                            </div>
                        </div>
                        <div class="space-y-2 text-left">
                            <div class="flex justify-between items-center px-1">
                                <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Password</label>
                                <a href="#" class="text-[10px] font-black uppercase tracking-widest text-accent-green hover:underline">Forgot?</a>
                            </div>
                            <div class="relative group">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-green transition-colors">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <input 
                                    type="password" 
                                    name="password"
                                    placeholder="••••••••"
                                    required
                                    class="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-accent-green/20 transition-all placeholder:text-slate-300"
                                />
                            </div>
                        </div>

                        <button 
                            type="submit"
                            class="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest flex items-center justify-center gap-2 hover:opacity-90 active:scale-[0.98] transition-all shadow-xl mt-6"
                        >
                            Sign In
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                    </form>

                    <div class="relative py-4">
                        <div class="absolute inset-0 flex items-center"><span class="w-full border-t border-slate-100 dark:border-slate-800"></span></div>
                        <div class="relative flex justify-center text-[10px] uppercase font-black tracking-widest"><span class="bg-white dark:bg-slate-950 px-3 text-slate-400">Or continue with</span></div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <button class="flex items-center justify-center gap-2 py-3 border border-slate-100 dark:border-slate-800 rounded-xl font-bold hover:bg-slate-50 dark:hover:bg-slate-950 transition-colors text-xs">
                            <span class="text-red-500 font-bold">G</span> Google
                        </button>
                        <button class="flex items-center justify-center gap-2 py-3 border border-slate-100 dark:border-slate-800 rounded-xl font-bold hover:bg-slate-50 dark:hover:bg-slate-950 transition-colors text-xs">
                            GitHub
                        </button>
                    </div>

                    <p class="text-center text-xs font-bold text-slate-500 pt-4">
                        Don't have an account? <a href="register.php" class="text-accent-green hover:underline">Register Now</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
