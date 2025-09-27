<?php
include('../../includes/db.php');

// Define a flag for AJAX requests
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- API MODE (AJAX requests) ---

if (isset($_GET['view_vehicle_no'])) {
    if (!$is_ajax) {
        http_response_code(403);
        exit();
    }
    header('Content-Type: application/json');
    $vehicle_no = $_GET['view_vehicle_no'];
    $sql = "SELECT vehicle.vehicle_no, vehicle.supplier_code, supplier.supplier, vehicle.capacity, vehicle.km_per_liter, vehicle.type, vehicle.purpose, vehicle.license_expiry_date, vehicle.insurance_expiry_date, vehicle.is_active, fr.type AS fuel_type, fr.rate_id FROM vehicle LEFT JOIN supplier ON vehicle.supplier_code = supplier.supplier_code LEFT JOIN fuel_rate AS fr ON vehicle.rate_id = fr.rate_id WHERE vehicle.vehicle_no = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $vehicle_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $vehicle = $result->fetch_assoc();
    echo json_encode($vehicle ?: null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    if (!$is_ajax) {
        http_response_code(403);
        exit();
    }
    header('Content-Type: application/json');

    try {
        $vehicle_no = $_POST['vehicle_no'];
        $supplier_code = $_POST['supplier_code'];
        $capacity = (int)$_POST['capacity'];
        $km_per_liter = $_POST['km_per_liter'];
        $type = $_POST['type'];
        $purpose = $_POST['purpose'];
        $license_expiry_date = $_POST['license_expiry_date'];
        $insurance_expiry_date = $_POST['insurance_expiry_date'];
        $rate_id = $_POST['rate_id'];

        $sql = "UPDATE vehicle 
                SET supplier_code=?, capacity=?, km_per_liter=?, type=?, purpose=?, 
                    license_expiry_date=?, insurance_expiry_date=?, rate_id=?
                WHERE vehicle_no=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sisssssss', $supplier_code, $capacity, $km_per_liter, $type, $purpose,
                                     $license_expiry_date, $insurance_expiry_date, $rate_id, $vehicle_no);

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Vehicle updated successfully!']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Database error: '.$stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    if (!$is_ajax) {
        http_response_code(403);
        exit();
    }
    header('Content-Type: application/json');

    try {
        $vehicle_no = $_POST['vehicle_no'];
        $new_status = (int)$_POST['is_active'];

        $sql = "UPDATE vehicle SET is_active = ? WHERE vehicle_no = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $new_status, $vehicle_no);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Vehicle status updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- NORMAL PAGE LOAD (HTML) ---
include('../../includes/header.php');
include('../../includes/navbar.php');

// Fetch all suppliers for the dropdown
$suppliers_sql = "SELECT supplier_code, supplier FROM supplier ORDER BY supplier";
$suppliers_result = $conn->query($suppliers_sql);
$suppliers = [];
if ($suppliers_result->num_rows > 0) {
    while ($row = $suppliers_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Fetch all fuel rates for the dropdown
$fuel_rates_sql = "SELECT rate_id, type FROM fuel_rate ORDER BY type";
$fuel_rates_result = $conn->query($fuel_rates_sql);
$fuel_rates = [];
if ($fuel_rates_result->num_rows > 0) {
    while ($row = $fuel_rates_result->fetch_assoc()) {
        $fuel_rates[] = $row;
    }
}

$purpose_filter = $_GET['purpose'] ?? 'staff';
$status_filter = $_GET['status'] ?? 'active';

$sql = "SELECT vehicle.*, supplier.supplier FROM vehicle LEFT JOIN supplier ON vehicle.supplier_code = supplier.supplier_code WHERE vehicle.purpose=?";
$types = "s";
$params = [$purpose_filter];

if ($status_filter === 'active') {
    $sql .= " AND vehicle.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND vehicle.is_active = 0";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$vehicles_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* CSS for modals and toast notifications */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 600px;
            position: relative;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
        }

        .toast {
            display: none;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            display: flex;
            align-items: center;
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
            color: white;
        }

        .toast.error {
            background-color: #F44336;
            color: white;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
    <div class="text-lg font-semibold ml-3">Barcode Generate</div>
    <div class="flex gap-4">
        <p class="hover:text-yellow-600 text-yellow-500 font-bold">Staff</p>
        <a href="" class="hover:text-yellow-600">Workers</a>
        <a href="" class="hover:text-yellow-600">Day Heldup</a>
        <a href="" class="hover:text-yellow-600">Night Heldup</a>
        <a href="barcode_generator_night_emergency.php" class="hover:text-yellow-600">Night Emergency</a>
        <a href="" class="hover:text-yellow-600">Own Vehicle</a>
    </div>
</div>

<div class="container ">
    <div class="w-[85%] ml-[15%] flex flex-col items-center">
    </div>
    </div>

</body>
</html>

<?php $conn->close(); ?>