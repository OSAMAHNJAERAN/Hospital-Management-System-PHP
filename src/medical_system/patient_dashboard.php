<?php
// Include database configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

// Check if user is a patient
if (!hasRole('patient')) {
    redirect('login.php');
}

// Get patient data
$userId = $_SESSION['user_id'];
$user = getUserById($userId);
$patient = getPatientByUserId($userId);

if (!$patient) {
    // Handle error - patient record not found
    redirect('logout.php');
}

// Get patient appointments
$appointments = getPatientAppointments($patient['id']);

// Get patient medical records
$medicalRecords = getPatientMedicalRecords($patient['id']);

// Get patient prescriptions
$prescriptions = getPatientPrescriptions($patient['id']);

// Process appointment booking
$bookingSuccess = '';
$bookingError = '';
// Process appointment cancellation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_appointment'])) {
    $appointmentId = sanitizeInput($_POST['appointment_id']);
    
    // Verify that this appointment belongs to the current patient
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT id FROM appointments WHERE id = ? AND patient_id = ? AND status IN ('pending', 'confirmed')");
    $stmt->bind_param("ii", $appointmentId, $patient['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Cancel the appointment
        $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\nCancelled by patient on ', NOW()) WHERE id = ?");
        $stmt->bind_param("i", $appointmentId);
        
        if ($stmt->execute()) {
            $bookingSuccess = "Appointment cancelled successfully!";
            // Refresh appointments list
            $appointments = getPatientAppointments($patient['id']);
        } else {
            $bookingError = "Failed to cancel appointment. Please try again.";
        }
    } else {
        $bookingError = "Appointment not found or cannot be cancelled.";
    }
    
    $stmt->close();
    $conn->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['book_appointment'])) {
    $doctorId = sanitizeInput($_POST['doctor_id']);
    $appointmentDate = sanitizeInput($_POST['appointment_date']);
    $appointmentTime = sanitizeInput($_POST['appointment_time']);
    $reason = sanitizeInput($_POST['reason']);
    $duration = sanitizeInput($_POST['duration']) ?: 15;
    
    // Validate input
    if (empty($doctorId) || empty($appointmentDate) || empty($appointmentTime)) {
        $bookingError = "All fields are required";
    } else {
        // Check if the time slot is still available
        if (!isTimeSlotAvailable($doctorId, $appointmentDate, $appointmentTime, $duration)) {
            $bookingError = "Sorry, this time slot is no longer available. Please select another time.";
        } else {
            // Insert appointment
            $conn = getConnection();
            $stmt = $conn->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, duration, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param("iissis", $patient['id'], $doctorId, $appointmentDate, $appointmentTime, $duration, $reason);
            
            if ($stmt->execute()) {
                $bookingSuccess = "Appointment booked successfully!";
                // Refresh appointments
                $appointments = getPatientAppointments($patient['id']);
            } else {
                if ($conn->errno == 1062) { // Duplicate entry error
                    $bookingError = "This time slot is already booked. Please select another time.";
                } else {
                    $bookingError = "Failed to book appointment. Please try again.";
                }
            }
            
            $stmt->close();
            $conn->close();
        }
    }
}

