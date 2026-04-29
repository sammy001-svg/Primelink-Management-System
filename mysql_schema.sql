-- Primelink Management System - MySQL Schema

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- 1. TABLES

-- Users (Replacing Supabase Auth)
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('tenant', 'landlord', 'staff', 'admin', 'utility') DEFAULT 'tenant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Profiles
CREATE TABLE IF NOT EXISTS profiles (
    id VARCHAR(36) PRIMARY KEY,
    full_name VARCHAR(255),
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    profile_image TEXT,
    address TEXT, -- Physical address for all roles
    role ENUM('tenant', 'landlord', 'staff', 'admin', 'utility') DEFAULT 'tenant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Landlords
CREATE TABLE IF NOT EXISTS landlords (
    id VARCHAR(36) PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    user_id VARCHAR(36),
    payout_details JSON, -- Store bank info etc
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Properties
CREATE TABLE IF NOT EXISTS properties (
    id VARCHAR(36) PRIMARY KEY,
    landlord_id VARCHAR(36),
    title VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(15, 2) NOT NULL DEFAULT 0,
    listing_type ENUM('Rent', 'Sale') NOT NULL DEFAULT 'Rent',
    property_type ENUM('Apartment', 'Villa', 'Single Room', 'Shop', 'Office', 'Land', 'Other') DEFAULT 'Apartment',
    status ENUM('Available', 'Under Maintenance', 'Inactive', 'Sold') DEFAULT 'Available',
    area DECIMAL(10, 2),
    property_code VARCHAR(50) NULL,
    images JSON,
    amenities JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Units
CREATE TABLE IF NOT EXISTS units (
    id VARCHAR(36) PRIMARY KEY,
    property_id VARCHAR(36),
    unit_number VARCHAR(50) NOT NULL,
    floor_number VARCHAR(50),
    unit_type VARCHAR(100) NOT NULL,
    monthly_rent DECIMAL(15, 2) NOT NULL DEFAULT 0,
    deposit_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    status ENUM('Available', 'Occupied', 'Maintenance') DEFAULT 'Available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tenants (Expanded with digital lease & spouse info)
CREATE TABLE IF NOT EXISTS tenants (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    profile_image TEXT,
    status ENUM('Active', 'Pending', 'Inactive') DEFAULT 'Pending',
    terms_accepted_at TIMESTAMP NULL,
    signature_name VARCHAR(255),
    spouse_name VARCHAR(255),
    id_no VARCHAR(100),
    spouse_id_no VARCHAR(100),
    id_copy_url TEXT,
    spouse_id_copy_url TEXT,
    spouse_phone VARCHAR(50),
    marital_status VARCHAR(50),
    has_kids TINYINT(1) DEFAULT 0,
    current_address TEXT,
    spouse_email VARCHAR(255),
    alt_contact VARCHAR(255),
    spouse_alt_contact VARCHAR(255),
    profession VARCHAR(255),
    spouse_profession VARCHAR(255),
    employer_name VARCHAR(255),
    spouse_employer_name VARCHAR(255),
    occupation_type VARCHAR(100),
    business_name VARCHAR(255),
    business_nature VARCHAR(255),
    business_location VARCHAR(255),
    next_of_kin_name VARCHAR(255),
    next_of_kin_contact VARCHAR(255),
    next_of_kin_relationship VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HR Employees
CREATE TABLE IF NOT EXISTS employees (
    id VARCHAR(36) PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    role VARCHAR(100) NOT NULL,
    department VARCHAR(100),
    salary DECIMAL(15, 2),
    status ENUM('Active', 'On Leave', 'Terminated') DEFAULT 'Active',
    hire_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leases (updated with property_id, deposit, terms)
CREATE TABLE IF NOT EXISTS leases (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(36),
    property_id VARCHAR(36),
    unit_id VARCHAR(36),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    monthly_rent DECIMAL(15, 2) NOT NULL,
    deposit_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    terms TEXT,
    status ENUM('Active', 'Expired', 'Terminated') DEFAULT 'Active',
    signed_lease_url VARCHAR(255) NULL,
    termination_date DATE NULL,
    termination_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36),
    title VARCHAR(255) NOT NULL,
    message TEXT,
    type ENUM('info', 'success', 'warning', 'alert') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maintenance Requests
CREATE TABLE IF NOT EXISTS maintenance_requests (
    id VARCHAR(36) PRIMARY KEY,
    property_id VARCHAR(36),
    unit_id VARCHAR(36),
    tenant_id VARCHAR(36),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Medium',
    status ENUM('Pending', 'In Progress', 'Completed') DEFAULT 'Pending',
    assigned_staff_id VARCHAR(36),
    admin_notes TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_staff_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 176. Transactions (Updated with invoice_id)
CREATE TABLE IF NOT EXISTS transactions (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(36),
    lease_id VARCHAR(36),
    invoice_id VARCHAR(36), -- Link to specific invoice
    amount DECIMAL(15, 2) NOT NULL,
    transaction_type ENUM('Rent', 'Deposit', 'Maintenance', 'Penalty', 'Water', 'Service Charge', 'Electricity Token', 'Water Token') NOT NULL,
    status ENUM('Paid', 'Pending', 'Failed', 'Overdue') DEFAULT 'Pending',
    payment_method VARCHAR(100),
    description TEXT,
    transaction_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 191. Landlord Payouts
CREATE TABLE IF NOT EXISTS landlord_payouts (
    id VARCHAR(36) PRIMARY KEY,
    landlord_id VARCHAR(36),
    amount DECIMAL(15, 2) NOT NULL,
    payout_date DATE NOT NULL,
    status ENUM('Pending', 'Completed', 'Failed') DEFAULT 'Pending',
    reference_no VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 202. Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id VARCHAR(36) PRIMARY KEY,
    property_id VARCHAR(36),
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    category ENUM('Maintenance', 'Utilities', 'Salaries', 'Taxes', 'Marketing', 'Legal', 'Other') DEFAULT 'Other',
    expense_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 214. Landlord Advances
CREATE TABLE IF NOT EXISTS landlord_advances (
    id VARCHAR(36) PRIMARY KEY,
    landlord_id VARCHAR(36),
    amount DECIMAL(15, 2) NOT NULL,
    purpose TEXT,
    status ENUM('Pending', 'Approved', 'Rejected', 'Deducted') DEFAULT 'Pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    is_deducted TINYINT(1) DEFAULT 0,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 227. Landlord Loans
CREATE TABLE IF NOT EXISTS landlord_loans (
    id VARCHAR(36) PRIMARY KEY,
    landlord_id VARCHAR(36),
    principal_amount DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) DEFAULT 0,
    total_repayable DECIMAL(15, 2) NOT NULL,
    balance_remaining DECIMAL(15, 2),
    status ENUM('Active', 'Cleared', 'Defaulted') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 240. Invoices
CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(36),
    lease_id VARCHAR(36),
    amount DECIMAL(15, 2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('Unpaid', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled') DEFAULT 'Unpaid',
    invoice_type VARCHAR(100) DEFAULT 'Rent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Utility Tokens
CREATE TABLE IF NOT EXISTS tokens (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(36),
    property_id VARCHAR(36),
    unit_id VARCHAR(36),
    token_type ENUM('Electricity', 'Water') NOT NULL,
    token_code VARCHAR(100) UNIQUE NOT NULL,
    units_value DECIMAL(15,2) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status ENUM('Active', 'Used') DEFAULT 'Active',
    created_by VARCHAR(36),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HR Leaves
CREATE TABLE IF NOT EXISTS employee_leaves (
    id VARCHAR(36) PRIMARY KEY,
    employee_id VARCHAR(36),
    leave_type VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Documents
CREATE TABLE IF NOT EXISTS documents (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(36),
    title VARCHAR(255) NOT NULL,
    category ENUM('Lease', 'ID', 'Termination', 'Other') NOT NULL,
    file_url TEXT NOT NULL,
    file_size VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- BUSINESS MODULES (Sales, Inventory, Procurement)
-- --------------------------------------------------------

-- Warehouses
CREATE TABLE IF NOT EXISTS warehouses (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    location TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product Categories
CREATE TABLE IF NOT EXISTS categories (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Products
CREATE TABLE IF NOT EXISTS products (
    id VARCHAR(36) PRIMARY KEY,
    category_id VARCHAR(36),
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) UNIQUE,
    description TEXT,
    purchase_price DECIMAL(15, 2) DEFAULT 0,
    sale_price DECIMAL(15, 2) DEFAULT 0,
    unit_of_measure VARCHAR(50) DEFAULT 'pc',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory Stock
CREATE TABLE IF NOT EXISTS inventory_stocks (
    id VARCHAR(36) PRIMARY KEY,
    product_id VARCHAR(36),
    warehouse_id VARCHAR(36),
    quantity DECIMAL(15, 2) NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    UNIQUE KEY (product_id, warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales
CREATE TABLE IF NOT EXISTS sales (
    id VARCHAR(36) PRIMARY KEY,
    customer_name VARCHAR(255),
    total_amount DECIMAL(15, 2) NOT NULL,
    status ENUM('Pending', 'Paid', 'Cancelled') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Procurements
CREATE TABLE IF NOT EXISTS procurements (
    id VARCHAR(36) PRIMARY KEY,
    supplier_name VARCHAR(255),
    total_amount DECIMAL(15, 2) NOT NULL,
    status ENUM('Requested', 'Received', 'Cancelled') DEFAULT 'Requested',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ledger Entries (Unified Accounting)
CREATE TABLE IF NOT EXISTS ledger_entries (
    id VARCHAR(36) PRIMARY KEY,
    transaction_type ENUM('Sale', 'Procurement', 'Rent', 'Expense', 'Payout') NOT NULL,
    reference_id VARCHAR(36), -- Link to sale_id, procurement_id, etc.
    debit DECIMAL(15, 2) DEFAULT 0,
    credit DECIMAL(15, 2) DEFAULT 0,
    description TEXT,
    entry_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
