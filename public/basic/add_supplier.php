<?php
// ⚠️ Critical: Start Output Buffering and PHP Error Reporting (for debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

require_once '../../includes/session_check.php';
// Include the database connection and start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in 
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// Ensure this file has NO leading/trailing whitespace
include('../../includes/db.php'); 

// 🎯 UPDATED AUDIT LOG FUNCTION: Uses 7 columns (no 'description')
function log_general_audit_entry(
    $conn, 
    $tableName, 
    $recordId, 
    $actionType, 
    $userId, 
    $fieldName = null,  // New: Field that was changed
    $oldValue = null,   // New: The value before the change
    $newValue = null    // New: The value after the change
) {
    // ⚠️ SQL query updated to match the new audit_log structure
    $log_sql = "INSERT INTO audit_log (table_name, record_id, action_type, user_id, field_name, old_value, new_value, change_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $log_stmt = $conn->prepare($log_sql);

    if ($log_stmt === false) {
        error_log("General Audit Log Preparation Error: " . $conn->error);
        return;
    }
    
    // ⚠️ Binding parameters: 7 string/text fields
    $log_stmt->bind_param(
        "sssssss", // 7 's' for the 7 fields
        $tableName, 
        $recordId, 
        $actionType,
        $userId, 
        $fieldName, 
        $oldValue, 
        $newValue
    );
    
    if (!$log_stmt->execute()) {
        error_log("General Audit Log Execution Error: " . $log_stmt->error);
    }
    $log_stmt->close();
}

$logged_in_user_id = $_SESSION['user_id'] ?? 0; 

// Define a flag to check if the request is an AJAX call
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Initialize the session step variable
if (!isset($_SESSION['add_supplier_step'])) {
    $_SESSION['add_supplier_step'] = 1;
}

// ----------------------------------------------------------------------------------
// PHP POST/AJAX Logic
// ----------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['next_step'])) {
        // Step 1 -> Step 2 logic
        if ($_POST['next_step'] == 1) {
            $_SESSION['supplier_data'] = [
                'supplier_code' => $_POST['supplier_code'],
                'supplier' => $_POST['supplier'],
                's_phone_no' => $_POST['s_phone_no'],
                'email' => $_POST['email'],
                'beneficiaress_name' => $_SESSION['supplier_data']['beneficiaress_name'] ?? null,
                'bank' => $_SESSION['supplier_data']['bank'] ?? null,
                'bank_code' => $_SESSION['supplier_data']['bank_code'] ?? null,
                'branch' => $_SESSION['supplier_data']['branch'] ?? null,
                'branch_code' => $_SESSION['supplier_data']['branch_code'] ?? null,
                'acc_no' => $_SESSION['supplier_data']['acc_no'] ?? null,
                'swift_code' => $_SESSION['supplier_data']['swift_code'] ?? null,
                'acc_currency_type' => $_SESSION['supplier_data']['acc_currency_type'] ?? null,
            ];
            $_SESSION['add_supplier_step'] = 2;
        }
        ob_end_clean(); // Clean the buffer before redirecting
        header("Location: add_supplier.php");
        exit();

    } elseif (isset($_POST['add_supplier'])) {
        
        if (!$is_ajax) {
             ob_end_clean();
             header("Location: add_supplier.php"); 
             exit();
        }
        
        // 🚨 CRITICAL FIX: Aggressively clean ALL output buffers right before JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        if (!isset($_SESSION['supplier_data'])) {
            echo json_encode(['status' => 'error', 'message' => 'Session data is missing. Please restart the process.']);
            exit();
        }

        $data = $_SESSION['supplier_data'];
        
        $final_data = array_merge($data, [
            'beneficiaress_name' => $_POST['beneficiaress_name'],
            'bank' => $_POST['bank'],
            'bank_code' => $_POST['bank_code'],
            'branch' => $_POST['branch'],
            'branch_code' => $_POST['branch_code'],
            'acc_no' => $_POST['acc_no'],
            'swift_code' => $_POST['swift_code'],
            'acc_currency_type' => $_POST['acc_currency_type'],
        ]);

        $sql = "INSERT INTO supplier (supplier_code, supplier, s_phone_no, email, beneficiaress_name, bank, bank_code, branch, branch_code, acc_no, swift_code, acc_currency_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssssssssss', 
            $final_data['supplier_code'], $final_data['supplier'], $final_data['s_phone_no'], $final_data['email'], $final_data['beneficiaress_name'],
            $final_data['bank'], $final_data['bank_code'], $final_data['branch'], $final_data['branch_code'], $final_data['acc_no'], $final_data['swift_code'], $final_data['acc_currency_type']
        );
        
        if ($stmt->execute()) {
            // Audit Logging (Using the 4 required arguments for an INSERT)
            $supplier_code = $final_data['supplier_code'];
            
            log_general_audit_entry(
                $conn, 
                'supplier', 
                $supplier_code, 
                'INSERT', 
                $logged_in_user_id
                // field_name, old_value, new_value are left as NULL by default
            );
            
            echo json_encode(['status'=>'success','message'=>'Supplier added successfully!']);
            
            unset($_SESSION['supplier_data']);
            unset($_SESSION['add_supplier_step']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Database error: '.$stmt->error]);
        }
        $stmt->close();
        
        // 🛑 Stop all execution and send JSON
        exit(); 
    }
} elseif (isset($_GET['back'])) {
    $_SESSION['add_supplier_step']--;
    if ($_SESSION['add_supplier_step'] < 1) {
        $_SESSION['add_supplier_step'] = 1;
    }
    ob_end_clean();
    header("Location: add_supplier.php");
    exit();
} elseif (isset($_GET['cancel'])) {
    unset($_SESSION['supplier_data']);
    unset($_SESSION['add_supplier_step']);
    ob_end_clean();
    header("Location: suppliers.php");
    exit();
}

