<?php
ob_start();
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
date_default_timezone_set('Asia/Colombo');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Other Vehicle Scan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background-color: #020617; overflow: hidden; font-family: 'Inter', sans-serif; }
        .btn-action { transition: all 0.2s ease; }
        .btn-action:hover { transform: translateY(-2px); filter: brightness(110%); shadow: 0 10px 15px -3px rgba(139, 92, 246, 0.3); }
        .animate-fade-in-up { animation: fadeInUp 0.4s ease-out; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="text-white">

<div class="fixed top-0 left-[15%] w-[85%] bg-slate-900/90 backdrop-blur border-b border-slate-800 h-16 flex justify-between items-center px-6 z-50 shadow-xl">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Scan Terminal <span class="text-xs font-normal text-slate-400 ml-1">(Other Vehicles)</span>
        </div>
    </div>
    <div class="text-xs font-bold text-slate-500 tracking-widest uppercase">System Operational</div>
</div>

<div class="w-[85%] ml-[15%] mt-16 h-[calc(100vh-4rem)] flex items-center justify-center p-4">
    
    <div class="w-full max-w-lg bg-slate-900 rounded-3xl shadow-2xl flex flex-col overflow-hidden border border-white/5">
        
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 p-6 text-center shadow-lg relative overflow-hidden">
            <div class="absolute -top-10 -right-10 w-32 h-32 bg-white/10 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-10 -left-10 w-24 h-24 bg-indigo-500/20 rounded-full blur-2xl"></div>
            
            <h1 class="text-2xl font-black text-white flex justify-center items-center gap-3 relative z-10">
                <i class="fas fa-shuttle-van text-3xl"></i> OTHER VEHICLES
            </h1>
            <p class="text-purple-100 text-[10px] mt-1 font-bold tracking-[0.2em] opacity-80 uppercase">Registration & Attendance</p>
        </div>

        <div class="p-8">
            <form id="attendanceForm">
                
                <div id="step1" class="transition-all duration-300">
                    <label class="block text-xs font-bold text-purple-400 mb-3 uppercase tracking-widest">Scan Operational Code</label>
                    <div class="relative group">
                        <input type="text" id="operationalCode" name="operational_code"
                               class="w-full pl-5 pr-12 py-4 bg-slate-950 rounded-2xl text-white text-2xl font-mono outline-none ring-2 ring-slate-800 focus:ring-purple-500 transition shadow-2xl uppercase placeholder-slate-800"
                               placeholder="EV-XXXX" autofocus autocomplete="off">
                        <i class="fas fa-qrcode absolute right-5 top-1/2 -translate-y-1/2 text-slate-600 text-xl group-focus-within:text-purple-500 transition"></i>
                    </div>
                </div>

                <div id="step2" class="hidden animate-fade-in-up">
                    <div class="bg-slate-800/40 rounded-2xl p-6 mb-6 border border-white/5 shadow-inner">
                        <label class="text-[10px] font-bold text-purple-400 uppercase tracking-[0.2em] block mb-2">Verify Vehicle Number</label>
                        <input type="text" id="displayVehicle" name="vehicle_no" 
                               class="w-full bg-slate-950 p-5 rounded-xl text-white font-black text-3xl text-center border-2 border-slate-800 focus:border-purple-500 uppercase tracking-widest shadow-lg transition-all outline-none">
                        
                        <div class="mt-4 flex justify-center">
                            <span class="px-3 py-1 bg-purple-500/10 text-purple-400 rounded-full text-[9px] font-black tracking-widest uppercase border border-purple-500/20">
                                Manual Edit Enabled
                            </span>
                        </div>

                        <input type="hidden" id="hiddenSupplier" name="supplier_code">
                        <input type="hidden" name="from_loc" value="-">
                        <input type="hidden" name="to_loc" value="-">
                    </div>

                    <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-black py-5 rounded-2xl shadow-xl btn-action tracking-widest uppercase text-lg mb-4">
                        RECORD ATTENDANCE
                    </button>
                    
                    <button type="button" onclick="location.reload()" class="w-full text-slate-500 text-xs font-bold uppercase hover:text-white transition flex items-center justify-center gap-2">
                        <i class="fas fa-redo-alt text-[10px]"></i> Cancel / Rescan
                    </button>
                </div>
            </form>

            <div id="statusMessage" class="mt-6 p-4 rounded-2xl text-center text-sm font-bold hidden shadow-2xl border transition-all duration-300"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const opInput = document.getElementById('operationalCode');
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const statusDiv = document.getElementById('statusMessage');
    const form = document.getElementById('attendanceForm');

    function showMsg(msg, type='info') {
        statusDiv.textContent = msg;
        statusDiv.className = `mt-6 p-4 rounded-2xl text-center text-sm font-bold block animate-fade-in-up ${
            type === 'success' ? 'bg-emerald-900/40 text-emerald-400 border-emerald-500/30' : 'bg-red-900/40 text-red-400 border-red-500/30'
        }`;
    }

    opInput.addEventListener('input', function() {
        if(this.value.length >= 4) { 
            fetch('get_op_info.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({op_code: this.value.trim()})
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    document.getElementById('displayVehicle').value = data.vehicle_no;
                    document.getElementById('hiddenSupplier').value = data.supplier_code;
                    step1.classList.add('hidden');
                    step2.classList.remove('hidden');
                    setTimeout(() => document.getElementById('displayVehicle').focus(), 150);
                }
            })
            .catch(err => console.error('Error:', err));
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('op_code', opInput.value.trim());

        fetch('other_vehicles_handler.php', { method: 'POST', body: formData })
        .then(async res => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Raw Response:", text);
                throw new Error("Invalid Server Response");
            }
        })
        .then(data => {
            showMsg(data.message, data.status === 'success' ? 'success' : 'error');
            if(data.status === 'success') {
                setTimeout(() => location.reload(), 2000);
            }
        })
        .catch(err => {
            console.error('Fetch Error:', err);
            showMsg('Error: ' + err.message, 'error');
        });
    });
});
</script>
</body>
</html>