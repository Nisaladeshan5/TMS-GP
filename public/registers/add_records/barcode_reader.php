<?php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transport Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Reintroducing the clean, modern styles */
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e0e7ec 100%); /* Light gradient background */
        }
        
        /* Main card styling */
        .main-card {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        
        /* Custom styles for button hovers/shadows */
        .btn-submit:not(:disabled):hover {
            box-shadow: 0 4px 10px -2px rgba(16, 185, 129, 0.6);
            transform: scale(1.02);
        }
        .btn-cancel:hover {
            box-shadow: 0 4px 10px -2px rgba(239, 68, 68, 0.6);
            transform: scale(1.02);
        }
        /* Style for disabled button */
        .btn-submit:disabled {
            background-color: #a7f3d0; /* Light green/teal */
            cursor: not-allowed;
            opacity: 0.8;
            transform: none !important;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div class="w-full">
        <div class="w-[85%] ml-[15%]">
            <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-full">
                <div class="text-lg font-semibold ml-3">Registers</div>
                <div class="flex gap-4 pr-4">
                    <a href="" class="hover:text-yellow-400 transition">Factory Register</a>
                    <a href="../Staff transport vehicle register.php" class="hover:text-yellow-400 transition">Staff Register</a>
                </div>
            </div>

            <div class="main-card bg-white p-6 sm:p-10 rounded-xl shadow-2xl w-full max-w-lg mx-auto mt-6">
                
                <h1 class="text-3xl font-extrabold mb-8 text-center text-gray-900 border-b-2 border-indigo-100 pb-3">
                    Transport Scan Terminal
                </h1>

                <div class="mb-8">
                    <label for="barcodeInput" class="block text-gray-700 text-base font-semibold mb-2">Scan Route QR code:</label>
                    <input type="text" id="barcodeInput" class="shadow-md appearance-none border border-gray-300 rounded-xl w-full py-4 px-6 text-gray-700 leading-tight focus:ring-4 focus:ring-indigo-300 focus:outline-none transition duration-150" placeholder="Scan route barcode here..." autofocus>
                </div>

                <div id="transportDetails" class="hidden bg-indigo-50 border border-indigo-200 text-indigo-900 p-4 sm:p-6 rounded-xl shadow-inner">
                    <h2 class="text-xl font-bold mb-4 text-indigo-700 border-b border-indigo-200 pb-3">Transaction Details</h2>

                    <div class="grid grid-cols-2 gap-y-2 mb-4">
                        <p class="text-gray-600"><strong>Route Name:</strong></p>
                        <span id="detailRouteName" class="font-semibold text-indigo-800 text-right"></span>

                        <p class="text-gray-600"><strong>Shift:</strong></p>
                        <span id="detailShift" class="font-semibold text-indigo-800 text-right"></span>

                        <p class="text-gray-600"><strong>Action:</strong></p>
                        <span id="detailTransactionType" class="font-bold text-lg text-blue-800 uppercase text-right"></span>
                    </div>

                    <div class="space-y-4 border-t border-indigo-100 pt-2 mt-2">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between">
                            <label class="block text-gray-700 text-sm font-bold w-full sm:w-1/3 mb-1 sm:mb-0">Vehicle No:</label>
                            <div class="flex items-center w-full sm:w-2/3 space-x-4">
                                <span id="displayVehicleNo" class="font-semibold text-gray-800 flex-grow"></span>
                                <input type="text" id="editableVehicleNo" class="hidden border border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-2 focus:ring-yellow-400 w-full" placeholder="Enter Vehicle No">
                                <div class="flex items-center whitespace-nowrap">
                                    <input type="checkbox" id="unknownVehicleToggle" class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded">
                                    <label for="unknownVehicleToggle" class="ml-2 text-sm text-gray-700 cursor-pointer">Unknown</label>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between">
                            <label class="block text-gray-700 text-sm font-bold w-full sm:w-1/3 mb-1 sm:mb-0">Driver NIC:</label>
                            <div class="flex items-center w-full sm:w-2/3 space-x-4">
                                <span id="displayDriverNIC" class="font-semibold text-gray-800 flex-grow"></span>
                                <input type="text" id="editableDriverNIC" class="hidden border border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-2 focus:ring-yellow-400 w-full" placeholder="Enter Driver NIC">
                                <div class="flex items-center whitespace-nowrap">
                                    <input type="checkbox" id="unknownDriverToggle" class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded">
                                    <label for="unknownDriverToggle" class="ml-2 text-sm text-gray-700 cursor-pointer">Unknown</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" id="originalRouteCode">
                    <input type="hidden" id="existingRecordId">
                </div>

                <div id="actionButtons" class="hidden flex flex-col space-y-3 mt-4">
                    
                    <div id="confirmationCheck" class="flex items-center justify-center p-3 rounded-xl bg-yellow-50 shadow-inner border border-yellow-200">
                        <input type="checkbox" id="finalConfirmCheck" class="h-5 w-5 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded cursor-pointer">
                        <label for="finalConfirmCheck" class="ml-3 text-base font-medium text-gray-700 select-none cursor-pointer">
                            I confirm all details are correct
                        </label>
                    </div>
                    
                    <div class="flex justify-between space-x-4">
                        <button id="submitBtn" class="btn-submit w-1/2 bg-green-500 text-white font-bold py-3 px-6 rounded-xl focus:outline-none focus:ring-4 focus:ring-green-300 shadow-lg transition duration-300 ease-in-out" disabled>
                            Confirm & Record
                        </button>
                        <button id="cancelBtn" class="btn-cancel w-1/2 bg-red-500 hover:bg-red-600 text-white font-bold py-3 px-6 rounded-xl focus:outline-none focus:ring-4 focus:ring-red-300 shadow-lg transition duration-300 ease-in-out transform hover:scale-[1.02]">
                            Cancel
                        </button>
                    </div>
                </div>

                <div id="messageBox" class="mt-2 p-1 rounded-xl text-center font-medium hidden shadow-md"></div>
            </div>
        </div>
    </div>

    <script>
    const barcodeInput = document.getElementById('barcodeInput');
    const transportDetailsDiv = document.getElementById('transportDetails');
    const detailRouteName = document.getElementById('detailRouteName');
    const detailShift = document.getElementById('detailShift');
    const detailTransactionType = document.getElementById('detailTransactionType');

    const displayVehicleNo = document.getElementById('displayVehicleNo');
    const editableVehicleNo = document.getElementById('editableVehicleNo');
    const unknownVehicleToggle = document.getElementById('unknownVehicleToggle');

    const displayDriverNIC = document.getElementById('displayDriverNIC');
    const editableDriverNIC = document.getElementById('editableDriverNIC');
    const unknownDriverToggle = document.getElementById('unknownDriverToggle');

    const originalRouteCode = document.getElementById('originalRouteCode');
    const existingRecordId = document.getElementById('existingRecordId');
    const actionButtonsDiv = document.getElementById('actionButtons');
    const submitBtn = document.getElementById('submitBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const messageBox = document.getElementById('messageBox');
    const finalConfirmCheck = document.getElementById('finalConfirmCheck'); // Get the new checkbox

    let currentScanData = null;
    let scanTimeout;

    // 1. Function to toggle the submit button state
    function toggleSubmitButtonState() {
        const shouldBeEnabled = finalConfirmCheck.checked && !transportDetailsDiv.classList.contains('hidden');
        submitBtn.disabled = !shouldBeEnabled;
        
        // Apply/Remove hover effects based on disabled state
        if (submitBtn.disabled) {
            submitBtn.classList.remove('hover:bg-green-600', 'transform', 'hover:scale-[1.02]');
        } else {
            submitBtn.classList.add('hover:bg-green-600', 'transform', 'hover:scale-[1.02]');
        }
    }

    function showMessage(message, type = 'info') {
        messageBox.textContent = message;
        messageBox.className = `mt-8 p-4 rounded-xl text-center font-medium shadow-md ${
            type === 'success' ? 'bg-green-100 text-green-800 border border-green-300' :
            type === 'error' ? 'bg-red-100 text-red-800 border border-red-300' :
            'bg-yellow-100 text-yellow-800 border border-yellow-300'
        }`;
        messageBox.style.display = 'block';
        setTimeout(() => {
            messageBox.style.display = 'none';
        }, 5000);
    }

    function getCurrentShift() {
        const hour = new Date().getHours();
        return (hour >= 0 && hour < 12) ? 'morning' : 'evening';
    }

    barcodeInput.addEventListener('input', function(event) {
        clearTimeout(scanTimeout);

        scanTimeout = setTimeout(() => {
            const routeCode = barcodeInput.value.trim();
            if (routeCode) {
                fetchTransportDetails(routeCode);
                // Important: Clear the input after the read is confirmed
                barcodeInput.value = ''; 
            }
        }, 50); 
    });

    unknownVehicleToggle.addEventListener('change', function() {
        if (this.checked) {
            displayVehicleNo.classList.add('hidden');
            editableVehicleNo.classList.remove('hidden');
            editableVehicleNo.focus();
        } else {
            displayVehicleNo.classList.remove('hidden');
            editableVehicleNo.classList.add('hidden');
            editableVehicleNo.value = displayVehicleNo.textContent;
        }
    });

    unknownDriverToggle.addEventListener('change', function() {
        if (this.checked) {
            displayDriverNIC.classList.add('hidden');
            editableDriverNIC.classList.remove('hidden');
            editableDriverNIC.focus();
        } else {
            displayDriverNIC.classList.remove('hidden');
            editableDriverNIC.classList.add('hidden');
            editableDriverNIC.value = displayDriverNIC.textContent;
        }
    });

    // 2. Event listener for the confirmation checkbox
    finalConfirmCheck.addEventListener('change', toggleSubmitButtonState);


    function fetchTransportDetails(routeCode) {
        resetUI();
        showMessage('Fetching transport details...', 'info');

        fetch(`get_transport_details.php?route_code=${encodeURIComponent(routeCode)}&shift=${encodeURIComponent(getCurrentShift())}`)
            .then(response => { if (!response.ok) throw new Error('Network response was not ok'); return response.json(); })
            .then(data => {
                if (data.success) {
                    currentScanData = data;
                    detailRouteName.textContent = data.route_name;
                    detailShift.textContent = getCurrentShift().charAt(0).toUpperCase() + getCurrentShift().slice(1);
                    detailTransactionType.textContent = data.transaction_type.toUpperCase();
                    detailTransactionType.classList.remove('text-red-600','text-green-600','text-blue-800');
                    detailTransactionType.classList.add(data.transaction_type==='in'?'text-green-600':'text-red-600');

                    // Set visibility controls
                    const unknownVehicleControl = unknownVehicleToggle.closest('.flex.items-center.whitespace-nowrap');
                    const unknownDriverControl = unknownDriverToggle.closest('.flex.items-center.whitespace-nowrap');

                    if (data.transaction_type === 'out') {
                        displayVehicleNo.textContent = data.vehicle_no; 
                        displayDriverNIC.textContent = data.driver_nic; 
                        
                        unknownVehicleControl.style.display = 'none';
                        unknownDriverControl.style.display = 'none';

                    } else { // For 'in' transactions
                        displayVehicleNo.textContent = data.default_vehicle_no;
                        editableVehicleNo.value = data.default_vehicle_no;

                        displayDriverNIC.textContent = data.default_driver_nic;
                        editableDriverNIC.value = data.default_driver_nic; 

                        unknownVehicleControl.style.display = 'flex';
                        unknownDriverControl.style.display = 'flex';
                    }

                    originalRouteCode.value = routeCode;
                    existingRecordId.value = data.existing_record_id || '';

                    transportDetailsDiv.classList.remove('hidden');
                    actionButtonsDiv.classList.remove('hidden');
                    showMessage(data.message, 'success');
                    
                    // 3. Update button state after details are loaded (will disable it initially)
                    toggleSubmitButtonState(); 

                } else {
                    showMessage(data.message, 'error');
                    currentScanData = null;
                    toggleSubmitButtonState();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while fetching details.', 'error');
                currentScanData = null;
                toggleSubmitButtonState();
            });
    }

    submitBtn.addEventListener('click', function() {
        if (!finalConfirmCheck.checked) {
             showMessage('Please confirm details by checking the box.', 'error');
             return;
        }

        if (currentScanData) {
            const routeCode = originalRouteCode.value;
            const transactionType = currentScanData.transaction_type;
            const recordId = existingRecordId.value;
            const shift = getCurrentShift();

            const vehicleNo = unknownVehicleToggle.checked ? editableVehicleNo.value.trim() : displayVehicleNo.textContent.trim();
            const driverNIC = unknownDriverToggle.checked ? editableDriverNIC.value.trim() : displayDriverNIC.textContent.trim();
            
            const vehicleStatus = unknownVehicleToggle.checked ? 0 : 1;
            const driverStatus = unknownDriverToggle.checked ? 0 : 1;

            if (!vehicleNo || !driverNIC) {
                showMessage('Vehicle No and Driver NIC cannot be empty.', 'error');
                return;
            }

            submitTransportTransaction(routeCode, vehicleNo, driverNIC, transactionType, recordId, shift, vehicleStatus, driverStatus);
        } else {
            showMessage('No scan details to submit.', 'error');
        }
    });

    cancelBtn.addEventListener('click', function() {
        resetUI();
        showMessage('Scan cancelled. Ready for new scan.', 'info');
    });

    function submitTransportTransaction(routeCode, vehicleNo, driverNIC, transactionType, recordId, shift, vehicleStatus, driverStatus) {
        showMessage('Submitting transaction...', 'info');

        const formData = new FormData();
        formData.append('route_code', routeCode);
        formData.append('vehicle_no', vehicleNo);
        formData.append('driver_nic', driverNIC); 
        formData.append('transaction_type', transactionType);
        formData.append('shift', shift);
        formData.append('vehicle_status', vehicleStatus);
        formData.append('driver_status', driverStatus);
        if (recordId) {
            formData.append('existing_record_id', recordId);
        }

        fetch('submit_transport_transaction.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                resetUI();
            } else {
                showMessage(data.message, 'error');
                toggleSubmitButtonState(); 
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showMessage('An error occurred while submitting transaction.', 'error');
            toggleSubmitButtonState(); 
        });
    }

    function resetUI() {
        transportDetailsDiv.classList.add('hidden');
        actionButtonsDiv.classList.add('hidden');
        detailRouteName.textContent = '';
        detailShift.textContent = '';
        detailTransactionType.textContent = '';
        detailTransactionType.classList.remove('text-red-600','text-green-600');
        detailTransactionType.classList.add('text-blue-800');
        
        displayVehicleNo.textContent = '';
        editableVehicleNo.value = '';
        editableVehicleNo.classList.add('hidden');
        displayVehicleNo.classList.remove('hidden');
        unknownVehicleToggle.checked = false;

        displayDriverNIC.textContent = '';
        editableDriverNIC.value = '';
        editableDriverNIC.classList.add('hidden');
        displayDriverNIC.classList.remove('hidden');
        unknownDriverToggle.checked = false;
        
        originalRouteCode.value = '';
        existingRecordId.value = '';
        currentScanData = null;
        
        // Reset checkbox and button state
        finalConfirmCheck.checked = false;
        toggleSubmitButtonState(); 
        
        barcodeInput.focus();
    }

    // Initial call to set the button state correctly on page load
    toggleSubmitButtonState();
    barcodeInput.focus();
</script>
</body>
</html>