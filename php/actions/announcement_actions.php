<?php
/**
 * Announcement Action Handler — Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff']);

require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../announcements.php');
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'send') {
    $title      = trim($_POST['title']       ?? '');
    $message    = trim($_POST['message']     ?? '');
    $urgency    = $_POST['urgency']          ?? 'Info';
    $audience   = $_POST['audience']         ?? 'all';
    $propertyId = trim($_POST['property_id'] ?? '');

    if (!$title || !$message) {
        header('Location: ../announcements.php?error=missing_fields');
        exit();
    }

    $validUrgencies = ['Info', 'Important', 'Urgent'];
    if (!in_array($urgency, $validUrgencies)) $urgency = 'Info';
    if ($audience !== 'property') { $audience = 'all'; $propertyId = ''; }
    if ($audience === 'property' && !$propertyId) {
        header('Location: ../announcements.php?error=no_property');
        exit();
    }

    // Resolve recipient tenant users from active leases
    if ($audience === 'all') {
        $stmt = $pdo->query("
            SELECT DISTINCT pr.id AS user_id, pr.full_name, pr.email
            FROM leases l
            JOIN tenants t   ON l.tenant_id = t.id
            JOIN profiles pr ON t.user_id   = pr.id
            WHERE l.status = 'Active'
              AND pr.email IS NOT NULL
              AND pr.email <> ''
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT pr.id AS user_id, pr.full_name, pr.email
            FROM leases l
            JOIN units    u  ON l.unit_id    = u.id
            JOIN tenants  t  ON l.tenant_id  = t.id
            JOIN profiles pr ON t.user_id    = pr.id
            WHERE l.status = 'Active'
              AND u.property_id = ?
              AND pr.email IS NOT NULL
              AND pr.email <> ''
        ");
        $stmt->execute([$propertyId]);
    }
    $recipients = $stmt->fetchAll();
    $recipientCount = count($recipients);

    // Notification type based on urgency
    $notifType = match($urgency) {
        'Urgent'    => 'error',
        'Important' => 'warning',
        default     => 'info',
    };

    $systemName = getSetting($pdo, 'company_name', 'Primelink Properties');
    $sentBy     = $_SESSION['user_id'] ?? '';

    foreach ($recipients as $r) {
        // In-app notification
        createNotification($pdo, $r['user_id'], $title, $message, $notifType);

        // Email
        $urgencyColor = match($urgency) {
            'Urgent'    => '#ef4444',
            'Important' => '#f97316',
            default     => '#3b82f6',
        };
        $emailHtml = "
        <div style='font-family:Inter,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0'>
          <div style='background:#0f172a;padding:28px 32px'>
            <h1 style='color:#fff;font-size:20px;font-weight:900;margin:0;letter-spacing:-0.5px'>{$systemName}</h1>
            <p style='color:#94a3b8;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2px;margin:4px 0 0'>Tenant Announcement</p>
          </div>
          <div style='padding:32px'>
            <div style='display:inline-block;background:{$urgencyColor}22;color:{$urgencyColor};font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:2px;padding:4px 12px;border-radius:999px;border:1px solid {$urgencyColor}44;margin-bottom:20px'>{$urgency}</div>
            <h2 style='font-size:22px;font-weight:900;color:#0f172a;margin:0 0 16px;line-height:1.3'>" . htmlspecialchars($title) . "</h2>
            <p style='font-size:15px;color:#475569;line-height:1.7;margin:0 0 24px;white-space:pre-wrap'>" . htmlspecialchars($message) . "</p>
            <div style='background:#f8fafc;border-radius:12px;padding:16px;border:1px solid #e2e8f0'>
              <p style='font-size:11px;color:#94a3b8;font-weight:700;margin:0'>This message was sent by your property management team. For questions, please contact the office directly.</p>
            </div>
          </div>
          <div style='background:#f8fafc;padding:20px 32px;border-top:1px solid #e2e8f0;text-align:center'>
            <p style='font-size:11px;color:#94a3b8;margin:0'>{$systemName} &mdash; Property Management System</p>
          </div>
        </div>";

        sendSystemEmail($pdo, $r['email'], $title, $emailHtml, $r['full_name']);
    }

    // Record announcement
    $annId = generateUUID();
    $pdo->prepare("
        INSERT INTO announcements (id, title, message, audience, property_id, urgency, sent_by, recipient_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $annId, $title, $message, $audience,
        $audience === 'property' ? $propertyId : null,
        $urgency, $sentBy, $recipientCount
    ]);

    logAction($pdo, 'announcement_sent', 'Announcements', $annId,
        "'{$title}' [{$urgency}] → {$audience}" . ($propertyId ? " (property:{$propertyId})" : '') . " — {$recipientCount} recipients");

    header("Location: ../announcements.php?success=sent&count={$recipientCount}");
    exit();
}

header('Location: ../announcements.php');
exit();
