<?php
// own_vehicle.php

// Includes & Session Management
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in 
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Include the database connection and header/navbar
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Check connection
if ($conn->connect_error) {
    die("<div class='p-4 bg-red-100 text-red-700 rounded-md'>Database connection failed: " . $conn->connect_error . "</div>");
}

// --- 1. Fetching Data for Display ---
$sql = "
    SELECT 
        ov.emp_id, 
        e.calling_name, 
        ov.vehicle_no, 
        ov.distance,
        ov.type,
        ov.fixed_amount,
        ov.fuel_efficiency AS consumption_id,
        fr_latest.type AS fuel_rate_type,
        fr_latest.rate AS fuel_rate 
    FROM 
        own_vehicle ov
    JOIN 
        employee e ON ov.emp_id = e.emp_id
    JOIN 
        fuel_rate fr_latest ON ov.rate_id = fr_latest.rate_id
    WHERE
        fr_latest.id = (
            SELECT id 
            FROM fuel_rate 
            WHERE rate_id = ov.rate_id 
            AND date <= CURDATE() 
            ORDER BY date DESC, id DESC 
            LIMIT 1
        )
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

// Check for and store toast message from session 
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Own Vehicle Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        .page-container {
            margin-top: 0rem; 
            padding: 1rem;
            border-radius: 0.75rem;
        }
        /* --- Toast Notifications CSS --- */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
        .toast {
            display: none; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); transform: translateY(-20px); opacity: 0; 
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; display: flex; align-items: center;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; color: white; }
        .toast.error { background-color: #F44336; color: white; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>

<body class="bg-gray-100 font-sans min-h-screen">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 

    <div class="containerl flex justify-center">
        <div class="w-[85%] ml-[15%]">
            <div class="page-container">
                
                <h1 class="text-4xl mx-auto font-extrabold text-gray-800 mt-0 mb-4 w-full text-center">
                    Employee Vehicle Details
                </h1>

                <div class="w-full flex justify-between items-center pb-3">
                    <div class="flex space-x-3">
                        <a href="add_own_vehicle.php" class="bg-blue-500 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 inline-block hover:bg-blue-600">
                            <span>Add New Vehicle</span>
                        </a>
                        
                        <a href="#" id="generate-qr-pdf-btn" class="bg-green-700 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 hover:bg-green-800">
                            <span>Generate Vehicle QR PDF</span>
                        </a>
                    </div>
                </div>
                
                <div class="overflow-x-auto shadow-lg rounded-lg border border-gray-100">
                    <table class="min-w-full table-auto divide-y divide-gray-200">
                        <thead class="bg-blue-600 text-white"> 
                            <tr>
                                <th class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider">
                                    <input type="checkbox" id="select-all-checkbox" title="Select All">
                                </th>
                                <th class="px-4 py-2 text-left text-md font-semibold ">Employee</th>
                                <th class="px-4 py-2 text-left text-md font-semibold ">Vehicle Details</th>
                                <th class="px-4 py-2 text-left text-md font-semibold ">Fuel Type & Rate</th>
                                <th class="px-4 py-2 text-left text-md font-semibold ">Fixed Amount</th>
                                <th class="px-4 py-2 text-left text-md font-semibold ">Consumption (L/100km)</th>
                                <th class="px-4 py-2 text-right text-md font-semibold ">Distance (km)</th>
                                <th class="px-4 py-2 text-center text-md font-semibold ">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php if (empty($vehicle_data)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-gray-500 py-12 border border-dashed border-gray-300 rounded-lg bg-gray-50">
                                        No employee vehicle details found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vehicle_data as $row): ?>
                                
                                <tr class="hover:bg-indigo-50/50 transition duration-150">
                                    <td class="px-4 py-2 whitespace-nowrap text-center">
                                        <input type="checkbox" class="qr-select-checkbox" data-emp-id="<?php echo htmlspecialchars($row['emp_id']); ?>">
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['calling_name']); ?></div>
                                        <div class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($row['emp_id']); ?></div>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-800">
                                        <div class="font-semibold"><?php echo htmlspecialchars($row['vehicle_no']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($row['type']); ?></div> 
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <div>
                                            <span class="items-center px-2.5 py-0.5 rounded-md text-xs font-semibold 
                                                <?php echo (strpos($row['fuel_rate_type'], 'Petrol') !== false) ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                <?php echo htmlspecialchars($row['fuel_rate_type']); ?>
                                            </span></div>
                                            <div>
                                            <span class="text-xs text-gray-500 ml-1"> (Rs. <?php echo number_format($row['fuel_rate'], 2); ?>)</span></div>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-left text-sm font-semibold text-green-600">
                                        <?php echo htmlspecialchars($row['fixed_amount']); ?>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($row['consumption_id']); ?></div>
                                    </td>
                                    <td class="px-4 py-2 whitespace-nowrap text-right text-sm font-semibold text-indigo-600">
                                        <?php echo htmlspecialchars($row['distance']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex justify-center space-x-2">
                                            
                                            <a href="edit_own_vehicle.php?emp_id=<?php echo htmlspecialchars($row['emp_id']); ?>&vehicle_no=<?php echo urlencode($row['vehicle_no']); ?>" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-1 px-2 rounded text-xs transition duration-300" title="Edit Record">
                                                Edit
                                            </a>

                                            <button class="delete-btn bg-red-600 hover:bg-red-700 text-white font-bold py-1 px-2 rounded text-xs transition duration-300" 
                                                title="Delete Record" 
                                                data-emp-id="<?php echo htmlspecialchars($row['emp_id']); ?>" 
                                                data-vehicle-no="<?php echo htmlspecialchars($row['vehicle_no']); ?>"
                                                data-emp-name="<?php echo htmlspecialchars($row['calling_name']); ?>">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div> 

    <div id="toast-container"></div>
    
    <script>
        // --- Toast Notification Function ---
        var toastContainer = document.getElementById("toast-container");

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.classList.add('toast', type);
            const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
            
            toast.innerHTML = `
                <i class="fas ${iconClass} toast-icon"></i>
                <span class="text-sm font-medium">${message}</span>
            `;
            
            toastContainer.appendChild(toast);
            setTimeout(() => { toast.classList.add('show'); }, 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => { toast.remove(); }, 300);
            }, 3000);
        }

        // Display toast message from session on page load
        <?php if ($toast): ?>
            showToast("<?php echo htmlspecialchars($toast['message']); ?>", "<?php echo htmlspecialchars($toast['type']); ?>");
        <?php endif; ?>

        // --- jQuery AJAX Delete Logic (UPDATED) ---
        $(document).ready(function() {
            $('.delete-btn').on('click', function() {
                const empId = $(this).data('emp-id');
                const vehicleNo = $(this).data('vehicle-no'); // Capture vehicle number
                const empName = $(this).data('emp-name');
                
                // Show vehicle no in confirmation
                if (confirm(`Are you sure you want to delete vehicle ${vehicleNo} belonging to ${empName}?`)) {
                    
                    $.ajax({
                        type: 'POST',
                        url: 'process_vehicle.php', 
                        // Send both emp_id and vehicle_no
                        data: { action: 'delete', emp_id: empId, vehicle_no: vehicleNo },
                        dataType: 'json',
                        success: function(response) {
                            showToast(response.message, 'success');
                            setTimeout(() => location.reload(), 1500); 
                        },
                        error: function(xhr) {
                            let errorMessage = 'Deletion failed. Check server response.';
                            try {
                                const errorResponse = JSON.parse(xhr.responseText);
                                errorMessage = errorResponse.message || errorMessage;
                            } catch (e) {}
                            showToast(errorMessage, 'error');
                        }
                    });
                }
            });

            // --- QR Generation Checkbox Logic ---
            const $selectAllCheckbox = $('#select-all-checkbox');
            const $qrCheckboxes = $('.qr-select-checkbox');
            const $generateQrBtn = $('#generate-qr-pdf-btn');

            function updateGenerateButtonLink() {
                const selectedIds = $qrCheckboxes.filter(':checked').map(function() {
                    return $(this).data('emp-id');
                }).get();

                if (selectedIds.length > 0) {
                    $generateQrBtn.attr('href', 'generate_vehicle_qr_pdf.php?emp_ids=' + selectedIds.join(','));
                    $generateQrBtn.removeClass('bg-gray-400 cursor-not-allowed').addClass('bg-green-700 hover:bg-green-800');
                } else {
                    $generateQrBtn.attr('href', '#');
                    $generateQrBtn.removeClass('bg-green-700 hover:bg-green-800').addClass('bg-gray-400 cursor-not-allowed');
                }
            }
            
            updateGenerateButtonLink();

            $selectAllCheckbox.on('change', function() {
                $qrCheckboxes.prop('checked', this.checked);
                updateGenerateButtonLink();
            });

            $qrCheckboxes.on('change', function() {
                if (!this.checked) {
                    $selectAllCheckbox.prop('checked', false);
                } else {
                    if ($qrCheckboxes.length === $qrCheckboxes.filter(':checked').length) {
                        $selectAllCheckbox.prop('checked', true);
                    }
                }
                updateGenerateButtonLink();
            });
            
            $generateQrBtn.on('click', function(e) {
                 if ($(this).attr('href') === '#') {
                     e.preventDefault();
                     showToast('Please select at least one vehicle to generate the QR PDF.', 'error');
                 }
            });
        });
    </script>
</body>
</html>