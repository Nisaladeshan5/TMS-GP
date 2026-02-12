<?php
// own_vehicle.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// --- 1. Fetching Data ---
$sql = "
    SELECT 
        ov.emp_id, 
        e.calling_name, 
        ov.vehicle_no, 
        ov.distance,
        ov.type,
        ov.fixed_amount,
        ov.fuel_efficiency AS consumption_id,
        ov.is_active, 
        ov.paid,
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

// Helper for Fuel Styles
function getFuelStyle($type) {
    if (stripos($type, 'petrol') !== false) {
        return 'bg-green-100 text-green-800 border-green-200';
    } elseif (stripos($type, 'diesel') !== false) {
        return 'bg-amber-100 text-amber-800 border-amber-200';
    }
    return 'bg-slate-100 text-slate-800 border-slate-200';
}

// Toast Handler
$toast = null;
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

include('../../includes/header.php'); 
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Vehicle Details</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* Toast CSS */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; min-width: 250px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }

        /* Custom Modal Transitions */
        .modal { opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal-content { transform: scale(0.95); transition: transform 0.3s ease; }
        .modal.active .modal-content { transform: scale(1); }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
    
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Own Vehicle Management
        </div>
    </div>
    
    <div class="flex items-center gap-4 text-sm font-medium">
        
        <a href="#" id="generate-qr-pdf-btn" class="flex items-center gap-2 bg-gray-700 text-gray-400 cursor-not-allowed px-3 py-1.5 rounded-md shadow-md transition transform border border-gray-600 pointer-events-none font-semibold text-xs tracking-wide">
            <i class="fas fa-qrcode"></i> Generate QR
        </a>

        <a href="manager_access_list.php" class="flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-teal-700">
            MAMS Admin
        </a>

        <span class="text-gray-600">|</span>

        <a href="add_own_vehicle.php" class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md shadow-md transition transform hover:scale-105 font-semibold text-xs tracking-wide border border-blue-700">
            Add Vehicle
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] p-2 mt-1">
    
    <div class="overflow-auto bg-white shadow-lg rounded-lg border border-gray-200 w-full max-h-[88vh]">
        <table class="w-full table-auto border-collapse">
            <thead class="text-white text-sm">
                <tr>
                    <th class="sticky top-0 z-10 bg-blue-600 px-2 py-3 text-center w-10 shadow-sm">
                        <input type="checkbox" id="select-all-checkbox" class="cursor-pointer">
                    </th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Employee</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Vehicle Info</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-left shadow-sm">Fuel Details</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Allowance (Rs)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-right shadow-sm">Distance (km)</th>
                    <th class="sticky top-0 z-10 bg-blue-600 px-4 py-3 text-center shadow-sm">Actions</th>
                </tr>
            </thead>
            
            <tbody class="text-gray-700 divide-y divide-gray-200 text-sm">
                <?php if (empty($vehicle_data)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-10 text-gray-500 italic">
                            No vehicles registered yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vehicle_data as $row): 
                        // Determine Row Style based on is_active
                        $isActive = isset($row['is_active']) ? (int)$row['is_active'] : 1;
                        $rowClass = $isActive === 1 ? 'hover:bg-indigo-50 bg-white' : 'bg-gray-100 text-gray-500';
                        $opacityClass = $isActive === 1 ? '' : 'opacity-75';
                    ?>
                    <tr class="<?php echo $rowClass; ?> border-b border-gray-100 transition duration-150 group">
                        
                        <td class="px-2 py-3 text-center">
                            <input type="checkbox" class="qr-select-checkbox cursor-pointer" data-emp-id="<?php echo htmlspecialchars($row['emp_id']); ?>">
                        </td>

                        <td class="px-4 py-3 <?php echo $opacityClass; ?>">
                            <div class="font-medium <?php echo $isActive ? 'text-gray-900' : 'text-gray-500'; ?>"><?php echo htmlspecialchars($row['calling_name']); ?></div>
                            <div class="text-xs text-gray-500 font-mono text-blue-600"><?php echo htmlspecialchars($row['emp_id']); ?></div>
                        </td>

                        <td class="px-4 py-3 <?php echo $opacityClass; ?>">
                            <div class="font-bold <?php echo $isActive ? 'text-gray-800' : 'text-gray-600'; ?> font-mono"><?php echo htmlspecialchars($row['vehicle_no']); ?></div>
                            <div class="text-xs text-gray-500 capitalize"><?php echo htmlspecialchars($row['type']); ?></div> 
                        </td>

                        <td class="px-4 py-3 <?php echo $opacityClass; ?>">
                            <?php $fuelClass = getFuelStyle($row['fuel_rate_type']); ?>
                            <div class="flex flex-col items-start gap-1">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border <?php echo $fuelClass; ?>">
                                    <?php echo htmlspecialchars($row['fuel_rate_type']); ?>
                                </span>
                                <span class="text-xs text-gray-500">
                                    Cons: <b><?php echo htmlspecialchars($row['consumption_id']); ?></b>
                                </span>
                            </div>
                        </td>

                        <td class="px-4 py-3 text-right font-mono <?php echo $isActive ? 'text-green-600' : 'text-gray-500'; ?> font-bold <?php echo $opacityClass; ?>">
                            <?php echo number_format($row['fixed_amount'], 2); ?>
                        </td>

                        <td class="px-4 py-3 text-right font-mono <?php echo $isActive ? 'text-gray-700' : 'text-gray-500'; ?> <?php echo $opacityClass; ?>">
                            <?php echo htmlspecialchars($row['distance']); ?>
                        </td>

                        <td class="px-4 py-3 text-center">
                            <div class="flex justify-center items-center gap-2">
                                <a href="edit_own_vehicle.php?emp_id=<?php echo htmlspecialchars($row['emp_id']); ?>&vehicle_no=<?php echo urlencode($row['vehicle_no']); ?>" 
                                   class="bg-yellow-500 hover:bg-yellow-600 text-white py-1 px-2 rounded-md shadow-sm transition" title="Edit">
                                    <i class="fas fa-edit text-xs"></i>
                                </a>
                                
                                <?php 
                                    $toggleIcon = $isActive ? 'fa-toggle-on text-green-600' : 'fa-toggle-off text-gray-400';
                                    $toggleTitle = $isActive ? 'Disable Vehicle' : 'Enable Vehicle';
                                ?>
                                <button class="toggle-status-btn focus:outline-none transition transform hover:scale-110" 
                                        title="<?php echo $toggleTitle; ?>" 
                                        data-emp-id="<?php echo htmlspecialchars($row['emp_id']); ?>" 
                                        data-vehicle-no="<?php echo htmlspecialchars($row['vehicle_no']); ?>"
                                        data-current-status="<?php echo $isActive; ?>"
                                        data-emp-name="<?php echo htmlspecialchars($row['calling_name']); ?>">
                                    <i class="fas <?php echo $toggleIcon; ?> text-2xl"></i>
                                </button>

                                <?php 
                                    $isPaid = isset($row['paid']) ? (int)$row['paid'] : 0;
                                    $paidIcon = $isPaid ? 'fa-check-circle text-blue-600' : 'fa-times-circle text-gray-400';
                                    $paidTitle = $isPaid ? 'Mark as Unpaid' : 'Mark as Paid';
                                ?>
                                <button class="toggle-paid-btn focus:outline-none transition transform hover:scale-110" 
                                        title="<?php echo $paidTitle; ?>" 
                                        data-emp-id="<?php echo htmlspecialchars($row['emp_id']); ?>" 
                                        data-vehicle-no="<?php echo htmlspecialchars($row['vehicle_no']); ?>"
                                        data-current-paid="<?php echo $isPaid; ?>">
                                    <i class="fas <?php echo $paidIcon; ?> text-2xl"></i>
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

