<?php
/**
 * Data Seeder
 * Primelink Management System
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

function seed($pdo) {
    echo "Starting seeding...\n";

    // 1. Clear existing data (CAREFUL with this in production!)
    $tables = ['documents', 'employee_leaves', 'transactions', 'maintenance_requests', 'leases', 'employees', 'tenants', 'units', 'properties', 'landlords', 'profiles', 'users'];
    foreach ($tables as $table) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("TRUNCATE TABLE $table;");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }

    // 2. Create Admin User
    $adminId = generateUUID();
    $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, ?)")
        ->execute([$adminId, 'admin@primelink.com', $adminPass, 'staff']);
    
    $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role) VALUES (?, ?, ?, ?, ?)")
        ->execute([$adminId, 'System Administrator', 'admin@primelink.com', '+254700000000', 'staff']);

    // 3. Create a Landlord
    $landlordUserId = generateUUID();
    $landlordPass = password_hash('landlord123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, ?)")
        ->execute([$landlordUserId, 'landlord@example.com', $landlordPass, 'landlord']);
    
    $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role) VALUES (?, ?, ?, ?, ?)")
        ->execute([$landlordUserId, 'Robert Mulwa', 'landlord@example.com', '+254712345678', 'landlord']);

    $landlordId = generateUUID();
    $pdo->prepare("INSERT INTO landlords (id, user_id, full_name, email, phone) VALUES (?, ?, ?, ?, ?)")
        ->execute([$landlordId, $landlordUserId, 'Robert Mulwa', 'landlord@example.com', '+254712345678']);

    // 4. Create Properties
    $propId1 = generateUUID();
    $pdo->prepare("INSERT INTO properties (id, landlord_id, title, location, description, price, property_type, status, images, amenities) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$propId1, $landlordId, 'Elysian Heights Luxury Villa', 'Golden District', 'Premium modern villa', 45000, 'Villa', 'Available', json_encode(['https://images.unsplash.com/photo-1613490493576-7fde63acd811?q=80&w=800']), json_encode(['Pool', 'Gym'])]);

    $propId2 = generateUUID();
    $pdo->prepare("INSERT INTO properties (id, landlord_id, title, location, description, price, property_type, status, images, amenities) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$propId2, $landlordId, 'Skyline Business Hub', 'Financial District', 'Modern office space', 82000, 'Office', 'Available', json_encode(['https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=800']), json_encode(['Conference', 'Parking'])]);

    // 5. Create Tenants
    $tenantUserId = generateUUID();
    $tenantPass = password_hash('tenant123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, ?)")
        ->execute([$tenantUserId, 'tenant@example.com', $tenantPass, 'tenant']);
    
    $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role) VALUES (?, ?, ?, ?, ?)")
        ->execute([$tenantUserId, 'Johnathan Davis', 'tenant@example.com', '+254722334455', 'tenant']);

    $tenantId = generateUUID();
    $pdo->prepare("INSERT INTO tenants (id, user_id, full_name, email, phone, status) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$tenantId, $tenantUserId, 'Johnathan Davis', 'tenant@example.com', '+254722334455', 'Active']);

    // 6. Create Maintenance Request
    $pdo->prepare("INSERT INTO maintenance_requests (id, property_id, tenant_id, title, description, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([generateUUID(), $propId1, $tenantId, 'HVAC Malfunction', 'Master bedroom AC is not cooling.', 'High', 'In Progress']);

    // 7. Create Employees
    $pdo->prepare("INSERT INTO employees (id, full_name, email, phone, role, department, salary, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([generateUUID(), 'Kelvin Mwangi', 'k.mwangi@primelink.com', '+254700111222', 'Property Manager', 'Administration', 120000, 'Active']);

    echo "Seeding complete! Admin: admin@primelink.com / admin123\n";
}

seed($pdo);
?>
