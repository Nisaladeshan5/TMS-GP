<?php
// edit_vehicle.php - FULL UPDATED VERSION WITH FILTER PERSISTENCE
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

$vehicle_no = $_GET['vehicle_no'] ?? null;
// Kalin page eke thibba filter settings tika gannawa
$current_purpose = $_GET['purpose'] ?? 'staff';
$current_status = $_GET['status'] ?? 'active';

$message = null; 

// Notification check
if (isset($_GET['status'])) {
    $message = [
        'status' => $_GET['status'],
        'text' => $_GET['message'] ?? ($_GET['status'] === 'success' ? 'Operation successful!' : 'An error occurred.')
    ];
}

// --- Function to fetch vehicle data ---
function fetch_vehicle_data($conn, $vehicle_no) {
    $sql = "SELECT * FROM vehicle WHERE vehicle_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

// --- Function to log changes ---
function log_audit_change($conn, $user_id, $table, $record_id, $field, $old, $new) {
    if (trim((string)$old) === trim((string)$new)) return; 

    $sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) 
             VALUES (?, ?, 'UPDATE', ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    $old_str = (string)$old;
    $new_str = (string)$new;
    $stmt->bind_param('ssisss', $table, $record_id, $user_id, $field, $old_str, $new_str);
    
    if (!$stmt->execute()) {
        error_log("Audit Log Insertion Failed: " . $stmt->error);
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_no = $_POST['vehicle_no'];
    // Hidden fields walin ena kalin filter settings gannawa
    $last_purpose = $_POST['last_purpose'] ?? 'staff';
    $last_status = $_POST['last_status'] ?? 'active';

    $original_data = fetch_vehicle_data($conn, $vehicle_no);

    if (!$original_data) {
        header("Location: vehicle.php?status=error&message=" . urlencode("Vehicle not found."));
        exit();
    } else {
        try {
            $new_values = [
                'supplier_code' => $_POST['supplier_code'],
                'capacity' => (int)$_POST['capacity'],
                'standing_capacity' => (int)$_POST['standing_capacity'],
                'fuel_efficiency' => $_POST['fuel_efficiency'],
                'type' => $_POST['type'],
                'purpose' => $_POST['purpose'],
                'license_expiry_date' => $_POST['license_expiry_date'],
                'insurance_expiry_date' => $_POST['insurance_expiry_date'],
                'rate_id' => $_POST['rate_id'],
            ];

            $sql = "UPDATE vehicle 
                    SET supplier_code=?, capacity=?, standing_capacity=?, fuel_efficiency=?, type=?, purpose=?, 
                        license_expiry_date=?, insurance_expiry_date=?, rate_id=?
                    WHERE vehicle_no=?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('siisssssss', 
                $new_values['supplier_code'], 
                $new_values['capacity'], 
                $new_values['standing_capacity'], 
                $new_values['fuel_efficiency'], 
                $new_values['type'], 
                $new_values['purpose'],
                $new_values['license_expiry_date'], 
                $new_values['insurance_expiry_date'], 
                $new_values['rate_id'], 
                $vehicle_no
            );

            if ($stmt->execute()) {
                $user_id = $_SESSION['user_id'] ?? 0;
                foreach ($new_values as $field => $new_value) {
                    $old_value = $original_data[$field] ?? '';
                    log_audit_change($conn, $user_id, 'vehicle', $vehicle_no, $field, $old_value, $new_value);
                }
                
                // Success redirect with same filters
                header("Location: vehicle.php?status=success&message=" . urlencode("Vehicle updated successfully!") . "&purpose=$last_purpose&status=$last_status");
                exit();
            } else {
                $message = ['status' => 'error', 'text' => 'Database error: ' . $stmt->error];
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = ['status' => 'error', 'text' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Fetch data for form
if ($vehicle_no) {
    $vehicle_data = fetch_vehicle_data($conn, $vehicle_no);
    if (!$vehicle_data) {
        header("Location: vehicle.php?status=error&message=" . urlencode("Vehicle not found."));
        exit();
    }
}

// Dropdown data fetches
$suppliers = $conn->query("SELECT supplier_code, supplier FROM supplier ORDER BY supplier")->fetch_all(MYSQLI_ASSOC);
$fuel_efficiencies = $conn->query("SELECT c_id, c_type FROM consumption ORDER BY c_id")->fetch_all(MYSQLI_ASSOC);
$fuel_rates = $conn->query("SELECT rate_id, type FROM fuel_rate GROUP BY type ORDER BY type")->fetch_all(MYSQLI_ASSOC);

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle: <?php echo htmlspecialchars($vehicle_no); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1.5rem; right: 1.5rem; z-index: 9999; }
        .toast { 
            display: flex; align-items: center; padding: 1rem 1.5rem; border-radius: 0.5rem; 
            color: white; font-weight: 500; margin-bottom: 10px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transform: translateX(100%); transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        .toast.show { transform: translateX(0); }
        .toast.success { background-color: #10B981; border-left: 6px solid #065F46; }
        .toast.error { background-color: #EF4444; border-left: 6px solid #991B1B; }
        .toast-icon { margin-right: 0.75rem; font-size: 1.25rem; }
    </style>
</head>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%]">
    <div class="w-full p-8 bg-white shadow-lg rounded-lg mt-10 mx-auto max-w-4xl">
        
        <div class="mb-6 pb-2 border-b border-gray-200">
            <h1 class="text-3xl font-extrabold text-gray-800">Edit Vehicle Details</h1>
            <p class="text-lg text-gray-600">Updating: <span class="text-blue-600 font-bold"><?= htmlspecialchars($vehicle_data['vehicle_no']) ?></span></p>
        </div>

        <form id="editForm" method="POST" class="space-y-6">
            <input type="hidden" name="vehicle_no" value="<?= htmlspecialchars($vehicle_data['vehicle_no']) ?>">
            
            <input type="hidden" name="last_purpose" value="<?= htmlspecialchars($current_purpose) ?>">
            <input type="hidden" name="last_status" value="<?= htmlspecialchars($current_status) ?>">
            
            <div class="bg-gray-50 p-6 border border-gray-200 rounded-xl">
                <h4 class="text-xl font-bold mb-4 text-blue-700">General Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Supplier</label>
                        <select name="supplier_code" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= $supplier['supplier_code'] ?>" <?= ($supplier['supplier_code'] == $vehicle_data['supplier_code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($supplier['supplier']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Seating Capacity</label>
                        <input type="number" name="capacity" value="<?= htmlspecialchars($vehicle_data['capacity']) ?>" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Standing Capacity</label>
                        <input type="number" name="standing_capacity" value="<?= htmlspecialchars($vehicle_data['standing_capacity'] ?? 0) ?>" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Fuel Efficiency Category</label>
                        <select name="fuel_efficiency" class="w-full p-2.5 border border-gray-300 rounded-lg">
                            <?php foreach ($fuel_efficiencies as $fe): ?>
                                <option value="<?= $fe['c_id'] ?>" <?= ($fe['c_id'] == $vehicle_data['fuel_efficiency']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fe['c_type']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Vehicle Type</label>
                        <select name="type" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                            <?php $types = ['van', 'bus', 'car', 'wheel', 'motor bike', 'lorry', 'tractor']; ?>
                            <?php foreach ($types as $t): ?>
                                <option value="<?= $t ?>" <?= ($t == $vehicle_data['type']) ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Fuel Type</label>
                        <select name="rate_id" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                            <?php foreach ($fuel_rates as $rate): ?>
                                <option value="<?= $rate['rate_id'] ?>" <?= ($rate['rate_id'] == $vehicle_data['rate_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rate['type']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Purpose</label>
                        <select name="purpose" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                            <?php $purposes = ['staff', 'factory', 'held_up', 'night_emergency' , 'sub_route', 'extra' ]; ?>
                            <?php foreach ($purposes as $p): ?>
                                <option value="<?= $p ?>" <?= ($p == $vehicle_data['purpose']) ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', $p)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-50 p-6 border border-gray-200 rounded-xl">
                <h4 class="text-xl font-bold mb-4 text-blue-700">Documents</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">License Expiry Date</label>
                        <input type="date" name="license_expiry_date" value="<?= htmlspecialchars($vehicle_data['license_expiry_date']) ?>" class="w-full p-2.5 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Insurance Expiry Date</label>
                        <input type="date" name="insurance_expiry_date" value="<?= htmlspecialchars($vehicle_data['insurance_expiry_date']) ?>" class="w-full p-2.5 border border-gray-300 rounded-lg">
                    </div>
                </div>
            </div>

            <div class="flex justify-between space-x-4 pt-4">
                <a href="vehicle.php?purpose=<?= urlencode($current_purpose) ?>&status=<?= urlencode($current_status) ?>" 
                   class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-8 rounded-lg transition duration-200">
                   Cancel
                </a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-10 rounded-lg shadow-lg transition duration-200">
                    Update Vehicle
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function showToast(message, status) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${status}`;
        
        const icon = status === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        toast.innerHTML = `<i class="fas ${icon} toast-icon"></i><span>${message}</span>`;
        
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 100);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 500);
        }, 4000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const phpMessage = <?php echo json_encode($message); ?>;
        if (phpMessage && phpMessage.text) {
            showToast(phpMessage.text, phpMessage.status);
        }
    });
</script>

</body>
</html>