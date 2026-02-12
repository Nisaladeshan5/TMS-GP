<?php
// night_emergency_barcode.php
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');

date_default_timezone_set('Asia/Colombo');

// --- PHP Database Logic ---
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
            Scan Terminal <span class="text-xs font-normal text-slate-400 ml-1">(Night Emergency)</span>
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        <span class="text-slate-500 text-xs uppercase tracking-wider font-bold">EMERGENCY MODE</span>
    </div>
</div>

<div class="w-[85%] ml-[15%] mt-16 h-[calc(100vh-4rem)] flex items-center justify-center p-4">
    
    <div class="w-full max-w-lg bg-slate-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
            
        <div class="bg-indigo-600 p-4 text-center shadow-lg">
            <h1 class="text-xl font-bold text-white flex justify-center items-center gap-2">
                <i class="fas fa-ambulance"></i> Night Emergency
            </h1>
        </div>

        <div class="p-6 overflow-y-auto custom-scroll relative">
            
            <?php if ($db_error): ?>
                <div class="bg-red-900/50 border border-red-500 text-red-200 px-4 py-3 rounded-xl relative mb-4 text-sm font-bold text-center">
                    <i class="fas fa-exclamation-triangle mr-2"></i> Database Error
                </div>
            <?php endif; ?>

            <form id="attendanceForm" method="POST" action="night_emergency_barcode_handler.php">
                
                <div id="step1" class="mb-5 transition-all duration-300">
                    <label class="block text-xs font-bold text-cyan-400 mb-2 uppercase tracking-wider">
                        Scan Operational Code
                    </label>
                    <div class="relative">
                        <input type="text" id="operationalCode" name="operational_code"
                               class="w-full pl-4 pr-10 py-3 bg-slate-950 rounded-xl text-white text-xl font-mono tracking-widest outline-none ring-2 ring-slate-700 focus:ring-cyan-500 transition shadow-inner placeholder-slate-600"
                               placeholder="SCAN HERE..." autofocus>
                        <i class="fas fa-barcode absolute right-4 top-4 text-slate-500"></i>
                    </div>
                </div>

                <div id="step2" class="hidden animate-fade-in-up">
                    
                    <div class="flex justify-between items-center mb-4 border-b border-slate-800 pb-2">
                        <span class="text-slate-500 text-xs font-bold uppercase">Transaction Info</span>
                        <span class="text-sm font-black px-3 py-1 rounded shadow-lg uppercase tracking-wider bg-red-500 text-white">DISPATCH</span>
                    </div>

                    <div class="bg-slate-800/50 rounded-xl p-3 mb-3">
                        
                        <div class="grid grid-cols-2 gap-4 mb-2">
                            
                            <div class="bg-slate-900/80 p-3 rounded-lg border border-white">
                                <div class="flex justify-between items-center mb-1">
                                    <label class="text-[10px] font-bold text-cyan-400 uppercase">Vehicle</label>
                                    <div class="flex items-center gap-1">
                                        <input type="checkbox" id="unknownVehicleToggle" class="w-3 h-3 accent-cyan-500 cursor-pointer">
                                        <span class="text-[9px] text-slate-500 uppercase">Unknown</span>
                                    </div>
                                </div>
                                
                                <select id="vehicleNoSelect" name="vehicle_no_select" 
                                        class="w-full bg-transparent text-white font-mono font-bold text-base outline-none appearance-none cursor-pointer">
                                    <option value="" class="bg-slate-900 text-slate-400">SELECT...</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?= htmlspecialchars($vehicle) ?>" class="bg-slate-900"><?= htmlspecialchars($vehicle) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="text" id="vehicleNoInput" name="vehicle_no_input" 
                                       class="hidden w-full bg-transparent border-b border-cyan-500 text-white font-mono font-bold text-base focus:outline-none placeholder-slate-600" 
                                       placeholder="TYPE NO">
                            </div>

                            <div class="bg-slate-900/80 p-3 rounded-lg border border-white">
                                <div class="flex justify-between items-center mb-1">
                                    <label class="text-[10px] font-bold text-cyan-400 uppercase">Driver NIC</label>
                                    <div class="flex items-center gap-1">
                                        <input type="checkbox" id="unknownDriverToggle" class="w-3 h-3 accent-cyan-500 cursor-pointer">
                                        <span class="text-[9px] text-slate-500 uppercase">Edit</span>
                                    </div>
                                </div>
                                
                                <input type="text" id="driverInput" name="driver_nic" 
                                       class="w-full bg-transparent text-slate-400 font-mono font-bold text-base outline-none focus:outline-none" 
                                       placeholder="WAITING..." readonly required>
                            </div>
                        </div>
                        
                        <p id="driverNameLabel" class="text-[10px] text-slate-400 text-center uppercase tracking-wider font-medium h-4"></p>

                    </div>

                    <label class="flex items-center justify-center gap-2 p-3 rounded-lg bg-slate-800 hover:bg-slate-700 cursor-pointer transition select-none group mb-3">
                        <input type="checkbox" id="verifyDetails" name="verify" required class="w-4 h-4 accent-yellow-500 cursor-pointer">
                        <span class="text-sm font-bold text-slate-400 group-hover:text-yellow-400 transition">Confirm details are correct</span>
                    </label>

                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2 rounded-xl shadow-lg btn-action tracking-wide">
                        RECORD
                    </button>

                </div>
            </form>

            <div id="statusMessage" class="mt-4 p-3 rounded-xl text-center text-sm font-bold hidden shadow-lg tracking-wide"></div>
        </div>
    </div>
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
    const unknownVehicleToggle = document.getElementById('unknownVehicleToggle'); // Changed to toggle
    
    const driverInput = document.getElementById('driverInput');
    const unknownDriverToggle = document.getElementById('unknownDriverToggle'); // Changed to toggle
    const driverNameLabel = document.getElementById('driverNameLabel');

    let isUnknownVehicle = false;
    let isUnknownDriver = true; 
    
    // --- Utility Functions ---
    function showMessage(message, type='info'){
        statusMessageDiv.textContent = message;
        statusMessageDiv.className = `mt-4 p-3 rounded-xl text-center text-sm font-bold shadow-lg tracking-wide transition-all duration-300 ${
            type === 'success' ? 'bg-emerald-900/90 text-emerald-200 border border-emerald-600' :
            type === 'error' ? 'bg-red-900/90 text-red-200 border border-red-600' :
            'bg-blue-900/90 text-blue-200 border border-blue-600'
        }`;
        statusMessageDiv.classList.remove('hidden');
        statusMessageDiv.style.display = 'block';
        setTimeout(()=>{ 
            statusMessageDiv.style.display = 'none'; 
            statusMessageDiv.classList.add('hidden');
        }, 5000);
    }

    // Styles for Readonly vs Editable (Matches Staff Page logic)
    function setDriverInputReadonly(readonly){
        driverInput.readOnly = readonly;
        if(readonly) {
            // Readonly style (Slate text, no border)
            driverInput.classList.remove('border-b', 'border-cyan-500', 'text-white');
            driverInput.classList.add('text-slate-400');
        } else {
            // Editable style (White text, Cyan border bottom)
            driverInput.classList.remove('text-slate-400');
            driverInput.classList.add('border-b', 'border-cyan-500', 'text-white');
        }
    }

    function clearDriverInfo(){
        driverInput.value = '';
        driverNameLabel.textContent = '';
        setDriverInputReadonly(false); 
        isUnknownDriver = true;
        unknownDriverToggle.checked = true; // Set checkbox checked
        driverInput.focus();
    }
    
    // --- Event Handlers ---
    
    // 1. Vehicle Selection (Dropdown)
    vehicleSelect.addEventListener('change', function(){
        const vehicleNo = this.value;
        if(vehicleNo){
            driverNameLabel.textContent = 'Fetching assigned driver...';
            setDriverInputReadonly(true); 
            isUnknownDriver = false; 
            unknownDriverToggle.checked = false; // Uncheck edit
            
            fetch('get_driver_info.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({vehicle_no:vehicleNo})
            })
            .then(res => res.json())
            .then(data=>{
                if(data.status==='success' && data.driver_nic){
                    driverInput.value=data.driver_nic;
                    driverNameLabel.textContent=`Assigned: ${data.calling_name || ''}`;
                    setDriverInputReadonly(true);
                }else{ 
                    // No driver found, enable edit
                    driverInput.value = '';
                    driverNameLabel.textContent = 'No assigned driver. Enter manually.';
                    setDriverInputReadonly(false);
                    isUnknownDriver = true;
                    unknownDriverToggle.checked = true;
                }
            }).catch(e=>{ 
                clearDriverInfo(); 
            });
        }else{ 
            // Reset
            driverInput.value = '';
            driverNameLabel.textContent = '';
        }
    });
    
    // 2. Unknown Vehicle Toggle (Checkbox Logic)
    unknownVehicleToggle.addEventListener('change', function(){
        isUnknownVehicle = this.checked;
        
        if(isUnknownVehicle){
            // Show Input, Hide Select
            vehicleSelect.classList.add('hidden');
            vehicleInput.classList.remove('hidden');
            vehicleInput.required = true;
            vehicleSelect.required = false;
            
            vehicleInput.focus();
            
            // If unknown vehicle, driver must be manual
            clearDriverInfo();
            driverNameLabel.textContent = 'Unknown vehicle requires manual driver entry.';
        } else {
            // Show Select, Hide Input
            vehicleInput.classList.add('hidden');
            vehicleSelect.classList.remove('hidden');
            vehicleSelect.required = true;
            vehicleInput.required = false;
            
            vehicleSelect.focus();
            if(vehicleSelect.value) vehicleSelect.dispatchEvent(new Event('change'));
            else {
                 driverInput.value = '';
                 setDriverInputReadonly(true);
                 unknownDriverToggle.checked = false;
            }
        }
    });

    // 3. Driver Edit Toggle (Checkbox Logic)
    unknownDriverToggle.addEventListener('change', function(){
        const selectedVehicle = isUnknownVehicle ? vehicleInput.value.trim() : vehicleSelect.value;
        
        // Only allow toggling if a vehicle is selected (or we are in manual vehicle mode)
        if(selectedVehicle || isUnknownVehicle) {
            isUnknownDriver = this.checked;
            setDriverInputReadonly(!isUnknownDriver); // If checked (true), readonly is false
            
            if(isUnknownDriver){
                // Manual Mode
                driverInput.value = '';
                driverInput.focus();
                driverNameLabel.textContent = 'Manual Entry';
            } else {
                // Auto Mode - try to fetch again
                if(!isUnknownVehicle && vehicleSelect.value) {
                    vehicleSelect.dispatchEvent(new Event('change'));
                }
            }
        } else {
             // Revert if no vehicle selected
             this.checked = false;
             showMessage('Select a vehicle first.', 'error');
        }
    });

    // 4. Operational Code Scan
    operationalCodeInput.addEventListener('input', function(){
        const code=this.value.trim();
        const expectedLength = 7; 

        if(code.length >= expectedLength){
            operationalCodeInput.value=code.substring(0, expectedLength); 
            
            fetch('check_operational_code.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({operational_code:code.substring(0, expectedLength)})
            })
            .then(res => res.json())
            .then(data=>{
                if(data.exists){
                    step1.classList.add('hidden'); 
                    step2.classList.remove('hidden');
                    
                    // Reset Step 2 UI
                    isUnknownVehicle = false;
                    unknownVehicleToggle.checked = false;
                    vehicleInput.classList.add('hidden');
                    vehicleSelect.classList.remove('hidden');
                    vehicleSelect.required = true;
                    vehicleInput.required = false;
                    
                    vehicleSelect.value = "";
                    driverInput.value = "";
                    unknownDriverToggle.checked = false;
                    setDriverInputReadonly(true);

                    vehicleSelect.focus();
                    showMessage('Code verified.', 'success');
                }
                else{ 
                    showMessage(data.message || 'Invalid code.', 'error'); 
                    operationalCodeInput.value=''; 
                    operationalCodeInput.focus(); 
                }
            }).catch(e=>{ 
                showMessage('Error checking code.', 'error'); 
                operationalCodeInput.value=''; 
            });
        }
    });

    // 5. Submit Form
    attendanceForm.addEventListener('submit', function(e){
        e.preventDefault();
        
        if(driverInput.value.trim() === '') {
            showMessage('Driver License ID is required.', 'error');
            driverInput.focus();
            return;
        }

        const formData = new FormData(this);
        const vehicleNo = isUnknownVehicle ? vehicleInput.value.trim() : vehicleSelect.value;
        const vehicleStatus = isUnknownVehicle ? 0 : 1;
        const driverNic = driverInput.value.trim();
        const driverStatus = isUnknownDriver ? 0 : 1;
        const operationalCode = operationalCodeInput.value.trim();

        formData.set('operational_code', operationalCode); 
        formData.set('vehicle_no',vehicleNo);
        formData.set('vehicle_status',vehicleStatus);
        formData.set('driver_nic',driverNic);
        formData.set('driver_status',driverStatus);
        
        fetch('night_emergency_barcode_handler.php',{method:'POST',body:formData})
        .then(res => res.json())
        .then(data=>{
            showMessage(data.message, data.status);
            
            // Reset ALL
            operationalCodeInput.value=''; 
            vehicleInput.value=''; 
            vehicleSelect.value=''; 
            document.getElementById('verifyDetails').checked=false;
            
            step2.classList.add('hidden'); 
            step1.classList.remove('hidden'); 
            operationalCodeInput.focus();
        }).catch(e=>{ 
            showMessage('Submission error.', 'error');
            step2.classList.add('hidden'); 
            step1.classList.remove('hidden'); 
            operationalCodeInput.focus();
        });
    });

    setDriverInputReadonly(true); 
    operationalCodeInput.focus();
});
</script>
</body>
</html>