<?php
// Include database configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

// Check if user is a doctor
if (!hasRole('doctor')) {
    redirect('login.php');
}

// Get doctor data
$userId = $_SESSION['user_id'];
$user = getUserById($userId);
$doctor = getDoctorByUserId($userId);

if (!$doctor) {
    // Handle error - doctor record not found
    redirect('logout.php');
}

// Get doctor appointments
$appointments = getDoctorAppointments($doctor['id']);

// Get doctor's schedule
$schedule = getDoctorSchedule($doctor['id']);

// Get doctor's schedule exceptions
$scheduleExceptions = getDoctorScheduleExceptions($doctor['id']);

// Process schedule management
$scheduleSuccess = '';
$scheduleError = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_schedule'])) {
    $scheduleData = $_POST['schedule'];
    $slotDuration = intval($_POST['slot_duration']) ?: 15;
    
    $savedDays = 0;
    
    foreach ($scheduleData as $day => $times) {
        if (!empty($times['start']) && !empty($times['end'])) {
            // Validate times
            if (strtotime($times['start']) >= strtotime($times['end'])) {
                $scheduleError = "End time must be after start time for " . ucfirst($day);
                break;
            }
            
            if (saveDoctorSchedule($doctor['id'], $day, $times['start'], $times['end'], $slotDuration)) {
                $savedDays++;
            }
        } else {
            // Remove schedule for this day if both times are empty
            deleteDoctorSchedule($doctor['id'], $day);
        }
    }
    
    if (empty($scheduleError) && $savedDays > 0) {
        $scheduleSuccess = "Schedule updated successfully for $savedDays day(s)!";
        $schedule = getDoctorSchedule($doctor['id']); // Refresh schedule
    } elseif (empty($scheduleError)) {
        $scheduleSuccess = "Schedule cleared successfully!";
        $schedule = getDoctorSchedule($doctor['id']); // Refresh schedule
    }
}

// Process schedule exception
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_exception'])) {
    $exceptionDate = sanitizeInput($_POST['exception_date']);
    $reason = sanitizeInput($_POST['reason']);
    $isUnavailable = isset($_POST['is_unavailable']) ? 1 : 0;
    $startTime = !empty($_POST['exception_start']) ? sanitizeInput($_POST['exception_start']) : null;
    $endTime = !empty($_POST['exception_end']) ? sanitizeInput($_POST['exception_end']) : null;
    
    if (addScheduleException($doctor['id'], $exceptionDate, $reason, $isUnavailable, $startTime, $endTime)) {
        $scheduleSuccess = "Schedule exception added successfully!";
        $scheduleExceptions = getDoctorScheduleExceptions($doctor['id']); // Refresh exceptions
    } else {
        $scheduleError = "Failed to add schedule exception. Please try again.";
    }
}

// Process appointment status update
$updateSuccess = '';
$updateError = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_appointment'])) {
    $appointmentId = sanitizeInput($_POST['appointment_id']);
    $status = sanitizeInput($_POST['status']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Update appointment
    $conn = getConnection();
    $stmt = $conn->prepare("UPDATE appointments SET status = ?, notes = ? WHERE id = ? AND doctor_id = ?");
    $stmt->bind_param("ssii", $status, $notes, $appointmentId, $doctor['id']);
    
    if ($stmt->execute()) {
        $updateSuccess = "Appointment updated successfully!";
        // Refresh appointments
        $appointments = getDoctorAppointments($doctor['id']);
    } else {
        $updateError = "Failed to update appointment. Please try again.";
    }
    
    $stmt->close();
    $conn->close();
}

