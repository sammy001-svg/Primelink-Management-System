-- Primelink Management System - Supabase Schema (Idempotent)

-- 1. EXTENSIONS
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- 2. TABLES

-- Landlords
CREATE TABLE IF NOT EXISTS landlords (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    full_name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    phone TEXT,
    user_id UUID REFERENCES profiles(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Properties
CREATE TABLE IF NOT EXISTS properties (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    landlord_id UUID REFERENCES landlords(id) ON DELETE SET NULL,
    title TEXT NOT NULL,
    location TEXT NOT NULL,
    description TEXT,
    price NUMERIC(15, 2) NOT NULL DEFAULT 0,
    listing_type TEXT CHECK (listing_type IN ('Rent', 'Sale')) NOT NULL DEFAULT 'Rent',
    property_type TEXT CHECK (property_type IN ('Apartment', 'Villa', 'Single Room', 'Shop', 'Office', 'Land', 'Other')) DEFAULT 'Apartment',
    status TEXT CHECK (status IN ('Available', 'Under Maintenance', 'Inactive', 'Sold')) DEFAULT 'Available',
    area NUMERIC(10, 2),
    images TEXT[] DEFAULT '{}',
    amenities TEXT[] DEFAULT '{}',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Units
CREATE TABLE IF NOT EXISTS units (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    property_id UUID REFERENCES properties(id) ON DELETE CASCADE,
    unit_number TEXT NOT NULL,
    floor_number TEXT,
    unit_type TEXT NOT NULL, -- e.g., 'Studio', '1BR', '2BR'
    rent_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status TEXT CHECK (status IN ('Available', 'Occupied', 'Maintenance')) DEFAULT 'Available',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Tenants
CREATE TABLE IF NOT EXISTS tenants (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID REFERENCES profiles(id) ON DELETE SET NULL, -- Links to auth/profiles
    full_name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    phone TEXT,
    profile_image TEXT,
    status TEXT CHECK (status IN ('Active', 'Pending', 'Inactive')) DEFAULT 'Pending',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- MIGRATION: Ensure user_id exists if table was created previously
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='maintenance_requests' AND column_name='admin_notes') THEN
        ALTER TABLE maintenance_requests ADD COLUMN admin_notes TEXT;
    END IF;

    -- Add payment_method to transactions
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='transactions' AND column_name='payment_method') THEN
        ALTER TABLE transactions ADD COLUMN payment_method TEXT;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='tenants' AND column_name='user_id') THEN
        ALTER TABLE tenants ADD COLUMN user_id UUID REFERENCES profiles(id) ON DELETE SET NULL;
    END IF;

    -- Profiles updates
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='profiles' AND column_name='phone') THEN
        ALTER TABLE profiles ADD COLUMN phone TEXT;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='profiles' AND column_name='profile_image') THEN
        ALTER TABLE profiles ADD COLUMN profile_image TEXT;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='landlords' AND column_name='user_id') THEN
        ALTER TABLE landlords ADD COLUMN user_id UUID REFERENCES profiles(id) ON DELETE SET NULL;
    END IF;
END $$;

