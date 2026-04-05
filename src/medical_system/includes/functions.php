<?php
// Start session
session_start();

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to check user role
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == $role;
}

// Function to redirect user
function redirect($url) {
    header("Location: $url");
    exit();
}

// Function to sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to display error message
function displayError($message) {
    return "<div class='alert alert-danger'>$message</div>";
}

// Function to display success message
function displaySuccess($message) {
    return "<div class='alert alert-success'>$message</div>";
}

// Function to generate random string
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

// Function to validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Function to check if email exists
function emailExists($email) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    $conn->close();
    return $exists;
}

// Function to get user by ID
function getUserById($id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $user;
}

// Function to get patient by user ID
function getPatientByUserId($userId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM patients WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $patient;
}

// Function to get doctor by user ID
function getDoctorByUserId($userId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $doctor = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $doctor;
}

// Function to get all doctors
function getAllDoctors() {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT d.*, u.name, u.email, u.phone 
        FROM doctors d
        JOIN users u ON d.user_id = u.id
        WHERE u.role = 'doctor'
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $doctors = [];
    while ($row = $result->fetch_assoc()) {
        $doctors[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $doctors;
}

// Function to get all patients
function getAllPatients() {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT p.*, u.name, u.email, u.phone 
        FROM patients p
        JOIN users u ON p.user_id = u.id
        WHERE u.role = 'patient'
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = [];
    while ($row = $result->fetch_assoc()) {
        $patients[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $patients;
}

// Function to get patient appointments
function getPatientAppointments($patientId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT a.*, d.specialty, u.name as doctor_name
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $appointments;
}

// Function to get doctor appointments
function getDoctorAppointments($doctorId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT a.*, p.id as patient_id, u.name as patient_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE a.doctor_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $appointments;
}

// Function to get patient medical records
function getPatientMedicalRecords($patientId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT mr.*, u.name as doctor_name
        FROM medical_records mr
        JOIN doctors d ON mr.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE mr.patient_id = ?
        ORDER BY mr.record_date DESC
    ");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $records;
}

function getDoctorPatients($doctorId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT DISTINCT p.id, u.name, u.email, u.phone, p.gender 
        FROM patients p
        JOIN users u ON p.user_id = u.id
        JOIN appointments a ON a.patient_id = p.id
        WHERE a.doctor_id = ?
        ORDER BY u.name
    ");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $patients;
}

// Function to get patient prescriptions
function getPatientPrescriptions($patientId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT p.*, u.name as doctor_name
        FROM prescriptions p
        JOIN doctors d ON p.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE p.patient_id = ?
        ORDER BY p.prescription_date DESC
    ");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $prescriptions = [];
    while ($row = $result->fetch_assoc()) {
        $prescriptions[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $prescriptions;
}

// ========================= SCHEDULE MANAGEMENT FUNCTIONS =========================

/**
 * Get doctor's weekly schedule
 */
function getDoctorSchedule($doctorId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM doctor_schedules WHERE doctor_id = ? AND is_active = TRUE ORDER BY FIELD(day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday')");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedule = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $schedule;
}

/**
 * Save or update doctor's schedule for a specific day
 */
function saveDoctorSchedule($doctorId, $dayOfWeek, $startTime, $endTime, $slotDuration = 15) {
    $conn = getConnection();
    
    // Check if schedule exists for this day
    $stmt = $conn->prepare("SELECT id FROM doctor_schedules WHERE doctor_id = ? AND day_of_week = ?");
    $stmt->bind_param("is", $doctorId, $dayOfWeek);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing schedule
        $stmt = $conn->prepare("UPDATE doctor_schedules SET start_time = ?, end_time = ?, slot_duration = ?, updated_at = CURRENT_TIMESTAMP WHERE doctor_id = ? AND day_of_week = ?");
        $stmt->bind_param("ssiis", $startTime, $endTime, $slotDuration, $doctorId, $dayOfWeek);
    } else {
        // Insert new schedule
        $stmt = $conn->prepare("INSERT INTO doctor_schedules (doctor_id, day_of_week, start_time, end_time, slot_duration) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $doctorId, $dayOfWeek, $startTime, $endTime, $slotDuration);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

/**
 * Delete doctor's schedule for a specific day
 */
function deleteDoctorSchedule($doctorId, $dayOfWeek) {
    $conn = getConnection();
    $stmt = $conn->prepare("UPDATE doctor_schedules SET is_active = FALSE WHERE doctor_id = ? AND day_of_week = ?");
    $stmt->bind_param("is", $doctorId, $dayOfWeek);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

/**
 * Get available time slots for a doctor on a specific date
 */
function getAvailableTimeSlots($doctorId, $date) {
    $conn = getConnection();
    $dayOfWeek = strtolower(date('l', strtotime($date)));
    
    // Get doctor's schedule for the day
    $stmt = $conn->prepare("SELECT start_time, end_time, slot_duration FROM doctor_schedules WHERE doctor_id = ? AND day_of_week = ? AND is_active = TRUE");
    $stmt->bind_param("is", $doctorId, $dayOfWeek);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $stmt->close();
        $conn->close();
        return []; // No schedule for this day
    }
    
    $schedule = $result->fetch_assoc();
    $startTime = $schedule['start_time'];
    $endTime = $schedule['end_time'];
    $slotDuration = $schedule['slot_duration'];
    
    // Check for schedule exceptions on this date
    $stmt = $conn->prepare("SELECT * FROM doctor_schedule_exceptions WHERE doctor_id = ? AND exception_date = ?");
    $stmt->bind_param("is", $doctorId, $date);
    $stmt->execute();
    $exceptionResult = $stmt->get_result();
    
    if ($exceptionResult->num_rows > 0) {
        $exception = $exceptionResult->fetch_assoc();
        if ($exception['is_unavailable']) {
            $stmt->close();
            $conn->close();
            return []; // Doctor is unavailable on this date
        } else {
            // Use exception times
            $startTime = $exception['start_time'];
            $endTime = $exception['end_time'];
        }
    }
    
    // Get already booked appointments for this date
    $stmt = $conn->prepare("SELECT appointment_time, duration FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status IN ('pending', 'confirmed')");
    $stmt->bind_param("is", $doctorId, $date);
    $stmt->execute();
    $bookedResult = $stmt->get_result();
    $bookedSlots = [];
    
    while ($booking = $bookedResult->fetch_assoc()) {
        $bookedTime = strtotime($booking['appointment_time']);
        $duration = $booking['duration'] ?? 15;
        
        // Mark all affected slots as booked
        for ($i = 0; $i < $duration; $i += $slotDuration) {
            $bookedSlots[] = date('H:i:s', $bookedTime + ($i * 60));
        }
    }
    
    // Generate available slots
    $availableSlots = [];
    $currentTime = strtotime($startTime);
    $endTimeStamp = strtotime($endTime);
    
    while ($currentTime < $endTimeStamp) {
        $timeSlot = date('H:i:s', $currentTime);
        
        // Check if this slot is available
        if (!in_array($timeSlot, $bookedSlots)) {
            $availableSlots[] = [
                'time' => $timeSlot,
                'formatted_time' => date('g:i A', $currentTime)
            ];
        }
        
        $currentTime += ($slotDuration * 60); // Add slot duration in seconds
    }
    
    $stmt->close();
    $conn->close();
    return $availableSlots;
}

/**
 * Add schedule exception (holiday, vacation, etc.)
 */
function addScheduleException($doctorId, $date, $reason, $isUnavailable = true, $startTime = null, $endTime = null) {
    $conn = getConnection();
    $stmt = $conn->prepare("INSERT INTO doctor_schedule_exceptions (doctor_id, exception_date, start_time, end_time, reason, is_unavailable) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), reason = VALUES(reason), is_unavailable = VALUES(is_unavailable)");
    $stmt->bind_param("issssi", $doctorId, $date, $startTime, $endTime, $reason, $isUnavailable);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $success;
}

/**
 * Get doctor's schedule exceptions
 */
function getDoctorScheduleExceptions($doctorId, $startDate = null, $endDate = null) {
    $conn = getConnection();
    
    if ($startDate && $endDate) {
        $stmt = $conn->prepare("SELECT * FROM doctor_schedule_exceptions WHERE doctor_id = ? AND exception_date BETWEEN ? AND ? ORDER BY exception_date");
        $stmt->bind_param("iss", $doctorId, $startDate, $endDate);
    } else {
        $stmt = $conn->prepare("SELECT * FROM doctor_schedule_exceptions WHERE doctor_id = ? ORDER BY exception_date");
        $stmt->bind_param("i", $doctorId);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $exceptions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $exceptions;
}

/**
 * Check if a time slot is available
 */
function isTimeSlotAvailable($doctorId, $date, $time, $duration = 15) {
    $availableSlots = getAvailableTimeSlots($doctorId, $date);
    
    foreach ($availableSlots as $slot) {
        if ($slot['time'] == $time) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get available days for a doctor based on their schedule
 */
function getDoctorAvailableDays($doctorId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT day_of_week FROM doctor_schedules WHERE doctor_id = ? AND is_active = TRUE");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $days = [];
    while ($row = $result->fetch_assoc()) {
        $days[] = $row['day_of_week'];
    }
    $stmt->close();
    $conn->close();
    return $days;
}

/**
 * Check if a doctor is available on a specific date
 */
function isDoctorAvailableOnDate($doctorId, $date) {
    $dayOfWeek = strtolower(date('l', strtotime($date)));
    $availableDays = getDoctorAvailableDays($doctorId);
    
    if (!in_array($dayOfWeek, $availableDays)) {
        return false;
    }
    
    // Check for exceptions on this date
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT is_unavailable FROM doctor_schedule_exceptions WHERE doctor_id = ? AND exception_date = ?");
    $stmt->bind_param("is", $doctorId, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $exception = $result->fetch_assoc();
        if ($exception['is_unavailable']) {
            $stmt->close();
            $conn->close();
            return false;
        }
    }
    
    $stmt->close();
    $conn->close();
    return true;
}

/**
 * Check if appointment time conflicts with existing appointments
 */
function hasTimeConflict($doctorId, $date, $time, $duration, $excludeAppointmentId = null) {
    $conn = getConnection();
    
    $appointmentStart = strtotime("$date $time");
    $appointmentEnd = $appointmentStart + ($duration * 60);
    
    $sql = "SELECT appointment_time, duration FROM appointments 
            WHERE doctor_id = ? AND appointment_date = ? AND status IN ('pending', 'confirmed')";
    
    if ($excludeAppointmentId) {
        $sql .= " AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isi", $doctorId, $date, $excludeAppointmentId);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $doctorId, $date);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($existing = $result->fetch_assoc()) {
        $existingStart = strtotime("$date {$existing['appointment_time']}");
        $existingEnd = $existingStart + (($existing['duration'] ?? 15) * 60);
        
        // Check for overlap
        if (($appointmentStart < $existingEnd) && ($appointmentEnd > $existingStart)) {
            $stmt->close();
            $conn->close();
            return true; // Conflict found
        }
    }
    
    $stmt->close();
    $conn->close();
    return false; // No conflict
}

/**
 * Validate appointment booking data
 */
function validateAppointmentBooking($doctorId, $patientId, $date, $time, $duration = 15) {
    $errors = [];
    
    // Check if doctor exists and is active
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT u.role FROM users u JOIN doctors d ON u.id = d.user_id WHERE d.id = ?");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $errors[] = "Invalid doctor selected.";
    }
    
    // Check if patient exists
    $stmt = $conn->prepare("SELECT id FROM patients WHERE id = ?");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $errors[] = "Invalid patient.";
    }
    
    $stmt->close();
    $conn->close();
    
    // Check if date is not in the past
    if (strtotime($date) < strtotime(date('Y-m-d'))) {
        $errors[] = "Cannot book appointments in the past.";
    }
    
    // Check if doctor is available on this date
    if (!isDoctorAvailableOnDate($doctorId, $date)) {
        $errors[] = "Doctor is not available on the selected date.";
    }
    
    // Check if time slot is available
    if (!isTimeSlotAvailable($doctorId, $date, $time, $duration)) {
        $errors[] = "Selected time slot is not available.";
    }
    
    // Check for time conflicts
    if (hasTimeConflict($doctorId, $date, $time, $duration)) {
        $errors[] = "Selected time conflicts with existing appointment.";
    }
    
    return $errors;
}

/**
 * Log system activity (placeholder function)
 */
function logActivity($userId, $action, $details = '') {
    // This is a placeholder function for logging system activities
    // You could implement this to track user actions, appointment bookings, etc.
    return true;
}

/**
 * Cancel appointment and send notification
 */
function cancelAppointment($appointmentId, $reason = '') {
    $conn = getConnection();
    
    // Update appointment status
    $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\nCancellation reason: ', ?) WHERE id = ?");
    $stmt->bind_param("si", $reason, $appointmentId);
    $success = $stmt->execute();
    
    if ($success) {
        // Log the cancellation
        $stmt = $conn->prepare("SELECT patient_id, doctor_id FROM appointments WHERE id = ?");
        $stmt->bind_param("i", $appointmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $appointment = $result->fetch_assoc();
        
        if ($appointment) {
            logActivity($appointment['patient_id'], 'appointment_cancelled', "Appointment ID: $appointmentId");
        }
    }
    
    $stmt->close();
    $conn->close();
    return $success;
}
function getDoctorMedicalRecords($doctorId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT mr.*, u.name as patient_name 
        FROM medical_records mr 
        JOIN patients p ON mr.patient_id = p.id 
        JOIN users u ON p.user_id = u.id 
        WHERE mr.doctor_id = ? 
        ORDER BY mr.record_date DESC
    ");
    $stmt->bind_param("i", $doctorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $records;
}
?>