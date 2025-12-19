<?php
// edit_supplier.php - FINAL CORRECTED VERSION with REDIRECT
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// 1. Initial connection and function definitions
include_once('../../includes/db.php');

$supplier_code = $_GET['code'] ?? null;
$supplier_data = null;
$original_data = null; 
$message = null; 

// --- Function to fetch supplier data ---
function fetch_supplier_data($conn, $code) {
    $sql = "SELECT * FROM supplier WHERE supplier_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

// --- Function to log changes (Corrected bind_param type string) ---
function log_audit_change($conn, $user_id, $table, $record_id, $field, $old, $new) {
    // Only log if the value actually changed
    if (trim((string)$old) === trim((string)$new)) return; 

    // SQL has 6 placeholders
    $sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) 
            VALUES (?, ?, 'UPDATE', ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    
    $old_str = (string)$old;
    $new_str = (string)$new;
    
    // FIX APPLIED HERE: Changed 'ssissss' (7 types) to 'ssisss' (6 types)
    $stmt->bind_param('ssisss', $table, $record_id, $user_id, $field, $old_str, $new_str);
    
    if (!$stmt->execute()) {
        error_log("Insertion Failed for Supplier {$record_id}: " . $stmt->error);
    }
    $stmt->close();
}


// --- POST Handling (MODIFIED FOR REDIRECT) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_code = $_POST['supplier_code'];
    
    // Fetch the ORIGINAL data BEFORE the update
    $original_data = fetch_supplier_data($conn, $supplier_code);

    if (!$original_data) {
        $message = ['status' => 'error', 'text' => 'Supplier not found for update.'];
    } else {
        try {
            // New submitted values
            $new_values = [
                'supplier' => $_POST['supplier'],
                's_phone_no' => $_POST['s_phone_no'],
                'email' => $_POST['email'],
                'beneficiaress_name' => $_POST['beneficiaress_name'],
                'bank' => $_POST['bank'],
                'bank_code' => $_POST['bank_code'],
                'branch' => $_POST['branch'],
                'branch_code' => $_POST['branch_code'],
                'acc_no' => $_POST['acc_no'],
                'swift_code' => $_POST['swift_code'],
                'acc_currency_type' => $_POST['acc_currency_type']
            ];

            // Prepare and execute the UPDATE query
            $sql = "UPDATE supplier 
                    SET supplier=?, s_phone_no=?, email=?, beneficiaress_name=?, bank=?, bank_code=?, 
                        branch=?, branch_code=?, acc_no=?, swift_code=?, acc_currency_type=?
                    WHERE supplier_code=?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssssssssssss', 
                $new_values['supplier'], $new_values['s_phone_no'], $new_values['email'], $new_values['beneficiaress_name'], $new_values['bank'], $new_values['bank_code'],
                $new_values['branch'], $new_values['branch_code'], $new_values['acc_no'], $new_values['swift_code'], $new_values['acc_currency_type'], $supplier_code
            );

            if ($stmt->execute()) {
                // Log the changes
                $user_id = $_SESSION['user_id'] ?? 0; 
                
                foreach ($new_values as $field => $new_value) {
                    $old_value = $original_data[$field];
                    log_audit_change($conn, $user_id, 'supplier', $supplier_code, $field, $old_value, $new_value);
                }
                
                // Success! Redirect to suppliers.php with a success message
                $message_text = urlencode('Supplier details updated successfully!');
                header("Location: suppliers.php?status=success&message={$message_text}");
                exit();
                
            } else {
                $message = ['status' => 'error', 'text' => 'Database error: ' . $stmt->error];
            }
            $stmt->close();
            
            // If update failed, re-fetch data for display on the current page
            $supplier_data = fetch_supplier_data($conn, $supplier_code); 

        } catch (Exception $e) {
            $message = ['status' => 'error', 'text' => 'An unexpected error occurred: ' . $e->getMessage()];
        }
    }
}


// --- RETRIEVE DATA (Initial Load or Post-Error) ---
if (!$supplier_data && $supplier_code) {
    $supplier_data = fetch_supplier_data($conn, $supplier_code); 

    if (!$supplier_data) {
        header("Location: suppliers.php?status=error&message=" . urlencode("Supplier not found."));
        exit();
    }
} elseif (!$supplier_code) {
    header("Location: suppliers.php?status=error&message=" . urlencode("Supplier code missing."));
    exit();
}

include_once('../../includes/header.php');
include_once('../../includes/navbar.php');

