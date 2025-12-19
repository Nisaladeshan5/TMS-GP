<?php
// night_emergency_barcode.php
// Client-side code to handle UI logic and AJAX calls
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

date_default_timezone_set('Asia/Colombo');

// --- PHP Database Logic (Error handling added) ---
$vehicles = [];
$db_error = false;
if (!isset($conn) || mysqli_connect_error()) {
    $db_error = true;
    error_log("DB Connection failed in night_emergency_barcode.php");
} else {
    $vehicles_query = "SELECT vehicle_no FROM vehicle WHERE purpose = 'night_emergency'";
    $vehicles_result = mysqli_query($conn, $vehicles_query);
    if ($vehicles_result) {
        while ($row = mysqli_fetch_assoc($vehicles_result)) {
            $vehicles[] = $row['vehicle_no'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Night Emergency Scan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e0e7ec 100%);
        }
        .btn-submit:hover {
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.4), 0 2px 4px -1px rgba(59, 130, 246, 0.2);
        }
        .btn-override-cancel:hover {
            box-shadow: 0 4px 6px -1px rgba(75, 85, 99, 0.4), 0 2px 4px -1px rgba(75, 85, 99, 0.2);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
<div class="w-[85%] ml-[15%] mt-10">

<div class="bg-white p-6 sm:p-10 rounded-xl shadow-2xl w-full max-w-xl mx-auto transform transition duration-500 hover:shadow-3xl">
    <h1 class="text-3xl font-extrabold mb-8 text-center text-gray-900 border-b-2 border-indigo-100 pb-3">
        Night Emergency Scan Terminal
    </h1>

    <?php if ($db_error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Error!</strong>
            <span class="block sm:inline"> Database connection failed. Cannot fetch vehicle list.</span>
        </div>
    <?php endif; ?>

    <form id="attendanceForm" method="POST" action="night_emergency_barcode_handler.php">
        
        <div id="step1" class="mb-8">
            <label for="operationalCode" class="block text-gray-700 text-base font-semibold mb-3">Scan Operational Code:</label>
            <input type="text" id="operationalCode" name="operational_code"
                    class="shadow-md appearance-none border border-gray-300 rounded-xl w-full py-4 px-6 text-gray-700 leading-tight focus:ring-4 focus:ring-indigo-300 focus:outline-none transition duration-150"
                    placeholder="Scan operational barcode..." autofocus>
        </div>

        <div id="step2" class="hidden bg-indigo-50 border border-indigo-200 text-indigo-900 p-5 sm:p-6 rounded-xl mb-6 shadow-inner space-y-5">
            <h2 class="text-xl font-bold text-indigo-700 border-b border-indigo-200 pb-3">
                Vehicle & Driver Info
            </h2>
            
            <div class="space-y-2">
                <label for="vehicleNoSelect" class="block text-gray-700 text-sm font-bold">Vehicle Number:</label>
                <select id="vehicleNoSelect" name="vehicle_no_select" class="shadow appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 focus:ring-2 focus:ring-indigo-400 transition" required>
                    <option value="">--- Select Registered Vehicle ---</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= htmlspecialchars($vehicle) ?>"><?= htmlspecialchars($vehicle) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="vehicleNoInput" name="vehicle_no_input" class="shadow appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 focus:ring-2 focus:ring-yellow-400 hidden" placeholder="Enter vehicle number (e.g., ABC-1234)">
                
                <button type="button" id="unknownVehicleBtn" class="btn-override-cancel bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-400 w-full transition duration-150 mt-2">
                    Unknown Vehicle / Out-of-Fleet
                </button>
            </div>
            
            <div class="space-y-2">
                <label for="driverInput" class="block text-gray-700 text-sm font-bold">Driver License ID:</label>
                <div class="flex items-center space-x-2">
                    <input type="text" id="driverInput" name="driver_nic" class="shadow appearance-none border border-gray-300 rounded-lg w-full py-2 px-3 text-gray-700 focus:ring-2 focus:ring-yellow-400 transition bg-gray-200" placeholder="Awaiting vehicle selection..." readonly required>
                    <button type="button" id="unknownDriverBtn" class="btn-override-cancel bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-gray-400 transition duration-150">
                        Override
                    </button>
                </div>
                <p id="driverNameLabel" class="text-gray-600 text-sm mt-1 font-medium pl-1"></p>
            </div>
            
            <div class="mt-6 pt-3 border-t border-indigo-100">
                <div class="flex items-center">
                    <input type="checkbox" id="verifyDetails" name="verify" required class="form-checkbox h-5 w-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <label for="verifyDetails" class="ml-3 text-gray-700 text-sm font-medium cursor-pointer">I verify the vehicle and driver details are correct.</label>
                </div>
            </div>
            
            <button type="submit" class="btn-submit bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-xl focus:outline-none focus:ring-4 focus:ring-blue-300 shadow-md w-full mt-4 transition duration-300 ease-in-out transform hover:scale-[1.01]">
                Record Dispatch
            </button>
        </div>
    </form>

    <div id="statusMessage" class="mt-8 p-4 rounded-xl text-center font-medium hidden shadow-md"></div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
    // --- UI Elements ---
    const operationalCodeInput = document.getElementById('operationalCode');
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const statusMessageDiv = document.getElementById('statusMessage');
    const attendanceForm = document.getElementById('attendanceForm');

    const vehicleSelect = document.getElementById('vehicleNoSelect');
    const vehicleInput = document.getElementById('vehicleNoInput');
    const unknownVehicleBtn = document.getElementById('unknownVehicleBtn');
    const unknownDriverBtn = document.getElementById('unknownDriverBtn');
    const driverInput = document.getElementById('driverInput');
    const driverNameLabel = document.getElementById('driverNameLabel');

    let isUnknownVehicle = false;
    let isUnknownDriver = true; // Default to true until a vehicle is selected
    
    // --- Utility Functions ---
    function showMessage(message, type='info'){
        statusMessageDiv.textContent = message;
        statusMessageDiv.className = 'mt-8 p-4 rounded-xl text-center font-medium shadow-md border';
        if(type==='success') statusMessageDiv.classList.add('bg-green-100','text-green-800','border-green-300');
        else if(type==='error') statusMessageDiv.classList.add('bg-red-100','text-red-800','border-red-300');
        else statusMessageDiv.classList.add('bg-blue-100','text-blue-800','border-blue-300');
        statusMessageDiv.classList.remove('hidden');
        setTimeout(()=>{ statusMessageDiv.classList.add('hidden'); },5000);
    }

    function setDriverInputReadonly(readonly){
        driverInput.readOnly = readonly;
        driverInput.classList.toggle('bg-gray-200',readonly);
    }

    function clearDriverInfo(){
        driverInput.value = '';
        driverNameLabel.textContent = 'Enter driver License ID...';
        setDriverInputReadonly(false); // Enable input for manual entry
        isUnknownDriver = true;
        unknownDriverBtn.textContent = 'Registered Driver?';
        driverInput.focus();
    }
    
    // --- Event Handlers ---
    
    // Vehicle selection (Registered Vehicle selected)
    vehicleSelect.addEventListener('change', function(){
        const vehicleNo = this.value;
        if(vehicleNo){
            driverNameLabel.textContent = 'Fetching assigned driver...';
            setDriverInputReadonly(true); 
            isUnknownDriver = false; 
            unknownDriverBtn.textContent='Override';
            
            fetch('get_driver_info.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({vehicle_no:vehicleNo})
            })
            .then(res => {
                if (!res.ok) throw new Error("Server response not ok: " + res.status);
                return res.json();
            })
            .then(data=>{
                if(data.status==='success' && data.driver_nic){
                    driverInput.value=data.driver_nic;
                    driverNameLabel.textContent=`Assigned Driver: ${data.calling_name || 'Name Unknown'}`;
                    setDriverInputReadonly(true);
                }else{ 
                    clearDriverInfo();
                    driverNameLabel.textContent='No assigned driver found. Please enter manually.';
                }
            }).catch(e=>{ 
                clearDriverInfo(); 
                showMessage('Error fetching driver info: ' + e.message,'error'); 
            });
        }else{ 
            clearDriverInfo(); 
            driverNameLabel.textContent = 'Please select a vehicle or enter driver manually.';
        }
    });
    
    // Unknown Vehicle Toggle
    unknownVehicleBtn.addEventListener('click',()=>{
        isUnknownVehicle=!isUnknownVehicle;
        vehicleSelect.classList.toggle('hidden',isUnknownVehicle);
        vehicleInput.classList.toggle('hidden',!isUnknownVehicle);
        
        vehicleInput.required=isUnknownVehicle;
        vehicleSelect.required=!isUnknownVehicle;
        
        unknownVehicleBtn.textContent=isUnknownVehicle?'Select Registered Vehicle':'Unknown Vehicle / Out-of-Fleet';
        
        if(isUnknownVehicle){ 
            vehicleInput.focus(); 
            clearDriverInfo(); 
            driverNameLabel.textContent='Enter vehicle and driver manually.';
        }
        else{ 
            vehicleSelect.focus(); 
            if(vehicleSelect.value) vehicleSelect.dispatchEvent(new Event('change'));
            else clearDriverInfo();
        }
    });

    // Driver Override Toggle
    unknownDriverBtn.addEventListener('click',()=>{
        const selectedVehicle = isUnknownVehicle ? vehicleInput.value.trim() : vehicleSelect.value;
        
        // Allow toggling only when a registered vehicle is selected
        if (selectedVehicle && !isUnknownVehicle) {
            isUnknownDriver = !isUnknownDriver;
            setDriverInputReadonly(!isUnknownDriver); // Toggle readonly status
            
            if(isUnknownDriver){ 
                driverInput.value=''; 
                driverNameLabel.textContent='Manual entry enabled. Enter License ID.'; 
                unknownDriverBtn.textContent='Use Default';
                driverInput.focus();
            } else { 
                unknownDriverBtn.textContent='Override'; 
                vehicleSelect.dispatchEvent(new Event('change')); // Restore default driver info
            }
        } else if (isUnknownVehicle) {
             showMessage('Driver must be entered manually for Unknown Vehicles.','info');
        } else {
             showMessage('Please select a registered vehicle first to use the Override feature.','info');
        }
    });

    // OPERATIONAL CODE Scan Logic (Step 1)
    operationalCodeInput.addEventListener('input', function(){
        const code=this.value.trim();
        const expectedLength = 7; // Assuming operational code is 5 chars

        if(code.length >= expectedLength){
            operationalCodeInput.value=code.substring(0, expectedLength); // Truncate and enforce max length
            
            // AJAX call to check_operational_code.php
            fetch('check_operational_code.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({operational_code:code.substring(0, expectedLength)})
            })
            .then(res => {
                if (!res.ok) throw new Error("Server error during operational code check: " + res.status);
                return res.json();
            })
            .then(data=>{
                if(data.exists){
                    step1.classList.add('hidden'); 
                    step2.classList.remove('hidden');
                    
                    // Reset to default step2 state
                    isUnknownVehicle = false;
                    vehicleInput.classList.add('hidden');
                    vehicleSelect.classList.remove('hidden');
                    vehicleSelect.required = true;
                    vehicleInput.required = false;
                    unknownVehicleBtn.textContent='Unknown Vehicle / Out-of-Fleet';

                    vehicleSelect.focus();
                    showMessage('Operational code verified. Proceeding to vehicle details.','success');
                    
                    if(vehicleSelect.value) vehicleSelect.dispatchEvent(new Event('change'));
                    else clearDriverInfo(); 
                }
                else{ 
                    showMessage(data.message || 'Operational code is invalid or already recorded today.','error'); 
                    operationalCodeInput.value=''; 
                    operationalCodeInput.focus(); 
                }
            }).catch(e=>{ 
                // This catch block handles the internal error you initially reported
                showMessage('An internal server error occurred while checking the Operational Code. Please check the console/network tab for the PHP error.','error'); 
                console.error('Operational Code Check Fetch Error:', e);
                operationalCodeInput.value=''; 
                operationalCodeInput.focus(); 
            });
        }
    });

    // Form Submission Handler
    attendanceForm.addEventListener('submit', function(e){
        e.preventDefault();
        
        if(driverInput.value.trim() === '') {
            showMessage('Driver License ID is required.','error');
            driverInput.focus();
            return;
        }

        const formData = new FormData(this);
        
        // Get the correct Vehicle No. and Status
        const vehicleNo = isUnknownVehicle ? vehicleInput.value.trim() : vehicleSelect.value;
        const vehicleStatus = isUnknownVehicle ? 0 : 1;
        const driverNic = driverInput.value.trim();
        const driverStatus = isUnknownDriver ? 0 : 1;
        
        // Get the scanned operational code from the input field
        const operationalCode = operationalCodeInput.value.trim();

        // Overriding the form data values to ensure correct submission
        formData.set('operational_code', operationalCode); // *** CRITICAL FIX: Ensure operational_code is submitted ***
        formData.set('vehicle_no',vehicleNo);
        formData.set('vehicle_status',vehicleStatus);
        formData.set('driver_nic',driverNic);
        formData.set('driver_status',driverStatus);
        
        // Final Fetch to handler
        fetch('night_emergency_barcode_handler.php',{method:'POST',body:formData})
        .then(res => {
            if (!res.ok) throw new Error("Handler server error: " + res.status);
            return res.json();
        })
        .then(data=>{
            showMessage(data.message,data.status);
            
            // --- Reset UI ---
            operationalCodeInput.value=''; vehicleInput.value=''; vehicleSelect.value=''; document.getElementById('verifyDetails').checked=false;
            isUnknownVehicle=false; 
            
            // Ensure driver is reset to the 'awaiting vehicle' state
            driverInput.value = '';
            driverNameLabel.textContent = 'Awaiting vehicle selection...';
            setDriverInputReadonly(true);
            isUnknownDriver = true;
            unknownDriverBtn.textContent = 'Override';


            // Reset Vehicle/Driver controls visibility
            vehicleSelect.classList.remove('hidden'); vehicleInput.classList.add('hidden');
            unknownVehicleBtn.textContent='Unknown Vehicle / Out-of-Fleet';

            // Back to Step 1
            step2.classList.add('hidden'); 
            step1.classList.remove('hidden'); 
            operationalCodeInput.focus();
        }).catch(e=>{ 
            showMessage('Critical submission error. Try again.','error'); 
            console.error('Submission Fetch Error:', e);
            
            // Force reset to step 1 on critical error
            step2.classList.add('hidden'); 
            step1.classList.remove('hidden'); 
            operationalCodeInput.focus();
        });
    });

    setDriverInputReadonly(true); // Default to read-only on load
    operationalCodeInput.focus();
});

</script>
</body>
</html>