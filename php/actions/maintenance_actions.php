<?php
/**
 * Maintenance Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_role = $_SESSION['role'] ?? 'tenant';

    if ($action === 'update_status' && $user_role != 'tenant') {
        $id = $_POST['id'] ?? '';
        $status = $_POST['status'] ?? 'Pending';

        try {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            header("Location: ../maintenance.php?success=updated");
            exit();
        } catch (PDOException $e) {
            die("Error updating maintenance request: " . $e->getMessage());
        }
    }

    if ($action === 'create') {
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $priority = $_POST['priority'] ?? 'Normal';
        $property_id = $_POST['property_id'] ?? null;
        $unit_id = $_POST['unit_id'] ?? null;
        
        // Find tenant_id for the current user (if tenant)
        $tenant_id = null;
        if ($_SESSION['role'] === 'tenant') {
            $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $tenant = $stmt->fetch();
            $tenant_id = $tenant['id'] ?? null;
        }

        $id = generateUUID();

        try {
            $stmt = $pdo->prepare("INSERT INTO maintenance_requests (id, property_id, unit_id, tenant_id, title, description, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$id, $property_id, $unit_id, $tenant_id, $title, $description, $priority]);
            header("Location: ../maintenance.php?success=created");
            exit();
        } catch (PDOException $e) {
            die("Error creating maintenance request: " . $e->getMessage());
        }
    }

    if ($action === 'assign_agent' && $user_role != 'tenant') {
        $id = $_POST['id'] ?? '';
        $staff_id = $_POST['staff_id'] ?? null;

        try {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET assigned_staff_id = ?, status = 'In Progress' WHERE id = ?");
            $stmt->execute([$staff_id, $id]);
            header("Location: ../maintenance.php?success=assigned");
            exit();
        } catch (PDOException $e) {
            die("Error assigning agent: " . $e->getMessage());
        }
    }

    if ($action === 'push_to_landlord' && $user_role != 'tenant') {
        $id = $_POST['id'] ?? '';

        try {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET pushed_to_landlord = 1, landlord_approval_status = 'Pending' WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: ../maintenance.php?success=pushed");
            exit();
        } catch (PDOException $e) {
            // Self-healing: if columns missing
            if ($e->getCode() == '42S22') {
                try {
                    $pdo->exec("ALTER TABLE `maintenance_requests` ADD COLUMN IF NOT EXISTS `pushed_to_landlord` TINYINT(1) DEFAULT 0");
                    $pdo->exec("ALTER TABLE `maintenance_requests` ADD COLUMN IF NOT EXISTS `landlord_approval_status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending'");
                    $stmt = $pdo->prepare("UPDATE maintenance_requests SET pushed_to_landlord = 1, landlord_approval_status = 'Pending' WHERE id = ?");
                    $stmt->execute([$id]);
                    header("Location: ../maintenance.php?success=pushed");
                    exit();
                } catch (PDOException $retryError) {
                    die("Maintenance Repair Failed: " . $retryError->getMessage());
                }
            }
            die("Error pushing to landlord: " . $e->getMessage());
        }
    }

    if ($action === 'landlord_decision' && $user_role === 'landlord') {
        $id = $_POST['id'] ?? '';
        $decision = $_POST['decision'] ?? 'Pending';

        try {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET landlord_approval_status = ? WHERE id = ?");
            $stmt->execute([$decision, $id]);
            header("Location: ../maintenance.php?success=decided");
            exit();
        } catch (PDOException $e) {
            die("Error recording landlord decision: " . $e->getMessage());
        }
    }
}
?>
