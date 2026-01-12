<?php
// download_ev_payments.php - Generates CSV summary for Extra Vehicle Payments (Fixed Logic)

require_once '../../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../../includes/login.php");
    exit();
}

include('../../../includes/db.php');
date_default_timezone_set('Asia/Colombo');

// --- 1. Get and Validate Inputs ---
$filterMonthNum = $_GET['month'] ?? date('m');
$filterYear = $_GET['year'] ?? date('Y');

// Ensure valid month/year format
$filterMonthNum = str_pad($filterMonthNum, 2, '0', STR_PAD_LEFT);
$monthName = date('F', mktime(0, 0, 0, (int)$filterMonthNum, 1));

// --- 2. PRE-FETCH DATA (Same Logic as ev_payments.php) ---

// A. Fuel Rate History
$fuel_history = [];
$fuel_res = $conn->query("SELECT rate_id, rate, date FROM fuel_rate ORDER BY date DESC");
if ($fuel_res) {
    while ($row = $fuel_res->fetch_assoc()) {
        $fuel_history[$row['rate_id']][] = [
            'date' => $row['date'],
            'rate' => (float)$row['rate']
        ];
    }
}

function get_rate_for_date($rate_id, $trip_date, $history) {
    if (!isset($history[$rate_id])) return 0;
    foreach ($history[$rate_id] as $record) {
        if ($record['date'] <= $trip_date) return $record['rate'];
    }
    $last = end($history[$rate_id]);
    return $last ? $last['rate'] : 0;
}

// B. Op Code Rates
$op_rates = [];
$op_res = $conn->query("SELECT op_code, extra_rate_ac, extra_rate FROM op_services");
if ($op_res) {
    while ($row = $op_res->fetch_assoc()) {
        $op_rates[$row['op_code']] = [
            'ac' => (float)$row['extra_rate_ac'],
            'non_ac' => (float)$row['extra_rate']
        ];
    }
}

// C. Vehicle Specs
$vehicle_specs = [];
$veh_res = $conn->query("SELECT v.vehicle_no, v.rate_id, c.distance AS km_per_liter FROM vehicle v LEFT JOIN consumption c ON v.fuel_efficiency = c.c_id");
if ($veh_res) {
    while ($row = $veh_res->fetch_assoc()) {
        $vehicle_specs[$row['vehicle_no']] = [
            'rate_id' => $row['rate_id'],
            'km_per_liter' => (float)$row['km_per_liter']
        ];
    }
}

// D. Route Data
$route_data = [];
$rt_res = $conn->query("SELECT route_code, fixed_amount, vehicle_no, with_fuel FROM route");
if ($rt_res) {
    while ($row = $rt_res->fetch_assoc()) {
        $route_data[$row['route_code']] = [
            'fixed_amount' => (float)$row['fixed_amount'],
            'assigned_vehicle' => $row['vehicle_no'], 
            'with_fuel' => (int)$row['with_fuel']
        ];
    }
}

// --- 3. MAIN CALCULATION & AGGREGATION ---
$payment_data = [];

$sql = "
    SELECT 
        evr.*,
        s.supplier,
        s.supplier_code
    FROM 
        extra_vehicle_register evr
    JOIN 
        supplier s ON evr.supplier_code = s.supplier_code
    WHERE 
        MONTH(evr.date) = ? AND YEAR(evr.date) = ? AND evr.done = 1
    ORDER BY 
        evr.date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $filterMonthNum, $filterYear);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $pay_amount = 0.00;
    $distance = (float)$row['distance'];
    $identifier = '';
    $type = '';
    $trip_date = $row['date'];

    // --- LOGIC 1: OP CODE ---
    if (!empty($row['op_code'])) {
        $identifier = $row['op_code'];
        $type = 'Operation';
        if (isset($op_rates[$identifier])) {
            $rate = ($row['ac_status'] == 1) ? $op_rates[$identifier]['ac'] : $op_rates[$identifier]['non_ac'];
            $pay_amount = $distance * $rate;
        }
    } 
    // --- LOGIC 2: ROUTE CODE ---
    elseif (!empty($row['route'])) {
        $identifier = $row['route'];
        $type = 'Route';
        if (isset($route_data[$identifier])) {
            $fixed_amount = $route_data[$identifier]['fixed_amount'];
            $assigned_vehicle = $route_data[$identifier]['assigned_vehicle'];
            $with_fuel = $route_data[$identifier]['with_fuel'];
            $fuel_cost_per_km = 0;
            
            if ($with_fuel == 1 && !empty($assigned_vehicle) && isset($vehicle_specs[$assigned_vehicle])) {
                $v_spec = $vehicle_specs[$assigned_vehicle];
                $km_l = $v_spec['km_per_liter'];
                $fuel_rate = get_rate_for_date($v_spec['rate_id'], $trip_date, $fuel_history);
                if ($km_l > 0) $fuel_cost_per_km = $fuel_rate / $km_l;
            }
            $pay_amount = $distance * ($fixed_amount + $fuel_cost_per_km);
        }
    }

    // Aggregate
    $key = $row['supplier_code'] . '_' . $identifier;
    if (!isset($payment_data[$key])) {
        $payment_data[$key] = [
            'identifier' => $identifier,
            'type' => $type,
            'supplier' => $row['supplier'],
            'vehicle_no' => $row['vehicle_no'], // Display used vehicle
            'total_trips' => 0,
            'total_distance' => 0,
            'total_payment' => 0
        ];
    }
    $payment_data[$key]['total_trips']++;
    $payment_data[$key]['total_distance'] += $distance;
    $payment_data[$key]['total_payment'] += $pay_amount;
}
$stmt->close();
$conn->close();

// --- 4. OUTPUT CSV ---

$filename = "Extra_Vehicle_Summary_{$monthName}_{$filterYear}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Title Row
fputcsv($output, ["Extra Vehicle Payment Summary - {$monthName} {$filterYear}"]);
fputcsv($output, [""]); 

// Header Row
$header = [
    'IDENTIFIER (OP/ROUTE)', 
    'TYPE', 
    'SUPPLIER', 
    'VEHICLE NO (REF)', 
    'TOTAL TRIPS', 
    'TOTAL DISTANCE (KM)', 
    'TOTAL PAYMENT (LKR)'
];
fputcsv($output, $header);

$grand_total_payment = 0;
$grand_total_distance = 0;
$grand_total_trips = 0;

foreach ($payment_data as $data) {
    $row = [
        $data['identifier'],
        $data['type'],
        $data['supplier'],
        $data['vehicle_no'],
        $data['total_trips'],
        number_format($data['total_distance'], 2),
        number_format($data['total_payment'], 2)
    ];
    fputcsv($output, $row);

    $grand_total_trips += $data['total_trips'];
    $grand_total_distance += $data['total_distance'];
    $grand_total_payment += $data['total_payment'];
}

// Grand Total Row
fputcsv($output, [""]);
fputcsv($output, [
    'GRAND TOTALS', 
    '', 
    '', 
    '', 
    $grand_total_trips, 
    number_format($grand_total_distance, 2), 
    number_format($grand_total_payment, 2)
]);

fclose($output);
exit;
?>