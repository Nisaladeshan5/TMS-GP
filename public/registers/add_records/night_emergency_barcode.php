<?php
// night_emergency_barcode.php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

date_default_timezone_set('Asia/Colombo');

$vehicles_query = "SELECT vehicle_no FROM vehicle WHERE purpose = 'night_emergency'";
$vehicles_result = mysqli_query($conn, $vehicles_query);
$vehicles = [];
if ($vehicles_result) {
    while ($row = mysqli_fetch_assoc($vehicles_result)) {
        $vehicles[] = $row['vehicle_no'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transport Scan Terminal</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
<div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%]">
    <div class="text-lg font-semibold ml-3">Registers</div>
    <div class="flex gap-4">
        <a href="night_emergency_attendance.php" class="hover:text-yellow-600">Attendance</a>
    </div>
</div>
<div class="flex items-center justify-center min-h-screen w-[85%] ml-[15%]">
    <div class="w-full max-w-sm bg-white p-6 rounded-lg shadow-md">
        <h1 class="text-2xl font-bold mb-6 text-center text-gray-800">Transport Scan Terminal</h1>
        
        <form id="attendanceForm" method="POST" action="night_emergency_barcode_handler.php">
            <div id="step1">
                <label for="supplierCode" class="block text-gray-700 text-sm font-bold mb-2">Scan Supplier Code:</label>
                <input type="text" id="supplierCode" name="supplier_code" class="shadow appearance-none border rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" placeholder="Scan supplier barcode..." autofocus>
            </div>
            
            <div id="step2" class="hidden">
                <div class="mb-4">
                    <label for="vehicleNo" class="block text-gray-700 text-sm font-bold mb-2">Vehicle Number:</label>
                    <select id="vehicleNoSelect" name="vehicle_no_select" class="shadow appearance-none border rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        <option value="">Select a Vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= htmlspecialchars($vehicle) ?>"><?= htmlspecialchars($vehicle) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="vehicleNoInput" name="vehicle_no_input" class="shadow appearance-none border rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline hidden mt-2" placeholder="Enter vehicle number">
                    <button type="button" id="unknownVehicleBtn" class="bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-1 px-2 rounded focus:outline-none focus:shadow-outline mt-2 w-full">Unknown Vehicle</button>
                </div>
                <div class="mb-4">
                    <label for="driverInput" class="block text-gray-700 text-sm font-bold mb-2">Driver:</label>
                    <div class="flex ">
                        <input type="text" id="driverInput" name="driver_nic"
       class="shadow appearance-none border rounded-lg w-[50%] py-2 mr-4 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
       placeholder="Enter driver License ID...">

<p id="driverNameLabel" class="text-gray-600 text-sm mt-1"></p>
                        <button type="button" id="unknownDriverBtn" class="bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-1 px-2 rounded focus:outline-none focus:shadow-outline ml-2">Unknown</button>
                    </div>
                </div>
                <div class="mb-4">
                    <div class="flex items-center">
                        <input type="checkbox" id="verifyDetails" name="verify" required class="form-checkbox h-5 w-5 text-blue-600">
                        <label for="verifyDetails" class="ml-2 text-gray-700 text-sm">I have verified the details are correct.</label>
                    </div>
                </div>
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline w-full">Record Attendance</button>
            </div>
        </form>

        <div id="statusMessage" class="mt-4 text-center"></div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const supplierCodeInput = document.getElementById('supplierCode');
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const statusMessageDiv = document.getElementById('statusMessage');
        const attendanceForm = document.getElementById('attendanceForm');
        
        const vehicleSelect = document.getElementById('vehicleNoSelect');
        const vehicleInput = document.getElementById('vehicleNoInput');
        const unknownVehicleBtn = document.getElementById('unknownVehicleBtn');
        const unknownDriverBtn = document.getElementById('unknownDriverBtn');

        const driverInput = document.getElementById('driverInput');
        
        let isUnknownVehicle = false;
        let isUnknownDriver = false;

        // Function to set driver input as readonly or editable
        function setDriverInputReadonly(readonly) {
            driverInput.readOnly = readonly;
            driverInput.classList.toggle('bg-gray-200', readonly);
            if (!readonly) {
                driverInput.focus();
            }
        }
        
        // Function to clear driver input
        function clearDriverInput() {
            driverInput.value = '';
            driverInput.placeholder = "Enter driver License ID...";
        }

        // Handle change in vehicle dropdown
        vehicleSelect.addEventListener('change', function() {
            const vehicleNo = this.value;
            clearDriverInput();
            isUnknownDriver = false;
            unknownDriverBtn.textContent = 'Unknown';
            if (vehicleNo) {
                setDriverInputReadonly(true);
                // Fetch driver details for the selected vehicle
                fetch('get_driver_info.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ vehicle_no: vehicleNo })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.driver_nic) {
    driverInput.value = data.driver_nic; // NIC goes into input (saved in DB)
    document.getElementById('driverNameLabel').textContent =
        `Name: ${data.calling_name}`; // Show name separately
} else {
    driverInput.value = '';
    document.getElementById('driverNameLabel').textContent = '';
    setDriverInputReadonly(false);
    driverInput.placeholder = "No assigned driver. Enter License ID...";
}
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusMessageDiv.textContent = 'An error occurred while fetching driver info.';
                    statusMessageDiv.className = 'mt-4 text-center text-red-600 font-bold';
                    setDriverInputReadonly(false);
                });
            } else {
                setDriverInputReadonly(false);
            }
        });

        // Handle "Unknown Vehicle" button click
        unknownVehicleBtn.addEventListener('click', () => {
            isUnknownVehicle = !isUnknownVehicle;
            vehicleSelect.classList.toggle('hidden', isUnknownVehicle);
            vehicleInput.classList.toggle('hidden', !isUnknownVehicle);
            vehicleInput.required = isUnknownVehicle;
            vehicleSelect.required = !isUnknownVehicle;
            unknownVehicleBtn.textContent = isUnknownVehicle ? 'Select Registered Vehicle' : 'Unknown Vehicle';
            
            clearDriverInput();
            setDriverInputReadonly(!isUnknownVehicle);
            isUnknownDriver = isUnknownVehicle; 
            unknownDriverBtn.textContent = isUnknownVehicle ? 'Registered' : 'Unknown';

            if (isUnknownVehicle) {
                vehicleInput.focus();
                driverInput.placeholder = "Enter driver License ID...";
            } else {
                vehicleSelect.focus();
                driverInput.placeholder = "";
            }
        });

        // Handle "Unknown Driver" button click
        unknownDriverBtn.addEventListener('click', () => {
            isUnknownDriver = !isUnknownDriver;
            setDriverInputReadonly(!isUnknownDriver);
            unknownDriverBtn.textContent = isUnknownDriver ? 'Registered' : 'Unknown';
            if (isUnknownDriver) {
                clearDriverInput();
            }
        });

        // Handle barcode scan
        supplierCodeInput.addEventListener('input', function(e) {
            const expectedBarcodeLength = 5;
            const supplierCode = this.value.trim();
            if (supplierCode.length === expectedBarcodeLength) {
                fetch('check_supplier.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ supplier_code: supplierCode })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.exists) {
                        step1.classList.add('hidden');
                        step2.classList.remove('hidden');
                        vehicleSelect.focus();
                        statusMessageDiv.textContent = '';
                        statusMessageDiv.className = 'mt-4 text-center';
                    } else {
                        statusMessageDiv.textContent = 'Error: Supplier code does not exist.';
                        statusMessageDiv.className = 'mt-4 text-center text-red-600 font-bold';
                        supplierCodeInput.value = '';
                        supplierCodeInput.focus();
                        setTimeout(() => {
                            statusMessageDiv.textContent = '';
                            statusMessageDiv.className = 'mt-4 text-center';
                        }, 3000);
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusMessageDiv.textContent = 'An error occurred. Please try again.';
                    statusMessageDiv.className = 'mt-4 text-center text-red-600 font-bold';
                    supplierCodeInput.value = '';
                    supplierCodeInput.focus();
                });
            }
        });

        // Handle form submission
        attendanceForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            if (isUnknownVehicle) {
                formData.set('vehicle_no', vehicleInput.value);
                formData.set('vehicle_status', 0);
            } else {
                formData.set('vehicle_no', vehicleSelect.value);
                formData.set('vehicle_status', 1);
            }
            
            formData.set('driver_status', isUnknownDriver ? 0 : 1);

            fetch('night_emergency_barcode_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                statusMessageDiv.textContent = data.message;
                statusMessageDiv.className = `mt-4 text-center font-bold ${data.status === 'success' ? 'text-green-600' : 'text-red-600'}`;
                
                supplierCodeInput.value = '';
                vehicleInput.value = '';
                clearDriverInput();
                setDriverInputReadonly(false);
                document.getElementById('verifyDetails').checked = false;
                
                isUnknownVehicle = false;
                isUnknownDriver = false;
                vehicleSelect.classList.remove('hidden');
                vehicleInput.classList.add('hidden');
                unknownVehicleBtn.textContent = 'Unknown Vehicle';
                unknownDriverBtn.textContent = 'Unknown';
                vehicleSelect.value = '';

                step2.classList.add('hidden');
                step1.classList.remove('hidden');
                supplierCodeInput.focus();

                setTimeout(() => {
                    statusMessageDiv.textContent = '';
                    statusMessageDiv.className = 'mt-4 text-center';
                }, 3000);
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                statusMessageDiv.textContent = 'An error occurred. Please try again.';
                statusMessageDiv.className = 'mt-4 text-center text-red-600 font-bold';
                
                supplierCodeInput.value = '';
                step2.classList.add('hidden');
                step1.classList.remove('hidden');
                supplierCodeInput.focus();
            });
        });
    });
</script>
</body>
</html>