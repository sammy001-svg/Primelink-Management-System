<?php
/**
 * Profile Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$pageTitle = "My Profile";
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        try {
            $pdo->prepare("UPDATE profiles SET full_name=?, phone=?, email=? WHERE id=?")->execute([$full_name, $phone, $email, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE users SET email=? WHERE id=?")->execute([$email, $_SESSION['user_id']]);
            $success = 'Profile updated successfully!';
            $user = getCurrentUser($pdo);
        } catch (PDOException $e) {
            $error = 'Update failed: ' . $e->getMessage();
        }
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif (!password_verify($current, $user['password'] ?? '')) {
            $error = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $_SESSION['user_id']]);
            $success = 'Password changed successfully!';
        }
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';

$userName = $user['full_name'] ?? '';
$userEmail = $user['email'] ?? '';
$userPhone = $user['phone'] ?? '';
$userRole  = $_SESSION['role'] ?? 'tenant';
$userInitial = strtoupper(substr($userName, 0, 1));
?>

<div class="max-w-3xl mx-auto space-y-8 animate-in">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-black text-slate-900 dark:text-white">My Profile</h1>
        <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">Manage your account information</p>
    </div>

    <?php if ($success): ?>
    <div class="success-toast p-4 bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-400 rounded-2xl font-bold text-sm flex items-center gap-3 transition-all duration-300">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="p-4 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 rounded-2xl font-bold text-sm">
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Avatar + Info -->
    <div class="glass-card p-8 flex items-center gap-6">
        <div class="w-20 h-20 rounded-2xl bg-linear-to-br from-accent-green to-emerald-600 flex items-center justify-center text-white font-black text-3xl shadow-xl shrink-0">
            <?php echo $userInitial; ?>
        </div>
        <div>
            <h2 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($userName); ?></h2>
            <p class="text-slate-500 text-sm font-medium"><?php echo htmlspecialchars($userEmail); ?></p>
            <span class="badge badge-primary mt-2 inline-block"><?php echo ucfirst($userRole); ?></span>
        </div>
    </div>

    <!-- Profile Info Form -->
    <div class="glass-card p-8">
        <h3 class="font-black text-slate-900 dark:text-white text-lg mb-6">Personal Information</h3>
        <form method="POST" class="space-y-5">
            <input type="hidden" name="action" value="update_profile">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div><label class="form-label">Full Name</label><input type="text" name="full_name" value="<?php echo htmlspecialchars($userName); ?>" required class="form-input"></div>
                <div><label class="form-label">Email Address</label><input type="email" name="email" value="<?php echo htmlspecialchars($userEmail); ?>" required class="form-input"></div>
            </div>
            <div><label class="form-label">Phone Number</label><input type="text" name="phone" value="<?php echo htmlspecialchars($userPhone); ?>" placeholder="+254..." class="form-input"></div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>

    <!-- Password Change -->
    <div class="glass-card p-8">
        <h3 class="font-black text-slate-900 dark:text-white text-lg mb-6">Change Password</h3>
        <form method="POST" class="space-y-5">
            <input type="hidden" name="action" value="change_password">
            <div><label class="form-label">Current Password</label><input type="password" name="current_password" required placeholder="••••••••" class="form-input"></div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div><label class="form-label">New Password</label><input type="password" name="new_password" required placeholder="••••••••" class="form-input"></div>
                <div><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" required placeholder="••••••••" class="form-input"></div>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
