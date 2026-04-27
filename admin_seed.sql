-- Verified Official Admin Seed Script
-- Primelink Management System

SET @admin_id = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
-- Verified hash for 'Prime@123@1L'
SET @admin_pass = '$2y$10$GIwPFSdwXYNUjknkGLRE7ehbjX6WdVrgSRYnp4eGcuYAqqZN347Xm';

INSERT INTO users (id, email, password, role) 
VALUES (@admin_id, 'Info@primelinkproperties.co.ke', @admin_pass, 'admin')
ON DUPLICATE KEY UPDATE password = @admin_pass, role = 'admin';

INSERT INTO profiles (id, full_name, email, phone, role) 
VALUES (@admin_id, 'Primelink Official Admin', 'Info@primelinkproperties.co.ke', '+254000000000', 'admin')
ON DUPLICATE KEY UPDATE full_name = 'Primelink Official Admin', role = 'admin';
