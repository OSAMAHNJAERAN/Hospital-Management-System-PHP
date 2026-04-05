<?php
// Include database configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

$userType = '';
$error = '';
$success = '';

// Process registration form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = sanitizeInput($_POST['name']);
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    $phone = sanitizeInput($_POST['phone']);
    $role = sanitizeInput($_POST['role']);
    
    // Validate input
    if (empty($name) || empty($email) || empty($password) || empty($confirmPassword)) {
        $error = "All fields are required";
    } elseif (!isValidEmail($email)) {
        $error = "Invalid email format";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long";
    } elseif (emailExists($email)) {
        $error = "Email already exists";
    } else {
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert user
        $conn = getConnection();
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $email, $hashedPassword, $phone, $role);
        
        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            
            // Insert additional data based on role
            if ($role == 'patient') {
                $dob = isset($_POST['dob']) ? $_POST['dob'] : NULL;
                $gender = isset($_POST['gender']) ? sanitizeInput($_POST['gender']) : NULL;
                
                $stmt = $conn->prepare("INSERT INTO patients (user_id, dob, gender) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $userId, $dob, $gender);
                $stmt->execute();
            } elseif ($role == 'doctor') {
                $specialty = isset($_POST['specialty']) ? sanitizeInput($_POST['specialty']) : NULL;
                $licenseNumber = isset($_POST['license_number']) ? sanitizeInput($_POST['license_number']) : NULL;
                
                $stmt = $conn->prepare("INSERT INTO doctors (user_id, specialty, license_number) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $userId, $specialty, $licenseNumber);
                $stmt->execute();
            }
            
            // Start session and log the user in automatically
            session_start();
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_role'] = $role;
            
            // Redirect to appropriate dashboard
            if ($role == 'patient') {
                header("Location: patient_dashboard.php");
                exit();
            } elseif ($role == 'doctor') {
                header("Location: doctor_dashboard.php");
                exit();
            }
            
        } else {
            $error = "Registration failed. Please try again.";
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Set user type if provided in URL
if (isset($_GET['type']) && in_array($_GET['type'], ['patient', 'doctor'])) {
    $userType = $_GET['type'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - MedPatient Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">
            <i class="fas fa-hospital-alt"></i>
            <span>MedPatient</span>
        </div>
        <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="login.php">Login</a>
            <a href="register.php" class="btn-login active">Register</a>
        </div>
    </nav>
    
    <?php if(empty($userType)): ?>
    <!-- Register Type Selection Page -->
    <div class="page-container active">
        <div class="auth-container">
            <div class="auth-header">
                <h1>Create Account</h1>
                <p>Select your account type to get started</p>
            </div>
            
            <?php if(isset($error) && !empty($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="user-type-selection">
                <a href="register.php?type=patient" class="user-type-card">
                    <div class="user-type-icon">
                        <i class="fas fa-user-injured"></i>
                    </div>
                    <h3>Patient</h3>
                    <p>I want to manage my health records and book appointments</p>
                </a>
                
                <a href="register.php?type=doctor" class="user-type-card">
                    <div class="user-type-icon">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <h3>Doctor</h3>
                    <p>I'm a healthcare provider and want to manage my practice</p>
                </a>
            </div>
            
            <div class="form-footer">
                <p>Already have an account? <a href="login.php">Login here</a></p>
            </div>
        </div>
    </div>
    <?php elseif($userType == 'patient'): ?>
    <!-- Patient Registration Page -->
    <div class="page-container active">
        <div class="auth-container">
            <div class="auth-header">
                <h1>Patient Registration</h1>
                <p>Create your patient account</p>
            </div>
            
            <?php if(isset($error) && !empty($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form id="registerPatientForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="role" value="patient">
                
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control" placeholder="Enter your full name" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="Enter your phone number" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
                </div>
                
                <div class="form-group">
                    <label for="dob">Date of Birth</label>
                    <input type="date" id="dob" name="dob" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender" class="form-control" required>
                        <option value="">Select Gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Register</button>
                
                <div class="form-footer">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </form>
        </div>
    </div>
    <?php elseif($userType == 'doctor'): ?>
    <!-- Doctor Registration Page -->
    <div class="page-container active">
        <div class="auth-container">
            <div class="auth-header">
                <h1>Doctor Registration</h1>
                <p>Create your healthcare provider account</p>
            </div>
            
            <?php if(isset($error) && !empty($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form id="registerDoctorForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <input type="hidden" name="role" value="doctor">
                
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control" placeholder="Enter your full name" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="Enter your phone number" required>
                </div>
                
                <div class="form-group">
                    <label for="specialty">Specialty</label>
                    <input type="text" id="specialty" name="specialty" class="form-control" placeholder="Enter your medical specialty" required>
                </div>
                
                <div class="form-group">
                    <label for="license_number">License Number</label>
                    <input type="text" id="license_number" name="license_number" class="form-control" placeholder="Enter your medical license number" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Register</button>
                
                <div class="form-footer">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="js/script.js"></script>
</body>
</html>