-- Leases
CREATE TABLE IF NOT EXISTS leases (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    unit_id UUID REFERENCES units(id) ON DELETE SET NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    monthly_rent NUMERIC(15, 2) NOT NULL,
    deposit_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    status TEXT CHECK (status IN ('Active', 'Expired', 'Terminated')) DEFAULT 'Active',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Maintenance Requests
CREATE TABLE IF NOT EXISTS maintenance_requests (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    property_id UUID REFERENCES properties(id) ON DELETE CASCADE,
    unit_id UUID REFERENCES units(id) ON DELETE SET NULL,
    tenant_id UUID REFERENCES tenants(id) ON DELETE SET NULL,
    title TEXT NOT NULL,
    description TEXT,
    priority TEXT CHECK (priority IN ('Low', 'Medium', 'High', 'Urgent')) DEFAULT 'Medium',
    status TEXT CHECK (status IN ('Pending', 'In Progress', 'Completed')) DEFAULT 'Pending',
    assigned_staff_id UUID REFERENCES employees(id) ON DELETE SET NULL,
    admin_notes TEXT,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Transactions (Updated with invoice_id)
CREATE TABLE IF NOT EXISTS transactions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE SET NULL,
    lease_id UUID REFERENCES leases(id) ON DELETE SET NULL,
    invoice_id UUID, -- Link to specific invoice
    amount NUMERIC(15, 2) NOT NULL,
    transaction_type TEXT CHECK (transaction_type IN ('Rent', 'Deposit', 'Maintenance', 'Penalty', 'Water', 'Service Charge', 'Electricity Token', 'Water Token')) NOT NULL,
    status TEXT CHECK (status IN ('Paid', 'Pending', 'Failed')) DEFAULT 'Pending',
    payment_method TEXT,
    transaction_date DATE DEFAULT CURRENT_DATE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Landlord Payouts
CREATE TABLE IF NOT EXISTS landlord_payouts (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    landlord_id UUID REFERENCES landlords(id) ON DELETE CASCADE,
    amount NUMERIC(15, 2) NOT NULL,
    payout_date DATE NOT NULL DEFAULT CURRENT_DATE,
    status TEXT CHECK (status IN ('Pending', 'Completed', 'Failed')) DEFAULT 'Pending',
    reference_no TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    property_id UUID REFERENCES properties(id) ON DELETE SET NULL,
    description TEXT NOT NULL,
    amount NUMERIC(15, 2) NOT NULL,
    category TEXT CHECK (category IN ('Maintenance', 'Utilities', 'Salaries', 'Taxes', 'Marketing', 'Legal', 'Other')) DEFAULT 'Other',
    expense_date DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Landlord Advances
CREATE TABLE IF NOT EXISTS landlord_advances (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    landlord_id UUID REFERENCES landlords(id) ON DELETE CASCADE,
    amount NUMERIC(15, 2) NOT NULL,
    purpose TEXT,
    status TEXT CHECK (status IN ('Pending', 'Approved', 'Rejected', 'Deducted')) DEFAULT 'Pending',
    requested_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP WITH TIME ZONE,
    is_deducted BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
);

-- Landlord Loans
CREATE TABLE IF NOT EXISTS landlord_loans (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    landlord_id UUID REFERENCES landlords(id) ON DELETE CASCADE,
    principal_amount NUMERIC(15, 2) NOT NULL,
    interest_rate NUMERIC(5, 2) DEFAULT 0,
    total_repayable NUMERIC(15, 2) NOT NULL,
    balance_remaining NUMERIC(15, 2),
    status TEXT CHECK (status IN ('Active', 'Cleared', 'Defaulted')) DEFAULT 'Active',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Invoices
CREATE TABLE IF NOT EXISTS invoices (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    lease_id UUID REFERENCES leases(id) ON DELETE CASCADE,
    amount NUMERIC(15, 2) NOT NULL,
    due_date DATE NOT NULL,
    status TEXT CHECK (status IN ('Unpaid', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled')) DEFAULT 'Unpaid',
    invoice_type TEXT DEFAULT 'Rent',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- HR Employees
CREATE TABLE IF NOT EXISTS employees (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    full_name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    phone TEXT,
    role TEXT NOT NULL,
    department TEXT,
    salary NUMERIC(15, 2),
    status TEXT CHECK (status IN ('Active', 'On Leave', 'Terminated')) DEFAULT 'Active',
    hire_date DATE DEFAULT CURRENT_DATE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- HR Leaves
CREATE TABLE IF NOT EXISTS employee_leaves (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    employee_id UUID REFERENCES employees(id) ON DELETE CASCADE,
    leave_type TEXT NOT NULL, -- e.g., 'Sick', 'Annual', 'Maternity'
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status TEXT CHECK (status IN ('Pending', 'Approved', 'Rejected')) DEFAULT 'Pending',
    reason TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Documents
CREATE TABLE IF NOT EXISTS documents (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    category TEXT CHECK (category IN ('Lease', 'ID', 'Termination', 'Other')) NOT NULL,
    file_url TEXT NOT NULL,
    file_size TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Profiles (Syncs with auth.users)
CREATE TABLE IF NOT EXISTS profiles (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    full_name TEXT,
    email TEXT UNIQUE NOT NULL,
    phone TEXT,
    profile_image TEXT,
    role TEXT CHECK (role IN ('tenant', 'landlord', 'staff', 'admin', 'utility')) DEFAULT 'tenant',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. SECURITY (Row Level Security)

ALTER TABLE landlords ENABLE ROW LEVEL SECURITY;
ALTER TABLE properties ENABLE ROW LEVEL SECURITY;
ALTER TABLE units ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenants ENABLE ROW LEVEL SECURITY;
ALTER TABLE leases ENABLE ROW LEVEL SECURITY;
ALTER TABLE maintenance_requests ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS "Tenants can view own requests" ON maintenance_requests;
CREATE POLICY "Tenants can view own requests" ON maintenance_requests
    FOR SELECT USING (tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid()));

DROP POLICY IF EXISTS "Tenants can create requests" ON maintenance_requests;
CREATE POLICY "Tenants can create requests" ON maintenance_requests
    FOR INSERT WITH CHECK (tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid()));

DROP POLICY IF EXISTS "Admins can view all requests" ON maintenance_requests;
CREATE POLICY "Admins can view all requests" ON maintenance_requests
    FOR ALL USING (EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND role = 'staff'));

-- Documents
ALTER TABLE documents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS "Tenants can view own docs" ON documents;
CREATE POLICY "Tenants can view own docs" ON documents
    FOR SELECT USING (tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid()));

DROP POLICY IF EXISTS "Admins can manage all docs" ON documents;
CREATE POLICY "Admins can manage all docs" ON documents
    FOR ALL USING (EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND role = 'staff'));

ALTER TABLE transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE employees ENABLE ROW LEVEL SECURITY;
ALTER TABLE employee_leaves ENABLE ROW LEVEL SECURITY;
ALTER TABLE employee_leaves ENABLE ROW LEVEL SECURITY;
ALTER TABLE profiles ENABLE ROW LEVEL SECURITY;

-- Helper functions for RLS to avoid recursion
CREATE OR REPLACE FUNCTION public.check_is_staff()
RETURNS BOOLEAN AS $$
BEGIN
    RETURN (EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND role = 'staff'));
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION public.check_is_tenant()
RETURNS BOOLEAN AS $$
BEGIN
    RETURN (EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND role = 'tenant'));
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION public.check_is_landlord()
RETURNS BOOLEAN AS $$
BEGIN
    RETURN (EXISTS (SELECT 1 FROM public.profiles WHERE id = auth.uid() AND role = 'landlord'));
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Helper function to drop policy if exists (added to script for idempotency)
CREATE OR REPLACE FUNCTION drop_policy_if_exists(p_name TEXT, p_table TEXT)
RETURNS VOID AS $$
BEGIN
    EXECUTE 'DROP POLICY IF EXISTS ' || quote_ident(p_name) || ' ON ' || quote_ident(p_table);
END;
$$ LANGUAGE plpgsql;

-- POLICIES (Idempotent)
DO $$
BEGIN
    -- Profiles
    PERFORM drop_policy_if_exists('Users can view their own profile', 'profiles');
    CREATE POLICY "Users can view their own profile" ON profiles FOR SELECT USING (auth.uid() = id);
    
    PERFORM drop_policy_if_exists('Users can update their own profile', 'profiles');
    CREATE POLICY "Users can update their own profile" ON profiles FOR UPDATE USING (auth.uid() = id);
    
    PERFORM drop_policy_if_exists('Staff can view all profiles', 'profiles');
    CREATE POLICY "Staff can view all profiles" ON profiles FOR SELECT USING (public.check_is_staff());

    -- Tenants
    PERFORM drop_policy_if_exists('Tenants can view their own record', 'tenants');
    CREATE POLICY "Tenants can view their own record" ON tenants FOR SELECT USING (user_id = auth.uid());
    
    PERFORM drop_policy_if_exists('Staff can view all tenants', 'tenants');
    CREATE POLICY "Staff can view all tenants" ON tenants FOR ALL USING (public.check_is_staff());

    -- Leases
    PERFORM drop_policy_if_exists('Tenants can view their own leases', 'leases');
    CREATE POLICY "Tenants can view their own leases" ON leases FOR SELECT USING (tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid()));
    
    PERFORM drop_policy_if_exists('Staff can manage all leases', 'leases');
    CREATE POLICY "Staff can manage all leases" ON leases FOR ALL USING (public.check_is_staff());

    -- Units (Tenants see only the unit they rent)
    PERFORM drop_policy_if_exists('Tenants can view their rented unit', 'units');
    CREATE POLICY "Tenants can view their rented unit" ON units FOR SELECT USING (id IN (SELECT unit_id FROM leases WHERE tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid())));
    
    PERFORM drop_policy_if_exists('Staff can manage all units', 'units');
    CREATE POLICY "Staff can manage all units" ON units FOR ALL USING (public.check_is_staff());

    -- Transactions (Receipts/Invoices)
    PERFORM drop_policy_if_exists('Tenants can view their own transactions', 'transactions');
    CREATE POLICY "Tenants can view their own transactions" ON transactions FOR SELECT USING (tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid()));
    
    PERFORM drop_policy_if_exists('Staff can manage all transactions', 'transactions');
    CREATE POLICY "Staff can manage all transactions" ON transactions FOR ALL USING (public.check_is_staff());

    -- Maintenance Requests
    PERFORM drop_policy_if_exists('Tenants can view/create their own requests', 'maintenance_requests');
    CREATE POLICY "Tenants can view/create their own requests" ON maintenance_requests FOR ALL USING (tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid()));
    
    PERFORM drop_policy_if_exists('Staff can manage all requests', 'maintenance_requests');
    CREATE POLICY "Staff can manage all requests" ON maintenance_requests FOR ALL USING (public.check_is_staff());

    -- Employees
    PERFORM drop_policy_if_exists('Staff can manage all employees', 'employees');
    CREATE POLICY "Staff can manage all employees" ON employees FOR ALL USING (public.check_is_staff());
    
    PERFORM drop_policy_if_exists('Tenants can view assigned employees', 'employees');
    CREATE POLICY "Tenants can view assigned employees" ON employees FOR SELECT USING (
        id IN (SELECT assigned_staff_id FROM maintenance_requests WHERE tenant_id IN (SELECT id FROM tenants WHERE user_id = auth.uid()))
    );

    -- Leaves
    PERFORM drop_policy_if_exists('Staff can manage all leaves', 'employee_leaves');
    CREATE POLICY "Staff can manage all leaves" ON employee_leaves FOR ALL USING (public.check_is_staff());

    -- LANDLORD POLICIES
    -- Properties
    PERFORM drop_policy_if_exists('Landlords can view their properties', 'properties');
    CREATE POLICY "Landlords can view their properties" ON properties 
      FOR SELECT USING (landlord_id IN (SELECT id FROM landlords WHERE user_id = auth.uid()));

    -- Units
    PERFORM drop_policy_if_exists('Landlords can view their units', 'units');
    CREATE POLICY "Landlords can view their units" ON units 
      FOR SELECT USING (property_id IN (SELECT id FROM properties WHERE landlord_id IN (SELECT id FROM landlords WHERE user_id = auth.uid())));

    -- Leases
    PERFORM drop_policy_if_exists('Landlords can view their leases', 'leases');
    CREATE POLICY "Landlords can view their leases" ON leases 
      FOR SELECT USING (unit_id IN (SELECT id FROM units WHERE property_id IN (SELECT id FROM properties WHERE landlord_id IN (SELECT id FROM landlords WHERE user_id = auth.uid()))));

    -- Transactions
    PERFORM drop_policy_if_exists('Landlords can view their revenue', 'transactions');
    CREATE POLICY "Landlords can view their revenue" ON transactions 
      FOR SELECT USING (lease_id IN (SELECT id FROM leases WHERE unit_id IN (SELECT id FROM units WHERE property_id IN (SELECT id FROM properties WHERE landlord_id IN (SELECT id FROM landlords WHERE user_id = auth.uid())))));

    -- Maintenance
    PERFORM drop_policy_if_exists('Landlords can view maintenance for their properties', 'maintenance_requests');
    CREATE POLICY "Landlords can view maintenance for their properties" ON maintenance_requests 
      FOR SELECT USING (property_id IN (SELECT id FROM properties WHERE landlord_id IN (SELECT id FROM landlords WHERE user_id = auth.uid())));
END $$;

-- 4. AUTH SYNC TRIGGER
-- Updated to include user_id in the tenants table if the person is a tenant
CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER AS $$
DECLARE
    u_role TEXT;
BEGIN
    u_role := COALESCE(new.raw_user_meta_data->>'role', 'tenant');

    -- Create profile
    INSERT INTO public.profiles (id, full_name, email, role, phone)
    VALUES (
        new.id, 
        new.raw_user_meta_data->>'full_name', 
        new.email,
        u_role,
        new.raw_user_meta_data->>'phone'
    );

    -- If role is tenant, also create a record in the tenants table linked to this user
    IF (u_role = 'tenant') THEN
        INSERT INTO public.tenants (user_id, full_name, email, status)
        VALUES (new.id, new.raw_user_meta_data->>'full_name', new.email, 'Active');
    ELSIF (u_role = 'landlord') THEN
        INSERT INTO public.landlords (user_id, full_name, email)
        VALUES (new.id, new.raw_user_meta_data->>'full_name', new.email);
    END IF;

    RETURN new;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Drop trigger if exists before creating
DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;

CREATE TRIGGER on_auth_user_created
    AFTER INSERT ON auth.users
    FOR EACH ROW EXECUTE FUNCTION public.handle_new_user();
