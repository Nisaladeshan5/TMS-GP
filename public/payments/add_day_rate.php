<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

$message = '';
$status = ''; // Initialize status variable for toast
$selected_supplier_code = isset($_GET['supplier']) ? $_GET['supplier'] : '';

// Handle form submission to add a new rate
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $supplier_code = $_POST['supplier_code'];
    $day_rate = $_POST['day_rate'];
    $month = $_POST['month'];
    $year = $_POST['year'];

    // Check if a rate for this supplier code, month, and year already exists
    $check_sql = "SELECT id FROM night_emergency_day_rate WHERE supplier_code = ? AND month = ? AND year = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("sii", $supplier_code, $month, $year);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        // Update the existing rate
        $update_sql = "UPDATE night_emergency_day_rate SET day_rate = ? WHERE supplier_code = ? AND month = ? AND year = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("dsii", $day_rate, $supplier_code, $month, $year);
        if ($update_stmt->execute()) {
            $message = "Day rate updated successfully for " . date('F', mktime(0, 0, 0, $month, 1)) . " $year.";
            $status = "success";
        } else {
            $message = "Error updating day rate: " . $conn->error;
            $status = "error";
        }
        $update_stmt->close();
    } else {
        // Insert a new rate
        $insert_sql = "INSERT INTO night_emergency_day_rate (supplier_code, day_rate, month, year) VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("sdsi", $supplier_code, $day_rate, $month, $year);
        if ($insert_stmt->execute()) {
            $message = "Day rate added successfully.";
            $status = "success";
        } else {
            $message = "Error adding day rate: " . $conn->error;
            $status = "error";
        }
        $insert_stmt->close();
    }
    // Update the selected supplier code after a submission
    $selected_supplier_code = $supplier_code;
}

// Fetch list of unique suppliers and their codes for the dropdown,
// filtering for only those with 'night_emergency' vehicles.
$suppliers = [];
$suppliers_sql = "SELECT DISTINCT s.supplier, s.supplier_code 
                  FROM supplier s
                  INNER JOIN vehicle v ON s.supplier_code = v.supplier_code
                  WHERE v.purpose = 'night_emergency' 
                  ORDER BY s.supplier ASC";
$suppliers_result = $conn->query($suppliers_sql);
if ($suppliers_result) {
    while ($row = $suppliers_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
} else {
    // Handle the SQL query error
    $message = "Error fetching suppliers: " . $conn->error;
    $status = "error";
}

// Fetch existing day rates for the selected supplier
$rates_data = [];
$supplier_name_for_history = '';
if (!empty($selected_supplier_code)) {
    // First, get the supplier's name to display in the history section
    $name_sql = "SELECT supplier FROM supplier WHERE supplier_code = ?";
    $name_stmt = $conn->prepare($name_sql);
    $name_stmt->bind_param("s", $selected_supplier_code);
    $name_stmt->execute();
    $name_result = $name_stmt->get_result();
    if ($row = $name_result->fetch_assoc()) {
        $supplier_name_for_history = $row['supplier'];
    }
    $name_stmt->close();

    // Then, fetch the rates
    $rates_sql = "SELECT month, year, day_rate FROM night_emergency_day_rate WHERE supplier_code = ? ORDER BY year DESC, month DESC";
    $rates_stmt = $conn->prepare($rates_sql);
    $rates_stmt->bind_param("s", $selected_supplier_code);
    $rates_stmt->execute();
    $rates_result = $rates_stmt->get_result();
    while ($row = $rates_result->fetch_assoc()) {
        $rates_data[] = $row;
    }
    $rates_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Day Rate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Toast CSS */
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
<body class="bg-gray-50 text-gray-800 h-screen">
    <div id="toast-container"></div>
    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
        <div class="text-lg font-semibold ml-3">Day Rate Management</div>
    </div>
    
    <main class="w-[85%] ml-[15%] p-4 h-[95%]">
        <div class="w-2xl mx-auto">
            
            <div class="bg-white p-6 rounded-lg shadow-xl border border-gray-200 mb-6">
                <form method="post" action="add_day_rate.php">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div>
                            <label for="supplier" class="block text-sm font-medium text-gray-700">Supplier</label>
                            <select name="supplier_code" id="supplier" required onchange="showHistory()" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['supplier_code']); ?>" <?php echo ($s['supplier_code'] == $selected_supplier_code) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['supplier']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="day_rate" class="block text-sm font-medium text-gray-700">Day Rate (LKR)</label>
                            <input type="number" name="day_rate" id="day_rate" step="0.01" required 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                        </div>

                        <div>
                            <label for="month" class="block text-sm font-medium text-gray-700">Month</label>
                            <select name="month" id="month" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                                <?php for ($m=1; $m<=12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div>
                            <label for="year" class="block text-sm font-medium text-gray-700">Year</label>
                            <select name="year" id="year" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                                <?php for ($y=date('Y') + 1; $y>=2020; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y == date('Y')) ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-4 flex gap-x-2 justify-end">
                        <a href="night_emergency_payment.php" class="w-[15%] px-4 py-2 bg-gray-300 text-black font-semibold rounded-md shadow-lg hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 ease-in-out">
                            Back
                        </a>
                        <button type="submit" class="w-[20%] px-4 py-2 bg-blue-600 text-white font-semibold rounded-md shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 ease-in-out">
                            Save Rate
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-xl border border-gray-200">
                <h3 class="text-xl font-bold text-gray-700 mb-4">Rate History for <span id="history-supplier-name"><?php echo htmlspecialchars($supplier_name_for_history); ?></span></h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full leading-normal">
                        <thead>
                            <tr class="bg-gray-200 text-gray-600 text-sm font-semibold tracking-wider">
                                <th class="py-2 px-6 text-left">Month</th>
                                <th class="py-2 px-6 text-left">Year</th>
                                <th class="py-2 px-6 text-right">Day Rate (LKR)</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200" id="rate-history-table-body">
                            <?php if (!empty($rates_data)): ?>
                                <?php foreach ($rates_data as $rate): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-6 whitespace-nowrap"><?php echo date('F', mktime(0, 0, 0, $rate['month'], 1)); ?></td>
                                        <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($rate['year']); ?></td>
                                        <td class="py-3 px-6 whitespace-nowrap text-right"><?php echo number_format($rate['day_rate'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="py-4 text-center text-gray-500">No rate history found for this supplier.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    <script>
        function showHistory() {
            var supplierCode = document.getElementById('supplier').value;
            window.location.href = `add_day_rate.php?supplier=${encodeURIComponent(supplierCode)}`;
        }

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

            setTimeout(() => toast.classList.add('show'), 10);

            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 2000);
        }

        // PHP to JavaScript integration for displaying the toast
        <?php if ($message && $status): ?>
            showToast("<?php echo htmlspecialchars($message); ?>", "<?php echo htmlspecialchars($status); ?>");
        <?php endif; ?>
    </script>
</body>
</html>

<?php
$conn->close();
?>