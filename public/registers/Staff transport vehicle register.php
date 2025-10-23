<?php
// Note: Assuming '../../includes/db.php' connects to the 'transport' database
include('../../includes/db.php');; 
include('../../includes/header.php');
include('../../includes/navbar.php');

// Set the filter date to today's date by default
$filterDate = date('Y-m-d');

// If a date is submitted via the form, use that date for the filter
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['date'])) {
    $filterDate = $_POST['date'];
}

// 1. Fetch Running Chart details from 'transport' DB
$sql = "SELECT s.id, s.vehicle_no, s.actual_vehicle_no, s.vehicle_status, s.shift, s.driver_NIC, s.driver_status, r.route AS route_name, r.route_code, s.in_time, s.out_time, s.date
        FROM staff_transport_vehicle_register s
        JOIN route r ON s.route = r.route_code
        WHERE DATE(s.date) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();


$cross_check_data = [];
// *** FIX: Reverting to 'route' as confirmed, but will add null check in loop ***
$sql_tms = "SELECT actual_vehicle_no, driver_NIC, route, shift 
            FROM cross_check 
            WHERE DATE(date) = ?"; // Assuming 'date' column exists and is filterable
$stmt_tms = $conn->prepare($sql_tms);
$stmt_tms->bind_param('s', $filterDate);
$stmt_tms->execute();
$result_tms = $stmt_tms->get_result();

while ($row_tms = $result_tms->fetch_assoc()) {
    // *** FIX: Use isset() or the Null Coalescing Operator (??) to prevent the "Undefined array key" warning.
    // Assuming 'route' is meant to hold the route code for comparison.
    $route_tms = $row_tms['route'] ?? null; 
    $shift_tms = $row_tms['shift'] ?? null;
    
    // Skip this record if the route is missing/null, as it's essential for the key
    if ($route_tms === null || $shift_tms === null) {
        continue;
    }
    
    // Create a unique key for comparison: 'date-route-shift'
    $key_tms = $filterDate . '-' . $route_tms . '-' . $shift_tms;
    $cross_check_data[$key_tms] = [
        'actual_vehicle_no' => $row_tms['actual_vehicle_no'] ?? null,
        'driver_NIC' => $row_tms['driver_NIC'] ?? null
    ];
}

