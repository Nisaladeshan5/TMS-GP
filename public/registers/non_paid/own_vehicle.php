<?php
// Include the database connection
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Check connection
if ($conn->connect_error) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Database connection failed: " . $conn->connect_error . "</div>");
}

// 1. Fetch Dropdown Data (Required for the Add/Edit Modal)
// Employee List (emp_id, calling_name)
$emp_query = "SELECT emp_id, calling_name FROM employee ORDER BY calling_name ASC";
$emp_result = $conn->query($emp_query);
$employees = [];
if ($emp_result && $emp_result->num_rows > 0) {
    while ($row = $emp_result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Consumption Types List (c_id, c_type)
$consumption_query = "SELECT c_id, c_type FROM consumption ORDER BY c_type ASC";
$consumption_result = $conn->query($consumption_query);
$consumptions = [];
if ($consumption_result && $consumption_result->num_rows > 0) {
    while ($row = $consumption_result->fetch_assoc()) {
        $consumptions[] = $row;
    }
}

// Fuel Rates List (rate_id, type, rate)
$rate_query = "SELECT rate_id, type, rate FROM fuel_rate ORDER BY type ASC";
$rate_result = $conn->query($rate_query);
$fuel_rates = [];
if ($rate_result && $rate_result->num_rows > 0) {
    while ($row = $rate_result->fetch_assoc()) {
        // Format the rate display for the dropdown
        $row['display'] = "{$row['type']} (Rs. " . number_format($row['rate'], 2) . ")";
        $fuel_rates[] = $row;
    }
}


// 2. Fetch Joined Vehicle Data
$sql = "
    SELECT 
        ov.emp_id, 
        e.calling_name, 
        ov.vehicle_no, 
        ov.distance,
        c.c_type AS fuel_efficiency_type,
        ov.fuel_efficiency AS consumption_id,      -- The required c_id 
        fr.type AS fuel_rate_type,        
        fr.rate AS fuel_rate,              
        ov.rate_id AS rate_id              -- The required rate_id
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    JOIN 
        consumption c ON ov.fuel_efficiency = c.c_id
    JOIN 
        fuel_rate fr ON ov.rate_id = fr.rate_id
    ORDER BY 
        ov.emp_id ASC;
";

$result = $conn->query($sql);
$vehicle_data = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $vehicle_data[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Own Vehicle Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        /* Optional: Add a subtle focus ring color for better accessibility/UI */
        .btn-action:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.4); /* Tailwind indigo-500 */
        }
    </style>
</head>
<body class="bg-gray-100 font-sans min-h-screen">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%] fixed top-0 left-0 right-0 z-10">
        <div class="text-lg font-semibold ml-3">Own Vehicle</div>
        <div class="flex gap-4">
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Own Vehicle</p>
            <a href="" class="hover:text-yellow-600">Add Attendance</a>
            <a href="" class="hover:text-yellow-600">Barcode</a>
        </div>
    </div>

    <div class="w-[85%] ml-[15%] mt-[4%]">
        <div class="max-w-7xl mx-auto mt-12">
            
            <div class="p-8 bg-white shadow-2xl border border-gray-200 rounded-xl">
                
                <div class="flex justify-between items-center pb-4 mb-6 border-b border-indigo-200">
                    <h2 class="text-2xl font-bold text-gray-900 tracking-tight">
                        <i class="fas fa-car mr-2 text-indigo-600"></i> Employee Own Vehicle 
                    </h2>
                    <a href="add_own_vehicle.php" class="flex items-center space-x-2 px-5 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 shadow-lg transition duration-300 transform hover:scale-[1.02]">
                        <i class="fas fa-plus"></i>
                        <span class="font-medium">Add New Vehicle</span>
                    </a>
                </div>
                
                <div id="alert-container" class="mb-4"></div>

                <?php if (empty($vehicle_data)): ?>
                    <p class="text-center text-gray-500 py-12 border border-dashed border-gray-300 rounded-lg bg-gray-50">
                        No employee vehicle details found.
                    </p>
                <?php else: ?>
                    <div class="overflow-x-auto shadow-lg rounded-lg border border-gray-100">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-indigo-50/70">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-indigo-700 uppercase tracking-wider">Employee (ID)</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-indigo-700 uppercase tracking-wider">Vehicle No</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-indigo-700 uppercase tracking-wider">Fuel Type & Rate</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-indigo-700 uppercase tracking-wider">Consumption Type</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-indigo-700 uppercase tracking-wider">Distance (km)</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-indigo-700 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php foreach ($vehicle_data as $row): ?>
                                
                                <tr 
                                    class="hover:bg-indigo-50/50 transition duration-150"
                                    data-emp-id="<?php echo htmlspecialchars($row['emp_id']); ?>"
                                    data-vehicle-no="<?php echo htmlspecialchars($row['vehicle_no']); ?>"
                                >
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['calling_name']); ?></div>
                                        <div class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($row['emp_id']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-800">
                                        <?php echo htmlspecialchars($row['vehicle_no']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-semibold bg-indigo-100 text-indigo-700">
                                            <?php echo htmlspecialchars($row['fuel_rate_type']); ?>
                                        </span> 
                                        <span class="text-xs text-gray-500 ml-1"> (Rs. <?php echo number_format($row['fuel_rate'], 2); ?>)</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($row['fuel_efficiency_type']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-indigo-600">
                                        <?php echo htmlspecialchars($row['distance']); ?>
                                    </td>
                                    <td class="px-6 py-4 flex justify-center space-x-2 whitespace-nowrap">
                                        
                                        <a href="edit_own_vehicle.php?emp_id=<?php echo htmlspecialchars($row['emp_id']); ?>" class="btn-action w-8 h-8 flex items-center justify-center bg-indigo-50 text-indigo-600 rounded-full hover:bg-indigo-600 hover:text-white transition duration-200 shadow-md" title="Edit Record">
                                            <i class="fas fa-pen text-xs"></i>
                                        </a>

                                        <button class="delete-btn btn-action w-8 h-8 flex items-center justify-center bg-red-50 text-red-600 rounded-full hover:bg-red-600 hover:text-white transition duration-200 shadow-md" title="Delete Record">
                                            <i class="fas fa-trash-alt text-xs"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div> 
    
    <script>
        $(document).ready(function() {
            const $alertContainer = $('#alert-container');

            // Function to display an alert message
            function showAlert(message, type = 'success') {
                const typeClasses = {
                    success: 'bg-green-100 text-green-700 border-green-200',
                    error: 'bg-red-100 text-red-700 border-red-200'
                };
                const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';

                const alertHtml = `
                    <div class="p-3 border rounded-lg flex items-center ${typeClasses[type]} shadow-sm" role="alert">
                        <i class="fas ${iconClass} mr-3"></i>
                        <span class="text-sm font-medium">${message}</span>
                    </div>
                `;
                $alertContainer.html(alertHtml).fadeIn();
                setTimeout(() => $alertContainer.fadeOut().empty(), 5000); // Clear after 5 seconds
            }

            // DELETE Button Logic (Remains AJAX-based for simplicity)
            $('.delete-btn').on('click', function() {
                const $row = $(this).closest('tr');
                const empId = $row.data('emp-id');
                const empName = $row.find('td:eq(0) .text-sm').text();
                
                if (confirm(`Are you sure you want to delete the vehicle record for ${empName} (${empId})?`)) {
                    
                    $.ajax({
                        type: 'POST',
                        url: 'process_vehicle.php',
                        data: {
                            action: 'delete',
                            emp_id: empId
                        },
                        dataType: 'json',
                        success: function(response) {
                            showAlert(response.message, 'success');
                            // Reload the table data/page
                            setTimeout(() => location.reload(), 1000); 
                        },
                        error: function(xhr) {
                            let errorMessage = 'An unexpected error occurred during deletion.';
                            try {
                                const errorResponse = JSON.parse(xhr.responseText);
                                errorMessage = errorResponse.message || errorMessage;
                            } catch (e) {
                                // Fallback to generic error if JSON parsing fails
                            }
                            showAlert(errorMessage, 'error');
                        }
                    });
                }
            });
            
            // NOTE: All modal/edit form submission logic is removed as it moves to edit_own_vehicle.php
        });
    </script>
</body>
</html>