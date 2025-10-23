<?php
// Include the database connection and header/navbar
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Check connection
if ($conn->connect_error) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Database connection failed: " . $conn->connect_error . "</div>");
}

$target_emp_id = $_GET['emp_id'] ?? null;

if (!$target_emp_id) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Error: Employee ID not specified for editing.</div>");
}

// 1. Fetch Dropdown Data (Required for the Form)
// Employee List (emp_id, calling_name)
// NOTE: We fetch all employees to allow the dropdown to render, but we will disable it later.
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

// 2. Fetch Current Vehicle Data for the specific Employee ID
$vehicle_sql = "
    SELECT 
        ov.emp_id, 
        e.calling_name, 
        ov.vehicle_no, 
        ov.distance,
        ov.fuel_efficiency AS consumption_id,
        ov.rate_id 
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    WHERE ov.emp_id = ?;
";

$stmt = $conn->prepare($vehicle_sql);
$stmt->bind_param("s", $target_emp_id);
$stmt->execute();
$vehicle_result = $stmt->get_result();
$current_vehicle_data = $vehicle_result->fetch_assoc();

if (!$current_vehicle_data) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Error: Vehicle data not found for Employee ID: " . htmlspecialchars($target_emp_id) . "</div>");
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Own Vehicle</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
</head>
<body class="bg-gray-100 font-sans">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 

    <div class="w-[85%] ml-[15%]">
        <div class="max-w-xl mx-auto mt-12">
            
            <div class="bg-white rounded-xl shadow-2xl p-8 border border-gray-200">
                
                <div class="flex justify-between items-center pb-4 mb-6 border-b border-indigo-200">
                    <h2 class="text-2xl font-bold text-gray-900 tracking-tight">
                        <i class="fas fa-edit mr-2 text-indigo-600"></i> Edit Vehicle for <?php echo htmlspecialchars($current_vehicle_data['calling_name']); ?>
                    </h2>
                    <a href="own_vehicle.php" class="flex items-center space-x-2 px-3 py-1.5 text-sm bg-gray-500 text-white rounded-lg hover:bg-gray-600 shadow-md transition duration-300">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back</span>
                    </a>
                </div>
                
                <div id="alert-container" class="mb-4"></div>

                <form id="edit-vehicle-form" action="process_vehicle.php" method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="original_emp_id" value="<?php echo htmlspecialchars($current_vehicle_data['emp_id']); ?>">

                    <div class="space-y-5">
                        <div>
                            <label for="emp_id" class="block text-sm font-medium text-gray-700 mb-1">Employee</label>
                            <select id="emp_id" name="emp_id" disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm bg-gray-100 cursor-not-allowed transition duration-150 appearance-none">
                                <?php foreach ($employees as $emp): ?>
                                    <option 
                                        value="<?php echo htmlspecialchars($emp['emp_id']); ?>" 
                                        <?php echo ($emp['emp_id'] == $current_vehicle_data['emp_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['calling_name']) . ' (ID: ' . htmlspecialchars($emp['emp_id']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($current_vehicle_data['emp_id']); ?>">
                        </div>

                        <div>
                            <label for="vehicle_no" class="block text-sm font-medium text-gray-700 mb-1">Vehicle No</label>
                            <input type="text" id="vehicle_no" name="vehicle_no" required 
                                value="<?php echo htmlspecialchars($current_vehicle_data['vehicle_no']); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150" placeholder="e.g., ABC-1234">
                        </div>

                        <div>
                            <label for="fuel_efficiency" class="block text-sm font-medium text-gray-700 mb-1">Fuel Efficiency Type</label>
                            <select id="fuel_efficiency" name="fuel_efficiency" required class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 appearance-none">
                                <option value="" disabled>Select Consumption Type</option>
                                <?php foreach ($consumptions as $con): ?>
                                    <option 
                                        value="<?php echo htmlspecialchars($con['c_id']); ?>"
                                        <?php echo ($con['c_id'] == $current_vehicle_data['consumption_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($con['c_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="rate_id" class="block text-sm font-medium text-gray-700 mb-1">Fuel Rate (Type & Price)</label>
                            <select id="rate_id" name="rate_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 appearance-none">
                                <option value="" disabled>Select Fuel Rate</option>
                                <?php foreach ($fuel_rates as $rate): ?>
                                    <option 
                                        value="<?php echo htmlspecialchars($rate['rate_id']); ?>"
                                        <?php echo ($rate['rate_id'] == $current_vehicle_data['rate_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rate['display']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="distance" class="block text-sm font-medium text-gray-700 mb-1">Distance (km)</label>
                            <input type="number" step="0.01" id="distance" name="distance" required 
                                value="<?php echo htmlspecialchars($current_vehicle_data['distance']); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150" placeholder="e.g., 50.5">
                        </div>
                    </div>

                    <div class="pt-6 border-t mt-6 border-gray-200 flex justify-end">
                        <button type="submit" class="px-5 py-2 text-base font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 shadow-xl transition duration-150">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </form>
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

            // Form Submission (Edit AJAX)
            $('#edit-vehicle-form').on('submit', function(e) {
                e.preventDefault();
                
                const formData = $(this).serialize();
                
                $.ajax({
                    type: 'POST',
                    url: $(this).attr('action'), // 'process_vehicle.php'
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        showAlert(response.message, 'success');
                        // Redirect back to the main list page on success
                        setTimeout(() => window.location.href = 'own_vehicle.php', 1000); 
                    },
                    error: function(xhr) {
                        let errorMessage = 'An unexpected error occurred.';
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            errorMessage = errorResponse.message || errorMessage;
                        } catch (e) {
                            // Fallback to generic error if JSON parsing fails
                        }
                        showAlert(`Operation Failed: ${errorMessage}`, 'error');
                    }
                });
            });
        });
    </script>
</body>
</html>