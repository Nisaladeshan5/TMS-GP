<?php

// --- 1. Configuration & Includes ---
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Start the session to access $_SESSION variables
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is currently logged in 
// NOTE: Ensure your login process correctly sets $_SESSION['loggedin']
$is_logged_in = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

// Set timezone to Sri Lanka
date_default_timezone_set('Asia/Colombo');

// --- 2. Input & Filter Initialization ---
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// --- 3. Database Query Preparation ---
// *** CHANGE 1: Added evr.ac_status to the SELECT list ***
$sql = "SELECT evr.id, evr.supplier_code, s.supplier, evr.vehicle_no, evr.date, evr.amount, evr.shift, evr.time, evr.from_location, evr.to_location, evr.distance, evr.ac_status
        FROM extra_vehicle_register AS evr
        INNER JOIN supplier AS s ON evr.supplier_code = s.supplier_code";
        
$conditions = ["evr.done = 1"];
$params = [];
$types = "";

if (!empty($filter_month) && !empty($filter_year)) {
    $conditions[] = "MONTH(date) = ? AND YEAR(date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY date DESC, time DESC";

// --- 4. Database Execution ---
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extra Vehicle Register</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Custom styles for modal transition */
        .modal {
            transition: opacity 0.25s ease;
        }
        .modal-active {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-inactive {
            opacity: 0;
            pointer-events: none;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] fixed top-0 z-10">
    <div class="text-lg font-semibold ml-3">Extra Vehicle Register</div>
    <div class="flex gap-4">
        <a href="add_records/extra_vehicle/add_non_schedule_extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">Add Non Schedule Extra</a>
        
        <?php if ($is_logged_in): ?>
            <a href="non_schedule_extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">Non Schedule Extra</a>
        <?php endif; ?>
        
        <a href="schedule_extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">Schedule Extra</a>
        
        <?php if ($is_logged_in): ?>
            <a href="add_records/extra_vehicle/add_extra_vehicle.php" class="hover:text-yellow-400 font-medium transition duration-150">Add Record</a>
        <?php endif; ?>
    </div>
</div>

<div class="container pt-16 pb-4" style="width: 82%; margin-left: 17%; margin-right: 1%;">
    <p class="text-4xl font-extrabold text-gray-800 mb-3 text-center">Extra Vehicle Register</p>

    <form method="GET" action="" class="mb-4 flex justify-center w-full">
        <div class="flex items-center space-x-6">
            
            <select id="month" name="month" class="border border-gray-300 p-2 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <?php
                $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April', '05' => 'May', '06' => 'June',
                    '07' => 'July', '08' => 'August', '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                ];
                foreach ($months as $num => $name) {
                    $selected = ($num == $filter_month) ? 'selected' : '';
                    echo "<option value='{$num}' {$selected}>{$name}</option>";
                }
                ?>
            </select>
            
            <select id="year" name="year" class="border border-gray-300 p-2 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                <?php
                $current_year = date('Y');
                for ($y = $current_year; $y >= 2020; $y--) {
                    $selected = ($y == $filter_year) ? 'selected' : '';
                    echo "<option value='{$y}' {$selected}>{$y}</option>";
                }
                ?>
            </select>

            <button type="submit" class="bg-blue-600 text-white px-3 py-2 rounded-lg hover:bg-blue-700 transition duration-150 ease-in-out font-medium">Apply Filter</button>
        </div>
    </form>
    
    <div class="overflow-x-auto bg-white shadow-xl rounded-lg w-full">
        <table class="min-w-full table-auto divide-y divide-gray-200">
            <thead class="bg-blue-700 text-white">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Date</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Time</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Vehicle No</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Supplier</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">Shift</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">From</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider">To</th>
                    <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">A/C</th> 
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider">Distance (Km)</th>
                    
                    <?php if ($is_logged_in): ?>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider">Amount(LKR)</th>
                    <?php endif; ?>
                    
                    <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php
                // Calculate colspan based on login status (10 columns if not logged in, 11 if logged in)
                $colspan = $is_logged_in ? 11 : 10;

                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $trip_id = $row['id'];
                        
                        // Logic to translate ac_status (1/0) to Yes/No
                        $ac_status_display = ($row['ac_status'] == 1) ? 
                            "<span class='font-bold text-green-600'>Yes</span>" : 
                            "<span class='text-gray-500'>No</span>";
                        
                        echo "<tr class='hover:bg-blue-50 transition duration-100'>";
                        
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>{$row['date']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>" . date('H:i', strtotime($row['time'])) . "</td>"; 
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm font-medium'>{$row['vehicle_no']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>{$row['supplier']} ({$row['supplier_code']})</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>{$row['shift']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>{$row['from_location']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm'>{$row['to_location']}</td>";
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm text-center'>{$ac_status_display}</td>"; // Display A/C Status
                        echo "<td class='px-3 py-2 whitespace-nowrap text-sm text-right'>{$row['distance']}</td>";
                        
                        // 🔑 CONDITIONAL CELL: Amount (Logged in only)
                        if ($is_logged_in) {
                            echo "<td class='px-3 py-2 whitespace-nowrap text-sm text-right font-semibold text-green-700'>" . number_format($row['amount'], 2) . "</td>";
                        }
                        
                        echo "<td class='px-3 py-2 whitespace-nowrap text-center'>";
                        echo "<button data-trip-id='{$trip_id}' class='view-details-btn bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 text-xs font-medium transition duration-150'>View Details</button>";
                        echo "</td>";
                        
                        echo "</tr>";
                    }
                } else {
                    // Use the calculated colspan
                    echo "<tr><td colspan='{$colspan}' class='px-3 py-4 text-center text-gray-500'>No extra vehicle trips found for this period.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div id="detailsModal" class="modal modal-inactive fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
    <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>

    <div class="modal-container bg-white w-11/12 md:max-w-lg mx-auto rounded-lg shadow-2xl z-50 overflow-y-auto">
        
        <div class="py-4 text-left px-6">
            <div class="flex justify-between items-center pb-4 border-b border-gray-200">
                <p class="text-2xl font-bold text-gray-800">Trip Details</p>
                <div class="modal-close cursor-pointer z-50 p-1 rounded-full hover:bg-gray-100 transition">
                    <svg class="fill-current text-gray-600" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18">
                        <path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path>
                    </svg>
                </div>
            </div>

            <div id="modal-content" class="my-4">
                <p class="text-gray-500">Loading...</p>
            </div>

            <div class="flex justify-end pt-2 border-t border-gray-200">
                <button class="modal-close px-6 bg-gray-500 p-2 rounded-lg text-white font-medium hover:bg-gray-600 transition duration-150">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$(document).ready(function() {
    var modal = $('#detailsModal');

    // --- Open Modal and Fetch Details ---
    $('.view-details-btn').on('click', function() {
        var tripId = $(this).data('trip-id');
        var modalContent = $('#modal-content');

        // Show loading state and open modal
        modalContent.html('<p class="text-center py-4 text-gray-600 flex items-center justify-center"><svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Loading employee and reason details...</p>');
        modal.removeClass('modal-inactive').addClass('modal-active');

        // AJAX call to fetch details
        $.ajax({
            url: 'fetch_trip_employees.php', // Path to the new PHP script
            type: 'GET',
            data: { trip_id: tripId },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.employees.length > 0) {
                    var html = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">';
                    html += '<thead class="bg-gray-100"><tr><th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Employee ID</th><th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reason for Trip</th></tr></thead>';
                    html += '<tbody class="bg-white divide-y divide-gray-200">';
                    
                    $.each(response.employees, function(i, emp) {
                        html += '<tr>';
                        html += '<td class="px-4 py-3 whitespace-nowrap text-sm font-mono">' + emp.emp_id + '</td>';
                        html += '<td class="px-4 py-3 text-sm">' + emp.reason + '</td>';
                        html += '</tr>';
                    });

                    html += '</tbody></table></div>';
                    modalContent.html(html);
                } else {
                    modalContent.html('<p class="text-center py-4 text-red-600 font-medium">No employee or reason details found for this trip.</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error:", status, error, xhr.responseText);
                modalContent.html('<p class="text-center py-4 text-red-600 font-medium">An error occurred while fetching details. Please check the network and `fetch_trip_employees.php` script.</p>');
            }
        });
    });

    // --- Close Modal functionality ---
    $('.modal-close, .modal-overlay').on('click', function() {
        modal.removeClass('modal-active').addClass('modal-inactive');
        setTimeout(() => {
            $('#modal-content').html('');
        }, 300); 
    });
});
</script>

</html>