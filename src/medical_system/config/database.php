<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'medical_system');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if ($conn->query($sql) === FALSE) {
    die("Error creating database: " . $conn->error);
}

// Select the database
$conn->select_db(DB_NAME);

// Create users table (UPDATED with profile_photo column)
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    profile_photo VARCHAR(255) DEFAULT NULL,
    role ENUM('patient', 'doctor', 'admin') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating users table: " . $conn->error);
}

// Add profile_photo column if it doesn't exist (for existing installations)
$checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
if ($checkColumn->num_rows == 0) {
    $alterSql = "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER phone";
    if ($conn->query($alterSql) === FALSE) {
        die("Error adding profile_photo column: " . $conn->error);
    }
}

// Create patients table
$sql = "CREATE TABLE IF NOT EXISTS patients (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    dob DATE,
    gender ENUM('male', 'female', 'other'),
    address TEXT,
    medical_history TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating patients table: " . $conn->error);
}

// Create doctors table
$sql = "CREATE TABLE IF NOT EXISTS doctors (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    specialty VARCHAR(100),
    license_number VARCHAR(50),
    bio TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating doctors table: " . $conn->error);
}

// Create doctor_schedules table (NEW)
$sql = "CREATE TABLE IF NOT EXISTS doctor_schedules (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT(11) NOT NULL,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_duration INT(11) DEFAULT 15 COMMENT 'Duration in minutes',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_doctor_day (doctor_id, day_of_week)
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating doctor_schedules table: " . $conn->error);
}

// Create doctor_schedule_exceptions table (NEW) - for special dates when doctor is unavailable
$sql = "CREATE TABLE IF NOT EXISTS doctor_schedule_exceptions (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT(11) NOT NULL,
    exception_date DATE NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    reason VARCHAR(255),
    is_unavailable BOOLEAN DEFAULT TRUE COMMENT 'TRUE = unavailable, FALSE = custom hours',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
    UNIQUE KEY unique_doctor_exception (doctor_id, exception_date)
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating doctor_schedule_exceptions table: " . $conn->error);
}

// Update appointments table to include more precise time slots
$sql = "CREATE TABLE IF NOT EXISTS appointments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    patient_id INT(11) NOT NULL,
    doctor_id INT(11) NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    duration INT(11) DEFAULT 15 COMMENT 'Duration in minutes',
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
    reason TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating appointments table: " . $conn->error);
}

// Add duration column to appointments if it doesn't exist
$checkColumn = $conn->query("SHOW COLUMNS FROM appointments LIKE 'duration'");
if ($checkColumn->num_rows == 0) {
    $alterSql = "ALTER TABLE appointments ADD COLUMN duration INT(11) DEFAULT 15 COMMENT 'Duration in minutes' AFTER appointment_time";
    if ($conn->query($alterSql) === FALSE) {
        die("Error adding duration column: " . $conn->error);
    }
}

// Add unique constraint for doctor_datetime if it doesn't exist
$checkIndex = $conn->query("SHOW INDEX FROM appointments WHERE Key_name = 'unique_doctor_datetime'");
if ($checkIndex->num_rows == 0) {
    $alterSql = "ALTER TABLE appointments ADD UNIQUE KEY unique_doctor_datetime (doctor_id, appointment_date, appointment_time)";
    if ($conn->query($alterSql) === FALSE) {
        // If the constraint fails due to existing duplicate data, we'll handle it gracefully
        echo "Note: Could not add unique constraint for appointments. This might be due to existing duplicate appointments.";
    }
}

// Create password_reset_codes table
$sql = "CREATE TABLE IF NOT EXISTS password_reset_codes (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_code (email, code),
    INDEX idx_expires_at (expires_at)
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating password_reset_codes table: " . $conn->error);
}

// Create medical_records table
$sql = "CREATE TABLE IF NOT EXISTS medical_records (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    patient_id INT(11) NOT NULL,
    doctor_id INT(11) NOT NULL,
    record_date DATE NOT NULL,
    diagnosis TEXT,
    treatment TEXT,
    prescription TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating medical_records table: " . $conn->error);
}

// Create prescriptions table
$sql = "CREATE TABLE IF NOT EXISTS prescriptions (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    patient_id INT(11) NOT NULL,
    doctor_id INT(11) NOT NULL,
    prescription_date DATE NOT NULL,
    medication TEXT NOT NULL,
    dosage TEXT NOT NULL,
    instructions TEXT,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
)";

if ($conn->query($sql) === FALSE) {
    die("Error creating prescriptions table: " . $conn->error);
}

// Insert default admin user if not exists
$admin_email = 'admin@medpatient.com';
$admin_password = password_hash('admin123', PASSWORD_DEFAULT);

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $admin_email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    $admin_name = 'System Administrator';
    $admin_role = 'admin';
    $stmt->bind_param("ssss", $admin_name, $admin_email, $admin_password, $admin_role);
    $stmt->execute();
}

$stmt->close();
$conn->close();

// Function to get database connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}


?>