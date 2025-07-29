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
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-lg">
        <h1 class="text-2xl font-bold mb-6 text-center text-gray-800">Transport Scan Terminal</h1>

        <div class="mb-4">
            <label for="barcodeInput" class="block text-gray-700 text-sm font-bold mb-2">Scan Route Barcode:</label>
            <input type="text" id="barcodeInput" class="shadow appearance-none border rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" placeholder="Scan route barcode..." autofocus>
        </div>

        <div id="transportDetails" class="hidden bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg mb-4">
            <h2 class="text-xl font-semibold mb-2">Scan Details:</h2>
            <p><strong>Route Name:</strong> <span id="detailRouteName" class="font-bold"></span></p>
            <p><strong>Shift:</strong> <span id="detailShift" class="font-bold"></span></p>
            <p><strong>Transaction Type:</strong> <span id="detailTransactionType" class="font-bold"></span></p>

            <div class="mt-4">
                <label for="editableVehicleNo" class="block text-gray-700 text-sm font-bold mb-2">Vehicle No:</label>
                <input type="text" id="editableVehicleNo" class="shadow appearance-none border rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>

            <div class="mt-2">
                <label for="editableDriverCallingName" class="block text-gray-700 text-sm font-bold mb-2">Driver Calling Name:</label>
                <input type="text" id="editableDriverCallingName" class="shadow appearance-none border rounded-lg w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
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

    <script>
        const barcodeInput = document.getElementById('barcodeInput');
        const transportDetailsDiv = document.getElementById('transportDetails');
        const detailRouteName = document.getElementById('detailRouteName');
        const detailShift = document.getElementById('detailShift');
        const detailTransactionType = document.getElementById('detailTransactionType');
        const editableVehicleNo = document.getElementById('editableVehicleNo');
        const editableDriverCallingName = document.getElementById('editableDriverCallingName');
        const originalRouteCode = document.getElementById('originalRouteCode');
        const existingRecordId = document.getElementById('existingRecordId');
        const actionButtonsDiv = document.getElementById('actionButtons');
        const submitBtn = document.getElementById('submitBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const messageBox = document.getElementById('messageBox');

        let currentScanData = null; // To store fetched data temporarily

        // Function to show messages
        function showMessage(message, type = 'info') {
            messageBox.textContent = message;
            messageBox.classList.remove('hidden', 'bg-green-100', 'text-green-800', 'bg-red-100', 'text-red-800', 'bg-yellow-100', 'text-yellow-800');
            if (type === 'success') {
                messageBox.classList.add('bg-green-100', 'text-green-800');
            } else if (type === 'error') {
                messageBox.classList.add('bg-red-100', 'text-red-800');
            } else { // info
                messageBox.classList.add('bg-yellow-100', 'text-yellow-800');
            }
            messageBox.style.display = 'block'; // Ensure it's visible
            setTimeout(() => {
                messageBox.style.display = 'none'; // Hide after 5 seconds
            }, 5000);
        }

        // Helper to get current shift
        function getCurrentShift() {
            const hour = new Date().getHours();
            return (hour >= 0 && hour < 12) ? 'morning' : 'evening';
        }

        // Handle barcode input (assuming scanner acts as keyboard)
        barcodeInput.addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent form submission
                const routeCode = barcodeInput.value.trim();
                if (routeCode) {
                    fetchTransportDetails(routeCode);
                    barcodeInput.value = ''; // Clear input after reading
                } else {
                    showMessage('Please scan a route barcode.', 'info');
                }
            }
        });

        // Fetch transport details from PHP
        function fetchTransportDetails(routeCode) {
            transportDetailsDiv.classList.add('hidden');
            actionButtonsDiv.classList.add('hidden');
            showMessage('Fetching transport details...', 'info');

            fetch(`get_transport_details.php?route_code=${encodeURIComponent(routeCode)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentScanData = data;
                        // Corrected assignments to match PHP JSON keys
                        detailRouteName.textContent = data.route_name;
                        detailShift.textContent = getCurrentShift().charAt(0).toUpperCase() + getCurrentShift().slice(1); // Capitalize
                        detailTransactionType.textContent = data.transaction_type.toUpperCase();
                        editableVehicleNo.value = data.default_vehicle_no;
                        editableDriverCallingName.value = data.default_driver_calling_name;
                        originalRouteCode.value = routeCode; // Store original barcode
                        existingRecordId.value = data.existing_record_id || ''; // Store ID for 'out' updates

                        transportDetailsDiv.classList.remove('hidden');
                        actionButtonsDiv.classList.remove('hidden');
                        showMessage(data.message, 'success');
                    } else {
                        showMessage(data.message, 'error');
                        currentScanData = null;
                        resetUI(); // Clear any previous details
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('An error occurred while fetching details.', 'error');
                    currentScanData = null;
                    resetUI();
                });
        }

        // Submit button click
        submitBtn.addEventListener('click', function() {
            if (currentScanData) {
                const routeCode = originalRouteCode.value;
                const vehicleNo = editableVehicleNo.value.trim();
                const driverCallingName = editableDriverCallingName.value.trim();
                const transactionType = currentScanData.transaction_type; // Use the type determined by PHP
                const recordId = existingRecordId.value;

                if (!vehicleNo || !driverCallingName) {
                    showMessage('Vehicle No and Driver Calling Name cannot be empty.', 'error');
                    return;
                }

                submitTransportTransaction(routeCode, vehicleNo, driverCallingName, transactionType, recordId);
            } else {
                showMessage('No scan details to submit.', 'error');
            }
        });

        // Cancel button click
        cancelBtn.addEventListener('click', function() {
            resetUI();
            showMessage('Scan cancelled. Ready for new scan.', 'info');
        });

        // Submit transaction to PHP
        function submitTransportTransaction(routeCode, vehicleNo, driverCallingName, transactionType, recordId) {
            showMessage('Submitting transaction...', 'info');

            const formData = new FormData();
            formData.append('route_code', routeCode);
            formData.append('vehicle_no', vehicleNo);
            formData.append('driver_calling_name', driverCallingName); // This matches the PHP POST expectation
            formData.append('transaction_type', transactionType);
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

        // Reset UI to initial state
        function resetUI() {
            transportDetailsDiv.classList.add('hidden');
            actionButtonsDiv.classList.add('hidden');
            detailRouteName.textContent = '';
            detailShift.textContent = '';
            detailTransactionType.textContent = '';
            editableVehicleNo.value = '';
            editableDriverCallingName.value = '';
            originalRouteCode.value = '';
            existingRecordId.value = '';
            currentScanData = null;
            barcodeInput.focus(); // Keep focus on the input for next scan
        }

        // Initial focus on barcode input
        barcodeInput.focus();
    </script>
</body>
</html>
