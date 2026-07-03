<?php
/**
 * Payout Action Handler — Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../payouts.php");
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'process_payout') {
    $landlordId  = $_POST['landlord_id']           ?? '';
    $gross       = (float)($_POST['gross_amount']   ?? 0);
    $feeDed      = (float)($_POST['fee_deducted']   ?? 0);
    $maintDed    = (float)($_POST['maintenance_deduction'] ?? 0);
    $expDed      = (float)($_POST['expense_deduction']     ?? 0);
    $advDed      = (float)($_POST['advance_deduction']     ?? 0);
    $net         = (float)($_POST['net_amount']     ?? 0);
    $method      = $_POST['method']                 ?? 'Bank Transfer';
    $notes       = trim($_POST['notes']             ?? '');
    $periodMonth = (int)($_POST['period_month']     ?? date('m'));
    $periodYear  = (int)($_POST['period_year']      ?? date('Y'));

    if (!$landlordId || $net <= 0) {
        header("Location: ../payouts.php?error=invalid");
        exit();
    }

    // Prevent duplicate payout for same landlord + period
    $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM landlord_payouts WHERE landlord_id = ? AND period_month = ? AND period_year = ?");
    $dupCheck->execute([$landlordId, $periodMonth, $periodYear]);
    if ((int)$dupCheck->fetchColumn() > 0) {
        header("Location: ../payouts.php?error=duplicate&month={$periodMonth}&year={$periodYear}");
        exit();
    }

    $id  = generateUUID();
    $ref = 'PAY-' . strtoupper(substr(md5($id . $landlordId . $periodMonth . $periodYear), 0, 8));

    try {
        $pdo->beginTransaction();

        // Insert payout record
        $stmt = $pdo->prepare("
            INSERT INTO landlord_payouts
                (id, landlord_id, amount, fee_deducted, gross_amount,
                 maintenance_deduction, expense_deduction, advance_deduction,
                 reference_code, status, payment_method, period_month, period_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed', ?, ?, ?)
        ");
        $stmt->execute([
            $id, $landlordId, $net, $feeDed, $gross,
            $maintDed, $expDed, $advDed,
            $ref, $method, $periodMonth, $periodYear,
        ]);

        // Mark advances as deducted
        if ($advDed > 0) {
            $pdo->prepare("UPDATE landlord_advances SET is_deducted = 1 WHERE landlord_id = ? AND status = 'Approved' AND (is_deducted = 0 OR is_deducted IS NULL)")
                ->execute([$landlordId]);
        }

        $pdo->commit();

        // Notify landlord user
        $llStmt = $pdo->prepare("SELECT user_id, full_name FROM landlords WHERE id = ?");
        $llStmt->execute([$landlordId]);
        $ll = $llStmt->fetch();
        if ($ll && !empty($ll['user_id'])) {
            $currency = getSetting($pdo, 'currency_symbol', 'KSh');
            $netFmt   = $currency . ' ' . number_format($net);
            $months   = ['1'=>'January','2'=>'February','3'=>'March','4'=>'April',
                         '5'=>'May','6'=>'June','7'=>'July','8'=>'August',
                         '9'=>'September','10'=>'October','11'=>'November','12'=>'December'];
            $period   = ($months[$periodMonth] ?? '') . ' ' . $periodYear;
            createNotification($pdo, $ll['user_id'], 'Payout Processed',
                "Your {$period} payout of {$netFmt} has been processed (Ref: {$ref}).", 'success');
        }

        logAction($pdo, 'payout_processed', 'Financials', $id,
            "Landlord ID {$landlordId} — Net {$net} (Gross {$gross}, Fee {$feeDed}, Maint {$maintDed}, Exp {$expDed}, Adv {$advDed}) Ref: {$ref}");

        header("Location: ../payouts.php?success=processed&ref={$ref}&month={$periodMonth}&year={$periodYear}");
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Error processing payout: " . $e->getMessage());
    }
}

header("Location: ../payouts.php");
exit();
