<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- Fetching Data ---
$sql = "
    SELECT ml.*, e.calling_name
    FROM manager_log ml
    JOIN employee e ON ml.emp_id = e.emp_id
    ORDER BY ml.role ASC, ml.emp_id ASC;
";
$result = $conn->query($sql);
$log_data = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) { $log_data[] = $row; }
}

$toast = isset($_SESSION['toast']) ? $_SESSION['toast'] : null;
unset($_SESSION['toast']);

include('../../includes/header.php'); 
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MAMS Access Control</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: 0.3s; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .modal { opacity: 0; visibility: hidden; transition: 0.3s; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal-content { transform: scale(0.95); transition: 0.3s; }
        .modal.active .modal-content { transform: scale(1); }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
    </style>
</head>

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="flex items-center space-x-2 w-fit">
                <a href="own_vehicle.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                    Own Vehicle Management
                </a>

                <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

                <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                    MAMS Admin
                </span>
            </div>
    </div>
    <div class="flex items-center gap-4 text-sm font-medium">
        
        
        <a href="add_manager_log.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-blue-700">
            <i class="fas fa-user-plus"></i> Grant Access
        </a>
        <span class="text-gray-600">|</span>
        <a href="own_vehicle.php" class="flex items-center gap-2 text-white px-3 py-1.5 rounded-md transition transform font-semibold text-xs tracking-wide">
            Back
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse">
            <thead class="text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Employee Name</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm w-32">Emp ID</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm w-32">Role</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm w-40">Status</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm w-32">Actions</th>
                </tr>
            </thead>
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php foreach ($log_data as $row): 
                    $isSetup = (int)$row['first_log'] === 1;
                ?>
                <tr class="hover:bg-indigo-50 bg-white border-b border-gray-100 transition duration-150 group">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($row['calling_name']); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center font-mono font-bold text-blue-600 uppercase"><?php echo $row['emp_id']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border <?php echo $row['role']==='admin' ? 'bg-purple-100 text-purple-800 border-purple-200':'bg-blue-100 text-blue-800 border-blue-200'; ?>">
                            <?php echo $row['role']; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs font-bold <?php echo $isSetup ? 'text-amber-500':'text-green-600'; ?>">
                            <i class="fas fa-circle text-[8px] mr-1"></i> <?php echo $isSetup ? 'Setup Pending':'Active'; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-3">
                            <button class="reset-btn text-yellow-500 hover:text-yellow-600 transition transform hover:scale-110" 
                                    title="Reset Password to 12345678"
                                    data-emp-id="<?php echo $row['emp_id']; ?>" data-name="<?php echo $row['calling_name']; ?>">
                                <i class="fas fa-key"></i>
                            </button>
                            <button class="remove-btn text-gray-400 hover:text-red-600 transition transform hover:scale-110" 
                                    title="Revoke Access"
                                    data-emp-id="<?php echo $row['emp_id']; ?>" data-name="<?php echo $row['calling_name']; ?>">
                                <i class="fas fa-user-minus"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="confirmation-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-60 backdrop-blur-sm">
    <div class="modal-content bg-white rounded-lg shadow-2xl w-full max-w-md p-6 relative">
        <button id="modal-cancel-x" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        <div class="flex flex-col items-center text-center">
            <div id="modal-icon-container" class="w-16 h-16 rounded-full flex items-center justify-center mb-4 text-3xl shadow-inner"></div>
            <h3 id="modal-title" class="text-xl font-bold text-gray-800 mb-2"></h3>
            <p id="modal-message" class="text-gray-600 mb-6 px-4"></p>
            <div class="flex gap-3 w-full justify-between px-4">
                <button id="modal-cancel-btn" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 font-medium w-1/3">Cancel</button>
                <button id="modal-confirm-btn" class="px-4 py-2 rounded-lg text-white font-medium shadow-md w-1/2"></button>
            </div>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.classList.add('toast', type);
        toast.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle':'fa-exclamation-circle'} mr-3"></i><span class="text-sm font-medium">${message}</span>`;
        document.getElementById("toast-container").appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
    }

    $(document).ready(function() {
        let actionData = {};
        const $modal = $('#confirmation-modal');

        // Reset Password Trigger
        $('.reset-btn').on('click', function() {
            actionData = { action: 'reset_pass', emp_id: $(this).data('emp-id') };
            $('#modal-title').text('Reset Password?');
            $('#modal-message').html(`Reset password to <b class="font-mono">12345678</b> for <b>${$(this).data('name')}</b>? User will be asked to setup again.`);
            $('#modal-icon-container').attr('class', 'w-16 h-16 rounded-full bg-yellow-100 text-yellow-500 flex items-center justify-center mb-4 text-3xl shadow-inner').html('<i class="fas fa-key"></i>');
            $('#modal-confirm-btn').attr('class', 'px-4 py-2 rounded-lg text-white font-medium shadow-md bg-yellow-500 hover:bg-yellow-600 w-1/2').text('Yes, Reset');
            $modal.addClass('active');
        });

        // Revoke Access Trigger
        $('.remove-btn').on('click', function() {
            actionData = { action: 'revoke_access', emp_id: $(this).data('emp-id') };
            $('#modal-title').text('Revoke Access?');
            $('#modal-message').html(`Are you sure you want to remove system access for <b>${$(this).data('name')}</b>?`);
            $('#modal-icon-container').attr('class', 'w-16 h-16 rounded-full bg-red-100 text-red-500 flex items-center justify-center mb-4 text-3xl shadow-inner').html('<i class="fas fa-user-shield"></i>');
            $('#modal-confirm-btn').attr('class', 'px-4 py-2 rounded-lg text-white font-medium shadow-md bg-red-600 hover:bg-red-700 w-1/2').text('Yes, Revoke');
            $modal.addClass('active');
        });

        $('#modal-confirm-btn').on('click', function() {
            $.ajax({
                type: 'POST',
                url: 'process_access.php',
                data: actionData,
                dataType: 'json',
                success: function(res) {
                    $modal.removeClass('active');
                    if(res.status==='success') { showToast(res.message); setTimeout(()=>location.reload(), 1000); }
                    else { showToast(res.message, 'error'); }
                }
            });
        });

        $('#modal-cancel-btn, #modal-cancel-x').on('click', () => $modal.removeClass('active'));
    });
</script>
</body>
</html>