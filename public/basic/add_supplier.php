<?php
// Include the database connection and start session
session_start();
include('../../includes/db.php');

// Define a flag to check if the request is an AJAX call
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Initialize the session step variable
if (!isset($_SESSION['add_supplier_step'])) {
    $_SESSION['add_supplier_step'] = 1;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle 'next' step submission (Step 1 -> Step 2)
    if (isset($_POST['next_step'])) {
        if ($_POST['next_step'] == 1) {
            $_SESSION['supplier_data'] = [
                'supplier_code' => $_POST['supplier_code'],
                'supplier' => $_POST['supplier'],
                's_phone_no' => $_POST['s_phone_no'],
                'email' => $_POST['email'],
            ];
            $_SESSION['add_supplier_step'] = 2;
        }
        header("Location: add_supplier.php");
        exit();
    } elseif (isset($_POST['add_supplier'])) {
        // This part is for the final AJAX submission from Step 2
        if (!$is_ajax) {
            header("Location: add_supplier.php");
            exit();
        }

        // Check if session data exists
        if (!isset($_SESSION['supplier_data'])) {
            echo json_encode(['status' => 'error', 'message' => 'Session data is missing. Please restart the process.']);
            exit();
        }

        $data = $_SESSION['supplier_data'];
        
        // Merge data from the final form submission with session data
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

        // Corrected SQL statement with all 12 columns
        $sql = "INSERT INTO supplier (supplier_code, supplier, s_phone_no, email, beneficiaress_name, bank, bank_code, branch, branch_code, acc_no, swift_code, acc_currency_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        // Bind parameters
        $stmt->bind_param('ssssssssssss', 
            $final_data['supplier_code'], $final_data['supplier'], $final_data['s_phone_no'], $final_data['email'], $final_data['beneficiaress_name'],
            $final_data['bank'], $final_data['bank_code'], $final_data['branch'], $final_data['branch_code'], $final_data['acc_no'], $final_data['swift_code'], $final_data['acc_currency_type']
        );
        
        if ($stmt->execute()) {
            echo json_encode(['status'=>'success','message'=>'Supplier added successfully!']);
            // Clear session data after successful submission
            unset($_SESSION['supplier_data']);
            unset($_SESSION['add_supplier_step']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Database error: '.$stmt->error]);
        }
        $stmt->close();
        exit();
    }
} elseif (isset($_GET['back'])) {
    // Handle 'back' button
    $_SESSION['add_supplier_step']--;
    if ($_SESSION['add_supplier_step'] < 1) {
        $_SESSION['add_supplier_step'] = 1;
    }
    header("Location: add_supplier.php");
    exit();
}

// Only include HTML and other content if it's not an AJAX request
if (!$is_ajax) {
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
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .toast {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
        }

        .toast.error {
            background-color: #F44336;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
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
    
    // Check the session step variable to determine which form to display
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
            <div class="flex justify-end mt-6">
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
                <a href="add_supplier.php?back=1" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                    Back
                </a>
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
    /**
     * Displays a toast notification.
     * @param {string} message The message to display.
     * @param {string} type The type of toast ('success' or 'error').
     */
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

        // Show the toast with a slight delay for the transition effect
        setTimeout(() => toast.classList.add('show'), 10);

        // Automatically hide and remove the toast after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 1300);
    }

    // Attach event listener to the final form submission
    const finalForm = document.querySelector('form[name="add_supplier_form"]');
    if (finalForm) {
        finalForm.addEventListener('submit', async function(event) {
            event.preventDefault();

            const formData = new FormData(this);
            formData.append('add_supplier', '1'); // Ensure this POST variable is set for AJAX

            try {
                const response = await fetch('add_supplier.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showToast(result.message, 'success');
                    setTimeout(() => {
                        window.location.href = 'suppliers.php';
                    }, 2000);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('Submission error:', error);
                showToast('An unexpected error occurred.', 'error');
            }
        });
    }
</script>
</body>
</html>
<?php 
} // End of if (!$is_ajax) block
$conn->close(); 
?>