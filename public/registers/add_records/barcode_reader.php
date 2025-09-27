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
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="w-[85%] ml-[15%]">
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-full">
            <div class="text-lg font-semibold ml-3">Registers</div>
            <div class="flex gap-4 pr-4">
                <a href="" class="hover:text-yellow-600">Factory Register</a>
                <a href="../Staff transport vehicle register.php" class="hover:text-yellow-600">Staff Register</a>
            </div>
        </div>
        <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-lg mt-20 mx-auto">
            <h1 class="text-2xl font-bold mb-6 text-center text-gray-800">Transport Scan Terminal</h1>

            <div class="mb-4">
                <label for="barcodeInput" class="block text-gray-700 text-sm font-bold mb-2">Scan Route Barcode:</label>
                <input type="text" id="barcodeInput" class="shadow appearance-none border rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" placeholder="Scan route barcode..." autofocus>
            </div>

            <div id="transportDetails" class="hidden bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg mb-4">
                <h2 class="text-xl flex items-center justify-center font-semibold mb-2">Scan Details</h2>
                <p class="text-black"><strong>Route Name :</strong> <span id="detailRouteName" class="font-bold text-blue-800"></span></p>
                <p class="text-black"><strong>Shift :</strong> <span id="detailShift" class="font-bold text-blue-800"></span></p>
                <p class="text-black"><strong>Transaction Type :</strong> <span id="detailTransactionType" class="font-bold text-blue-800"></span></p>

                <div class="mt-4 flex items-center justify-between">
                    <label class="block text-gray-700 text-sm font-bold">Vehicle No:</label>
                    <div class="flex items-center">
                        <span id="displayVehicleNo" class="font-bold text-gray-800 mr-4"></span>
                        <input type="text" id="editableVehicleNo" class="hidden shadow appearance-none border rounded-lg py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        <div class="flex items-center">
                            <input type="checkbox" id="unknownVehicleToggle" class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded">
                            <label for="unknownVehicleToggle" class="ml-2 text-sm text-gray-700">Unknown</label>
                        </div>
                    </div>
                </div>

                <div class="mt-2 flex items-center justify-between">
                    <label class="block text-gray-700 text-sm font-bold">Driver NIC:</label>
                    <div class="flex items-center">
                        <span id="displayDriverNIC" class="font-bold text-gray-800 mr-4"></span>
                        <input type="text" id="editableDriverNIC" class="hidden shadow appearance-none border rounded-lg py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        <div class="flex items-center">
                            <input type="checkbox" id="unknownDriverToggle" class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded">
                            <label for="unknownDriverToggle" class="ml-2 text-sm text-gray-700">Unknown</label>
                        </div>
                    </div>
                </div>

                <input type="hidden" id="originalRouteCode">
                <input type="hidden" id="existingRecordId">
            </div>

            <div id="actionButtons" class="hidden flex justify-center space-x-4 mt-6">
                <button id="submitBtn" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg focus:outline-none focus:shadow-outline shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
                    Confirm & Record
                </button>
                <button id="cancelBtn" class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-6 rounded-lg focus:outline-none focus:shadow-outline shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
                    Cancel
                </button>
            </div>

        <div id="messageBox" class="mt-4 p-3 rounded-lg text-center hidden"></div>
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

    let currentScanData = null;
    let scanTimeout;

    function showMessage(message, type = 'info') {
        messageBox.textContent = message;
        messageBox.className = `mt-4 p-3 rounded-lg text-center ${
            type === 'success' ? 'bg-green-100 text-green-800' :
            type === 'error' ? 'bg-red-100 text-red-800' :
            'bg-yellow-100 text-yellow-800'
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

    // New event listener for the 'input' event
    barcodeInput.addEventListener('input', function(event) {
        // Clear the previous timeout
        clearTimeout(scanTimeout);

        // Set a new timeout to process the barcode after a short delay
        scanTimeout = setTimeout(() => {
            const routeCode = barcodeInput.value.trim();
            if (routeCode) {
                fetchTransportDetails(routeCode);
                barcodeInput.value = ''; // Clear the input field after successful scan
            }
        }, 50); // 50ms delay to ensure the full barcode is read
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

    function fetchTransportDetails(routeCode) {
        resetUI();
        showMessage('Fetching transport details...', 'info');

        fetch(`get_transport_details.php?route_code=${encodeURIComponent(routeCode)}&shift=${encodeURIComponent(getCurrentShift())}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentScanData = data;
                    detailRouteName.textContent = data.route_name;
                    detailShift.textContent = getCurrentShift().charAt(0).toUpperCase() + getCurrentShift().slice(1);
                    detailTransactionType.textContent = data.transaction_type.toLowerCase();

                    // Set values and conditionally hide/show "Unknown" toggles
                    if (data.transaction_type === 'out') {
                        displayVehicleNo.textContent = data.vehicle_no; // from the `in` record
                        displayDriverNIC.textContent = data.driver_nic;  // from the `in` record
                        
                        // Hide the "Unknown" toggles for 'out' transactions
                        document.getElementById('unknownVehicleToggle').parentNode.style.display = 'none';
                        document.getElementById('unknownDriverToggle').parentNode.style.display = 'none';

                    } else { // For 'in' transactions
                        displayVehicleNo.textContent = data.default_vehicle_no;
                        displayDriverNIC.textContent = data.default_driver_nic;

                        // Show the "Unknown" toggles for 'in' transactions
                        document.getElementById('unknownVehicleToggle').parentNode.style.display = 'flex';
                        document.getElementById('unknownDriverToggle').parentNode.style.display = 'flex';
                    }

                    originalRouteCode.value = routeCode;
                    existingRecordId.value = data.existing_record_id || '';

                    transportDetailsDiv.classList.remove('hidden');
                    actionButtonsDiv.classList.remove('hidden');
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                    currentScanData = null;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred while fetching details.', 'error');
                currentScanData = null;
            });
    }

    submitBtn.addEventListener('click', function() {
        if (currentScanData) {
            const routeCode = originalRouteCode.value;
            const transactionType = currentScanData.transaction_type;
            const recordId = existingRecordId.value;
            const shift = getCurrentShift();

            const vehicleNo = unknownVehicleToggle.checked ? editableVehicleNo.value.trim() : displayVehicleNo.textContent;
            const driverNIC = unknownDriverToggle.checked ? editableDriverNIC.value.trim() : displayDriverNIC.textContent;
            
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
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showMessage('An error occurred while submitting transaction.', 'error');
        });
    }

    function resetUI() {
        transportDetailsDiv.classList.add('hidden');
        actionButtonsDiv.classList.add('hidden');
        detailRouteName.textContent = '';
        detailShift.textContent = '';
        detailTransactionType.textContent = '';
        
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
        barcodeInput.focus();
    }

    barcodeInput.focus();
</script>
</body>
</html>