// Process medical record creation
$recordSuccess = '';
$recordError = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_record'])) {
    $patientId = sanitizeInput($_POST['patient_id']);
    $diagnosis = sanitizeInput($_POST['diagnosis']);
    $treatment = sanitizeInput($_POST['treatment']);
    $prescription = sanitizeInput($_POST['prescription']);
    $notes = sanitizeInput($_POST['notes']);
    $recordDate = date('Y-m-d');
    
    // Insert medical record
    $conn = getConnection();
    $stmt = $conn->prepare("INSERT INTO medical_records (patient_id, doctor_id, record_date, diagnosis, treatment, prescription, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssss", $patientId, $doctor['id'], $recordDate, $diagnosis, $treatment, $prescription, $notes);
    
    if ($stmt->execute()) {
        // If prescription is provided, also add to prescriptions table
        if (!empty($prescription)) {
            $stmt = $conn->prepare("INSERT INTO prescriptions (patient_id, doctor_id, prescription_date, medication, dosage, instructions, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $medication = $prescription;
            $dosage = "As prescribed";
            $instructions = $notes;
            $stmt->bind_param("iissss", $patientId, $doctor['id'], $recordDate, $medication, $dosage, $instructions);
            $stmt->execute();
        }
        
        $recordSuccess = "Medical record added successfully!";
        $medicalRecords = getDoctorMedicalRecords($doctor['id']); // Refresh records
    } else {
        $recordError = "Failed to add medical record. Please try again.";
    }
    
    $stmt->close();
    $conn->close();
}