<div id="confirmation-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900 bg-opacity-60 backdrop-blur-sm">
    <div class="modal-content bg-white rounded-lg shadow-2xl w-full max-w-md p-6 relative">
        <button id="modal-cancel-x" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-lg"></i>
        </button>

        <div class="flex flex-col items-center text-center">
            <div id="modal-icon-container" class="w-16 h-16 rounded-full bg-yellow-100 text-yellow-500 flex items-center justify-center mb-4 text-3xl shadow-inner">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            
            <h3 id="modal-title" class="text-xl font-bold text-gray-800 mb-2">Confirm Action</h3>
            
            <p id="modal-message" class="text-gray-600 mb-6 px-4">
                Are you sure you want to proceed?
            </p>
            
            <div class="flex gap-3 w-full justify-between">
                <button id="modal-cancel-btn" class="px-4 py-1.5 rounded-lg border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 font-medium transition shadow-sm w-1/3">
                    Cancel
                </button>
                <button id="modal-confirm-btn" class="px-4 py-1.5 rounded-lg text-white font-medium shadow-md transition hover:shadow-lg w-2/5">
                    Yes, Proceed
                </button>
            </div>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    // --- Toast Notification ---
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

    <?php if ($toast): ?>
        showToast("<?php echo htmlspecialchars($toast['message']); ?>", "<?php echo htmlspecialchars($toast['type']); ?>");
    <?php endif; ?>

    // --- Logic ---
    $(document).ready(function() {
        
        // Variables to store data for the modal action
        let pendingActionData = {};
        
        // Modal Elements
        const $modal = $('#confirmation-modal');
        const $modalTitle = $('#modal-title');
        const $modalMessage = $('#modal-message');
        const $modalConfirmBtn = $('#modal-confirm-btn');
        const $modalIconContainer = $('#modal-icon-container');
        
        // Helper to close modal
        function closeModal() {
            $modal.removeClass('active');
            pendingActionData = {}; // Clear data
        }

        // 1. Click Toggle Button -> Open Modal
        $('.toggle-status-btn').on('click', function(e) {
            e.preventDefault();

            const empId = $(this).data('emp-id');
            const vehicleNo = $(this).data('vehicle-no');
            const empName = $(this).data('emp-name');
            const currentStatus = $(this).data('current-status');
            
            const isActivating = currentStatus == 0; // If 0, we are activating (Enable)
            
            // Store data for the confirm button
            pendingActionData = {
                emp_id: empId,
                vehicle_no: vehicleNo,
                status: isActivating ? 1 : 0
            };

            // Customize Modal Content
            if (isActivating) {
                // Enable Style
                $modalTitle.text('Enable Vehicle?');
                $modalMessage.html(`Are you sure you want to <b>Enable</b> vehicle <span class="font-mono text-blue-600">${vehicleNo}</span> for <b>${empName}</b>?`);
                $modalConfirmBtn
                    .removeClass('bg-red-600 hover:bg-red-700')
                    .addClass('bg-green-600 hover:bg-green-700')
                    .text('Yes, Enable');
                $modalIconContainer
                    .removeClass('bg-red-100 text-red-500 bg-yellow-100 text-yellow-500')
                    .addClass('bg-green-100 text-green-500')
                    .html('<i class="fas fa-power-off"></i>');
            } else {
                // Disable Style
                $modalTitle.text('Disable Vehicle?');
                $modalMessage.html(`Are you sure you want to <b>Disable</b> vehicle <span class="font-mono text-blue-600">${vehicleNo}</span> for <b>${empName}</b>?`);
                $modalConfirmBtn
                    .removeClass('bg-green-600 hover:bg-green-700')
                    .addClass('bg-red-600 hover:bg-red-700')
                    .text('Yes, Disable');
                $modalIconContainer
                    .removeClass('bg-green-100 text-green-500 bg-yellow-100 text-yellow-500')
                    .addClass('bg-red-100 text-red-500')
                    .html('<i class="fas fa-ban"></i>');
            }

            // Show Modal
            $modal.addClass('active');
        });

        // 2. Click Cancel -> Close Modal
        $('#modal-cancel-btn, #modal-cancel-x').on('click', function() {
            closeModal();
        });

        // 3. Click Confirm -> Run AJAX
        $modalConfirmBtn.on('click', function() {
            // Disable button to prevent double clicks
            const $btn = $(this);
            $btn.prop('disabled', true).addClass('opacity-50 cursor-not-allowed');

            const actionName = (pendingActionData.type === 'paid') ? 'toggle_paid' : 'toggle_status'; 
            
            $.ajax({
                type: 'POST',
                url: 'process_vehicle.php', 
                data: { 
                    action: actionName,
                    emp_id: pendingActionData.emp_id, 
                    vehicle_no: pendingActionData.vehicle_no,
                    status: pendingActionData.status 
                },
                dataType: 'json',
                success: function(response) {
                    closeModal();
                    if(response.status === 'success') {
                        showToast(response.message, 'success');
                        setTimeout(() => location.reload(), 1000); 
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function(xhr) {
                    closeModal();
                    let errorMessage = 'Update failed.';
                    try { errorMessage = JSON.parse(xhr.responseText).message || errorMessage; } catch (e) {}
                    showToast(errorMessage, 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
                }
            });
        });

        // Close modal when clicking outside
        $modal.on('click', function(e) {
            if ($(e.target).is('#confirmation-modal')) {
                closeModal();
            }
        });

        // 1. Paid Toggle Button -> Open Modal
        $('.toggle-paid-btn').on('click', function(e) {
            e.preventDefault();

            const empId = $(this).data('emp-id');
            const vehicleNo = $(this).data('vehicle-no');
            const currentPaid = $(this).data('current-paid');
            
            const isPaying = currentPaid == 0; // 0 නම් 'Paid' කරනවා

            // Modal එකට data set කරනවා
            pendingActionData = {
                emp_id: empId,
                vehicle_no: vehicleNo,
                status: isPaying ? 1 : 0,
                type: 'paid' // handle කරන logic එක වෙනස් නිසා type එකක් දැම්මා
            };

            // Modal එකේ පෙනුම වෙනස් කරමු
            if (isPaying) {
                $modalTitle.text('Mark as Paid?');
                $modalMessage.html(`Are you sure you want to mark vehicle <span class="font-mono text-blue-600">${vehicleNo}</span> as <b>Paid</b>?`);
                $modalConfirmBtn
                    .removeClass('bg-red-600 hover:bg-red-700 bg-green-600 hover:bg-green-700')
                    .addClass('bg-indigo-600 hover:bg-indigo-700') // Paid වලට Indigo color එක
                    .text('Yes, Mark Paid');
                $modalIconContainer
                    .removeClass('bg-red-100 text-red-500 bg-green-100 text-green-500')
                    .addClass('bg-indigo-100 text-indigo-500')
                    .html('<i class="fas fa-hand-holding-usd"></i>');
            } else {
                $modalTitle.text('Mark as Unpaid?');
                $modalMessage.html(`Are you sure you want to mark vehicle <span class="font-mono text-blue-600">${vehicleNo}</span> as <b>Unpaid</b>?`);
                $modalConfirmBtn
                    .removeClass('bg-green-600 hover:bg-green-700 bg-indigo-600 hover:bg-indigo-700')
                    .addClass('bg-gray-600 hover:bg-gray-700')
                    .text('Yes, Mark Unpaid');
                $modalIconContainer
                    .removeClass('bg-green-100 text-green-500 bg-indigo-100 text-indigo-500')
                    .addClass('bg-gray-100 text-gray-400')
                    .html('<i class="fas fa-undo"></i>');
            }

            $modal.addClass('active');
        });

        // QR Selection Logic (Existing)
        const $selectAllCheckbox = $('#select-all-checkbox');
        const $qrCheckboxes = $('.qr-select-checkbox');
        const $generateQrBtn = $('#generate-qr-pdf-btn');

        function updateGenerateButtonLink() {
            const selectedIds = $qrCheckboxes.filter(':checked').map(function() {
                return $(this).data('emp-id');
            }).get();

            if (selectedIds.length > 0) {
                $generateQrBtn.attr('href', 'generate_vehicle_qr_pdf.php?emp_ids=' + selectedIds.join(','));
                $generateQrBtn.removeClass('bg-gray-700 text-gray-400 cursor-not-allowed pointer-events-none border-gray-600')
                              .addClass('bg-green-600 hover:bg-green-700 text-white shadow-md cursor-pointer pointer-events-auto border-green-600 transform hover:scale-105');
            } else {
                $generateQrBtn.attr('href', '#');
                $generateQrBtn.removeClass('bg-green-600 hover:bg-green-700 text-white shadow-md cursor-pointer pointer-events-auto border-green-600 transform hover:scale-105')
                              .addClass('bg-gray-700 text-gray-400 cursor-not-allowed pointer-events-none border-gray-600');
            }
        }
        
        $selectAllCheckbox.on('change', function() {
            $qrCheckboxes.prop('checked', this.checked);
            updateGenerateButtonLink();
        });

        $qrCheckboxes.on('change', function() {
            if (!this.checked) $selectAllCheckbox.prop('checked', false);
            if ($qrCheckboxes.length === $qrCheckboxes.filter(':checked').length) $selectAllCheckbox.prop('checked', true);
            updateGenerateButtonLink();
        });
    });
</script>

</body>
</html>

<?php $conn->close(); ?>