<?php
/**
 * Tenant Detail Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tenantId = $_POST['tenant_id'] ?? '';

    if ($action === 'update_profile') {
        $fullName = $_POST['full_name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $profession = $_POST['profession'] ?? '';
        $employerName = $_POST['employer_name'] ?? '';
        $maritalStatus = $_POST['marital_status'] ?? 'Single';

        try {
            $pdo->beginTransaction();

            // 1. Update Profile (linked by user_id)
            $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $userId = $stmt->fetchColumn();

            if ($userId) {
                $stmt = $pdo->prepare("UPDATE profiles SET full_name = ?, phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone, $address, $userId]);
            }

            // 2. Update Tenant record
            $stmt = $pdo->prepare("
                UPDATE tenants 
                SET full_name = ?, phone = ?, current_address = ?, profession = ?, employer_name = ?, marital_status = ?
                WHERE id = ?
            ");
            $stmt->execute([$fullName, $phone, $address, $profession, $employerName, $maritalStatus, $tenantId]);

            $pdo->commit();
            header("Location: ../tenant_details.php?id=$tenantId&success=profile_updated");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Error updating profile: " . $e->getMessage());
        }
    }

    if ($action === 'reset_password') {
        $newPass  = $_POST['new_password']    ?? '';
        $confPass = $_POST['confirm_password'] ?? '';
        $redir    = $_POST['_redirect']        ?? null;

        if ($newPass !== $confPass) {
            $back = $redir ?: "../tenant_details.php?id=$tenantId";
            header("Location: $back&error=passwords_mismatch");
            exit();
        }

        $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $userId]);
            $dest = $redir ?: "../tenant_details.php?id=$tenantId&success=password_reset";
            // append success param if redirecting back to tenants list
            if ($redir && strpos($redir, '?') === false) $dest .= '?success=password_reset';
            elseif ($redir)                               $dest .= '&success=password_reset';
            header("Location: $dest");
            exit();
        }
    }

    if ($action === 'quick_edit') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone']     ?? '');
        $email    = trim($_POST['email']     ?? '');
        if (!$fullName) {
            header('Location: ../tenants.php?error=' . urlencode('Name is required.'));
            exit();
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $userId = $stmt->fetchColumn();
            $pdo->prepare("UPDATE tenants SET full_name = ?, phone = ?, email = ? WHERE id = ?")->execute([$fullName, $phone, $email, $tenantId]);
            if ($userId) {
                $pdo->prepare("UPDATE profiles SET full_name = ?, phone = ?, email = ? WHERE id = ?")->execute([$fullName, $phone, $email, $userId]);
            }
            $pdo->commit();
            header('Location: ../tenants.php?success=profile_updated');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: ../tenants.php?error=' . urlencode($e->getMessage()));
        }
        exit();
    }
}
