<?php
// Include the database connection and header/navbar
// NOTE: Re-establishing connection for logic, as the previous one was closed.
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

// Check connection
if ($conn->connect_error) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Database connection failed: " . $conn->connect_error . "</div>");
}

// Fetch Dropdown Data (Only for Consumption and Fuel Rates, as Employee is now a text input)

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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Own Vehicle</title>
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
                        <i class="fas fa-plus-circle mr-2 text-indigo-600"></i> Add New Employee Vehicle
                    </h2>
                    <a href="own_vehicle.php" class="flex items-center space-x-2 px-3 py-1.5 text-sm bg-gray-500 text-white rounded-lg hover:bg-gray-600 shadow-md transition duration-300">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back</span>
                    </a>
                </div>
                
                <div id="alert-container" class="mb-4"></div>

                <form id="add-vehicle-form" action="process_vehicle.php" method="POST">
                    <input type="hidden" name="action" value="add">

                    <div class="space-y-5">
                        <div>
                            <label for="emp_id" class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
                            <input type="text" id="emp_id" name="emp_id" required 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150" 
                                placeholder="Enter Employee ID (e.g., E001)">
                            <p id="emp-id-status" class="mt-1 text-sm"></p>
                        </div>
                        <div>
                            <label for="vehicle_no" class="block text-sm font-medium text-gray-700 mb-1">Vehicle No</label>
                            <input type="text" id="vehicle_no" name="vehicle_no" required class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150" placeholder="e.g., ABC-1234">
                        </div>

                        <div>
                            <label for="fuel_efficiency" class="block text-sm font-medium text-gray-700 mb-1">Fuel Efficiency Type</label>
                            <select id="fuel_efficiency" name="fuel_efficiency" required class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 appearance-none">
                                <option value="" disabled selected>Select Consumption Type</option>
                                <?php foreach ($consumptions as $con): ?>
                                    <option value="<?php echo htmlspecialchars($con['c_id']); ?>">
                                        <?php echo htmlspecialchars($con['c_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="rate_id" class="block text-sm font-medium text-gray-700 mb-1">Fuel Rate (Type & Price)</label>
                            <select id="rate_id" name="rate_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 appearance-none">
                                <option value="" disabled selected>Select Fuel Rate</option>
                                <?php foreach ($fuel_rates as $rate): ?>
                                    <option value="<?php echo htmlspecialchars($rate['rate_id']); ?>">
                                        <?php echo htmlspecialchars($rate['display']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="distance" class="block text-sm font-medium text-gray-700 mb-1">Distance (km)</label>
                            <input type="number" step="0.01" id="distance" name="distance" required class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150" placeholder="e.g., 50.5">
                        </div>
                    </div>

                    <div class="pt-6 border-t mt-6 border-gray-200 flex justify-end">
                        <button type="submit" id="submit-btn" class="px-5 py-2 text-base font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 shadow-xl transition duration-150" disabled>
                            <i class="fas fa-save mr-2"></i> Add Vehicle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div> 

    <script>
        $(document).ready(function() {
            const $alertContainer = $('#alert-container');
            const $empIdInput = $('#emp_id');
            const $empIdStatus = $('#emp-id-status');
            const $submitBtn = $('#submit-btn');
            let isEmpIdValid = false;

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

            // Function to enable/disable the submit button
            function checkFormValidity() {
                // Ensure the Employee ID is confirmed valid AND all other required fields are filled
                const otherFieldsValid = $('#add-vehicle-form').get(0).checkValidity();
                $submitBtn.prop('disabled', !(isEmpIdValid && otherFieldsValid));
            }
            
            // Debounce function to limit AJAX calls
            let debounceTimer;
            
            // Keyup event to trigger Employee ID validation
            $empIdInput.on('keyup change', function() {
                clearTimeout(debounceTimer);
                const empId = $(this).val().trim();
                
                if (empId.length === 0) {
                    $empIdStatus.text('Please enter an Employee ID.').removeClass().addClass('mt-1 text-sm text-gray-500');
                    isEmpIdValid = false;
                    checkFormValidity();
                    return;
                }
                
                // Show 'Checking...' status
                $empIdStatus.text('Checking...').removeClass().addClass('mt-1 text-sm text-yellow-600');
                isEmpIdValid = false;
                checkFormValidity();

                // Wait 500ms after the last key press to send the AJAX request
                debounceTimer = setTimeout(function() {
                    $.ajax({
                        type: 'POST',
                        url: 'check_emp_id.php', // We will create this new PHP file
                        data: { emp_id: empId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.exists) {
                                $empIdStatus.html(`<i class="fas fa-check-circle mr-1"></i> Employee **${response.name}** found.`).removeClass().addClass('mt-1 text-sm text-green-600');
                                isEmpIdValid = true;
                            } else {
                                $empIdStatus.text('Employee ID not found in the system.').removeClass().addClass('mt-1 text-sm text-red-600');
                                isEmpIdValid = false;
                            }
                            checkFormValidity();
                        },
                        error: function() {
                            $empIdStatus.text('Error checking Employee ID.').removeClass().addClass('mt-1 text-sm text-red-600');
                            isEmpIdValid = false;
                            checkFormValidity();
                        }
                    });
                }, 500);
            });
            
            // Re-check validity when other fields change
            $('#add-vehicle-form input:not(#emp_id), #add-vehicle-form select').on('change keyup', checkFormValidity);
            
            // Initial check on load
            checkFormValidity();


            // Form Submission (Add AJAX)
            $('#add-vehicle-form').on('submit', function(e) {
                e.preventDefault();
                
                // Final validation check before submission
                if (!isEmpIdValid) {
                    showAlert('Please enter a valid Employee ID before proceeding.', 'error');
                    return;
                }

                // If form is valid, submit data
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