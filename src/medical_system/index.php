<?php
// Include database configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (isLoggedIn()) {
    // Redirect based on role
    switch ($_SESSION['user_role']) {
        case 'admin':
            redirect('admin_dashboard.php');
            break;
        case 'doctor':
            redirect('doctor_dashboard.php');
            break;
        case 'patient':
            redirect('patient_dashboard.php');
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedPatient Portal</title>
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
            <a href="index.php" class="active">Home</a>
            <a href="login.php">Login</a>
            <a href="register.php" class="btn-login">Register</a>
        </div>
    </nav>
    
    <!-- Home Page -->
    <div class="page-container active">
        <div class="hero">
            <div class="hero-content">
                <h1>Caring for you, Always</h1>
                <p>Access your medical records, book appointments, and manage your healthcare all in one place with our secure patient portal. Take control of your health journey today.</p>
                <div>
                    <a href="register.php" class="btn btn-primary">Get Started</a>
                    <a href="login.php" class="btn btn-outline">Login</a>
                </div>
            </div>
            <div class="hero-image">
                <img src="https://i.pinimg.com/474x/93/21/ca/9321ca4e33b13bf014f7b01e0b5f9026.jpg" alt="Healthcare professionals" width="10">
            </div>
        </div>
        
        <div class="features">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h3>Easy Appointment Booking</h3>
                <p>Schedule, reschedule or cancel appointments with your healthcare providers at your convenience.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-file-medical"></i>
                </div>
                <h3>Medical Records Access</h3>
                <p>View your medical history, test results, and treatment plans anytime, anywhere.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-prescription-bottle-alt"></i>
                </div>
                <h3>Prescription Management</h3>
                <p>Request prescription refills and track your medications all in one place.</p>
            </div>
        </div>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>