// Final database connection close:
if (isset($conn)) {
    closeDbConnection($conn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Supplier: <?php echo htmlspecialchars($supplier_data['supplier_code'] ?? 'N/A'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        #toast-container {
            position: fixed; top: 1rem; right: 1rem; z-index: 2000;
            display: flex; flex-direction: column; align-items: flex-end;
        }
        .toast {
            display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem;
            border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; 
            transform: translateY(-20px); opacity: 0; max-width: 350px;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; flex-shrink: 0; }
    </style>
</head>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<body class="bg-gray-100 font-sans">

<div id="toast-container"></div>

<div class="w-[85%] ml-[15%]">
    <div class="w-full max-w-4xl p-6 bg-white shadow-lg rounded-lg mt-10 mx-auto">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-2 border-b pb-2">Edit Supplier Details</h1>
        
        <div class="mb-3 pb-2 border-b border-gray-200">
            <h3 class="text-2xl font-semibold text-gray-700"><?php echo htmlspecialchars($supplier_data['supplier'] ?? 'N/A'); ?></h3>
            <p class="text-sm text-gray-500 mt-1">Supplier Code: <span class="font-medium"><?php echo htmlspecialchars($supplier_data['supplier_code'] ?? 'N/A'); ?></span></p>
        </div>

        <form id="supplierForm" method="POST" action="edit_supplier.php?code=<?php echo urlencode($supplier_code); ?>" class="space-y-4">
            <input type="hidden" name="supplier_code" value="<?php echo htmlspecialchars($supplier_data['supplier_code'] ?? ''); ?>">
            
            <div class="grid md:grid-cols-2 gap-4 bg-gray-100 p-4 border border-gray-100 rounded-lg shadow-sm">
                <div class="md:col-span-2">
                    <h4 class="text-xl font-bold text-blue-600 border-b pb-1">Basic Information</h4>
                </div>
                
                <div class="col-span-1">
                    <label for="supplier" class="block text-sm font-semibold text-gray-700">Supplier Name:</label>
                    <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars($supplier_data['supplier'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="s_phone_no" class="block text-sm font-semibold text-gray-700">Supplier Phone No:</label>
                    <input type="text" id="s_phone_no" name="s_phone_no" value="<?php echo htmlspecialchars($supplier_data['s_phone_no'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="md:col-span-2"> 
                    <label for="email" class="block text-sm font-semibold text-gray-700">Email:</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($supplier_data['email'] ?? ''); ?>" class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-4 bg-gray-100 p-4 border border-gray-100 rounded-lg shadow-sm">
                <div class="md:col-span-2">
                    <h4 class="text-xl font-bold text-blue-600 border-b pb-1">Bank Details</h4>
                </div>
                
                <div class="col-span-1">
                    <label for="beneficiaress_name" class="block text-sm font-semibold text-gray-700">Beneficiary's Name:</label>
                    <input type="text" id="beneficiaress_name" name="beneficiaress_name" value="<?php echo htmlspecialchars($supplier_data['beneficiaress_name'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="bank" class="block text-sm font-semibold text-gray-700">Bank Name:</label>
                    <input type="text" id="bank" name="bank" value="<?php echo htmlspecialchars($supplier_data['bank'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="bank_code" class="block text-sm font-semibold text-gray-700">Bank Code:</label>
                    <input type="text" id="bank_code" name="bank_code" value="<?php echo htmlspecialchars($supplier_data['bank_code'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="branch" class="block text-sm font-semibold text-gray-700">Branch Name:</label>
                    <input type="text" id="branch" name="branch" value="<?php echo htmlspecialchars($supplier_data['branch'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="branch_code" class="block text-sm font-semibold text-gray-700">Branch Code:</label>
                    <input type="text" id="branch_code" name="branch_code" value="<?php echo htmlspecialchars($supplier_data['branch_code'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="acc_no" class="block text-sm font-semibold text-gray-700">Account No:</label>
                    <input type="text" id="acc_no" name="acc_no" value="<?php echo htmlspecialchars($supplier_data['acc_no'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="swift_code" class="block text-sm font-semibold text-gray-700">Swift Code:</label>
                    <input type="text" id="swift_code" name="swift_code" value="<?php echo htmlspecialchars($supplier_data['swift_code'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
                <div class="col-span-1">
                    <label for="acc_currency_type" class="block text-sm font-semibold text-gray-700">Account Currency Type:</label>
                    <input type="text" id="acc_currency_type" name="acc_currency_type" value="<?php echo htmlspecialchars($supplier_data['acc_currency_type'] ?? ''); ?>" required class="mt-1 p-2 block w-full rounded-md border border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>
            </div>

            <div class="flex justify-between mt-4 pt-2">
                <a href="suppliers.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Toast Function ---
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container'); 
            const toast = document.createElement('div'); 
            toast.className = `toast ${type}`; 
            
            let iconPath = (type === 'success') 
                ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />'
                : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />';

            toast.innerHTML = ` 
                <svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    ${iconPath}
                </svg>
                <span>${message}</span> 
            `; 
            
            toastContainer.appendChild(toast); 
            setTimeout(() => toast.classList.add('show'), 10); 
            
            setTimeout(() => { 
                toast.classList.remove('show'); 
                toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
            }, 4000); 
        }

        const phpMessage = <?php echo json_encode($message); ?>;

        if (phpMessage && phpMessage.status && phpMessage.text) {
            showToast(phpMessage.text, phpMessage.status);
        }
    });
</script>

</body>
</html>