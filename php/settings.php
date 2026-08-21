<?php
/**
 * System Settings
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

require_once __DIR__ . '/includes/settings.php';

$pageTitle = "System Settings";

$s = getSettings($pdo, [
    'company_name', 'company_email', 'company_phone', 'company_address', 'company_tagline', 'logo_url',
    'currency_symbol', 'invoice_prefix', 'receipt_prefix', 'invoice_due_days', 'invoice_footer', 'fiscal_year_start', 'management_fee_rate',
    'mpesa_shortcode', 'mpesa_consumer_key', 'mpesa_consumer_secret',
    'mpesa_passkey', 'mpesa_callback_url', 'mpesa_environment',
    'mail_driver', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
    'smtp_from_name', 'smtp_from_email',
    'notify_on_payment', 'notify_on_maintenance', 'notify_on_lease',
    'penalty_enabled', 'penalty_grace_days', 'penalty_type', 'penalty_amount', 'penalty_percentage',
]);

// Defaults for anything not yet seeded
$defaults = [
    'company_name'          => 'Primelink Management System',
    'company_email'         => 'info@primelink.co.ke',
    'company_phone'         => '+254 700 000000',
    'company_address'       => 'Nairobi, Kenya',
    'company_tagline'       => 'Premium Property Management',
    'currency_symbol'       => 'KSh',
    'invoice_prefix'        => 'INV',
    'receipt_prefix'        => 'RCT',
    'invoice_due_days'      => '7',
    'invoice_footer'        => 'For inquiries and payment confirmation, please contact us at the above details.',
    'fiscal_year_start'     => '1',
    'mpesa_shortcode'       => '',
    'mpesa_consumer_key'    => '',
    'mpesa_consumer_secret' => '',
    'mpesa_passkey'         => '',
    'mpesa_callback_url'    => '',
    'mpesa_environment'     => 'sandbox',
    'mail_driver'           => 'mail',
    'smtp_host'             => '',
    'smtp_port'             => '587',
    'smtp_user'             => '',
    'smtp_pass'             => '',
    'smtp_from_name'        => 'Primelink Management',
    'smtp_from_email'       => 'noreply@primelink.co.ke',
    'notify_on_payment'     => '1',
    'notify_on_maintenance' => '1',
    'notify_on_lease'       => '1',
    'penalty_enabled'       => '0',
    'penalty_grace_days'    => '5',
    'penalty_type'          => 'fixed',
    'penalty_amount'        => '500',
    'penalty_percentage'    => '5',
    'management_fee_rate'   => '10',
    'logo_url'              => '',
];

foreach ($defaults as $key => $def) {
    if (!isset($s[$key]) || $s[$key] === '') {
        $s[$key] = $def;
    }
}

$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in max-w-4xl">

    <!-- Page header -->
    <div>
        <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">System Settings</h1>
        <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-1">Configure company details, billing preferences, and integrations.</p>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 p-1 bg-slate-100 dark:bg-slate-800/60 rounded-2xl w-fit flex-wrap" id="settings-tabs">
        <button onclick="switchTab('company')"   class="tab-btn active" data-tab="company">Company Profile</button>
        <button onclick="switchTab('invoice')"   class="tab-btn"        data-tab="invoice">Invoice & Billing</button>
        <button onclick="switchTab('mpesa')"     class="tab-btn"        data-tab="mpesa">M-Pesa</button>
        <button onclick="switchTab('email')"     class="tab-btn"        data-tab="email">Email & Notifications</button>
        <button onclick="switchTab('system')"    class="tab-btn"        data-tab="system">System</button>
        <button onclick="switchTab('penalties')" class="tab-btn"        data-tab="penalties">Penalties</button>
    </div>

    <!-- Toast -->
    <div id="toast" class="hidden fixed top-6 right-6 z-50 flex items-center gap-3 px-5 py-4 rounded-2xl shadow-2xl text-sm font-bold max-w-xs"></div>

    <!-- ===== TAB: COMPANY ===== -->
    <div id="tab-company" class="tab-panel">
        <form id="form-company" class="glass-card p-8 space-y-6">
            <input type="hidden" name="action" value="save_company">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Company Profile</h2>
            <p class="text-xs text-slate-400 font-medium -mt-3">This information appears on all invoices, receipts, and documents.</p>

            <div class="space-y-2">
                <label class="field-label">Company Name</label>
                <input type="text" name="company_name" value="<?php echo htmlspecialchars($s['company_name']); ?>" required
                       class="field-input" placeholder="E.g. Primelink Management System">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="field-label">Email Address</label>
                    <input type="email" name="company_email" value="<?php echo htmlspecialchars($s['company_email']); ?>"
                           class="field-input" placeholder="info@yourcompany.com">
                </div>
                <div class="space-y-2">
                    <label class="field-label">Phone Number</label>
                    <input type="text" name="company_phone" value="<?php echo htmlspecialchars($s['company_phone']); ?>"
                           class="field-input" placeholder="+254 700 000000">
                </div>
            </div>

            <div class="space-y-2">
                <label class="field-label">Physical Address</label>
                <input type="text" name="company_address" value="<?php echo htmlspecialchars($s['company_address']); ?>"
                       class="field-input" placeholder="E.g. Nairobi, Kilimani">
            </div>

            <div class="space-y-2">
                <label class="field-label">Tagline / Slogan</label>
                <input type="text" name="company_tagline" value="<?php echo htmlspecialchars($s['company_tagline']); ?>"
                       class="field-input" placeholder="E.g. Premium Property Management">
                <p class="text-[10px] text-slate-400 px-2">Shown beneath the company name on invoices.</p>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="btn-green px-8 py-3 font-black">Save Company Profile</button>
            </div>
        </form>

        <!-- Logo Upload -->
        <form id="form-logo" class="glass-card p-8 space-y-6 mt-6">
            <input type="hidden" name="action" value="save_logo">
            <div>
                <h2 class="text-lg font-black text-slate-900 dark:text-white">Company Logo</h2>
                <p class="text-xs text-slate-400 font-medium mt-0.5">Shown on invoices, statements, and printed documents.</p>
            </div>

            <?php if (!empty($s['logo_url'])): ?>
            <div id="logo-preview-wrap" class="flex items-center gap-5 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                <img id="logo-preview-img" src="<?php echo htmlspecialchars($s['logo_url']); ?>"
                     alt="Company Logo" class="h-16 max-w-[180px] object-contain rounded-xl">
                <div>
                    <p class="text-xs font-black text-slate-500 uppercase tracking-widest">Current Logo</p>
                    <p class="text-[11px] text-slate-400 mt-0.5 break-all"><?php echo htmlspecialchars($s['logo_url']); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div id="logo-preview-wrap" class="hidden flex items-center gap-5 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                <img id="logo-preview-img" src="" alt="Logo preview" class="h-16 max-w-[180px] object-contain rounded-xl">
            </div>
            <?php endif; ?>

            <div class="space-y-2">
                <label class="field-label">Upload Logo File</label>
                <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/svg+xml"
                       class="field-input py-3 cursor-pointer"
                       onchange="previewLogo(this)">
                <p class="text-[10px] text-slate-400 px-2">PNG, JPG, GIF, or SVG &middot; Max 2 MB &middot; Transparent PNG recommended</p>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="btn-green px-8 py-3 font-black">Upload Logo</button>
            </div>
        </form>
    </div>

    <!-- ===== TAB: INVOICE ===== -->
    <div id="tab-invoice" class="tab-panel hidden">
        <form id="form-invoice" class="glass-card p-8 space-y-6">
            <input type="hidden" name="action" value="save_invoice">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Invoice & Billing Settings</h2>
            <p class="text-xs text-slate-400 font-medium -mt-3">Controls invoice numbering, due dates, and PDF footer text.</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-2">
                    <label class="field-label">Currency Symbol</label>
                    <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars($s['currency_symbol']); ?>"
                           class="field-input" placeholder="KSh">
                </div>
                <div class="space-y-2">
                    <label class="field-label">Invoice Number Prefix</label>
                    <input type="text" name="invoice_prefix" value="<?php echo htmlspecialchars($s['invoice_prefix']); ?>"
                           class="field-input" placeholder="INV">
                    <p class="text-[10px] text-slate-400 px-2">E.g. INV-A1B2C3D4 &middot; corrected copies gain -R1, -R2 &hellip;</p>
                </div>
                <div class="space-y-2">
                    <label class="field-label">Receipt Number Prefix</label>
                    <input type="text" name="receipt_prefix" value="<?php echo htmlspecialchars($s['receipt_prefix']); ?>"
                           class="field-input" placeholder="RCT">
                    <p class="text-[10px] text-slate-400 px-2">E.g. RCT-A1B2C3D4</p>
                </div>
                <div class="space-y-2">
                    <label class="field-label">Payment Due (days)</label>
                    <input type="number" name="invoice_due_days" value="<?php echo (int)$s['invoice_due_days']; ?>"
                           min="1" max="90" class="field-input" placeholder="7">
                </div>
            </div>

            <div class="space-y-2">
                <label class="field-label">Management Fee Rate (%)</label>
                <input type="number" name="management_fee_rate"
                       value="<?php echo number_format((float)$s['management_fee_rate'], 2, '.', ''); ?>"
                       min="0" max="100" step="0.01" class="field-input" placeholder="10">
                <p class="text-[10px] text-slate-400 px-2">Percentage deducted from landlord gross rent for payout calculations (e.g. 10 = 10%).</p>
            </div>

            <div class="space-y-2">
                <label class="field-label">Invoice Footer Text</label>
                <textarea name="invoice_footer" rows="3"
                          class="field-input resize-none"
                          placeholder="e.g. For inquiries, contact us at the above details."><?php echo htmlspecialchars($s['invoice_footer']); ?></textarea>
                <p class="text-[11px] text-slate-400 mt-1">The due date is always shown automatically on invoices. Use this field for contact info, terms, or any additional note.</p>
            </div>

            <div class="space-y-2">
                <label class="field-label">Fiscal Year Start Month</label>
                <select name="fiscal_year_start" class="field-input">
                    <?php foreach ($months as $i => $month): ?>
                    <option value="<?php echo $i + 1; ?>" <?php echo (int)$s['fiscal_year_start'] === $i + 1 ? 'selected' : ''; ?>>
                        <?php echo $month; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="btn-green px-8 py-3 font-black">Save Invoice Settings</button>
            </div>
        </form>
    </div>

    <!-- ===== TAB: MPESA ===== -->
    <div id="tab-mpesa" class="tab-panel hidden">
        <form id="form-mpesa" class="glass-card p-8 space-y-6">
            <input type="hidden" name="action" value="save_mpesa">

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-black text-slate-900 dark:text-white">M-Pesa (Daraja) Configuration</h2>
                    <p class="text-xs text-slate-400 font-medium mt-0.5">Credentials for STK Push on utility token purchases.</p>
                </div>
                <span id="mpesa-env-badge"
                      class="badge <?php echo $s['mpesa_environment'] === 'live' ? 'badge-green' : 'badge-orange'; ?>">
                    <?php echo $s['mpesa_environment'] === 'live' ? 'Live' : 'Sandbox'; ?>
                </span>
            </div>

            <!-- Environment toggle -->
            <div class="space-y-2">
                <label class="field-label">Environment</label>
                <div class="flex gap-3">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mpesa_environment" value="sandbox" class="accent-orange-400"
                               <?php echo $s['mpesa_environment'] !== 'live' ? 'checked' : ''; ?>
                               onchange="updateEnvBadge(this.value)">
                        <span class="text-sm font-bold">Sandbox (Testing)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mpesa_environment" value="live" class="accent-green-500"
                               <?php echo $s['mpesa_environment'] === 'live' ? 'checked' : ''; ?>
                               onchange="updateEnvBadge(this.value)">
                        <span class="text-sm font-bold">Live (Production)</span>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="field-label">Business Shortcode / Till</label>
                    <input type="text" name="mpesa_shortcode" value="<?php echo htmlspecialchars($s['mpesa_shortcode']); ?>"
                           class="field-input" placeholder="174379">
                </div>
                <div class="space-y-2">
                    <label class="field-label">Consumer Key</label>
                    <input type="text" name="mpesa_consumer_key" value="<?php echo htmlspecialchars($s['mpesa_consumer_key']); ?>"
                           class="field-input" placeholder="Enter Consumer Key">
                </div>
                <div class="space-y-2">
                    <label class="field-label">Consumer Secret</label>
                    <input type="password" name="mpesa_consumer_secret"
                           value="<?php echo htmlspecialchars($s['mpesa_consumer_secret']); ?>"
                           class="field-input" placeholder="Enter Consumer Secret"
                           autocomplete="new-password">
                </div>
                <div class="space-y-2">
                    <label class="field-label">Passkey</label>
                    <input type="password" name="mpesa_passkey" value="<?php echo htmlspecialchars($s['mpesa_passkey']); ?>"
                           class="field-input" placeholder="Enter Passkey"
                           autocomplete="new-password">
                </div>
            </div>

            <div class="space-y-2">
                <label class="field-label">STK Push Callback URL</label>
                <input type="url" name="mpesa_callback_url" value="<?php echo htmlspecialchars($s['mpesa_callback_url']); ?>"
                       class="field-input" placeholder="https://yourdomain.com/mpesa/callback.php">
                <p class="text-[10px] text-slate-400 px-2">Must be a publicly accessible HTTPS URL. M-Pesa sends payment results here.</p>
            </div>

            <div class="p-4 bg-orange-50 dark:bg-orange-900/15 rounded-2xl border border-orange-100 dark:border-orange-900/30">
                <p class="text-xs font-bold text-orange-700 dark:text-orange-400">
                    If credentials are left blank, STK push is skipped and tokens are saved as <strong>Pending</strong> for manual confirmation.
                </p>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="btn-orange px-8 py-3 font-black">Save M-Pesa Settings</button>
            </div>
        </form>
    </div>

    <!-- ===== TAB: EMAIL ===== -->
    <div id="tab-email" class="tab-panel hidden">
        <form id="form-email" class="glass-card p-8 space-y-6">
            <input type="hidden" name="action" value="save_email">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Email & Notification Settings</h2>
            <p class="text-xs text-slate-400 font-medium -mt-3">Configure outgoing email delivery and which events send emails.</p>

            <!-- Mail driver -->
            <div class="space-y-2">
                <label class="field-label">Mail Driver</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mail_driver" value="mail" class="accent-green-500"
                               <?php echo $s['mail_driver'] !== 'smtp' ? 'checked' : ''; ?>>
                        <span class="text-sm font-bold">PHP mail() <span class="font-medium text-slate-400">(default, works on most hosting)</span></span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="mail_driver" value="smtp" class="accent-green-500"
                               <?php echo $s['mail_driver'] === 'smtp' ? 'checked' : ''; ?>>
                        <span class="text-sm font-bold">SMTP <span class="font-medium text-slate-400">(Gmail, Outlook, custom)</span></span>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="field-label">From Name</label>
                    <input type="text" name="smtp_from_name" value="<?php echo htmlspecialchars($s['smtp_from_name']); ?>"
                           class="field-input" placeholder="Primelink Management">
                </div>
                <div class="space-y-2">
                    <label class="field-label">From Email</label>
                    <input type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($s['smtp_from_email']); ?>"
                           class="field-input" placeholder="noreply@primelink.co.ke">
                </div>
            </div>

            <!-- SMTP fields (shown when SMTP selected) -->
            <div id="smtp-fields" class="<?php echo $s['mail_driver'] === 'smtp' ? '' : 'hidden'; ?> space-y-4 p-5 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-100 dark:border-slate-800">
                <p class="text-xs font-black text-slate-500 uppercase tracking-widest">SMTP Configuration</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2 space-y-2">
                        <label class="field-label">SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($s['smtp_host']); ?>"
                               class="field-input" placeholder="smtp.gmail.com">
                    </div>
                    <div class="space-y-2">
                        <label class="field-label">Port</label>
                        <input type="number" name="smtp_port" value="<?php echo (int)$s['smtp_port'] ?: 587; ?>"
                               class="field-input" placeholder="587">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="field-label">SMTP Username</label>
                        <input type="text" name="smtp_user" value="<?php echo htmlspecialchars($s['smtp_user']); ?>"
                               class="field-input" placeholder="you@gmail.com">
                    </div>
                    <div class="space-y-2">
                        <label class="field-label">SMTP Password / App Password</label>
                        <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars($s['smtp_pass']); ?>"
                               class="field-input" placeholder="App password" autocomplete="new-password">
                    </div>
                </div>
                <p class="text-[10px] text-slate-400">For Gmail: use an <strong>App Password</strong> (not your account password). Port 587 with STARTTLS is recommended.</p>
            </div>

            <!-- Notification toggles -->
            <div class="space-y-3 pt-2">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Send Email Notifications For:</p>
                <?php
                $notifSettings = [
                    ['notify_on_payment',     'Payment recorded / submitted'],
                    ['notify_on_maintenance', 'Maintenance request created / status updated'],
                    ['notify_on_lease',       'Lease created / renewed / terminated'],
                ];
                foreach ($notifSettings as [$key, $label]): ?>
                <label class="flex items-center gap-3 cursor-pointer group">
                    <div class="relative">
                        <input type="checkbox" name="<?php echo $key; ?>" value="1" id="toggle_<?php echo $key; ?>"
                               <?php echo $s[$key] === '1' ? 'checked' : ''; ?>
                               class="sr-only peer">
                        <div class="w-10 h-5 bg-slate-200 dark:bg-slate-700 rounded-full peer-checked:bg-accent-green transition-colors cursor-pointer"></div>
                        <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                    </div>
                    <span class="text-sm font-bold text-slate-700 dark:text-slate-300"><?php echo $label; ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Test email -->
            <div class="p-4 bg-blue-50 dark:bg-blue-900/15 rounded-2xl border border-blue-100 dark:border-blue-900/30 flex flex-col sm:flex-row items-start sm:items-center gap-4">
                <div class="flex-1">
                    <p class="text-xs font-black text-blue-700 dark:text-blue-400 mb-1">Test Your Configuration</p>
                    <p class="text-[10px] text-blue-600 dark:text-blue-500">Send a test email to verify your settings are correct.</p>
                </div>
                <button type="button" onclick="sendTestEmail()" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black transition-colors whitespace-nowrap">
                    Send Test Email
                </button>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="btn-green px-8 py-3 font-black">Save Email Settings</button>
            </div>
        </form>
    </div>

    <!-- ===== TAB: SYSTEM ===== -->
    <div id="tab-system" class="tab-panel hidden">
        <div class="glass-card p-8 space-y-6">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">System Information</h2>
            <p class="text-xs text-slate-400 font-medium -mt-3">Read-only diagnostics and quick links.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php
                $systemInfo = [
                    ['PHP Version',       PHP_VERSION],
                    ['Server Time',       date('Y-m-d H:i:s')],
                    ['Database',          'primelink_db (MySQL)'],
                    ['App Version',       'v1.0.0'],
                ];
                foreach ($systemInfo as [$label, $value]):
                ?>
                <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo $label; ?></p>
                    <p class="text-sm font-bold text-slate-900 dark:text-white mt-1"><?php echo htmlspecialchars($value); ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <hr class="border-slate-200 dark:border-slate-800">

            <h3 class="font-black text-slate-900 dark:text-white">Database Tools</h3>
            <div class="flex flex-wrap gap-3">
                <a href="migrate.php" target="_blank"
                   class="px-5 py-3 bg-slate-100 dark:bg-slate-800 rounded-xl text-xs font-bold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                    Run Migrations →
                </a>
                <a href="seed.php" target="_blank"
                   class="px-5 py-3 bg-slate-100 dark:bg-slate-800 rounded-xl text-xs font-bold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                    Seed Test Data →
                </a>
            </div>

            <hr class="border-slate-200 dark:border-slate-800">

            <div class="p-4 bg-red-50 dark:bg-red-900/10 rounded-2xl border border-red-100 dark:border-red-900/30">
                <p class="text-xs font-black text-red-600 dark:text-red-400 mb-1">Danger Zone</p>
                <p class="text-xs text-red-500 dark:text-red-500/70">Database tools above execute immediately. Only run migrations in a maintenance window.</p>
            </div>
        </div>
    </div>

    <!-- ===== TAB: PENALTIES ===== -->
    <div id="tab-penalties" class="tab-panel hidden">
        <form id="form-penalties" class="glass-card p-8 space-y-6">
            <input type="hidden" name="action" value="save_penalties">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">Late Payment Penalties</h2>
            <p class="text-xs text-slate-400 font-medium -mt-3">Automatically charge a penalty fee on overdue invoices that exceed the grace period.</p>

            <!-- Enable toggle -->
            <div class="flex items-center justify-between p-5 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-100 dark:border-slate-800">
                <div>
                    <p class="font-black text-sm text-slate-900 dark:text-white">Enable Late Payment Penalties</p>
                    <p class="text-xs text-slate-400 mt-0.5">When enabled, overdue invoices past the grace period will appear in the Penalty Manager.</p>
                </div>
                <label class="relative cursor-pointer shrink-0">
                    <input type="checkbox" name="penalty_enabled" value="1" id="penalty_enabled_toggle"
                           <?php echo $s['penalty_enabled'] === '1' ? 'checked' : ''; ?>
                           class="sr-only peer">
                    <div class="w-12 h-6 bg-slate-200 dark:bg-slate-700 rounded-full peer-checked:bg-accent-green transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-6"></div>
                </label>
            </div>

            <!-- Grace period -->
            <div class="space-y-2">
                <label class="field-label">Grace Period (days after due date)</label>
                <input type="number" name="penalty_grace_days"
                       value="<?php echo (int)$s['penalty_grace_days']; ?>"
                       min="0" max="90" class="field-input" placeholder="5">
                <p class="text-[10px] text-slate-400 px-2">
                    Invoices must be overdue for at least this many days before a penalty can be applied.
                    Set to 0 for no grace period.
                </p>
            </div>

            <!-- Penalty type -->
            <div class="space-y-3">
                <label class="field-label">Penalty Type</label>
                <div class="grid grid-cols-2 gap-4">
                    <label class="relative cursor-pointer">
                        <input type="radio" name="penalty_type" value="fixed" class="peer sr-only"
                               <?php echo $s['penalty_type'] !== 'percentage' ? 'checked' : ''; ?>
                               onchange="document.getElementById('pen-fixed').classList.remove('hidden'); document.getElementById('pen-pct').classList.add('hidden');">
                        <div class="p-4 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-center transition-all peer-checked:border-accent-green peer-checked:bg-green-50 dark:peer-checked:bg-green-900/20 hover:border-slate-400 dark:hover:border-slate-500">
                            <p class="font-black text-sm">Fixed Amount</p>
                            <p class="text-[10px] text-slate-400 mt-0.5">Same penalty regardless of invoice amount</p>
                        </div>
                    </label>
                    <label class="relative cursor-pointer">
                        <input type="radio" name="penalty_type" value="percentage" class="peer sr-only"
                               <?php echo $s['penalty_type'] === 'percentage' ? 'checked' : ''; ?>
                               onchange="document.getElementById('pen-pct').classList.remove('hidden'); document.getElementById('pen-fixed').classList.add('hidden');">
                        <div class="p-4 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-center transition-all peer-checked:border-accent-green peer-checked:bg-green-50 dark:peer-checked:bg-green-900/20 hover:border-slate-400 dark:hover:border-slate-500">
                            <p class="font-black text-sm">Percentage (%)</p>
                            <p class="text-[10px] text-slate-400 mt-0.5">Proportional to the overdue invoice amount</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Fixed amount field -->
            <div id="pen-fixed" class="space-y-2 <?php echo $s['penalty_type'] === 'percentage' ? 'hidden' : ''; ?>">
                <label class="field-label">Penalty Amount (<?php echo htmlspecialchars($s['currency_symbol'] ?: 'KSh'); ?>)</label>
                <input type="number" name="penalty_amount"
                       value="<?php echo number_format((float)$s['penalty_amount'], 2, '.', ''); ?>"
                       min="0" step="0.01" class="field-input" placeholder="500">
            </div>

            <!-- Percentage field -->
            <div id="pen-pct" class="space-y-2 <?php echo $s['penalty_type'] !== 'percentage' ? 'hidden' : ''; ?>">
                <label class="field-label">Penalty Percentage (%)</label>
                <input type="number" name="penalty_percentage"
                       value="<?php echo number_format((float)$s['penalty_percentage'], 2, '.', ''); ?>"
                       min="0" max="100" step="0.1" class="field-input" placeholder="5">
                <p class="text-[10px] text-slate-400 px-2">E.g. 5 means 5% of the original overdue invoice amount.</p>
            </div>

            <!-- Info box -->
            <div class="p-4 bg-blue-50 dark:bg-blue-900/15 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                <p class="text-[10px] font-black text-blue-700 dark:text-blue-400 uppercase tracking-widest mb-1">How it works</p>
                <p class="text-xs text-blue-600 dark:text-blue-400 leading-relaxed">
                    Penalties are <strong>not applied automatically</strong>. Go to
                    <a href="late_penalties.php" class="font-black underline">Financials → Late Penalties</a>
                    to preview eligible invoices and apply penalties in bulk or individually.
                    Each penalty is created as a separate invoice of type <em>Penalty</em>, visible in the tenant's portal.
                </p>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="btn-green px-8 py-3 font-black">Save Penalty Settings</button>
            </div>
        </form>
    </div>

</div>

<style>
.tab-btn {
    padding: 0.5rem 1.1rem;
    border-radius: 0.875rem;
    font-size: 0.75rem;
    font-weight: 900;
    transition: all 0.15s;
    color: #94a3b8;
    background: transparent;
    border: none;
    cursor: pointer;
}
.tab-btn.active {
    background: #fff;
    color: #0f172a;
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
}
.dark .tab-btn.active {
    background: #1e293b;
    color: #f1f5f9;
}
.tab-btn:hover:not(.active) { color: #64748b; }
.field-label {
    display: block;
    font-size: 0.625rem;
    font-weight: 900;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    padding: 0 0.5rem;
}
.field-input {
    width: 100%;
    padding: 1rem 1.25rem;
    background: #f1f5f9;
    border: none;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 700;
    outline: none;
    transition: box-shadow 0.15s;
}
.field-input:focus { box-shadow: 0 0 0 3px rgba(34,197,94,0.18); }
.dark .field-input { background: rgba(30,41,59,0.5); color: #f1f5f9; }
</style>

<script>
const VALID_TABS = ['company', 'invoice', 'mpesa', 'email', 'system', 'penalties'];

function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.remove('hidden');
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    history.replaceState(null, '', '#' + tab);
}

// Restore active tab from URL hash on load
(function initTab() {
    const hash = location.hash.replace('#', '');
    switchTab(VALID_TABS.includes(hash) ? hash : 'company');
})();

function updateEnvBadge(val) {
    const badge = document.getElementById('mpesa-env-badge');
    if (val === 'live') {
        badge.textContent = 'Live';
        badge.className = 'badge badge-green';
    } else {
        badge.textContent = 'Sandbox';
        badge.className = 'badge badge-orange';
    }
}

function showToast(msg, ok) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'fixed top-6 right-6 z-50 flex items-center gap-3 px-5 py-4 rounded-2xl shadow-2xl text-sm font-bold max-w-xs transition-all '
        + (ok ? 'bg-green-500 text-white' : 'bg-red-500 text-white');
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 3500);
}

// Logo local preview before upload
function previewLogo(input) {
    const wrap = document.getElementById('logo-preview-wrap');
    const img  = document.getElementById('logo-preview-img');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            img.src = e.target.result;
            wrap.classList.remove('hidden');
            wrap.style.display = 'flex';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// SMTP field visibility toggle
document.querySelectorAll('[name="mail_driver"]').forEach(r => {
    r.addEventListener('change', () => {
        document.getElementById('smtp-fields').classList.toggle('hidden', r.value !== 'smtp');
    });
});

// Test email
async function sendTestEmail() {
    const fr = new FormData();
    fr.append('action', 'test_email');
    const res = await fetch('actions/settings_actions.php', { method: 'POST', body: fr });
    const data = await res.json().catch(() => ({ message: 'Could not connect.' }));
    showToast(data.message, data.success);
}

// Generic form submit handler
['company', 'invoice', 'mpesa', 'email', 'penalties', 'logo'].forEach(name => {
    const form = document.getElementById('form-' + name);
    if (!form) return;
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const btn  = form.querySelector('[type="submit"]');
        const orig = btn.textContent;
        btn.textContent = 'Saving…';
        btn.disabled = true;
        try {
            const res  = await fetch('actions/settings_actions.php', { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            showToast(data.message, data.success);
            // Update logo preview src from server response
            if (name === 'logo' && data.success && data.logo_url) {
                const img = document.getElementById('logo-preview-img');
                if (img) img.src = data.logo_url + '?t=' + Date.now();
            }
        } catch {
            showToast('Network error — please retry.', false);
        } finally {
            btn.textContent = orig;
            btn.disabled = false;
        }
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
