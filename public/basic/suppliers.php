<?php
include('../../includes/db.php');

// --- API MODE (AJAX requests) ---

if (isset($_GET['view_supplier_code'])) {
    header('Content-Type: application/json');
    $supplier_code = $_GET['view_supplier_code'];
    $sql = "SELECT * FROM supplier WHERE supplier_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $supplier_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplier = $result->fetch_assoc();
    echo json_encode($supplier ?: null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    header('Content-Type: application/json');

    try {
        $supplier_code = $_POST['supplier_code'];
        $supplier = $_POST['supplier'];
        $s_phone_no = $_POST['s_phone_no'];
        $email = $_POST['email'];
        $beneficiaress_name = $_POST['beneficiaress_name'];
        $bank = $_POST['bank'];
        $bank_code = $_POST['bank_code'];
        $branch = $_POST['branch'];
        $branch_code = $_POST['branch_code'];
        $acc_no = $_POST['acc_no'];
        $swift_code = $_POST['swift_code'];
        $acc_currency_type = $_POST['acc_currency_type'];

        $sql = "UPDATE supplier 
                SET supplier=?, s_phone_no=?, email=?, beneficiaress_name=?, bank=?, bank_code=?, 
                    branch=?, branch_code=?, acc_no=?, swift_code=?, acc_currency_type=?
                WHERE supplier_code=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssssssssss', 
            $supplier, $s_phone_no, $email, $beneficiaress_name, $bank, $bank_code,
            $branch, $branch_code, $acc_no, $swift_code, $acc_currency_type, $supplier_code
        );

        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Supplier details updated successfully!']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Database error: '.$stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json');

    try {
        $supplier_code = $_POST['supplier_code'];
        $new_status = (int)$_POST['is_active'];

        $sql = "UPDATE supplier SET is_active = ? WHERE supplier_code = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $new_status, $supplier_code);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Supplier status updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- NORMAL PAGE LOAD (HTML) ---
include('../../includes/header.php');
include('../../includes/navbar.php');

$status_filter = $_GET['status'] ?? 'active';

$sql = "SELECT * FROM supplier WHERE 1=1";
$types = "";
$params = [];

if ($status_filter === 'active') {
    $sql .= " AND is_active = 1";
} elseif ($status_filter === 'inactive') {
    $sql .= " AND is_active = 0";
}

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$suppliers_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* CSS for modals and toast notifications */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 600px;
            position: relative;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
        }

        .toast {
            display: none;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            display: flex;
            align-items: center;
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
            color: white;
        }

        .toast.error {
            background-color: #F44336;
            color: white;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="container" style="width: 80%; margin-left: 17.5%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <p class="text-4xl font-bold text-gray-800 mt-6 mb-4">Supplier Details</p>
    <div class="w-full flex justify-between items-center mb-6">
        <a href="add_supplier.php" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300">
            Add New Supplier
        </a>
        <div class="flex items-center space-x-2">
            <select id="status-filter" onchange="filterStatus(this.value)" class="p-2 border rounded-md">
                <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
    </div>
    <div class="overflow-x-auto bg-white shadow-md rounded-md w-full">
        <table class="min-w-full table-auto">
            <thead class="bg-blue-600 text-white">
                <tr>
                    <th class="px-4 py-2 text-left">Supplier Code</th>
                    <th class="px-4 py-2 text-left">Supplier</th>
                    <th class="px-4 py-2 text-left">Phone No</th>
                    <th class="px-4 py-2 text-left">Email</th>
                    <th class="px-4 py-2 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($suppliers_result && $suppliers_result->num_rows > 0): ?>
                    <?php while ($supplier = $suppliers_result->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-100">
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['supplier_code']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['supplier']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['s_phone_no']); ?></td>
                            <td class="border px-4 py-2"><?php echo htmlspecialchars($supplier['email']); ?></td>
                            <td class="border px-4 py-2">
                                <button onclick='viewSupplierDetails("<?php echo htmlspecialchars($supplier['supplier_code']); ?>")' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 mr-2'>View</button>
                                <button onclick='openEditModal("<?php echo htmlspecialchars($supplier['supplier_code']); ?>")' class='bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300'>Edit</button>
                                <?php if ($supplier['is_active'] == 1): ?>
                                    <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($supplier['supplier_code']); ?>", 0)' class='bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Disable</button>
                                <?php else: ?>
                                    <button onclick='confirmToggleStatus("<?php echo htmlspecialchars($supplier['supplier_code']); ?>", 1)' class='bg-green-500 hover:bg-green-600 text-white font-bold py-1 px-2 rounded text-sm transition duration-300 ml-2'>Enable</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="border px-4 py-2 text-center">No suppliers found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content !max-w-4xl !p-6 bg-gray-50 rounded-xl shadow-2xl">
        <span class="close" onclick="closeModal('editModal')">&times;</span>
        
        <div class="mb-1 pb-1 border-b border-gray-200">
            <h3 class="text-3xl font-extrabold text-gray-800" id="editModalTitle">Edit Supplier</h3>
            <p class="text-lg text-gray-600 mt-1">Supplier Code: <span id="editSupplierCodeTitle" class="font-semibold"></span></p>
        </div>

        <form id="editForm" onsubmit="handleEditSubmit(event)" class="space-y-3">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" id="edit_supplier_code" name="supplier_code">
            
            <div data-step="0" class="edit-step">
                <div class="bg-white p-2 rounded-lg">
                    <h4 class="text-xl font-bold mb-4 text-blue-600">Basic Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="edit_supplier" class="block text-sm font-medium text-gray-700">Supplier:</label>
                            <input type="text" id="edit_supplier" name="supplier" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_s_phone_no" class="block text-sm font-medium text-gray-700">Supplier Phone No:</label>
                            <input type="text" id="edit_s_phone_no" name="s_phone_no" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_email" class="block text-sm font-medium text-gray-700">Email:</label>
                            <input type="email" id="edit_email" name="email" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                </div>
            </div>

            <div data-step="1" class="edit-step" style="display: none;">
                <div class="bg-white p-2 rounded-lg">
                    <h4 class="text-xl font-bold mb-4 text-blue-600">Bank Details</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="edit_beneficiaress_name" class="block text-sm font-medium text-gray-700">Beneficiary's Name:</label>
                            <input type="text" id="edit_beneficiaress_name" name="beneficiaress_name" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_bank" class="block text-sm font-medium text-gray-700">Bank Name:</label>
                            <input type="text" id="edit_bank" name="bank" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_bank_code" class="block text-sm font-medium text-gray-700">Bank Code:</label>
                            <input type="text" id="edit_bank_code" name="bank_code" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_branch" class="block text-sm font-medium text-gray-700">Branch Name:</label>
                            <input type="text" id="edit_branch" name="branch" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_branch_code" class="block text-sm font-medium text-gray-700">Branch Code:</label>
                            <input type="text" id="edit_branch_code" name="branch_code" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_acc_no" class="block text-sm font-medium text-gray-700">Account No:</label>
                            <input type="text" id="edit_acc_no" name="acc_no" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_swift_code" class="block text-sm font-medium text-gray-700">Swift Code:</label>
                            <input type="text" id="edit_swift_code" name="swift_code" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="edit_acc_currency_type" class="block text-sm font-medium text-gray-700">Account Currency Type:</label>
                            <input type="text" id="edit_acc_currency_type" name="acc_currency_type" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-between mt-8">
                <button type="button" id="editBackButton" onclick="showPrevStep('edit')" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300" style="display: none;">Back</button>
                <button type="button" id="editNextButton" onclick="showNextStep('edit')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300">Next</button>
                <button type="submit" id="editSaveChangesButton" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300" style="display: none;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="viewModal" class="modal">
    <div class="modal-content !max-w-4xl !p-6 bg-gray-50 rounded-xl shadow-2xl">
        <span class="close" onclick="closeModal('viewModal')">&times;</span>
        
        <div class="mb-1 pb-1 border-b border-gray-200">
            <h3 class="text-3xl font-extrabold text-gray-800" id="viewModalTitle">Supplier Details</h3>
            <p class="text-lg text-gray-600 mt-1">Supplier Code: <span id="viewSupplierCode" class="font-semibold"></span></p>
        </div>

        <div id="supplierDetails" class="space-y-2">
            <div data-step="0" class="view-step">
                <div class="bg-white p-2 rounded-lg transition-all duration-300 transform hover:scale-[1.01]">
                    <h4 class="text-xl font-bold mb-4 text-blue-600">Basic Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Supplier</p>
                            <p id="viewSupplier" class="text-base font-semibold "></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Phone No</p>
                            <p id="viewSPhoneNo" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Email</p>
                            <p id="viewEmail" class="text-base font-semibold"></p>
                        </div>
                    </div>
                </div>
            </div>

            <div data-step="1" class="view-step" style="display:none;">
                <div class="bg-white p-2 rounded-lg transition-all duration-300 transform hover:scale-[1.01]">
                    <h4 class="text-xl font-bold mb-4 text-blue-600">Bank Details</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Beneficiary's Name</p>
                            <p id="viewBeneficiaressName" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Bank Name</p>
                            <p id="viewBank" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Bank Code</p>
                            <p id="viewBankCode" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Branch Name</p>
                            <p id="viewBranch" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Branch Code</p>
                            <p id="viewBranchCode" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Account No</p>
                            <p id="viewAccNo" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Swift Code</p>
                            <p id="viewSwiftCode" class="text-base font-semibold"></p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-1">
                            <p class="text-sm font-medium text-gray-500">Account Currency Type</p>
                            <p id="viewAccCurrencyType" class="text-base font-semibold"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-between mt-8">
            <button id="viewBackButton" onclick="showPrevStep('view')" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300" style="display: none;">Back</button>
            <button id="viewNextButton" onclick="showNextStep('view')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300">Next</button>
            <button id="closeViewButton" onclick="closeModal('viewModal')" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-6 rounded-lg cursor-pointer transition duration-300" style="display: none;">Close</button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<script>
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                ${type === 'success'
                    ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                    : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
                }
            </svg>
            <span>${message}</span>
        `;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 1300);
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    function filterStatus(status) {
        window.location.href = `suppliers.php?status=${status}`;
    }

    function confirmToggleStatus(supplierCode, newStatus) {
        if (confirm(`Are you sure you want to ${newStatus == 1 ? 'enable' : 'disable'} this supplier?`)) {
            toggleStatus(supplierCode, newStatus);
        }
    }

    async function toggleStatus(supplierCode, newStatus) {
        try {
            const response = await fetch('suppliers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=toggle_status&supplier_code=${encodeURIComponent(supplierCode)}&is_active=${newStatus}`
            });
            const result = await response.json();
            if (result.status === 'success') {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast('An unexpected error occurred.', 'error');
        }
    }

    let currentStep = 0;
    const totalSteps = 2; // For both edit and view modals

    function showStep(modalType, step) {
        const steps = document.querySelectorAll(`.${modalType}-step`);
        steps.forEach(s => s.style.display = 'none');
        if (steps[step]) {
            steps[step].style.display = 'block';
        }

        // Call the new helper function to update buttons
        updateButtons(modalType, step, totalSteps);

        currentStep = step;
    }

    function showNextStep(modalType) {
        if (currentStep < totalSteps - 1) {
            showStep(modalType, currentStep + 1);
        }
    }

    function showPrevStep(modalType) {
        if (currentStep > 0) {
            showStep(modalType, currentStep - 1);
        }
    }

    function updateButtons(modalType, currentStep, totalSteps) {
        const modalId = modalType === 'edit' ? 'editModal' : 'viewModal';
        const modal = document.getElementById(modalId);
        if (!modal) return; // Guard clause in case the modal isn't found

        const backButton = modal.querySelector(`#${modalType}BackButton`);
        const nextButton = modal.querySelector(`#${modalType}NextButton`);
        const saveButton = modal.querySelector(`#${modalType}SaveChangesButton`);
        const closeViewButton = modal.querySelector('#closeViewButton');
        const buttonContainer = modal.querySelector('.flex.justify-between, .flex.justify-end');

        if (backButton) {
            backButton.style.display = currentStep > 0 ? 'block' : 'none';
        }

        if (modalType === 'edit') {
            if (nextButton) nextButton.style.display = (currentStep < totalSteps - 1) ? 'block' : 'none';
            if (saveButton) saveButton.style.display = (currentStep === totalSteps - 1) ? 'block' : 'none';
        }

        if (modalType === 'view') {
            if (nextButton) nextButton.style.display = (currentStep < totalSteps - 1) ? 'block' : 'none';
            if (closeViewButton) closeViewButton.style.display = (currentStep === totalSteps - 1) ? 'block' : 'none';
        }

        if (buttonContainer) {
            if (currentStep === 0) {
                buttonContainer.classList.remove('justify-between');
                buttonContainer.classList.add('justify-end');
            } else {
                buttonContainer.classList.remove('justify-end');
                buttonContainer.classList.add('justify-between');
            }
        }
    }

    async function openEditModal(supplierCode) {
        try {
            const response = await fetch(`suppliers.php?view_supplier_code=${encodeURIComponent(supplierCode)}`);
            const supplier = await response.json();
            
            if (supplier) {
                document.getElementById('edit_supplier_code').value = supplier.supplier_code;
                document.getElementById('editSupplierCodeTitle').innerText = supplier.supplier_code;
                document.getElementById('edit_supplier').value = supplier.supplier;
                document.getElementById('edit_s_phone_no').value = supplier.s_phone_no;
                document.getElementById('edit_email').value = supplier.email;
                document.getElementById('edit_beneficiaress_name').value = supplier.beneficiaress_name;
                document.getElementById('edit_bank').value = supplier.bank;
                document.getElementById('edit_bank_code').value = supplier.bank_code;
                document.getElementById('edit_branch').value = supplier.branch;
                document.getElementById('edit_branch_code').value = supplier.branch_code;
                document.getElementById('edit_acc_no').value = supplier.acc_no;
                document.getElementById('edit_swift_code').value = supplier.swift_code;
                document.getElementById('edit_acc_currency_type').value = supplier.acc_currency_type;

                showStep('edit', 0);
                document.getElementById('editModal').style.display = 'flex';
            } else {
                showToast('Supplier not found.', 'error');
            }
        } catch (error) {
            showToast('Failed to fetch supplier data.', 'error');
        }
    }

    async function viewSupplierDetails(supplierCode) {
        try {
            const response = await fetch(`suppliers.php?view_supplier_code=${encodeURIComponent(supplierCode)}`);
            const supplier = await response.json();
            
            if (supplier) {
                document.getElementById('viewSupplierCode').innerText = supplier.supplier_code;
                document.getElementById('viewSupplier').innerText = supplier.supplier;
                document.getElementById('viewSPhoneNo').innerText = supplier.s_phone_no;
                document.getElementById('viewEmail').innerText = supplier.email;
                document.getElementById('viewBeneficiaressName').innerText = supplier.beneficiaress_name;
                document.getElementById('viewBank').innerText = supplier.bank;
                document.getElementById('viewBankCode').innerText = supplier.bank_code;
                document.getElementById('viewBranch').innerText = supplier.branch;
                document.getElementById('viewBranchCode').innerText = supplier.branch_code;
                document.getElementById('viewAccNo').innerText = supplier.acc_no;
                document.getElementById('viewSwiftCode').innerText = supplier.swift_code;
                document.getElementById('viewAccCurrencyType').innerText = supplier.acc_currency_type;

                showStep('view', 0);
                document.getElementById('viewModal').style.display = 'flex';
            } else {
                showToast('Supplier not found.', 'error');
            }
        } catch (error) {
            showToast('Failed to fetch supplier data.', 'error');
        }
    }

    async function handleEditSubmit(event) {
        event.preventDefault();

        const form = document.getElementById('editForm');
        const formData = new FormData(form);

        try {
            const response = await fetch('suppliers.php', {
                method: 'POST',
                body: new URLSearchParams(formData).toString(),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });

            const result = await response.json();
            if (result.status === 'success') {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast('An unexpected error occurred.', 'error');
        }
    }
</script>

</body>
</html>

<?php $conn->close(); ?>