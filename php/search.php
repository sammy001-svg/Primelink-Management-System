<?php
/**
 * Global Search Endpoint
 * Returns JSON results for tenants, properties, leases, transactions.
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$like  = '%' . $q . '%';
$role  = $_SESSION['role'] ?? 'tenant';
$results = [];

// Tenants — visible to admin/staff/landlord
if (in_array($role, ['admin', 'staff', 'landlord'])) {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, phone, email, status
         FROM tenants
         WHERE full_name LIKE ? OR phone LIKE ? OR email LIKE ?
         LIMIT 5"
    );
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type'     => 'Tenant',
            'icon'     => 'user',
            'label'    => $row['full_name'],
            'sub'      => $row['phone'] . ' · ' . ucfirst(strtolower($row['status'] ?? '')),
            'url'      => 'tenant_details.php?id=' . $row['id'],
        ];
    }
}

// Properties — visible to admin/staff/landlord
if (in_array($role, ['admin', 'staff', 'landlord'])) {
    $stmt = $pdo->prepare(
        "SELECT id, title, location, property_type
         FROM properties
         WHERE title LIKE ? OR location LIKE ? OR property_code LIKE ?
         LIMIT 5"
    );
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Property',
            'icon'  => 'building',
            'label' => $row['title'],
            'sub'   => $row['location'] . ' · ' . $row['property_type'],
            'url'   => 'property_details.php?id=' . $row['id'],
        ];
    }
}

// Leases — admin/staff
if (in_array($role, ['admin', 'staff'])) {
    $stmt = $pdo->prepare(
        "SELECT l.id, t.full_name, p.title as property_title, u.unit_number, l.status
         FROM leases l
         JOIN tenants t  ON l.tenant_id   = t.id
         JOIN units u    ON l.unit_id     = u.id
         JOIN properties p ON u.property_id = p.id
         WHERE t.full_name LIKE ? OR p.title LIKE ? OR u.unit_number LIKE ?
         LIMIT 4"
    );
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Lease',
            'icon'  => 'file',
            'label' => $row['full_name'] . ' — ' . $row['unit_number'],
            'sub'   => $row['property_title'] . ' · ' . $row['status'],
            'url'   => 'leases.php',
        ];
    }
}

// Transactions — admin/staff
if (in_array($role, ['admin', 'staff'])) {
    $stmt = $pdo->prepare(
        "SELECT tx.id, t.full_name, tx.amount, tx.status, tx.transaction_type
         FROM transactions tx
         JOIN tenants t ON tx.tenant_id = t.id
         WHERE t.full_name LIKE ? OR tx.transaction_type LIKE ?
         ORDER BY tx.transaction_date DESC
         LIMIT 4"
    );
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Payment',
            'icon'  => 'dollar',
            'label' => $row['full_name'] . ' — KSh ' . number_format($row['amount']),
            'sub'   => $row['transaction_type'] . ' · ' . $row['status'],
            'url'   => 'financials.php',
        ];
    }
}

// Maintenance — everyone with access
if (in_array($role, ['admin', 'staff', 'landlord'])) {
    $stmt = $pdo->prepare(
        "SELECT id, title, status, priority FROM maintenance_requests
         WHERE title LIKE ? OR description LIKE ?
         ORDER BY created_at DESC LIMIT 3"
    );
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Maintenance',
            'icon'  => 'tool',
            'label' => $row['title'],
            'sub'   => ucfirst($row['priority'] ?? 'Normal') . ' · ' . $row['status'],
            'url'   => 'maintenance.php',
        ];
    }
}

echo json_encode(['results' => $results]);