// Process profile update
$profileSuccess = '';
$profileError = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $specialty = sanitizeInput($_POST['specialty']);
    $license_number = sanitizeInput($_POST['license_number']);
    $bio = sanitizeInput($_POST['bio']);
    
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
            // Update doctors table
            $stmt = $conn->prepare("UPDATE doctors SET specialty = ?, license_number = ?, bio = ? WHERE user_id = ?");
            $stmt->bind_param("sssi", $specialty, $license_number, $bio, $userId);
            
            if ($stmt->execute()) {
                $profileSuccess = "Profile updated successfully!";
                $_SESSION['user_name'] = $name; // Update session
                // Refresh user data
                $user = getUserById($userId);
                $doctor = getDoctorByUserId($userId);
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

// Get only patients who have appointments with this doctor
$patients = getDoctorPatients($doctor['id']);

// Get medical records created by this doctor
$medicalRecords = getDoctorMedicalRecords($doctor['id']);

// Convert schedule array to associative array for easier access
$scheduleByDay = [];
foreach ($schedule as $s) {
    $scheduleByDay[$s['day_of_week']] = $s;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard - MedPatient</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        /* Schedule management styles */
        .schedule-grid {
            display: grid;
            grid-template-columns: 1fr 2fr 2fr 1fr;
            gap: 15px;
            align-items: center;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
        }
        
        .schedule-grid:hover {
            background-color: #e9ecef;
        }
        
        .schedule-day {
            font-weight: bold;
            color: var(--primary);
        }
        
        .schedule-times {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .schedule-times input[type="time"] {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .schedule-status {
            text-align: center;
        }
        
        .schedule-status.active {
            color: #28a745;
            font-weight: bold;
        }
        
        .schedule-status.inactive {
            color: #6c757d;
            font-style: italic;
        }
        
        .schedule-actions {
            text-align: center;
        }
        
        .slot-duration-setting {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }
        
        .exceptions-list {
            margin-top: 20px;
        }
        
        .exception-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 10px;
            background-color: #fff3cd;
        }
        
        .exception-item.unavailable {
            background-color: #f8d7da;
        }
        
        .exception-details {
            flex: 1;
        }
        
        .exception-date {
            font-weight: bold;
            color: var(--primary);
        }
        
        .exception-reason {
            font-size: 14px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .exception-times {
            font-size: 12px;
            color: #495057;
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
        
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 10px 0;
        }
        
        .checkbox-container input[type="checkbox"] {
            margin: 0;
        }
        .record-details .info-item {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .record-details .info-item:last-child {
            border-bottom: none;
        }

        .record-details label {
            font-weight: bold;
            color: var(--primary);
            display: block;
            margin-bottom: 5px;
        }

        .record-details div {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
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
                        <a href="#schedule" onclick="showTab('schedule')">
                            <i class="fas fa-calendar-alt"></i>
                            <span>My Schedule</span>
                        </a>
                    </li>
                    <li>
                        <a href="#appointments" onclick="showTab('appointments')">
                            <i class="fas fa-calendar-check"></i>
                            <span>Appointments</span>
                        </a>
                    </li>
                    <li>
                        <a href="#patients" onclick="showTab('patients')">
                            <i class="fas fa-user-injured"></i>
                            <span>Patients</span>
                        </a>
                    </li>
                    <li>
                        <a href="#medical-records" onclick="showTab('medical-records')">
                            <i class="fas fa-file-medical"></i>
                            <span>Medical Records</span>
                        </a>
                    </li>
                    <li>
                        <a href="#profile" onclick="showTab('profile')">
                            <i class="fas fa-user-md"></i>
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
                    <h1>Doctor Dashboard</h1>
                </div>
                <div class="user-info">
                    <span>Welcome, Dr. <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <div class="user-avatar">
                        <?php if ($user['profile_photo'] && file_exists($user['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Profile Photo">
                        <?php else: ?>
                            <i class="fas fa-user-md"></i>
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
                                <h3>Today's Appointments</h3>
                                <p class="count">
                                    <?php 
                                    $todayAppointments = array_filter($appointments, function($a) {
                                        return $a['appointment_date'] == date('Y-m-d');
                                    });
                                    echo count($todayAppointments);
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-user-injured"></i>
                            </div>
                            <div class="card-info">
                                <h3>Total Patients</h3>
                                <p class="count"><?php echo count($patients); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <div class="card-info">
                                <h3>Pending Appointments</h3>
                                <p class="count">
                                    <?php 
                                    $pendingAppointments = array_filter($appointments, function($a) {
                                        return $a['status'] == 'pending';
                                    });
                                    echo count($pendingAppointments);
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="card-info">
                                <h3>Active Schedule Days</h3>
                                <p class="count"><?php echo count($schedule); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="upcoming-appointments">
                        <h2>Upcoming Appointments</h2>
                        <?php if (empty($appointments)): ?>
                            <p>No upcoming appointments.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $upcomingAppointments = array_filter($appointments, function($a) {
                                            return $a['status'] != 'cancelled' && $a['status'] != 'completed' && 
                                                  (strtotime($a['appointment_date']) >= strtotime(date('Y-m-d')) || 
                                                  (strtotime($a['appointment_date']) == strtotime(date('Y-m-d')) && 
                                                   strtotime($a['appointment_time']) > strtotime(date('H:i:s'))));
                                        });
                                        
                                        $upcomingAppointments = array_slice($upcomingAppointments, 0, 5);
                                        
                                        foreach ($upcomingAppointments as $appointment): 
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                                <td><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></td>
                                                <td><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></td>
                                                <td><span class="status <?php echo strtolower($appointment['status']); ?>"><?php echo ucfirst($appointment['status']); ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary" onclick="showUpdateModal(<?php echo $appointment['id']; ?>, '<?php echo $appointment['status']; ?>', '<?php echo addslashes($appointment['notes'] ?? ''); ?>')">Update</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Schedule Management Tab -->
                <div id="schedule" class="tab-content">
                    <div class="section-header">
                        <h2>My Schedule</h2>
                        <button class="btn btn-primary" onclick="showModal('addExceptionModal')">Add Exception</button>
                    </div>
                    
                    <?php if(isset($scheduleSuccess) && !empty($scheduleSuccess)): ?>
                        <div class="alert alert-success"><?php echo $scheduleSuccess; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($scheduleError) && !empty($scheduleError)): ?>
                        <div class="alert alert-danger"><?php echo $scheduleError; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="slot-duration-setting">
                            <h3>Appointment Settings</h3>
                            <div class="form-group">
                                <label for="slot_duration">Appointment Slot Duration (minutes)</label>
                                <select id="slot_duration" name="slot_duration" class="form-control" style="width: auto; display: inline-block;">
                                    <option value="15" <?php echo (isset($schedule[0]) && $schedule[0]['slot_duration'] == 15) ? 'selected' : ''; ?>>15 minutes</option>
                                    <option value="30" <?php echo (isset($schedule[0]) && $schedule[0]['slot_duration'] == 30) ? 'selected' : ''; ?>>30 minutes</option>
                                    <option value="45" <?php echo (isset($schedule[0]) && $schedule[0]['slot_duration'] == 45) ? 'selected' : ''; ?>>45 minutes</option>
                                    <option value="60" <?php echo (isset($schedule[0]) && $schedule[0]['slot_duration'] == 60) ? 'selected' : ''; ?>>60 minutes</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="schedule-management">
                            <h3>Weekly Schedule</h3>
                            <p>Set your available hours for each day of the week. Leave both times empty to mark a day as unavailable.</p>
                            
                            <?php 
                            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                            $dayLabels = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            
                            foreach ($days as $index => $day):
                                $hasSchedule = isset($scheduleByDay[$day]);
                                $startTime = $hasSchedule ? $scheduleByDay[$day]['start_time'] : '';
                                $endTime = $hasSchedule ? $scheduleByDay[$day]['end_time'] : '';
                            ?>
                                <div class="schedule-grid">
                                    <div class="schedule-day">
                                        <?php echo $dayLabels[$index]; ?>
                                    </div>
                                    <div class="schedule-times">
                                        <label for="start_<?php echo $day; ?>">From:</label>
                                        <input type="time" id="start_<?php echo $day; ?>" name="schedule[<?php echo $day; ?>][start]" value="<?php echo $startTime; ?>">
                                    </div>
                                    <div class="schedule-times">
                                        <label for="end_<?php echo $day; ?>">To:</label>
                                        <input type="time" id="end_<?php echo $day; ?>" name="schedule[<?php echo $day; ?>][end]" value="<?php echo $endTime; ?>">
                                    </div>
                                    <div class="schedule-status <?php echo $hasSchedule ? 'active' : 'inactive'; ?>">
                                        <?php echo $hasSchedule ? 'Active' : 'Inactive'; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="form-actions">
                                <button type="submit" name="save_schedule" class="btn btn-primary">Save Schedule</button>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Schedule Exceptions -->
                    <div class="exceptions-management">
                        <h3>Schedule Exceptions</h3>
                        <p>Manage holidays, vacations, or special hours for specific dates.</p>
                        
                        <?php if (empty($scheduleExceptions)): ?>
                            <p>No schedule exceptions found.</p>
                        <?php else: ?>
                            <div class="exceptions-list">
                                <?php foreach ($scheduleExceptions as $exception): ?>
                                    <div class="exception-item <?php echo $exception['is_unavailable'] ? 'unavailable' : ''; ?>">
                                        <div class="exception-details">
                                            <div class="exception-date">
                                                <?php echo date('F j, Y (l)', strtotime($exception['exception_date'])); ?>
                                            </div>
                                            <div class="exception-reason">
                                                <?php echo htmlspecialchars($exception['reason']); ?>
                                            </div>
                                            <?php if (!$exception['is_unavailable'] && $exception['start_time'] && $exception['end_time']): ?>
                                                <div class="exception-times">
                                                    Special hours: <?php echo date('g:i A', strtotime($exception['start_time'])); ?> - <?php echo date('g:i A', strtotime($exception['end_time'])); ?>
                                                </div>
                                            <?php elseif ($exception['is_unavailable']): ?>
                                                <div class="exception-times">
                                                    Unavailable all day
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="exception-actions">
                                            <button class="btn btn-sm btn-danger" onclick="deleteException(<?php echo $exception['id']; ?>)">Remove</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Appointments Tab -->
                <div id="appointments" class="tab-content">
                    <div class="section-header">
                        <h2>All Appointments</h2>
                    </div>
                    
                    <?php if(isset($updateSuccess) && !empty($updateSuccess)): ?>
                        <div class="alert alert-success"><?php echo $updateSuccess; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($updateError) && !empty($updateError)): ?>
                        <div class="alert alert-danger"><?php echo $updateError; ?></div>
                    <?php endif; ?>
                    
                    <div class="appointments-list">
                        <?php if (empty($appointments)): ?>
                            <p>No appointments found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Duration</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($appointments as $appointment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                                <td><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></td>
                                                <td><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></td>
                                                <td><?php echo ($appointment['duration'] ?? 15); ?> min</td>
                                                <td><?php echo htmlspecialchars($appointment['reason'] ?? 'N/A'); ?></td>
                                                <td><span class="status <?php echo strtolower($appointment['status']); ?>"><?php echo ucfirst($appointment['status']); ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary" onclick="showUpdateModal(<?php echo $appointment['id']; ?>, '<?php echo $appointment['status']; ?>', '<?php echo addslashes($appointment['notes'] ?? ''); ?>')">Update</button>
                                                    <?php if ($appointment['status'] == 'confirmed' || $appointment['status'] == 'completed'): ?>
                                                        <button class="btn btn-sm btn-success" onclick="showRecordModal(<?php echo $appointment['patient_id']; ?>)">Add Record</button>
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
                
                <!-- Patients Tab -->
                <div id="patients" class="tab-content">
                    <div class="section-header">
                        <h2>My Patients</h2>
                    </div>
                    
                    <div class="patients-list">
                        <?php 
                        // Filter patients who have confirmed or completed appointments
                        $eligiblePatients = array_filter($patients, function($patient) use ($appointments) {
                            foreach ($appointments as $appointment) {
                                if ($appointment['patient_id'] == $patient['id'] && 
                                    ($appointment['status'] == 'confirmed' || $appointment['status'] == 'completed')) {
                                    return true;
                                }
                            }
                            return false;
                        });
                        ?>
                        
                        <?php if (empty($eligiblePatients)): ?>
                            <p>No patients with confirmed or completed appointments found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Gender</th>
                                            <th>Appointment Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($eligiblePatients as $patient): ?>
                                            <?php 
                                            // Get the patient's appointment status
                                            $patientStatus = '';
                                            foreach ($appointments as $appointment) {
                                                if ($appointment['patient_id'] == $patient['id'] && 
                                                    ($appointment['status'] == 'confirmed' || $appointment['status'] == 'completed')) {
                                                    $patientStatus = $appointment['status'];
                                                    break;
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($patient['name']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['email']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></td>
                                                <td><?php echo ucfirst(htmlspecialchars($patient['gender'] ?? 'N/A')); ?></td>
                                                <td><span class="status <?php echo $patientStatus; ?>"><?php echo ucfirst($patientStatus); ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-success" onclick="showRecordModal(<?php echo $patient['id']; ?>)">Add Record</button>
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
                    
                    <?php if(isset($recordSuccess) && !empty($recordSuccess)): ?>
                        <div class="alert alert-success"><?php echo $recordSuccess; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($recordError) && !empty($recordError)): ?>
                        <div class="alert alert-danger"><?php echo $recordError; ?></div>
                    <?php endif; ?>
                    
                    <div class="medical-records-list">
                        <?php if (empty($medicalRecords)): ?>
                            <p>No medical records found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Date</th>
                                            <th>Diagnosis</th>
                                            <th>Treatment</th>
                                            <th>Prescription</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($medicalRecords as $record): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($record['patient_name']); ?></td>
                                                <td><?php echo date('F j, Y', strtotime($record['record_date'])); ?></td>
                                                <td><?php echo htmlspecialchars(substr($record['diagnosis'], 0, 50)) . (strlen($record['diagnosis']) > 50 ? '...' : ''); ?></td>
                                                <td><?php echo htmlspecialchars(substr($record['treatment'], 0, 50)) . (strlen($record['treatment']) > 50 ? '...' : ''); ?></td>
                                                <td><?php echo htmlspecialchars($record['prescription'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info" onclick="viewRecord(<?php echo $record['id']; ?>)">View</button>
                                                </td>
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
                                    <span>Dr. <?php echo htmlspecialchars($user['name']); ?></span>
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
                                    <label>Specialty:</label>
                                    <span><?php echo htmlspecialchars($doctor['specialty'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>License Number:</label>
                                    <span><?php echo htmlspecialchars($doctor['license_number'] ?? 'N/A'); ?></span>
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
    
    <!-- Add Schedule Exception Modal -->
    <div id="addExceptionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Schedule Exception</h2>
                <span class="close" onclick="hideModal('addExceptionModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="form-group">
                        <label for="exception_date">Date</label>
                        <input type="date" id="exception_date" name="exception_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="reason">Reason</label>
                        <input type="text" id="reason" name="reason" class="form-control" required placeholder="e.g., Holiday, Vacation, Conference">
                    </div>
                    
                    <div class="checkbox-container">
                        <input type="checkbox" id="is_unavailable" name="is_unavailable" checked onchange="toggleExceptionTimes()">
                        <label for="is_unavailable">Unavailable all day</label>
                    </div>
                    
                    <div id="exceptionTimes" style="display: none;">
                        <div class="form-group">
                            <label for="exception_start">Start Time</label>
                            <input type="time" id="exception_start" name="exception_start" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="exception_end">End Time</label>
                            <input type="time" id="exception_end" name="exception_end" class="form-control">
                        </div>
                    </div>
                    
                    <button type="submit" name="add_exception" class="btn btn-primary">Add Exception</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Appointment Modal -->
    <div id="updateAppointmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Update Appointment</h2>
                <span class="close" onclick="hideModal('updateAppointmentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" id="appointment_id" name="appointment_id">
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="pending">Pending</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Add notes about this appointment"></textarea>
                    </div>
                    
                    <button type="submit" name="update_appointment" class="btn btn-primary">Update Appointment</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Medical Record Modal -->
    <div id="addRecordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Medical Record</h2>
                <span class="close" onclick="hideModal('addRecordModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" id="patient_id" name="patient_id">
                    
                    <div class="form-group">
                        <label for="diagnosis">Diagnosis</label>
                        <textarea id="diagnosis" name="diagnosis" class="form-control" rows="3" required placeholder="Enter diagnosis"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="treatment">Treatment</label>
                        <textarea id="treatment" name="treatment" class="form-control" rows="3" required placeholder="Enter treatment plan"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="prescription">Prescription</label>
                        <textarea id="prescription" name="prescription" class="form-control" rows="3" placeholder="Enter prescription details"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea id="record_notes" name="notes" class="form-control" rows="3" placeholder="Add any additional notes"></textarea>
                    </div>
                    
                    <button type="submit" name="add_record" class="btn btn-primary">Save Record</button>
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
                                <i class="fas fa-user-md" id="previewIcon"></i>
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
                        <label for="edit_specialty">Specialty</label>
                        <input type="text" id="edit_specialty" name="specialty" class="form-control" value="<?php echo htmlspecialchars($doctor['specialty'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_license">License Number</label>
                        <input type="text" id="edit_license" name="license_number" class="form-control" value="<?php echo htmlspecialchars($doctor['license_number'] ?? ''); ?>">
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
    
    <!-- View Medical Record Modal -->
<div id="viewRecordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Medical Record Details</h2>
            <span class="close" onclick="hideModal('viewRecordModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="record-details">
                <div class="info-item">
                    <label>Patient:</label>
                    <span id="view_patient_name"></span>
                </div>
                <div class="info-item">
                    <label>Date:</label>
                    <span id="view_record_date"></span>
                </div>
                <div class="info-item">
                    <label>Diagnosis:</label>
                    <div id="view_diagnosis" style="white-space: pre-wrap; margin-top: 5px;"></div>
                </div>
                <div class="info-item">
                    <label>Treatment:</label>
                    <div id="view_treatment" style="white-space: pre-wrap; margin-top: 5px;"></div>
                </div>
                <div class="info-item">
                    <label>Prescription:</label>
                    <div id="view_prescription" style="white-space: pre-wrap; margin-top: 5px;"></div>
                </div>
                <div class="info-item">
                    <label>Additional Notes:</label>
                    <div id="view_notes" style="white-space: pre-wrap; margin-top: 5px;"></div>
                </div>
            </div>
        </div>
    </div>
</div>
    <script>
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
            <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
                <?php if (isset($_POST['update_profile']) || isset($_POST['change_password'])): ?>
                    // If profile was updated or password changed, stay on profile tab
                    showTab('profile');
                <?php elseif (isset($_POST['save_schedule']) || isset($_POST['add_exception'])): ?>
                    // If schedule was updated, stay on schedule tab
                    showTab('schedule');
                <?php else: ?>
                    // Otherwise, restore the last active tab or default to overview
                    const savedTab = localStorage.getItem('currentTab') || 'overview';
                    showTab(savedTab);
                <?php endif; ?>
            <?php else: ?>
                // Otherwise, restore the last active tab or default to overview
                const savedTab = localStorage.getItem('currentTab') || 'overview';
                showTab(savedTab);
            <?php endif; ?>
            
            // Auto-hide alerts
            autoHideAlerts();
        });
        
        // Show update appointment modal
        function showUpdateModal(appointmentId, status, notes) {
            document.getElementById('appointment_id').value = appointmentId;
            document.getElementById('status').value = status;
            document.getElementById('notes').value = notes;
            document.getElementById('updateAppointmentModal').style.display = 'block';
        }
        
        // Show add medical record modal
        function showRecordModal(patientId) {
            document.getElementById('patient_id').value = patientId;
            document.getElementById('addRecordModal').style.display = 'block';
        }
        
        // Show modal
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        // Hide modal
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Toggle exception times visibility
        function toggleExceptionTimes() {
            const checkbox = document.getElementById('is_unavailable');
            const timesDiv = document.getElementById('exceptionTimes');
            
            if (checkbox.checked) {
                timesDiv.style.display = 'none';
                // Clear the time inputs
                document.getElementById('exception_start').value = '';
                document.getElementById('exception_end').value = '';
            } else {
                timesDiv.style.display = 'block';
            }
        }
        
        // Delete schedule exception
        function deleteException(exceptionId) {
            if (confirm('Are you sure you want to remove this schedule exception?')) {
                // Create a form to submit the deletion
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_exception';
                input.value = exceptionId;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
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
        // View medical record details
        function viewRecord(recordId) {
            // Find the record data from the PHP array
            <?php if (!empty($medicalRecords)): ?>
            const records = <?php echo json_encode($medicalRecords); ?>;
            const record = records.find(r => r.id == recordId);
            
            if (record) {
                // Populate the modal with record data
                document.getElementById('view_patient_name').textContent = record.patient_name;
                document.getElementById('view_record_date').textContent = new Date(record.record_date).toLocaleDateString();
                document.getElementById('view_diagnosis').textContent = record.diagnosis;
                document.getElementById('view_treatment').textContent = record.treatment;
                document.getElementById('view_prescription').textContent = record.prescription || 'N/A';
                document.getElementById('view_notes').textContent = record.notes || 'No additional notes';
                
                // Show the modal
                showModal('viewRecordModal');
            }
            <?php endif; ?>
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
                icon.className = 'fas fa-user-md';
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
        
        // Validate schedule times
        function validateScheduleTimes() {
            const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            
            days.forEach(day => {
                const startInput = document.getElementById('start_' + day);
                const endInput = document.getElementById('end_' + day);
                
                if (startInput && endInput) {
                    startInput.addEventListener('change', function() {
                        validateDayTimes(day);
                    });
                    
                    endInput.addEventListener('change', function() {
                        validateDayTimes(day);
                    });
                }
            });
        }
        
        function validateDayTimes(day) {
            const startTime = document.getElementById('start_' + day).value;
            const endTime = document.getElementById('end_' + day).value;
            
            if (startTime && endTime && startTime >= endTime) {
                alert('End time must be after start time for ' + day.charAt(0).toUpperCase() + day.slice(1));
                document.getElementById('end_' + day).value = '';
            }
        }
        
        // Initialize validation when page loads
        document.addEventListener('DOMContentLoaded', function() {
            validateScheduleTimes();
        });
    </script>
</body>
</html>