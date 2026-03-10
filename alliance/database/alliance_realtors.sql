-- Create database
CREATE DATABASE IF NOT EXISTS alliance_realtors;
USE alliance_realtors;

-- Roles table
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_name VARCHAR(50) NOT NULL UNIQUE
);

INSERT INTO roles (role_name) VALUES ('Admin'), ('Agent'), ('Tenant');

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Insert default users (password = 'password123')
INSERT INTO users (username, full_name, email, phone, password, role_id) VALUES
('admin', 'System Admin', 'admin@alliance.com', '0700000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1),
('agent', 'John Agent', 'agent@alliance.com', '0711111111', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2),
('tenant', 'Jane Tenant', 'tenant@alliance.com', '0722222222', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3);

-- Properties table
CREATE TABLE properties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    location VARCHAR(100),
    price DECIMAL(12,2),
    bedrooms INT DEFAULT 1,
    bathrooms INT DEFAULT 1,
    image VARCHAR(255) DEFAULT 'default.jpg',
    status ENUM('Available', 'Rented', 'Maintenance') DEFAULT 'Available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO properties (title, description, location, price, bedrooms, bathrooms, status) VALUES
('Sunset Apartment', 'Beautiful 2 bedroom apartment with balcony', 'Westlands', 45000, 2, 2, 'Available'),
('Green Villa', 'Spacious 3 bedroom house with garden', 'Kilimani', 65000, 3, 3, 'Available'),
('CBD Office', 'Modern office space in city center', 'Nairobi CBD', 85000, 0, 2, 'Available');

-- Tenants table
CREATE TABLE tenants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    phone VARCHAR(20),
    property_id INT,
    move_in_date DATE,
    status VARCHAR(20) DEFAULT 'Active',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL
);

INSERT INTO tenants (user_id, full_name, email, phone, property_id, move_in_date, status) VALUES
(3, 'Jane Tenant', 'tenant@alliance.com', '0722222222', 2, '2026-01-01', 'Active');

-- Payments table
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    method ENUM('M-Pesa', 'Cash', 'Bank Transfer') DEFAULT 'M-Pesa',
    transaction_code VARCHAR(100),
    status ENUM('Completed', 'Pending', 'Failed') DEFAULT 'Completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

INSERT INTO payments (tenant_id, amount, payment_date, method, transaction_code, status) VALUES
(1, 35000, '2026-02-05', 'M-Pesa', 'MPESA123', 'Completed'),
(1, 35000, '2026-03-05', 'M-Pesa', 'MPESA456', 'Completed');

-- Maintenance requests table
CREATE TABLE maintenance_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Medium',
    status ENUM('Pending', 'In Progress', 'Completed') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

INSERT INTO maintenance_requests (tenant_id, property_id, title, description, priority, status) VALUES
(1, 2, 'Leaking Faucet', 'Kitchen sink is leaking', 'High', 'Pending');