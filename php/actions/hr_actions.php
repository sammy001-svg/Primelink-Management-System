<?php
/**
 * HR Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $role = $_POST['role_title'] ?? '';
        $department = $_POST['department'] ?? 'Operations';
        $salary = $_POST['salary'] ?? 0;
        $status = $_POST['status'] ?? 'Active';

        $id = generateUUID();

        try {
            $stmt = $pdo->prepare("INSERT INTO employees (id, full_name, email, phone, role, department, salary, status, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$id, $full_name, $email, $phone, $role, $department, $salary, $status]);
            header("Location: ../hr.php?success=created");
            exit();
        } catch (PDOException $e) {
            die("Error adding employee: " . $e->getMessage());
        }
    }
}
?>
