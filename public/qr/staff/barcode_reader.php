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
    <title>Transport Scan Terminal (Staff)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        /* Deep Dark Background for maximum contrast */
        body { font-family: 'Inter', sans-serif; background-color: #020617; /* Very Dark Slate */ overflow: hidden; }
        
        /* Smooth Button Effects */
        .btn-action { transition: all 0.2s ease; }
        .btn-action:hover { transform: translateY(-2px); filter: brightness(110%); }
        .btn-action:active { transform: translateY(0); }

        /* Custom Scrollbar - Minimal */
        .custom-scroll::-webkit-scrollbar { width: 4px; }
        .custom-scroll::-webkit-scrollbar-track { background: #1e293b; }
        .custom-scroll::-webkit-scrollbar-thumb { background-color: #475569; border-radius: 20px; }
    </style>
</head>

<body class="bg-slate-950 h-screen w-screen overflow-hidden text-white">

<div class="fixed top-0 left-[15%] w-[85%] bg-slate-900/90 backdrop-blur border-b border-slate-800 h-16 flex justify-between items-center px-6 shadow-2xl z-50">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Scan Terminal <span class="text-xs font-normal text-slate-400 ml-1">(Staff)</span>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <a href="../../registers/Staff transport vehicle register.php" class="text-slate-400 hover:text-cyan-400 transition flex items-center gap-2">
            <i class="fas fa-list-alt"></i> View Register
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] mt-16 h-[calc(100vh-4rem)] flex items-center justify-center p-4">
    
    <div class="w-full max-w-lg bg-slate-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
            
        <div class="bg-indigo-600 p-4 text-center shadow-lg">
            <h1 class="text-xl font-bold text-white flex justify-center items-center gap-2">
                <i class="fas fa-qrcode"></i> Transport Scan
            </h1>
        </div>

        <div class="p-6 overflow-y-auto custom-scroll">
            
            <div class="mb-5">
                <label class="block text-xs font-bold text-cyan-400 mb-2 uppercase tracking-wider">
                    Scan Route Code
                </label>
                <div class="relative">
                    <input type="text" id="barcodeInput" 
                           class="w-full pl-4 pr-4 py-3 bg-slate-950 rounded-xl text-white text-xl font-mono tracking-widest outline-none ring-2 ring-slate-700 focus:ring-cyan-500 transition shadow-inner placeholder-slate-600" 
                           placeholder="SCAN HERE..." autofocus>
                    <i class="fas fa-barcode absolute right-4 top-4 text-slate-500"></i>
                </div>
            </div>

            <div id="transportDetails" class="hidden animate-fade-in-up">
                
                <div class="flex justify-between items-center mb-4 border-b border-slate-800 pb-2">
                    <span class="text-slate-500 text-xs font-bold uppercase">Transaction Info</span>
                    <span id="detailTransactionType" class="text-sm font-black px-3 py-1 rounded shadow-lg uppercase tracking-wider"></span>
                </div>

                <div class="bg-slate-800/50 rounded-xl p-3 mb-3">
                    <div class="grid grid-cols-2 gap-6 mb-3">
                        <div>
                            <span class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Route Name</span>
                            <span id="detailRouteName" class="block text-lg font-bold text-white truncate"></span>
                        </div>
                        <div class="text-right">
                            <span class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Shift</span>
                            <span id="detailShift" class="block text-lg font-bold text-yellow-400 uppercase"></span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-900/80 p-3 rounded-lg border border-white">
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-[10px] font-bold text-cyan-400 uppercase">Vehicle</label>
                                <div class="flex items-center gap-1">
                                    <input type="checkbox" id="unknownVehicleToggle" class="w-3 h-3 accent-cyan-500 cursor-pointer">
                                    <span class="text-[9px] text-slate-500 uppercase">Edit</span>
                                </div>
                            </div>
                            <span id="displayVehicleNo" class="block text-white font-mono font-bold text-base truncate"></span>
                            <input type="text" id="editableVehicleNo" class="hidden w-full bg-transparent border-b border-cyan-500 text-white font-mono font-bold text-base focus:outline-none" placeholder="Enter No">
                        </div>

                        <div class="bg-slate-900/80 p-3 rounded-lg  border border-white">
                            <div class="flex justify-between items-center mb-1">
                                <label class="text-[10px] font-bold text-cyan-400 uppercase">Driver NIC</label>
                                <div class="flex items-center gap-1">
                                    <input type="checkbox" id="unknownDriverToggle" class="w-3 h-3 accent-cyan-500 cursor-pointer">
                                    <span class="text-[9px] text-slate-500 uppercase">Edit</span>
                                </div>
                            </div>
                            <span id="displayDriverNIC" class="block text-white font-mono font-bold text-base truncate"></span>
                            <input type="text" id="editableDriverNIC" class="hidden w-full bg-transparent border-b border-cyan-500 text-white font-mono font-bold text-base focus:outline-none" placeholder="Enter NIC">
                        </div>
                    </div>
                </div>

                <input type="hidden" id="originalRouteCode">
                <input type="hidden" id="existingRecordId">
            </div>

            <div id="actionButtons" class="hidden flex flex-col gap-3">
                
                <label class="flex items-center justify-center gap-2 p-3 rounded-lg bg-slate-800 hover:bg-slate-700 cursor-pointer transition select-none group">
                    <input type="checkbox" id="finalConfirmCheck" class="w-4 h-4 accent-yellow-500 cursor-pointer">
                    <span class="text-sm font-bold text-slate-400 group-hover:text-yellow-400 transition">Confirm details are correct</span>
                </label>
                
                <div class="flex gap-3">
                    <button id="submitBtn" class="flex-1 bg-emerald-600 text-white font-bold py-2 rounded-xl shadow-lg btn-action disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                        RECORD
                    </button>
                    <button id="cancelBtn" class="flex-1 bg-slate-700 text-red-400 font-bold py-2 rounded-xl shadow-lg btn-action border border-slate-600 hover:bg-slate-600">
                        CANCEL
                    </button>
                </div>
            </div>

            <div id="messageBox" class="mt-4 p-3 rounded-xl text-center text-sm font-bold hidden shadow-lg tracking-wide"></div>
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
    const finalConfirmCheck = document.getElementById('finalConfirmCheck'); 

    let currentScanData = null;
    let scanTimeout;

    // Toggle submit button state
    function toggleSubmitButtonState() {
        const shouldBeEnabled = finalConfirmCheck.checked && !transportDetailsDiv.classList.contains('hidden');
        submitBtn.disabled = !shouldBeEnabled;
        
        if(shouldBeEnabled) {
            submitBtn.classList.remove('opacity-50');
            submitBtn.classList.add('hover:bg-emerald-500');
        } else {
            submitBtn.classList.add('opacity-50');
            submitBtn.classList.remove('hover:bg-emerald-500');
        }
    }

    function showMessage(message, type = 'info') {
        messageBox.textContent = message;
        messageBox.className = `mt-4 p-3 rounded-xl text-center text-sm font-bold shadow-lg tracking-wide transition-all duration-300 ${
            type === 'success' ? 'bg-emerald-900/90 text-emerald-200 border border-emerald-600' :
            type === 'error' ? 'bg-red-900/90 text-red-200 border border-red-600' :
            'bg-blue-900/90 text-blue-200 border border-blue-600'
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

    finalConfirmCheck.addEventListener('change', toggleSubmitButtonState);

    function fetchTransportDetails(routeCode) {
        resetUI();
        showMessage('Fetching details...', 'info');

        fetch(`get_transport_details.php?route_code=${encodeURIComponent(routeCode)}&shift=${encodeURIComponent(getCurrentShift())}`)
            .then(response => { if (!response.ok) throw new Error('Network response was not ok'); return response.json(); })
            .then(data => {
                if (data.success) {
                    currentScanData = data;
                    detailRouteName.textContent = data.route_name;
                    detailShift.textContent = getCurrentShift().charAt(0).toUpperCase() + getCurrentShift().slice(1);
                    detailTransactionType.textContent = data.transaction_type.toUpperCase();
                    
                    // Transaction Badge Colors - VERY VIBRANT
                    detailTransactionType.className = `text-sm font-black px-4 py-1 rounded shadow-lg tracking-wider ${
                        data.transaction_type === 'in' 
                        ? 'bg-emerald-500 text-black' 
                        : 'bg-red-500 text-white'
                    }`;

                    if (data.transaction_type === 'out') {
                        displayVehicleNo.textContent = data.vehicle_no; 
                        displayDriverNIC.textContent = data.driver_nic; 
                        
                        unknownVehicleToggle.parentElement.parentElement.style.display = 'none';
                        unknownDriverToggle.parentElement.parentElement.style.display = 'none';

                    } else { // 'in'
                        displayVehicleNo.textContent = data.default_vehicle_no;
                        editableVehicleNo.value = data.default_vehicle_no;

                        displayDriverNIC.textContent = data.default_driver_nic;
                        editableDriverNIC.value = data.default_driver_nic; 

                        unknownVehicleToggle.parentElement.parentElement.style.display = 'flex';
                        unknownDriverToggle.parentElement.parentElement.style.display = 'flex';
                    }

                    originalRouteCode.value = routeCode;
                    existingRecordId.value = data.existing_record_id || '';

                    transportDetailsDiv.classList.remove('hidden');
                    actionButtonsDiv.classList.remove('hidden');
                    messageBox.style.display = 'none'; 
                    
                    toggleSubmitButtonState(); 

                } else {
                    showMessage(data.message, 'error');
                    currentScanData = null;
                    toggleSubmitButtonState();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Connection error.', 'error');
                currentScanData = null;
                toggleSubmitButtonState();
            });
    }

    submitBtn.addEventListener('click', function() {
        if (!finalConfirmCheck.checked) {
             showMessage('Please confirm details.', 'error');
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
                showMessage('Vehicle & Driver NIC required.', 'error');
                return;
            }

            submitTransportTransaction(routeCode, vehicleNo, driverNIC, transactionType, recordId, shift, vehicleStatus, driverStatus);
        } else {
            showMessage('No scan data.', 'error');
        }
    });

    cancelBtn.addEventListener('click', function() {
        resetUI();
        showMessage('Cancelled. Ready.', 'info');
    });

    function submitTransportTransaction(routeCode, vehicleNo, driverNIC, transactionType, recordId, shift, vehicleStatus, driverStatus) {
        showMessage('Processing...', 'info');

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
                setTimeout(resetUI, 2000); 
            } else {
                showMessage(data.message, 'error');
                toggleSubmitButtonState(); 
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showMessage('System error.', 'error');
            toggleSubmitButtonState(); 
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
        
        finalConfirmCheck.checked = false;
        toggleSubmitButtonState(); 
        
        barcodeInput.focus();
    }

    toggleSubmitButtonState();
    barcodeInput.focus();
</script>

</body>
</html>