// Process profile update
$profileSuccess = '';
$profileError = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $dob = sanitizeInput($_POST['dob']);
    $gender = sanitizeInput($_POST['gender']);
    $address = sanitizeInput($_POST['address']);
    $medical_history = sanitizeInput($_POST['medical_history']);
    
    $profilePhotoPath = $user['profile_photo']; // Keep existing photo by default
    
    // Handle profile photo upload
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/profile_photos/';
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileName = $_FILES['profile_photo']['name'];
        $fileSize = $_FILES['profile_photo']['size'];
        $fileTmpName = $_FILES['profile_photo']['tmp_name'];
        $fileType = $_FILES['profile_photo']['type'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Validate file
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        
        if (in_array($fileExtension, $allowedExtensions) && $fileSize <= $maxFileSize) {
            // Generate unique filename
            $newFileName = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
            $uploadPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmpName, $uploadPath)) {
                // Delete old profile photo if exists
                if ($user['profile_photo'] && file_exists($user['profile_photo'])) {
                    unlink($user['profile_photo']);
                }
                $profilePhotoPath = $uploadPath;
            } else {
                $profileError = "Failed to upload profile photo.";
            }
        } else {
            $profileError = "Invalid file type or size. Please upload a JPG, PNG, or GIF file under 5MB.";
        }
    }
    
    if (empty($profileError)) {
        $conn = getConnection();
        
        // Update users table
        $stmt = $conn->prepare("UPDATE users SET name = ?, phone = ?, profile_photo = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $phone, $profilePhotoPath, $userId);
        
        if ($stmt->execute()) {
            // Update patients table
            $stmt = $conn->prepare("UPDATE patients SET dob = ?, gender = ?, address = ?, medical_history = ? WHERE user_id = ?");
            $stmt->bind_param("ssssi", $dob, $gender, $address, $medical_history, $userId);
            
            if ($stmt->execute()) {
                $profileSuccess = "Profile updated successfully!";
                $_SESSION['user_name'] = $name; // Update session
                // Refresh user data
                $user = getUserById($userId);
                $patient = getPatientByUserId($userId);
            } else {
                $profileError = "Failed to update profile. Please try again.";
            }
        } else {
            $profileError = "Failed to update profile. Please try again.";
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Process password change
$passwordSuccess = '';
$passwordError = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate passwords
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $passwordError = "All password fields are required.";
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = "New passwords do not match.";
    } elseif (strlen($newPassword) < 6) {
        $passwordError = "New password must be at least 6 characters long.";
    } else {
        // Verify current password
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userData = $result->fetch_assoc();
        
        if (password_verify($currentPassword, $userData['password'])) {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                $passwordSuccess = "Password changed successfully!";
            } else {
                $passwordError = "Failed to change password. Please try again.";
            }
        } else {
            $passwordError = "Current password is incorrect.";
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Get all doctors for appointment booking
$doctors = getAllDoctors();

// AJAX endpoint to get available time slots
if (isset($_GET['action']) && $_GET['action'] === 'get_available_slots') {
    header('Content-Type: application/json');
    
    $doctorId = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;
    $date = isset($_GET['date']) ? $_GET['date'] : '';
    
    if ($doctorId && $date) {
        $availableSlots = getAvailableTimeSlots($doctorId, $date);
        echo json_encode($availableSlots);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Get doctor's schedule info for a specific doctor
if (isset($_GET['action']) && $_GET['action'] === 'get_doctor_schedule') {
    header('Content-Type: application/json');
    
    $doctorId = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;
    
    if ($doctorId) {
        $schedule = getDoctorSchedule($doctorId);
        $scheduleInfo = [];
        
        foreach ($schedule as $daySchedule) {
            $scheduleInfo[$daySchedule['day_of_week']] = [
                'start_time' => $daySchedule['start_time'],
                'end_time' => $daySchedule['end_time'],
                'slot_duration' => $daySchedule['slot_duration']
            ];
        }
        
        echo json_encode($scheduleInfo);
    } else {
        echo json_encode([]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - MedPatient</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        /* Time slots grid */
        .time-slots-container {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #f8f9fa;
        }
        
        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .time-slot-btn {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: white;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .time-slot-btn:hover {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .time-slot-btn.selected {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .time-slot-btn:disabled {
            background-color: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
            border-color: #dee2e6;
        }
        
        .no-slots-message {
            text-align: center;
            color: #6c757d;
            font-style: italic;
            padding: 20px;
        }
        
        .doctor-schedule-info {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            border-left: 4px solid var(--primary);
        }
        
        .schedule-day {
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .schedule-day strong {
            color: var(--primary);
        }
        
        .loading-slots {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        
        /* Profile photo styles */
        .user-avatar {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid #ddd;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-avatar i {
            font-size: 20px;
            color: #6c757d;
        }
        
        .profile-photo-upload {
            position: relative;
            display: inline-block;
            margin-bottom: 20px;
        }
        
        .profile-photo-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 3px solid #ddd;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            margin: 0 auto 15px;
            position: relative;
        }
        
        .profile-photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-photo-preview i {
            font-size: 40px;
            color: #6c757d;
        }
        
        .photo-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }
        
        .photo-upload-btn:hover {
            background-color: var(--primary-dark);
        }
        
        .file-upload-input {
            display: none;
        }
        
        .remove-photo-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 12px;
            cursor: pointer;
            margin-top: 10px;
            transition: background-color 0.3s;
        }
        
        .remove-photo-btn:hover {
            background-color: #c82333;
        }
        
        .photo-upload-info {
            font-size: 12px;
            color: #6c757d;
            text-align: center;
            margin-top: 10px;
        }
        
        /* Password toggle styles */
        .password-input-container {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .password-input-container .form-control {
            padding-right: 45px;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 10px;
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s;
        }
        
        .password-toggle-btn:hover {
            color: var(--primary);
        }
        
        .password-toggle-btn i {
            font-size: 16px;
        }
        
        .duration-selector {
            margin-top: 15px;
        }
        
        .duration-options {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .duration-option {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: white;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .duration-option:hover {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .duration-option.selected {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-hospital-alt"></i>
                    <span>MedPatient</span>
                </div>
            </div>
            
            <div class="sidebar-menu">
                <ul>
                    <li class="active">
                        <a href="#overview" onclick="showTab('overview')">
                            <i class="fas fa-home"></i>
                            <span>Overview</span>
                        </a>
                    </li>
                    <li>
                        <a href="#appointments" onclick="showTab('appointments')">
                            <i class="fas fa-calendar-check"></i>
                            <span>Appointments</span>
                        </a>
                    </li>
                    <li>
                        <a href="#medical-records" onclick="showTab('medical-records')">
                            <i class="fas fa-file-medical"></i>
                            <span>Medical Records</span>
                        </a>
                    </li>
                    <li>
                        <a href="#prescriptions" onclick="showTab('prescriptions')">
                            <i class="fas fa-prescription-bottle-alt"></i>
                            <span>Prescriptions</span>
                        </a>
                    </li>
                    <li>
                        <a href="#profile" onclick="showTab('profile')">
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="sidebar-footer">
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <div class="page-title">
                    <h1>Patient Dashboard</h1>
                </div>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <div class="user-avatar">
                        <?php if ($user['profile_photo'] && file_exists($user['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Profile Photo">
                        <?php else: ?>
                            <i class="fas fa-user-circle"></i>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="content">
                <!-- Overview Tab -->
                <div id="overview" class="tab-content active">
                    <div class="dashboard-cards">
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="card-info">
                                <h3>Upcoming Appointments</h3>
                                <p class="count"><?php echo count(array_filter($appointments, function($a) { return $a['status'] != 'cancelled' && $a['status'] != 'completed'; })); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-file-medical"></i>
                            </div>
                            <div class="card-info">
                                <h3>Medical Records</h3>
                                <p class="count"><?php echo count($medicalRecords); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-prescription-bottle-alt"></i>
                            </div>
                            <div class="card-info">
                                <h3>Active Prescriptions</h3>
                                <p class="count"><?php echo count(array_filter($prescriptions, function($p) { return $p['status'] == 'active'; })); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="recent-activity">
                        <h2>Recent Activity</h2>
                        <div class="activity-list">
                            <?php if (empty($appointments) && empty($medicalRecords) && empty($prescriptions)): ?>
                                <p>No recent activity.</p>
                            <?php else: ?>
                                <?php 
                                $recentItems = array_merge(
                                    array_map(function($a) { return ['type' => 'appointment', 'date' => $a['appointment_date'], 'data' => $a]; }, $appointments),
                                    array_map(function($r) { return ['type' => 'record', 'date' => $r['record_date'], 'data' => $r]; }, $medicalRecords),
                                    array_map(function($p) { return ['type' => 'prescription', 'date' => $p['prescription_date'], 'data' => $p]; }, $prescriptions)
                                );
                                
                                usort($recentItems, function($a, $b) {
                                    return strtotime($b['date']) - strtotime($a['date']);
                                });
                                
                                $recentItems = array_slice($recentItems, 0, 5);
                                
                                foreach ($recentItems as $item):
                                    if ($item['type'] == 'appointment'):
                                ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                        <div class="activity-details">
                                            <h4>Appointment with Dr. <?php echo htmlspecialchars($item['data']['doctor_name']); ?></h4>
                                            <p><?php echo date('F j, Y', strtotime($item['data']['appointment_date'])); ?> at <?php echo date('g:i A', strtotime($item['data']['appointment_time'])); ?></p>
                                            <span class="status <?php echo strtolower($item['data']['status']); ?>"><?php echo ucfirst($item['data']['status']); ?></span>
                                        </div>
                                    </div>
                                <?php elseif ($item['type'] == 'record'): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-file-medical"></i>
                                        </div>
                                        <div class="activity-details">
                                            <h4>Medical Record Added</h4>
                                            <p><?php echo date('F j, Y', strtotime($item['data']['record_date'])); ?> by Dr. <?php echo htmlspecialchars($item['data']['doctor_name']); ?></p>
                                        </div>
                                    </div>
                                <?php elseif ($item['type'] == 'prescription'): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-prescription-bottle-alt"></i>
                                        </div>
                                        <div class="activity-details">
                                            <h4>Prescription: <?php echo htmlspecialchars($item['data']['medication']); ?></h4>
                                            <p><?php echo date('F j, Y', strtotime($item['data']['prescription_date'])); ?> by Dr. <?php echo htmlspecialchars($item['data']['doctor_name']); ?></p>
                                            <span class="status <?php echo strtolower($item['data']['status']); ?>"><?php echo ucfirst($item['data']['status']); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Appointments Tab -->
                <div id="appointments" class="tab-content">
                    <div class="section-header">
                        <h2>My Appointments</h2>
                        <button class="btn btn-primary" onclick="showModal('bookAppointmentModal')">Book New Appointment</button>
                    </div>
                    
                    <?php if(isset($bookingSuccess) && !empty($bookingSuccess)): ?>
                        <div class="alert alert-success"><?php echo $bookingSuccess; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($bookingError) && !empty($bookingError)): ?>
                        <div class="alert alert-danger"><?php echo $bookingError; ?></div>
                    <?php endif; ?>
                    
                    <div class="appointments-list">
                        <?php if (empty($appointments)): ?>
                            <p>No appointments found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Doctor</th>
                                            <th>Specialty</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($appointments as $appointment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($appointment['doctor_name']); ?></td>
                                                <td><?php echo htmlspecialchars($appointment['specialty']); ?></td>
                                                <td><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></td>
                                                <td><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></td>
                                                <td><?php echo ($appointment['duration'] ?? 15); ?> min</td>
                                                <td><span class="status <?php echo strtolower($appointment['status']); ?>"><?php echo ucfirst($appointment['status']); ?></span></td>
                                                <td>
                                                    <?php if ($appointment['status'] == 'pending' || $appointment['status'] == 'confirmed'): ?>
                                                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" style="display: inline;">
                                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                                <button type="submit" name="cancel_appointment" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to cancel this appointment?')">Cancel</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Medical Records Tab -->
                <div id="medical-records" class="tab-content">
                    <div class="section-header">
                        <h2>Medical Records</h2>
                    </div>
                    
                    <div class="medical-records-list">
                        <?php if (empty($medicalRecords)): ?>
                            <p>No medical records found.</p>
                        <?php else: ?>
                            <div class="accordion">
                                <?php foreach ($medicalRecords as $index => $record): ?>
                                    <div class="accordion-item">
                                        <div class="accordion-header" onclick="toggleAccordion('record-<?php echo $index; ?>')">
                                            <div class="accordion-title">
                                                <h3><?php echo date('F j, Y', strtotime($record['record_date'])); ?> - Dr. <?php echo htmlspecialchars($record['doctor_name']); ?></h3>
                                            </div>
                                            <div class="accordion-icon">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                        <div class="accordion-content" id="record-<?php echo $index; ?>">
                                            <div class="record-details">
                                                <div class="record-section">
                                                    <h4>Diagnosis</h4>
                                                    <p><?php echo nl2br(htmlspecialchars($record['diagnosis'])); ?></p>
                                                </div>
                                                <div class="record-section">
                                                    <h4>Treatment</h4>
                                                    <p><?php echo nl2br(htmlspecialchars($record['treatment'])); ?></p>
                                                </div>
                                                <div class="record-section">
                                                    <h4>Prescription</h4>
                                                    <p><?php echo nl2br(htmlspecialchars($record['prescription'])); ?></p>
                                                </div>
                                                <div class="record-section">
                                                    <h4>Notes</h4>
                                                    <p><?php echo nl2br(htmlspecialchars($record['notes'])); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Prescriptions Tab -->
                <div id="prescriptions" class="tab-content">
                    <div class="section-header">
                        <h2>Prescriptions</h2>
                    </div>
                    
                    <div class="prescriptions-list">
                        <?php if (empty($prescriptions)): ?>
                            <p>No prescriptions found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Doctor</th>
                                            <th>Medication</th>
                                            <th>Dosage</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prescriptions as $prescription): ?>
                                            <tr>
                                                <td><?php echo date('F j, Y', strtotime($prescription['prescription_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($prescription['doctor_name']); ?></td>
                                                <td><?php echo htmlspecialchars($prescription['medication']); ?></td>
                                                <td><?php echo htmlspecialchars($prescription['dosage']); ?></td>
                                                <td><span class="status <?php echo strtolower($prescription['status']); ?>"><?php echo ucfirst($prescription['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Profile Tab -->
                <div id="profile" class="tab-content">
                    <div class="section-header">
                        <h2>My Profile</h2>
                    </div>
                    
                    <?php if(isset($profileSuccess) && !empty($profileSuccess)): ?>
                        <div class="alert alert-success"><?php echo $profileSuccess; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($profileError) && !empty($profileError)): ?>
                        <div class="alert alert-danger"><?php echo $profileError; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($passwordSuccess) && !empty($passwordSuccess)): ?>
                        <div class="alert alert-success"><?php echo $passwordSuccess; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($passwordError) && !empty($passwordError)): ?>
                        <div class="alert alert-danger"><?php echo $passwordError; ?></div>
                    <?php endif; ?>
                    
                    <div class="profile-details">
                        <div class="profile-section">
                            <h3>Personal Information</h3>
                            <div class="profile-info">
                                <div class="info-item">
                                    <label>Name:</label>
                                    <span><?php echo htmlspecialchars($user['name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Email:</label>
                                    <span><?php echo htmlspecialchars($user['email']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Phone:</label>
                                    <span><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Date of Birth:</label>
                                    <span><?php echo $patient['dob'] ? date('F j, Y', strtotime($patient['dob'])) : 'N/A'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Gender:</label>
                                    <span><?php echo ucfirst(htmlspecialchars($patient['gender'] ?? 'N/A')); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Address:</label>
                                    <span><?php echo htmlspecialchars($patient['address'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="profile-actions">
                            <button class="btn btn-primary" onclick="showModal('editProfileModal')">Edit Profile</button>
                            <button class="btn btn-outline" onclick="showModal('changePasswordModal')">Change Password</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Book Appointment Modal -->
    <div id="bookAppointmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Book New Appointment</h2>
                <span class="close" onclick="hideModal('bookAppointmentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="appointmentForm">
                    <div class="form-group">
                        <label for="doctor_id">Select Doctor</label>
                        <select id="doctor_id" name="doctor_id" class="form-control" required onchange="loadDoctorSchedule()">
                            <option value="">Select a doctor</option>
                            <?php foreach ($doctors as $doctor): ?>
                                <option value="<?php echo $doctor['id']; ?>" data-specialty="<?php echo htmlspecialchars($doctor['specialty']); ?>">
                                    Dr. <?php echo htmlspecialchars($doctor['name']); ?> (<?php echo htmlspecialchars($doctor['specialty']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Doctor Schedule Info -->
                    <div id="doctorScheduleInfo" class="doctor-schedule-info" style="display: none;">
                        <h4><i class="fas fa-calendar-alt"></i> Doctor's Available Hours</h4>
                        <div id="scheduleDetails"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="appointment_date">Appointment Date</label>
                        <input type="date" id="appointment_date" name="appointment_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>" onchange="loadAvailableSlots()">
                    </div>
                    
                    <!-- Duration Selector -->
                    <div class="duration-selector" id="durationSelector" style="display: none;">
                        <label>Appointment Duration</label>
                        <div class="duration-options">
                            <div class="duration-option selected" data-duration="15">15 min</div>
                            <div class="duration-option" data-duration="30">30 min</div>
                            <div class="duration-option" data-duration="45">45 min</div>
                            <div class="duration-option" data-duration="60">60 min</div>
                        </div>
                        <input type="hidden" id="duration" name="duration" value="15">
                    </div>
                    
                    <!-- Available Time Slots -->
                    <div class="time-slots-container" id="timeSlotsContainer" style="display: none;">
                        <h4><i class="fas fa-clock"></i> Available Time Slots</h4>
                        <p>Click on a time slot to select it:</p>
                        <div id="timeSlotsGrid" class="time-slots-grid">
                            <!-- Time slots will be loaded here dynamically -->
                        </div>
                        <div id="loadingSlots" class="loading-slots" style="display: none;">
                            <i class="fas fa-spinner fa-spin"></i> Loading available slots...
                        </div>
                        <div id="noSlotsMessage" class="no-slots-message" style="display: none;">
                            No available time slots for the selected date. Please choose another date.
                        </div>
                    </div>
                    
                    <input type="hidden" id="appointment_time" name="appointment_time" required>
                    
                    <div class="form-group">
                        <label for="reason">Reason for Visit</label>
                        <textarea id="reason" name="reason" class="form-control" rows="3" placeholder="Briefly describe your reason for the appointment"></textarea>
                    </div>
                    
                    <button type="submit" name="book_appointment" class="btn btn-primary" id="submitBtn" disabled>Book Appointment</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Profile Modal -->
    <div id="editProfileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Profile</h2>
                <span class="close" onclick="hideModal('editProfileModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                    <!-- Profile Photo Section -->
                    <div class="profile-photo-upload">
                        <div class="profile-photo-preview" id="photoPreview">
                            <?php if ($user['profile_photo'] && file_exists($user['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Profile Photo" id="previewImage">
                            <?php else: ?>
                                <i class="fas fa-user-circle" id="previewIcon"></i>
                            <?php endif; ?>
                            <button type="button" class="photo-upload-btn" onclick="document.getElementById('profilePhotoInput').click()">
                                <i class="fas fa-camera"></i>
                            </button>
                        </div>
                        <input type="file" id="profilePhotoInput" name="profile_photo" class="file-upload-input" accept="image/*" onchange="previewPhoto(this)">
                        <?php if ($user['profile_photo']): ?>
                            <button type="button" class="remove-photo-btn" onclick="removePhoto()">Remove Photo</button>
                        <?php endif; ?>
                        <div class="photo-upload-info">
                            Upload JPG, PNG, or GIF. Max size 5MB.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_name">Full Name</label>
                        <input type="text" id="edit_name" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_phone">Phone Number</label>
                        <input type="tel" id="edit_phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_dob">Date of Birth</label>
                        <input type="date" id="edit_dob" name="dob" class="form-control" value="<?php echo htmlspecialchars($patient['dob'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_gender">Gender</label>
                        <select id="edit_gender" name="gender" class="form-control">
                            <option value="">Select Gender</option>
                            <option value="male" <?php echo ($patient['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($patient['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($patient['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_address">Address</label>
                        <textarea id="edit_address" name="address" class="form-control" rows="3" placeholder="Enter your full address"><?php echo htmlspecialchars($patient['address'] ?? ''); ?></textarea>
                    </div>
                                        
                    <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Change Password</h2>
                <span class="close" onclick="hideModal('changePasswordModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <div class="password-input-container">
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('current_password')">
                                <i class="fas fa-eye" id="current_password_icon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="password-input-container">
                            <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('new_password')">
                                <i class="fas fa-eye" id="new_password_icon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="password-input-container">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6">
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('confirm_password')">
                                <i class="fas fa-eye" id="confirm_password_icon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let selectedTimeSlot = null;
        let doctorSchedule = {};
        
        // Show tab content
        function showTab(tabId) {
            // Hide all tab content
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Update active menu item
            const menuItems = document.querySelectorAll('.sidebar-menu li');
            menuItems.forEach(item => {
                item.classList.remove('active');
            });
            
            // Find and activate the clicked menu item
            const activeMenuItem = document.querySelector(`.sidebar-menu a[href="#${tabId}"]`).parentElement;
            activeMenuItem.classList.add('active');
            
            // Store current tab in localStorage
            localStorage.setItem('currentTab', tabId);
        }
        
        // Function to auto-hide alerts after 5 seconds
        function autoHideAlerts() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000); // Hide after 5 seconds
            });
        }
        
        // Restore tab on page load if form was submitted
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['update_profile']) || isset($_POST['change_password']) || isset($_POST['book_appointment']) || isset($_POST['cancel_appointment']))): ?>
                // If profile was updated, password changed, or appointment booked/cancelled, stay on respective tab
                <?php if (isset($_POST['update_profile']) || isset($_POST['change_password'])): ?>
                    showTab('profile');
                <?php elseif (isset($_POST['book_appointment']) || isset($_POST['cancel_appointment'])): ?>
                    showTab('appointments');
                <?php endif; ?>
            <?php else: ?>
                // Otherwise, restore the last active tab or default to overview
                const savedTab = localStorage.getItem('currentTab') || 'overview';
                showTab(savedTab);
            <?php endif; ?>
            
            // Auto-hide alerts
            autoHideAlerts();
            
            // Initialize duration selector
            initializeDurationSelector();
        });
        
        // Initialize duration selector
        function initializeDurationSelector() {
            const durationOptions = document.querySelectorAll('.duration-option');
            const durationInput = document.getElementById('duration');
            
            durationOptions.forEach(option => {
                option.addEventListener('click', function() {
                    // Remove selected class from all options
                    durationOptions.forEach(opt => opt.classList.remove('selected'));
                    
                    // Add selected class to clicked option
                    this.classList.add('selected');
                    
                    // Update hidden input value
                    durationInput.value = this.dataset.duration;
                    
                    // Reload available slots with new duration
                    loadAvailableSlots();
                });
            });
        }
        
        // Load doctor's schedule information
        function loadDoctorSchedule() {
            const doctorSelect = document.getElementById('doctor_id');
            const doctorId = doctorSelect.value;
            const scheduleInfo = document.getElementById('doctorScheduleInfo');
            const scheduleDetails = document.getElementById('scheduleDetails');
            const durationSelector = document.getElementById('durationSelector');
            
            if (!doctorId) {
                scheduleInfo.style.display = 'none';
                durationSelector.style.display = 'none';
                clearTimeSlots();
                return;
            }
            
            // Show duration selector
            durationSelector.style.display = 'block';
            
            // Fetch doctor's schedule
            fetch(`?action=get_doctor_schedule&doctor_id=${doctorId}`)
                .then(response => response.json())
                .then(data => {
                    doctorSchedule = data;
                    
                    if (Object.keys(data).length === 0) {
                        scheduleDetails.innerHTML = '<p style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> This doctor has not set up their schedule yet.</p>';
                    } else {
                        let scheduleHtml = '';
                        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        const dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        
                        days.forEach((day, index) => {
                            if (data[day]) {
                                const startTime = formatTime(data[day].start_time);
                                const endTime = formatTime(data[day].end_time);
                                const slotDuration = data[day].slot_duration;
                                
                                scheduleHtml += `<div class="schedule-day">
                                    <strong>${dayNames[index]}:</strong> ${startTime} - ${endTime} (${slotDuration} min slots)
                                </div>`;
                            }
                        });
                        
                        if (scheduleHtml) {
                            scheduleDetails.innerHTML = scheduleHtml;
                        } else {
                            scheduleDetails.innerHTML = '<p style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> This doctor has no available days set up.</p>';
                        }
                    }
                    
                    scheduleInfo.style.display = 'block';
                    
                    // Clear previous time slots
                    clearTimeSlots();
                    
                    // Load available slots if date is selected
                    const dateInput = document.getElementById('appointment_date');
                    if (dateInput.value) {
                        loadAvailableSlots();
                    }
                })
                .catch(error => {
                    console.error('Error loading doctor schedule:', error);
                    scheduleDetails.innerHTML = '<p style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> Error loading doctor schedule.</p>';
                    scheduleInfo.style.display = 'block';
                });
        }
        
        // Load available time slots
        function loadAvailableSlots() {
            const doctorId = document.getElementById('doctor_id').value;
            const date = document.getElementById('appointment_date').value;
            const duration = document.getElementById('duration').value;
            const timeSlotsContainer = document.getElementById('timeSlotsContainer');
            const timeSlotsGrid = document.getElementById('timeSlotsGrid');
            const loadingSlots = document.getElementById('loadingSlots');
            const noSlotsMessage = document.getElementById('noSlotsMessage');
            
            if (!doctorId || !date) {
                timeSlotsContainer.style.display = 'none';
                return;
            }
            
            // Show container and loading indicator
            timeSlotsContainer.style.display = 'block';
            loadingSlots.style.display = 'block';
            noSlotsMessage.style.display = 'none';
            timeSlotsGrid.innerHTML = '';
            
            // Clear previous selection
            clearTimeSelection();
            
            // Fetch available slots
            fetch(`?action=get_available_slots&doctor_id=${doctorId}&date=${date}`)
                .then(response => response.json())
                .then(slots => {
                    loadingSlots.style.display = 'none';
                    
                    if (slots.length === 0) {
                        noSlotsMessage.style.display = 'block';
                    } else {
                        // Create time slot buttons
                        slots.forEach(slot => {
                            const slotBtn = document.createElement('div');
                            slotBtn.className = 'time-slot-btn';
                            slotBtn.textContent = slot.formatted_time;
                            slotBtn.dataset.time = slot.time;
                            
                            slotBtn.addEventListener('click', function() {
                                selectTimeSlot(this);
                            });
                            
                            timeSlotsGrid.appendChild(slotBtn);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading time slots:', error);
                    loadingSlots.style.display = 'none';
                    noSlotsMessage.innerHTML = 'Error loading available time slots. Please try again.';
                    noSlotsMessage.style.display = 'block';
                });
        }
        
        // Select a time slot
        function selectTimeSlot(slotElement) {
            // Remove selection from other slots
            document.querySelectorAll('.time-slot-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
            
            // Select this slot
            slotElement.classList.add('selected');
            selectedTimeSlot = slotElement.dataset.time;
            
            // Update hidden input
            document.getElementById('appointment_time').value = selectedTimeSlot;
            
            // Enable submit button
            document.getElementById('submitBtn').disabled = false;
        }
        
        // Clear time selection
        function clearTimeSelection() {
            selectedTimeSlot = null;
            document.getElementById('appointment_time').value = '';
            document.getElementById('submitBtn').disabled = true;
            
            document.querySelectorAll('.time-slot-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
        }
        
        // Clear time slots
        function clearTimeSlots() {
            document.getElementById('timeSlotsContainer').style.display = 'none';
            document.getElementById('timeSlotsGrid').innerHTML = '';
            clearTimeSelection();
        }
        
        // Format time for display
        function formatTime(timeString) {
            const [hours, minutes] = timeString.split(':');
            const hour24 = parseInt(hours);
            const hour12 = hour24 === 0 ? 12 : (hour24 > 12 ? hour24 - 12 : hour24);
            const ampm = hour24 < 12 ? 'AM' : 'PM';
            return `${hour12}:${minutes} ${ampm}`;
        }
        
        // Toggle accordion
        function toggleAccordion(id) {
            const content = document.getElementById(id);
            content.style.display = content.style.display === 'block' ? 'none' : 'block';
        }
        
        // Show modal
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            
            // Reset form if it's the booking modal
            if (modalId === 'bookAppointmentModal') {
                resetBookingForm();
            }
        }
        
        // Hide modal
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Reset booking form
        function resetBookingForm() {
            document.getElementById('appointmentForm').reset();
            document.getElementById('doctorScheduleInfo').style.display = 'none';
            document.getElementById('durationSelector').style.display = 'none';
            clearTimeSlots();
            
            // Reset duration selector
            document.querySelectorAll('.duration-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector('.duration-option[data-duration="15"]').classList.add('selected');
            document.getElementById('duration').value = '15';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Profile photo preview function
        function previewPhoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const preview = document.getElementById('photoPreview');
                    const previewImage = document.getElementById('previewImage');
                    const previewIcon = document.getElementById('previewIcon');
                    
                    if (previewImage) {
                        previewImage.src = e.target.result;
                    } else {
                        // Replace icon with image
                        if (previewIcon) {
                            previewIcon.remove();
                        }
                        const img = document.createElement('img');
                        img.id = 'previewImage';
                        img.src = e.target.result;
                        img.alt = 'Profile Photo';
                        preview.insertBefore(img, preview.firstChild);
                    }
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Remove photo function
        function removePhoto() {
            const preview = document.getElementById('photoPreview');
            const previewImage = document.getElementById('previewImage');
            const input = document.getElementById('profilePhotoInput');
            
            if (previewImage) {
                previewImage.remove();
                // Add back the icon
                const icon = document.createElement('i');
                icon.id = 'previewIcon';
                icon.className = 'fas fa-user-circle';
                preview.insertBefore(icon, preview.firstChild);
            }
            
            // Clear the file input
            input.value = '';
            
            // Hide remove button
            const removeBtn = document.querySelector('.remove-photo-btn');
            if (removeBtn) {
                removeBtn.style.display = 'none';
            }
        }
        
        // Toggle password visibility
        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById(fieldId + '_icon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Form validation before submission
        document.getElementById('appointmentForm').addEventListener('submit', function(e) {
            if (!selectedTimeSlot) {
                e.preventDefault();
                alert('Please select a time slot for your appointment.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>