// Group and merge logic
$grouped = [];
while ($row = $result->fetch_assoc()) {
    // Determine the key for grouping/merging and cross-check
    $group_key = $row['date'] . '-' . $row['route_name']; // For grouping morning/evening display
    $cross_check_key = $row['date'] . '-' . $row['route_code'] . '-' . $row['shift']; // For DB comparison

    // Check against the cross-check data
    $is_match = true;
    if (isset($cross_check_data[$cross_check_key])) {
        $tms_record = $cross_check_data[$cross_check_key];
        // Comparison: actual_vehicle_no AND driver_NIC must match
        if ($row['actual_vehicle_no'] !== $tms_record['actual_vehicle_no'] || $row['driver_NIC'] !== $tms_record['driver_NIC']) {
            $is_match = false; // Mismatch found
        }
    } else {
        $is_match = false; // Record is missing in cross_check table (or different shift/route_code)
    }

    if (!isset($grouped[$group_key])) {
        $grouped[$group_key] = [
            'date' => $row['date'],
            'route_name' => $row['route_name'],
            'morning_vehicle' => null,
            'morning_actual_vehicle' => null,
            'morning_vehicle_status' => null,
            'morning_driver' => null,
            'morning_driver_status' => null,
            'morning_in' => null,
            'morning_out' => null,
            'morning_match' => true, // Default to true
            'evening_vehicle' => null,
            'evening_actual_vehicle' => null,
            'evening_vehicle_status' => null,
            'evening_driver' => null,
            'evening_driver_status' => null,
            'evening_in' => null,
            'evening_out' => null,
            'evening_match' => true // Default to true
        ];
    }

    // Assign shift data and match status
    if ($row['shift'] === 'morning') {
        $grouped[$group_key]['morning_vehicle'] = $row['vehicle_no'];
        $grouped[$group_key]['morning_actual_vehicle'] = $row['actual_vehicle_no'];
        $grouped[$group_key]['morning_vehicle_status'] = $row['vehicle_status'];
        $grouped[$group_key]['morning_driver'] = $row['driver_NIC'];
        $grouped[$group_key]['morning_driver_status'] = $row['driver_status'];
        $grouped[$group_key]['morning_in'] = $row['in_time'];
        $grouped[$group_key]['morning_out'] = $row['out_time'];
        $grouped[$group_key]['morning_match'] = $is_match; // Store the match status
    } elseif ($row['shift'] === 'evening') {
        $grouped[$group_key]['evening_vehicle'] = $row['vehicle_no'];
        $grouped[$group_key]['evening_actual_vehicle'] = $row['actual_vehicle_no'];
        $grouped[$group_key]['evening_vehicle_status'] = $row['vehicle_status'];
        $grouped[$group_key]['evening_driver'] = $row['driver_NIC'];
        $grouped[$group_key]['evening_driver_status'] = $row['driver_status'];
        $grouped[$group_key]['evening_in'] = $row['in_time'];
        $grouped[$group_key]['evening_out'] = $row['out_time'];
        $grouped[$group_key]['evening_match'] = $is_match; // Store the match status
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<style>
    /* CSS for toast - kept as is */
    #toast-container {
        position: fixed;
        top: 1rem;
        right: 1rem;
        z-index: 2000;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    .toast {
        display: flex;
        align-items: center;
        padding: 1rem;
        margin-bottom: 0.5rem;
        border-radius: 0.5rem;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        color: white;
        transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
        transform: translateY(-20px);
        opacity: 0;
        max-width: 400px; /* Optional: limits toast width */
    }

    .toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    .toast.success {
        background-color: #4CAF50;
    }
    .toast.warning {
        background-color: #ff9800;
    }
    .toast.error {
        background-color: #F44336;
    }

    .toast-icon {
        width: 1.5rem;
        height: 1.5rem;
        margin-right: 0.75rem;
    }

    /* Custom CSS for mismatch highlight */
    .mismatch-row {
        background-color: #dc2626 !important; /* Tailwind's red-600 for contrast */
        color: white !important;
    }
    .mismatch-row td {
        border-color: #fca5a5; /* Lighter red border for contrast */
    }
</style>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="add_records/trip.php" class="hover:text-yellow-600">Trip</a>
        <a href="add_records/absent_staff.php" class="hover:text-yellow-600">Absent</a>
        <!-- <a href="add_records/extra_distance.php" class="hover:text-yellow-600">eXTRA </a> -->
        <a href="add_records/add_staff_record.php" class="hover:text-yellow-600">Add Record</a>
        <a href="add_records/barcode_reader.php" class="hover:text-yellow-600">Barcode</a>
    </div>
</div>

<div class="container" style="width: 80%; margin-left: 18%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-[48px] font-bold text-gray-800 mt-2">Staff Transport Vehicle Details</p>

    <form method="POST" class="mb-6 flex justify-center">
        <div class="flex items-center">
            <label for="date" class="text-lg font-medium mr-2">Filter by Date:</label>
            <input type="date" id="date" name="date" class="border border-gray-300 p-2 rounded-md"
                    value="<?php echo htmlspecialchars($filterDate); ?>" required>
            <button type="submit" class="bg-blue-500 text-white px-3 py-2 rounded-md ml-2 hover:bg-blue-600">Filter</button>
        </div>
    </form>

    <div class="overflow-x-auto bg-white shadow-md rounded-md mb-6">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Route</th>
                    <th class="px-4 py-2 text-left">Assigned Vehicle No</th>
                    <th class="px-4 py-2 text-left">Vehicle No</th>
                    <th class="px-4 py-2 text-left">Driver</th>
                    <th class="px-4 py-2 text-left">Morning IN</th>
                    <th class="px-4 py-2 text-left">Morning OUT</th>
                    <th class="px-4 py-2 text-left">Evening IN</th>
                    <th class="px-4 py-2 text-left">Evening OUT</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($grouped)) {
                    // Display a row indicating no data if $grouped is empty.
                    // The colspan should match the number of columns in your table (which is 10 based on the <td> counts).
                    echo "<tr><td colspan='10' class='border px-4 py-2 text-center text-gray-500'>No staff transport vehicle record available for today.</td></tr>";
                } else {
                    foreach ($grouped as $entry) {
                        $morning_in_time = ($entry['morning_in'] !== null) ? date('H:i', strtotime($entry['morning_in'])) : '-';
                        $morning_out_time = ($entry['morning_out'] !== null) ? date('H:i', strtotime($entry['morning_out'])) : '-';
                        $evening_in_time = ($entry['evening_in'] !== null) ? date('H:i', strtotime($entry['evening_in'])) : '-';
                        $evening_out_time = ($entry['evening_out'] !== null) ? date('H:i', strtotime($entry['evening_out'])) : '-';

                        // Determine cell colors based on statuses (0 for unknown/red)
                        // Note: These statuses are kept separate from the cross-check mismatch
                        $morning_vehicle_cell_class = ($entry['morning_vehicle_status'] == 0) ? 'bg-red-200' : '';
                        $morning_driver_cell_class = ($entry['morning_driver_status'] == 0) ? 'bg-red-200' : '';
                        $evening_vehicle_cell_class = ($entry['evening_vehicle_status'] == 0) ? 'bg-red-200' : '';
                        $evening_driver_cell_class = ($entry['evening_driver_status'] == 0) ? 'bg-red-200' : '';

                        // Check if both morning and evening have records
                        if ($entry['morning_vehicle'] !== null && $entry['evening_vehicle'] !== null) {
                            // Check if vehicle and driver are the same for both shifts
                            if ($entry['morning_actual_vehicle'] === $entry['evening_actual_vehicle'] && $entry['morning_driver'] === $entry['evening_driver']) {
                                // Single row display for both shifts (same vehicle/driver)
                                // Apply red if EITHER shift mismatches the cross-check data
                                $row_class = (!$entry['morning_match'] || !$entry['evening_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50'; 

                                echo "<tr class='{$row_class}'>
                                    <td class='border px-4 py-2'>{$entry['date']}</td>
                                    <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                    <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                                    <td class='border px-4 py-2 {$morning_vehicle_cell_class}'>{$entry['morning_actual_vehicle']}</td>
                                    <td class='border px-4 py-2 {$morning_driver_cell_class}'>{$entry['morning_driver']}</td>
                                    <td class='border px-4 py-2'>{$morning_in_time}</td>
                                    <td class='border px-4 py-2'>{$morning_out_time}</td>
                                    <td class='border px-4 py-2'>{$evening_in_time}</td>
                                    <td class='border px-4 py-2'>{$evening_out_time}</td>
                                    </tr>";
                            } else {
                                // Two separate rows for morning and evening (different vehicle/driver)
                                
                                // Morning Row
                                $morning_row_class = (!$entry['morning_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50';
                                echo "<tr class='{$morning_row_class}'>
                                    <td class='border px-4 py-2'>{$entry['date']}</td>
                                    <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                    <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                                    <td class='border px-4 py-2 {$morning_vehicle_cell_class}'>{$entry['morning_actual_vehicle']}</td>
                                    <td class='border px-4 py-2 {$morning_driver_cell_class}'>{$entry['morning_driver']}</td>
                                    <td class='border px-4 py-2'>{$morning_in_time}</td>
                                    <td class='border px-4 py-2'>{$morning_out_time}</td>
                                    <td class='border px-4 py-2'>-</td>
                                    <td class='border px-4 py-2'>-</td>
                                    </tr>";

                                // Evening Row
                                $evening_row_class = (!$entry['evening_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50';
                                echo "<tr class='{$evening_row_class}'>
                                    <td class='border px-4 py-2'>{$entry['date']}</td>
                                    <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                    <td class='border px-4 py-2'>{$entry['evening_vehicle']}</td>
                                    <td class='border px-4 py-2 {$evening_vehicle_cell_class}'>{$entry['evening_actual_vehicle']}</td>
                                    <td class='border px-4 py-2 {$evening_driver_cell_class}'>{$entry['evening_driver']}</td>
                                    <td class='border px-4 py-2'>-</td>
                                    <td class='border px-4 py-2'>-</td>
                                    <td class='border px-4 py-2'>{$evening_in_time}</td>
                                    <td class='border px-4 py-2'>{$evening_out_time}</td>
                                    </tr>";
                            }
                        } else if ($entry['morning_vehicle'] !== null) {
                            // Only a morning record exists
                            $row_class = (!$entry['morning_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50';
                            echo "<tr class='{$row_class}'>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'>{$entry['morning_vehicle']}</td>
                                <td class='border px-4 py-2 {$morning_vehicle_cell_class}'>{$entry['morning_actual_vehicle']}</td>
                                <td class='border px-4 py-2 {$morning_driver_cell_class}'>{$entry['morning_driver']}</td>
                                <td class='border px-4 py-2'>{$morning_in_time}</td>
                                <td class='border px-4 py-2'>{$morning_out_time}</td>
                                <td class='border px-4 py-2'>-</td>
                                <td class='border px-4 py-2'>-</td>
                                </tr>";
                        } else if ($entry['evening_vehicle'] !== null) {
                            // Only an evening record exists
                            $row_class = (!$entry['evening_match']) ? 'mismatch-row' : 'bg-white hover:bg-gray-50';
                            echo "<tr class='{$row_class}'>
                                <td class='border px-4 py-2'>{$entry['date']}</td>
                                <td class='border px-4 py-2'>{$entry['route_name']}</td>
                                <td class='border px-4 py-2'>{$entry['evening_vehicle']}</td>
                                <td class='border px-4 py-2 {$evening_vehicle_cell_class}'>{$entry['evening_actual_vehicle']}</td>
                                <td class='border px-4 py-2 {$evening_driver_cell_class}'>{$entry['evening_driver']}</td>
                                <td class='border px-4 py-2'>-</td>
                                <td class='border px-4 py-2'>-</td>
                                <td class='border px-4 py-2'>{$evening_in_time}</td>
                                <td class='border px-4 py-2'>{$evening_out_time}</td>
                                </tr>";
                        }
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<div id="toast-container"></div>
</body>
<script>
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let iconPath;
        switch (type) {
            case 'success':
                iconPath = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />';
                break;
            case 'warning':
            case 'error':
                iconPath = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />';
                break;
            default:
                iconPath = '';
        }

        toast.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                ${iconPath}
            </svg>
            <span>${message}</span>
        `;
        
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 5000); 
    }

    // This logic runs when the page loads
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    const message = urlParams.get('message');
    
    if (status && message) {
        // 1. Decode and show the toast
        showToast(decodeURIComponent(message), status);

        // 2. KEY STEP: Clean up the URL
        // Replaces the current URL with the clean path, removing ?status=...&message=...
        window.history.replaceState(null, null, window.location.pathname);
    }
</script>
</html>