// ----------------------------------------------------------------------------------
// HTML Output Block: Only execute if it's NOT an AJAX request
// ----------------------------------------------------------------------------------
if (!$is_ajax) {
    // ⚠️ CRITICAL FIX: Clean the buffer before rendering HTML
    ob_end_clean(); 
    
    include('../../includes/header.php');
    include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Supplier</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* ... CSS styles ... */
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
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
<div class="w-[85%] ml-[15%]">
<div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900">Add New Supplier</h1>
        <div class="text-lg text-gray-600 font-semibold">
            Step <?php echo $_SESSION['add_supplier_step']; ?> of 2
        </div>
    </div>
    <hr class="mb-6">

    <?php 
    $supplier_data = $_SESSION['supplier_data'] ?? [];
    
    if ($_SESSION['add_supplier_step'] == 1): ?>
        <h3 class="text-xl md:text-2xl font-semibold mb-4 text-gray-700">Basic Details</h3>
        <form method="POST" action="add_supplier.php" class="space-y-6">
            <input type="hidden" name="next_step" value="1">
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="supplier_code" class="block text-sm font-medium text-gray-700">Supplier Code:</label>
                    <input type="text" id="supplier_code" name="supplier_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['supplier_code'] ?? ''); ?>">
                </div>
                <div>
                    <label for="supplier" class="block text-sm font-medium text-gray-700">Supplier Name:</label>
                    <input type="text" id="supplier" name="supplier" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['supplier'] ?? ''); ?>">
                </div>
            </div>
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="s_phone_no" class="block text-sm font-medium text-gray-700">Phone No:</label>
                    <input type="text" id="s_phone_no" name="s_phone_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['s_phone_no'] ?? ''); ?>">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email:</label>
                    <input type="email" id="email" name="email" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['email'] ?? ''); ?>">
                </div>
            </div>
            <div class="flex justify-between mt-6">
                <a href="add_supplier.php?cancel=1" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Next
                </button>
            </div>
        </form>

    <?php elseif ($_SESSION['add_supplier_step'] == 2): ?>
        <h3 class="text-xl md:text-2xl font-semibold mb-4 text-gray-700">Bank Details</h3>
        <form name="add_supplier_form" class="space-y-6">
            <input type="hidden" name="add_supplier" value="1">
            <div>
                <label for="beneficiaress_name" class="block text-sm font-medium text-gray-700">Beneficiary's Name:</label>
                <input type="text" id="beneficiaress_name" name="beneficiaress_name" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['beneficiaress_name'] ?? ''); ?>">
            </div>
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="bank" class="block text-sm font-medium text-gray-700">Bank Name:</label>
                    <input type="text" id="bank" name="bank" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['bank'] ?? ''); ?>">
                </div>
                <div>
                    <label for="bank_code" class="block text-sm font-medium text-gray-700">Bank Code:</label>
                    <input type="text" id="bank_code" name="bank_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['bank_code'] ?? ''); ?>">
                </div>
            </div>
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="branch" class="block text-sm font-medium text-gray-700">Branch Name:</label>
                    <input type="text" id="branch" name="branch" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['branch'] ?? ''); ?>">
                </div>
                <div>
                    <label for="branch_code" class="block text-sm font-medium text-gray-700">Branch Code:</label>
                    <input type="text" id="branch_code" name="branch_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['branch_code'] ?? ''); ?>">
                </div>
            </div>
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label for="acc_no" class="block text-sm font-medium text-gray-700">Account No:</label>
                    <input type="text" id="acc_no" name="acc_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['acc_no'] ?? ''); ?>">
                </div>
                <div>
                    <label for="swift_code" class="block text-sm font-medium text-gray-700">Swift Code:</label>
                    <input type="text" id="swift_code" name="swift_code" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['swift_code'] ?? ''); ?>">
                </div>
            </div>
            <div>
                <label for="acc_currency_type" class="block text-sm font-medium text-gray-700">Account Currency Type:</label>
                <input type="text" id="acc_currency_type" name="acc_currency_type" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2" value="<?php echo htmlspecialchars($supplier_data['acc_currency_type'] ?? ''); ?>">
            </div>
            <div class="flex justify-between mt-6">
                <div class="flex space-x-4">
                    <a href="add_supplier.php?back=1" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                        Back
                    </a>
                    </div>
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Submit
                </button>
            </div>
        </form>
    <?php endif; ?>
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
        }, 3000); 
    }

    const finalForm = document.querySelector('form[name="add_supplier_form"]');
    if (finalForm) {
        finalForm.addEventListener('submit', async function(event) {
            event.preventDefault();

            const formData = new FormData(this);
            formData.append('add_supplier', '1'); 

            let response; 

            try {
                response = await fetch('add_supplier.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                // Read response as raw text first for robust error handling
                const rawText = await response.text();
                console.log('RAW RESPONSE (for debugging stray characters):', rawText);
                
                // Attempt to parse the cleaned text
                const result = JSON.parse(rawText);

                if (result.status === 'success') {
                    showToast(result.message, 'success');
                    setTimeout(() => {
                        window.location.href = 'suppliers.php';
                    }, 2000); 
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('Submission error (JSON.parse failed):', error);
                
                // Show a helpful error message to the user
                showToast('Submission failed! Server sent unexpected data. Check console for PHP errors or stray output.', 'error'); 
            }
        });
    }
</script>
</body>
</html>
<?php 
if (isset($conn)) {
    $conn->close();
}
// This is the end of the HTML block.
} 

// 🚨 CRITICAL: Clean up the buffer if it was used for HTML output
ob_end_flush();
// Ensure no whitespace or characters follow this closing tag.