<?php
// Include database configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('login.php');
}

// Check if user is an admin
if (!hasRole('admin')) {
    redirect('login.php');
}

// Get all users
$conn = getConnection();
$stmt = $conn->prepare("SELECT * FROM users ORDER BY role, name");
$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Get all doctors
$doctors = getAllDoctors();

// Get all patients
$patients = getAllPatients();

// Get all appointments
$stmt = $conn->prepare("
    SELECT a.*, p.id as patient_id, d.id as doctor_id, 
           u1.name as patient_name, u2.name as doctor_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN doctors d ON a.doctor_id = d.id
    JOIN users u1 ON p.user_id = u1.id
    JOIN users u2 ON d.user_id = u2.id
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$stmt->execute();
$result = $stmt->get_result();
$appointments = [];
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}

// Process user deletion
$deleteSuccess = '';
$deleteError = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {
    $userId = sanitizeInput($_POST['user_id']);
    
    // Delete user
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    
    if ($stmt->execute()) {
        $deleteSuccess = "User deleted successfully!";
        // Refresh users list
        $stmt = $conn->prepare("SELECT * FROM users ORDER BY role, name");
        $stmt->execute();
        $result = $stmt->get_result();
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    } else {
        $deleteError = "Failed to delete user. Please try again.";
    }
}

// Reports functionality
$reportData = [];
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : '';
$selectedDay = isset($_GET['day']) ? $_GET['day'] : '';

// Function to generate reports
function generateReport($conn, $filterType = '', $filterValue = '') {
    $whereClause = '';
    $params = [];
    $types = '';
    
    if ($filterType === 'month' && !empty($filterValue)) {
        $whereClause = " WHERE DATE_FORMAT(a.appointment_date, '%Y-%m') = ?";
        $params[] = $filterValue;
        $types .= 's';
    } elseif ($filterType === 'day' && !empty($filterValue)) {
        $whereClause = " WHERE a.appointment_date = ?";
        $params[] = $filterValue;
        $types .= 's';
    }
    
    // Get appointment statistics
    $appointmentStats = [];
    $sql = "SELECT 
                COUNT(*) as total_appointments,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_appointments,
                COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_appointments,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_appointments,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_appointments
            FROM appointments a" . $whereClause;
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $appointmentStats = $stmt->get_result()->fetch_assoc();
    
    // Get doctor statistics
    $doctorStats = [];
    $sql = "SELECT 
                u.name as doctor_name,
                d.specialty,
                COUNT(a.id) as total_appointments,
                COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed_appointments
            FROM doctors d
            JOIN users u ON d.user_id = u.id
            LEFT JOIN appointments a ON d.id = a.doctor_id" . $whereClause . "
            GROUP BY d.id, u.name, d.specialty
            ORDER BY total_appointments DESC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $doctorStats[] = $row;
    }
    
    // Get patient statistics
    $patientStats = [];
    $sql = "SELECT 
                u.name as patient_name,
                p.gender,
                COUNT(a.id) as total_appointments,
                MAX(a.appointment_date) as last_appointment
            FROM patients p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN appointments a ON p.id = a.patient_id" . $whereClause . "
            GROUP BY p.id, u.name, p.gender
            HAVING total_appointments > 0
            ORDER BY total_appointments DESC";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $patientStats[] = $row;
    }
    
    // Get monthly trend data (for charts)
    $monthlyTrend = [];
    $sql = "SELECT 
                DATE_FORMAT(appointment_date, '%Y-%m') as month,
                COUNT(*) as appointments_count,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count
            FROM appointments a
            WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
            ORDER BY month";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $monthlyTrend[] = $row;
    }
    
    return [
        'appointments' => $appointmentStats,
        'doctors' => $doctorStats,
        'patients' => $patientStats,
        'monthly_trend' => $monthlyTrend
    ];
}

