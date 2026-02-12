<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

$msg = ""; $msg_type = "";

// --- 1. Form Submission Logic with Duplicate Key Catching ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_log'])) {
    $emp_id = trim($_POST['emp_id']);
    $role = $_POST['role'];
    
    // REQUIREMENT: Auto set password to 12345678
    $password = password_hash('12345678', PASSWORD_DEFAULT); 

    try {
        // 1.1 Check Master Registry (Employee Table)
        $check_emp = $conn->prepare("SELECT emp_id FROM employee WHERE emp_id = ?");
        $check_emp->bind_param("s", $emp_id);
        $check_emp->execute();
        
        if ($check_emp->get_result()->num_rows === 0) {
            $msg = "Error: Employee ID not found in system!"; 
            $msg_type = "error";
        } else {
            $proceed = true;
            // 1.2 Manager requirement check
            if ($role === 'manager') {
                $check_v = $conn->prepare("SELECT emp_id FROM own_vehicle WHERE emp_id = ?");
                $check_v->bind_param("s", $emp_id); 
                $check_v->execute();
                if ($check_v->get_result()->num_rows === 0) {
                    $msg = "Error: Managers must have a registered vehicle!"; 
                    $msg_type = "error";
                    $proceed = false;
                }
            }

            if ($proceed) {
                // 1.3 Attempt to Insert (first_log = 1 means they must change password)
                $stmt = $conn->prepare("INSERT INTO manager_log (emp_id, password, role, first_log) VALUES (?, ?, ?, 1)");
                $stmt->bind_param("sss", $emp_id, $password, $role);
                if ($stmt->execute()) {
                    $_SESSION['toast'] = ['message' => "Access granted! Default password: 12345678", 'type' => 'success'];
                    header("Location: manager_access_list.php"); 
                    exit();
                }
            }
        }
    } catch (mysqli_sql_exception $e) {
        // SQL Error Code 1062 kiyanne Duplicate Entry (Primary Key violation)
        if ($e->getCode() === 1062) {
            $msg = "Action Denied: This Employee ID already has system access!";
        } else {
            $msg = "System Error: " . $e->getMessage();
        }
        $msg_type = "error";
    }
}

include('../../includes/header.php'); 
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grant System Access</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        body { font-family: 'Inter', sans-serif; }
        .spinner { border: 3px solid rgba(0, 0, 0, 0.1); border-left-color: #4f46e5; border-radius: 50%; width: 1rem; height: 1rem; animation: spin 1s linear infinite; display: inline-block; margin-right: 0.5rem; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>

<body class="bg-gray-100 font-sans">

    <div class="w-[85%] ml-[15%] flex justify-center"> 
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-xl mt-10 border border-gray-200">
            
            <div class="mb-8 border-b pb-4">
                <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Grant Access</h1>
                <p class="text-gray-500 text-sm mt-1">Assign roles and generate login credentials.</p>
            </div>
            
            <form id="grant-access-form" action="" method="POST" class="space-y-6">
                
                <?php if ($msg): ?>
                    <div class="p-4 rounded-lg text-sm font-bold flex items-center <?php echo $msg_type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                        <i class="fas <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2 text-lg"></i>
                        <?php echo $msg; ?>
                    </div>
                <?php endif; ?>

                <div class="space-y-4">
                    <div>
                        <label for="emp_id" class="block text-sm font-semibold text-gray-700">Employee ID</label>
                        <input type="text" id="emp_id" name="emp_id" required 
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-3 border" 
                            placeholder="Enter ID (e.g., GP0000)">
                        <p id="emp-id-status" class="mt-2 text-xs text-gray-500 font-medium italic">Type ID to check availability...</p>
                    </div>

                    <div>
                        <label for="role" class="block text-sm font-semibold text-gray-700">System Role</label>
                        <select id="role" name="role" required 
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-3 border appearance-none bg-white">
                            <option value="manager">MANAGER (Requires Own Vehicle)</option>
                            <option value="admin">ADMINISTRATOR</option>
                        </select>
                        <p class="mt-2 text-[10px] text-blue-500 uppercase tracking-widest font-bold">* Default password will be set to: 12345678</p>
                    </div>
                </div>

                <div class="flex justify-between items-center mt-8 pt-6 border-t border-gray-100">
                    <a href="manager_access_list.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2.5 px-6 rounded-lg transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" name="create_log" id="submit-btn" 
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-300 transform hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed">
                        Generate Access
                    </button>
                </div>
            </form>
        </div>
    </div> 

    <script>
        const empIdInput = document.getElementById('emp_id');
        const empIdStatus = document.getElementById('emp-id-status');
        const submitBtn = document.getElementById('submit-btn');
        let debounceTimer;

        empIdInput.addEventListener('keyup', function() {
            clearTimeout(debounceTimer);
            const empId = this.value.trim();

            if (empId.length < 3) {
                empIdStatus.textContent = 'Type ID to check availability...';
                empIdStatus.className = "mt-2 text-xs text-gray-500 font-medium italic";
                submitBtn.disabled = true;
                return;
            }

            empIdStatus.innerHTML = '<span class="spinner"></span> Validating...';
            
            debounceTimer = setTimeout(() => {
                fetch('check_emp.php?emp_id=' + empId) 
                    .then(response => response.json())
                    .then(data => {
                        if (data.isValid) {
                            empIdStatus.innerHTML = `<i class="fas fa-check-circle"></i> ID Available: <span class="font-bold">${data.name}</span>`;
                            empIdStatus.className = "mt-2 text-xs text-green-600 font-bold";
                            submitBtn.disabled = false;
                        } else {
                            empIdStatus.innerHTML = `<i class="fas fa-exclamation-triangle text-red-500"></i> ${data.message}`;
                            empIdStatus.className = "mt-2 text-xs text-red-600 font-bold";
                            submitBtn.disabled = true;
                        }
                    })
                    .catch(err => {
                        empIdStatus.textContent = 'Error verifying ID status.';
                        submitBtn.disabled = true;
                    });
            }, 500);
        });
    </script>
</body>
</html>