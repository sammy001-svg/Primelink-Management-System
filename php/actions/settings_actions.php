<?php
/**
 * Settings Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/mailer.php';

$action = $_POST['action'] ?? '';

header('Content-Type: application/json');

if ($action === 'save_company') {
    setSettings($pdo, [
        'company_name'    => trim($_POST['company_name']    ?? ''),
        'company_email'   => trim($_POST['company_email']   ?? ''),
        'company_phone'   => trim($_POST['company_phone']   ?? ''),
        'company_address' => trim($_POST['company_address'] ?? ''),
        'company_tagline' => trim($_POST['company_tagline'] ?? ''),
    ]);
    echo json_encode(['success' => true, 'message' => 'Company profile saved.']);
    exit;
}

if ($action === 'save_invoice') {
    setSettings($pdo, [
        'currency_symbol'     => trim($_POST['currency_symbol']  ?? 'KSh'),
        'invoice_prefix'      => strtoupper(trim($_POST['invoice_prefix']  ?? 'INV')),
        'invoice_due_days'    => (string)(int)($_POST['invoice_due_days'] ?? 7),
        'invoice_footer'      => trim($_POST['invoice_footer']   ?? ''),
        'fiscal_year_start'   => (string)max(1, min(12, (int)($_POST['fiscal_year_start'] ?? 1))),
        'management_fee_rate' => (string)max(0, min(100, (float)($_POST['management_fee_rate'] ?? 10))),
    ]);
    echo json_encode(['success' => true, 'message' => 'Invoice settings saved.']);
    exit;
}

if ($action === 'save_mpesa') {
    $env = in_array($_POST['mpesa_environment'] ?? '', ['sandbox', 'live']) ? $_POST['mpesa_environment'] : 'sandbox';
    setSettings($pdo, [
        'mpesa_shortcode'       => trim($_POST['mpesa_shortcode']        ?? ''),
        'mpesa_consumer_key'    => trim($_POST['mpesa_consumer_key']     ?? ''),
        'mpesa_consumer_secret' => trim($_POST['mpesa_consumer_secret']  ?? ''),
        'mpesa_passkey'         => trim($_POST['mpesa_passkey']          ?? ''),
        'mpesa_callback_url'    => trim($_POST['mpesa_callback_url']     ?? ''),
        'mpesa_environment'     => $env,
    ]);
    echo json_encode(['success' => true, 'message' => 'M-Pesa settings saved.']);
    exit;
}

if ($action === 'save_email') {
    $driver = in_array($_POST['mail_driver'] ?? '', ['mail', 'smtp']) ? $_POST['mail_driver'] : 'mail';
    setSettings($pdo, [
        'mail_driver'           => $driver,
        'smtp_host'             => trim($_POST['smtp_host']         ?? ''),
        'smtp_port'             => (string)(int)($_POST['smtp_port'] ?? 587),
        'smtp_user'             => trim($_POST['smtp_user']         ?? ''),
        'smtp_pass'             => trim($_POST['smtp_pass']         ?? ''),
        'smtp_from_name'        => trim($_POST['smtp_from_name']    ?? 'Primelink Management'),
        'smtp_from_email'       => trim($_POST['smtp_from_email']   ?? ''),
        'notify_on_payment'     => isset($_POST['notify_on_payment'])     ? '1' : '0',
        'notify_on_maintenance' => isset($_POST['notify_on_maintenance']) ? '1' : '0',
        'notify_on_lease'       => isset($_POST['notify_on_lease'])       ? '1' : '0',
    ]);
    echo json_encode(['success' => true, 'message' => 'Email settings saved.']);
    exit;
}

if ($action === 'test_email') {
    $toEmail = $_SESSION['email'] ?? getSetting($pdo, 'company_email', '');
    if (!$toEmail) {
        echo json_encode(['success' => false, 'message' => 'No target email address found.']);
        exit;
    }
    $html = buildEmailHtml(
        $pdo,
        'Test Email — Configuration OK',
        '<p style="color:#475569;font-size:14px;line-height:1.7">This is a test email from your Primelink Management System. If you received this, your email configuration is working correctly.</p>',
    );
    $ok = sendSystemEmail($pdo, $toEmail, 'Primelink — Test Email', $html);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? "Test email sent to {$toEmail}." : 'Failed to send. Check your SMTP credentials or try PHP mail() driver.',
    ]);
    exit;
}

if ($action === 'save_penalties') {
    $type = in_array($_POST['penalty_type'] ?? '', ['fixed', 'percentage']) ? $_POST['penalty_type'] : 'fixed';
    setSettings($pdo, [
        'penalty_enabled'    => isset($_POST['penalty_enabled']) ? '1' : '0',
        'penalty_grace_days' => (string)max(0, min(90, (int)($_POST['penalty_grace_days'] ?? 5))),
        'penalty_type'       => $type,
        'penalty_amount'     => (string)max(0, (float)($_POST['penalty_amount']     ?? 500)),
        'penalty_percentage' => (string)max(0, min(100, (float)($_POST['penalty_percentage'] ?? 5))),
    ]);
    echo json_encode(['success' => true, 'message' => 'Penalty settings saved.']);
    exit;
}

if ($action === 'save_logo') {
    if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
        exit;
    }
    $file    = $_FILES['logo_file'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Use PNG, JPG, GIF, or SVG.']);
        exit;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large. Maximum 2 MB.']);
        exit;
    }
    // Verify MIME for raster types
    if ($ext !== 'svg') {
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mime     = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            echo json_encode(['success' => false, 'message' => 'File content does not match the selected image type.']);
            exit;
        }
    }
    $uploadDir = __DIR__ . '/../../uploads/logos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'company_logo.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file. Check directory permissions.']);
        exit;
    }
    $logoUrl = 'uploads/logos/' . $filename;
    setSetting($pdo, 'logo_url', $logoUrl);
    echo json_encode(['success' => true, 'message' => 'Logo uploaded successfully.', 'logo_url' => $logoUrl]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