// Generate report based on filters
if (isset($_GET['generate_report'])) {
    if (!empty($selectedMonth)) {
        $reportData = generateReport($conn, 'month', $selectedMonth);
    } elseif (!empty($selectedDay)) {
        $reportData = generateReport($conn, 'day', $selectedDay);
    } else {
        $reportData = generateReport($conn);
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MedPatient</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            max-width: 400px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0,123,255,.25);
        }

        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .report-filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            gap: 15px;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .filter-item {
            display: flex;
            flex-direction: column;
        }
        
        .filter-item label {
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        
        .filter-item input,
        .filter-item select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .btn-generate {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-generate:hover {
            background: #0056b3;
        }
        
        .report-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .report-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .report-card .number {
            font-size: 2em;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }
        
        .report-card .label {
            color: #666;
            font-size: 0.9em;
        }
        
        .report-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .report-section h3 {
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 5px;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 40px;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-completed { background: #d1ecf1; color: #0c5460; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: #f5f5f5;
        }
        .password-container {
            position: relative;
            max-width: 400px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 16px;
        }

        .password-toggle:hover {
            color: #007bff;
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
                        <a href="#users" onclick="showTab('users')">
                            <i class="fas fa-users"></i>
                            <span>Users</span>
                        </a>
                    </li>
                    <li>
                        <a href="#doctors" onclick="showTab('doctors')">
                            <i class="fas fa-user-md"></i>
                            <span>Doctors</span>
                        </a>
                    </li>
                    <li>
                        <a href="#patients" onclick="showTab('patients')">
                            <i class="fas fa-user-injured"></i>
                            <span>Patients</span>
                        </a>
                    </li>
                    <li>
                        <a href="#appointments" onclick="showTab('appointments')">
                            <i class="fas fa-calendar-check"></i>
                            <span>Appointments</span>
                        </a>
                    </li>
                    <li>
                        <a href="#reports" onclick="showTab('reports')">
                            <i class="fas fa-chart-bar"></i>
                            <span>Reports</span>
                        </a>
                    </li>
                    <li>
                        <a href="#profile" onclick="showTab('profile')">
                            <i class="fas fa-user-cog"></i>
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
                    <h1>Admin Dashboard</h1>
                </div>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
                    <div class="user-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
            </div>
            
            <div class="content">
                <!-- Overview Tab -->
                <div id="overview" class="tab-content active">
                    <div class="dashboard-cards">
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="card-info">
                                <h3>Total Users</h3>
                                <p class="count"><?php echo count($users); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-user-md"></i>
                            </div>
                            <div class="card-info">
                                <h3>Doctors</h3>
                                <p class="count"><?php echo count($doctors); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-user-injured"></i>
                            </div>
                            <div class="card-info">
                                <h3>Patients</h3>
                                <p class="count"><?php echo count($patients); ?></p>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="card-info">
                                <h3>Appointments</h3>
                                <p class="count"><?php echo count($appointments); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="recent-activity">
                        <h2>Recent Appointments</h2>
                        <?php if (empty($appointments)): ?>
                            <p>No appointments found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Doctor</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $recentAppointments = array_slice($appointments, 0, 5);
                                        foreach ($recentAppointments as $appointment): 
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                                <td><?php echo htmlspecialchars($appointment['doctor_name']); ?></td>
                                                <td><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></td>
                                                <td><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></td>
                                                <td><span class="status <?php echo strtolower($appointment['status']); ?>"><?php echo ucfirst($appointment['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Users Tab -->
                <div id="users" class="tab-content">
                    <div class="section-header">
                        <h2>All Users</h2>
                    </div>
                    
                    <?php if(isset($deleteSuccess) && !empty($deleteSuccess)): ?>
                        <div class="alert alert-success"><?php echo $deleteSuccess; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($deleteError) && !empty($deleteError)): ?>
                        <div class="alert alert-danger"><?php echo $deleteError; ?></div>
                    <?php endif; ?>
                    
                    <div class="users-list">
                        <?php if (empty($users)): ?>
                            <p>No users found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td><span class="role <?php echo strtolower($user['role']); ?>"><?php echo ucfirst($user['role']); ?></span></td>
                                                <td><?php echo date('F j, Y', strtotime($user['created_at'])); ?></td>
                                                <td>
                                                    <?php if ($user['id'] != ($_SESSION['user_id'] ?? 0)): ?>
                                                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" style="display: inline;">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="delete_user" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this user?')">Delete</button>
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
                
                <!-- Doctors Tab -->
                <div id="doctors" class="tab-content">
                    <div class="section-header">
                        <h2>All Doctors</h2>
                    </div>
                    
                    <div class="doctors-list">
                        <?php if (empty($doctors)): ?>
                            <p>No doctors found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Specialty</th>
                                            <th>License Number</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($doctors as $doctor): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($doctor['name']); ?></td>
                                                <td><?php echo htmlspecialchars($doctor['email']); ?></td>
                                                <td><?php echo htmlspecialchars($doctor['phone'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($doctor['specialty'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($doctor['license_number'] ?? 'N/A'); ?></td>
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
                        <h2>All Patients</h2>
                    </div>
                    
                    <div class="patients-list">
                        <?php if (empty($patients)): ?>
                            <p>No patients found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Gender</th>
                                            <th>Date of Birth</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($patients as $patient): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($patient['name']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['email']); ?></td>
                                                <td><?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></td>
                                                <td><?php echo ucfirst(htmlspecialchars($patient['gender'] ?? 'N/A')); ?></td>
                                                <td><?php echo isset($patient['dob']) ? date('F j, Y', strtotime($patient['dob'])) : 'N/A'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Appointments Tab -->
                <div id="appointments" class="tab-content">
                    <div class="section-header">
                        <h2>All Appointments</h2>
                    </div>
                    
                    <div class="appointments-list">
                        <?php if (empty($appointments)): ?>
                            <p>No appointments found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Doctor</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Status</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($appointments as $appointment): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($appointment['patient_name']); ?></td>
                                                <td><?php echo htmlspecialchars($appointment['doctor_name']); ?></td>
                                                <td><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></td>
                                                <td><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></td>
                                                <td><span class="status <?php echo strtolower($appointment['status']); ?>"><?php echo ucfirst($appointment['status']); ?></span></td>
                                                <td><?php echo htmlspecialchars($appointment['reason'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Reports Tab -->
                <div id="reports" class="tab-content">
                    <div class="section-header">
                        <h2>System Reports</h2>
                    </div>
                    
                    <!-- Report Filters -->
                    <div class="report-filters">
                        <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="filter-group">
                                <div class="filter-item">
                                    <label for="month">Filter by Month:</label>
                                    <input type="month" id="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                                </div>
                                <div class="filter-item">
                                    <label for="day">Or Filter by Day:</label>
                                    <input type="date" id="day" name="day" value="<?php echo htmlspecialchars($selectedDay); ?>">
                                </div>
                                <div class="filter-item">
                                    <button type="submit" name="generate_report" value="1" class="btn-generate">
                                        <i class="fas fa-chart-line"></i> Generate Report
                                    </button>
                                </div>
                                <div class="filter-item">
                                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?generate_report=1" class="btn-generate">
                                        <i class="fas fa-eye"></i> View All Time
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <?php if (isset($_GET['generate_report'])): ?>
                        <?php if (!empty($reportData) && !empty($reportData['appointments'])): ?>
                            <!-- Report Summary Cards -->
                            <div class="report-cards">
                                <div class="report-card">
                                    <div class="number"><?php echo $reportData['appointments']['total_appointments'] ?? 0; ?></div>
                                    <div class="label">Total Appointments</div>
                                </div>
                                <div class="report-card">
                                    <div class="number"><?php echo $reportData['appointments']['completed_appointments'] ?? 0; ?></div>
                                    <div class="label">Completed</div>
                                </div>
                                <div class="report-card">
                                    <div class="number"><?php echo $reportData['appointments']['pending_appointments'] ?? 0; ?></div>
                                    <div class="label">Pending</div>
                                </div>
                                <div class="report-card">
                                    <div class="number"><?php echo $reportData['appointments']['cancelled_appointments'] ?? 0; ?></div>
                                    <div class="label">Cancelled</div>
                                </div>
                            </div>
                            
                            <!-- Monthly Trend Chart -->
                            <?php if (!empty($reportData['monthly_trend'])): ?>
                                <div class="report-section">
                                    <h3><i class="fas fa-chart-line"></i> Monthly Appointments Trend</h3>
                                    <div class="chart-container">
                                        <canvas id="monthlyTrendChart"></canvas>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Doctor Performance -->
                            <div class="report-section">
                                <h3><i class="fas fa-user-md"></i> Doctor Performance</h3>
                                <?php if (!empty($reportData['doctors'])): ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Doctor Name</th>
                                                    <th>Specialty</th>
                                                    <th>Total Appointments</th>
                                                    <th>Completed Appointments</th>
                                                    <th>Completion Rate</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reportData['doctors'] as $doctor): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($doctor['doctor_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($doctor['specialty'] ?? 'N/A'); ?></td>
                                                        <td><?php echo $doctor['total_appointments']; ?></td>
                                                        <td><?php echo $doctor['completed_appointments']; ?></td>
                                                        <td>
                                                            <?php 
                                                            $rate = $doctor['total_appointments'] > 0 
                                                                ? round(($doctor['completed_appointments'] / $doctor['total_appointments']) * 100, 1) 
                                                                : 0;
                                                            echo $rate . '%';
                                                            ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="no-data">No doctor data available for the selected period.</div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Patient Activity -->
                            <div class="report-section">
                                <h3><i class="fas fa-user-injured"></i> Most Active Patients</h3>
                                <?php if (!empty($reportData['patients'])): ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Patient Name</th>
                                                    <th>Gender</th>
                                                    <th>Total Appointments</th>
                                                    <th>Last Appointment</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($reportData['patients'], 0, 10) as $patient): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($patient['patient_name']); ?></td>
                                                        <td><?php echo ucfirst(htmlspecialchars($patient['gender'] ?? 'N/A')); ?></td>
                                                        <td><?php echo $patient['total_appointments']; ?></td>
                                                        <td><?php echo $patient['last_appointment'] ? date('F j, Y', strtotime($patient['last_appointment'])) : 'N/A'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="no-data">No patient data available for the selected period.</div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Appointment Status Distribution -->
                            <div class="report-section">
                                <h3><i class="fas fa-chart-pie"></i> Appointment Status Distribution</h3>
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-chart-bar" style="font-size: 3em; color: #ccc; margin-bottom: 15px;"></i>
                                <p>No data available for the selected filters.</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-chart-bar" style="font-size: 3em; color: #ccc; margin-bottom: 15px;"></i>
                            <p>Select a filter and click "Generate Report" to view analytics.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Profile Tab -->
                <!-- Profile Tab -->
                <div id="profile" class="tab-content">
                    <div class="section-header">
                        <h2>Admin Profile</h2>
                    </div>
                    
                    <?php if(isset($_POST['change_password'])): ?>
                        <?php
                        $oldPassword = $_POST['old_password'];
                        $newPassword = $_POST['new_password'];
                        $confirmPassword = $_POST['confirm_password'];
                        
                        if($newPassword !== $confirmPassword) {
                            echo '<div class="alert alert-danger">New passwords do not match!</div>';
                        } else {
                            $conn = getConnection();
                            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                            $stmt->bind_param("i", $_SESSION['user_id']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $user = $result->fetch_assoc();
                            
                            if(password_verify($oldPassword, $user['password'])) {
                                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                                $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                                $updateStmt->bind_param("si", $hashedPassword, $_SESSION['user_id']);
                                
                                if($updateStmt->execute()) {
                                    echo '<div class="alert alert-success">Password changed successfully!</div>';
                                } else {
                                    echo '<div class="alert alert-danger">Error updating password!</div>';
                                }
                                $updateStmt->close();
                            } else {
                                echo '<div class="alert alert-danger">Current password is incorrect!</div>';
                            }
                            $stmt->close();
                            $conn->close();
                        }
                        ?>
                    <?php endif; ?>
                    
                    <div class="report-section">
                        <h3><i class="fas fa-key"></i> Change Password</h3>
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="form-group">
                                <label for="old_password">Current Password:</label>
                                <div class="password-container">
                                    <input type="password" id="old_password" name="old_password" required class="form-control">
                                    <i class="fas fa-eye password-toggle" onclick="togglePassword('old_password')"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="new_password">New Password:</label>
                                <div class="password-container">
                                    <input type="password" id="new_password" name="new_password" required class="form-control" minlength="6">
                                    <i class="fas fa-eye password-toggle" onclick="togglePassword('new_password')"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password:</label>
                                <div class="password-container">
                                    <input type="password" id="confirm_password" name="confirm_password" required class="form-control" minlength="6">
                                    <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password')"></i>
                                </div>
                            </div>
                            <button type="submit" name="change_password" class="btn-generate">
                                <i class="fas fa-save"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>    
            </div>
        </div>
    </div>
    
    <script>
        // Auto-show profile tab if password was changed
        if (window.location.search.includes('change_password') || 
            document.querySelector('.alert')) {
            showTab('profile');
        }
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
            const activeMenuItem = document.querySelector(`.sidebar-menu a[href="#${tabId}"]`);
            if (activeMenuItem) {
                activeMenuItem.parentElement.classList.add('active');
            }
        }
        
        // Chart configurations
        <?php if (isset($_GET['generate_report']) && !empty($reportData)): ?>
            
            // Monthly Trend Chart
            <?php if (!empty($reportData['monthly_trend'])): ?>
            const monthlyTrendCtx = document.getElementById('monthlyTrendChart');
            if (monthlyTrendCtx) {
                new Chart(monthlyTrendCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php foreach ($reportData['monthly_trend'] as $trend): ?>
                                '<?php echo date('M Y', strtotime($trend['month'] . '-01')); ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [{
                            label: 'Total Appointments',
                            data: [
                                <?php foreach ($reportData['monthly_trend'] as $trend): ?>
                                    <?php echo $trend['appointments_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: '#007bff',
                            backgroundColor: 'rgba(0, 123, 255, 0.1)',
                            tension: 0.4,
                            fill: false
                        }, {
                            label: 'Completed Appointments',
                            data: [
                                <?php foreach ($reportData['monthly_trend'] as $trend): ?>
                                    <?php echo $trend['completed_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4,
                            fill: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            }
                        }
                    }
                });
            }
            <?php endif; ?>
            
            // Status Distribution Chart
            const statusCtx = document.getElementById('statusChart');
            if (statusCtx) {
                const totalAppointments = <?php echo $reportData['appointments']['total_appointments']; ?>;
                if (totalAppointments > 0) {
                    new Chart(statusCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Completed', 'Pending', 'Confirmed', 'Cancelled'],
                            datasets: [{
                                data: [
                                    <?php echo $reportData['appointments']['completed_appointments']; ?>,
                                    <?php echo $reportData['appointments']['pending_appointments']; ?>,
                                    <?php echo $reportData['appointments']['confirmed_appointments']; ?>,
                                    <?php echo $reportData['appointments']['cancelled_appointments']; ?>
                                ],
                                backgroundColor: [
                                    '#28a745',
                                    '#ffc107',
                                    '#17a2b8',
                                    '#dc3545'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                } else {
                    statusCtx.getContext('2d').fillText('No data available', 10, 50);
                }
            }
            
        <?php endif; ?>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling;
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        // Filter functionality
        document.addEventListener('DOMContentLoaded', function() {
            const monthInput = document.getElementById('month');
            const dayInput = document.getElementById('day');
            
            if (monthInput && dayInput) {
                monthInput.addEventListener('change', function() {
                    if (this.value) {
                        dayInput.value = '';
                    }
                });
                
                dayInput.addEventListener('change', function() {
                    if (this.value) {
                        monthInput.value = '';
                    }
                });
            }
        });
        
        // Set active tab based on URL hash
        // Set active tab based on URL hash or if reports were generated
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash;
            const urlParams = new URLSearchParams(window.location.search);
            
            if (urlParams.has('generate_report')) {
                // If report was generated, show reports tab
                showTab('reports');
            } else if (hash) {
                const tabId = hash.substring(1);
                showTab(tabId);
            }
        });

    </script>
</body>
</html>