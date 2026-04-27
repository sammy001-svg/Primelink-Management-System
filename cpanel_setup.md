# cPanel Deployment Guide

This guide explains how to deploy the converted Primelink Management System (PHP/MySQL) to your cPanel hosting.

## 1. Database Setup

1. Log in to your cPanel.
2. Go to **MySQL® Databases**.
3. Create a new database named `primelink_db` (or any preferred name).
4. Create a new database user and assign a password.
5. Add the user to the database with **All Privileges**.
6. Go to **phpMyAdmin** and select your new database.
7. Click the **Import** tab and upload the `mysql_schema.sql` file provided in the project root.

## 2. File Upload

1. Open **File Manager** in cPanel.
2. Navigate to `public_html` (or your subdomain folder).
3. Upload all files from the `php/` directory to this folder.
   - _Note: Ensure the `config`, `includes`, `css` folders are uploaded correctly._

## 3. Configuration

1. In File Manager, find the `.env` file you just uploaded.
2. Edit the file and update the database credentials to match the ones you created in Step 1:
   ```env
   DB_HOST=localhost
   DB_NAME=your_db_name
   DB_USER=your_db_user
   DB_PASS=your_db_password
   ```

## 4. Initial Data (Optional)

1. To seed the database with initial test data (Admin, Landlord, Tenant), visit:
   `https://yourdomain.com/seed.php`
2. **IMPORTANT**: Delete `seed.php` immediately after running it for security.

## 5. Login Credentials

If you ran the seeder, use these credentials to test:

- **Admin**: `admin@primelink.com` / `admin123`
- **Landlord**: `landlord@example.com` / `landlord123`
- **Tenant**: `tenant@example.com` / `tenant123`

## 6. Security Reminders

- Ensure your PHP version is **7.4 or higher** (8.1/8.2 recommended).
- Set standard directory permissions (755 for folders, 644 for files).
- The `.env` file should be protected; cPanel usually handles this, but you can add a `.htaccess` rule if needed.
