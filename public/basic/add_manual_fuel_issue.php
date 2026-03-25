<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// Session eken user_id eka gannawa
$logged_in_user_id = $_SESSION['user_id'] ?? 0;

ini_set('display_errors', 0);
ini_set('log_errors', 1);

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// --- Data Insert Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_route_fuel'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $code = trim($_POST['code']);
    $issued_qty = (float)$_POST['issued_qty'];
    $issue_date = trim($_POST['issue_date']);

    if (empty($code) || $issued_qty <= 0 || empty($issue_date)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required and quantity must be positive.']);
        exit();
    }

    // SQL update kala user_id ekath ekka
    $sql = "INSERT INTO fuel_issues (code, date, issued_qty, user_id) VALUES (?, ?, ?, ?)";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssdi', $code, $issue_date, $issued_qty, $logged_in_user_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Route fuel issue recorded successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    $conn->close();
    exit();
}

// --- Fetching Active Routes & Sub Routes for Dropdown ---
$main_routes = $conn->query("SELECT route_code, route FROM route WHERE is_active = 1 ORDER BY route_code ASC")->fetch_all(MYSQLI_ASSOC);
$sub_routes = $conn->query("SELECT sub_route_code, sub_route FROM sub_route WHERE is_active = 1 ORDER BY sub_route_code ASC")->fetch_all(MYSQLI_ASSOC);

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Route Fuel Issue</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); color: white; transition: all 0.3s ease-in-out; transform: translateY(-20px); opacity: 0; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="w-[85%] ml-[15%]">
        <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10 mx-auto border border-gray-200">
            <h1 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">Manual Route Fuel Issue</h1>
            
            <form id="routeFuelForm" class="space-y-6">
                <input type="hidden" name="add_route_fuel" value="1">
                
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 font-bold mb-1">Select Route / Sub-Route:</label>
                        <select id="code" name="code" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-3 border">
                            <option value="">-- Choose Route --</option>
                            
                            <optgroup label="MAIN ROUTES">
                                <?php foreach ($main_routes as $r): ?>
                                    <option value="<?php echo htmlspecialchars($r['route_code']); ?>">
                                        <?php echo htmlspecialchars($r['route_code']) . " - " . htmlspecialchars($r['route']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>

                            <optgroup label="SUB ROUTES">
                                <?php foreach ($sub_routes as $sr): ?>
                                    <option value="<?php echo htmlspecialchars($sr['sub_route_code']); ?>">
                                        <?php echo htmlspecialchars($sr['sub_route_code']) . " - " . htmlspecialchars($sr['sub_route']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="issued_qty" class="block text-sm font-medium text-gray-700 font-bold mb-1">Quantity (L):</label>
                            <input type="number" step="0.01" id="issued_qty" name="issued_qty" required placeholder="0.00" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-3 border font-mono">
                        </div>

                        <div>
                            <label for="issue_date" class="block text-sm font-medium text-gray-700 font-bold mb-1">Issue Date:</label>
                            <input type="date" id="issue_date" name="issue_date" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-3 border">
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-between mt-6 space-x-4">
                    <a href="fuel_issue_history.php?view_filter=routes" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2.5 px-6 rounded-md transition duration-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-8 rounded-md shadow-lg transition duration-300 transform hover:scale-105">
                        Save Route Issue <i class="fas fa-gas-pump ml-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast-container"></div>

    <script>
        function showToast(message, type) {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type} px-6 py-3 font-bold`;
            toast.innerHTML = `<i class="fas ${type==='success'?'fa-check-circle':'fa-exclamation-triangle'} mr-2"></i> <span>${message}</span>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 3000);
        }

        document.getElementById('routeFuelForm').addEventListener('submit', async function(event) {
            event.preventDefault();
            const formData = new FormData(this);
            try {
                const response = await fetch('add_manual_fuel_issue.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showToast(result.message, 'success');
                    setTimeout(() => window.location.href = 'fuel_issue_history.php?view_filter=routes', 2000);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('An unexpected error occurred.', 'error');
            }
        });
    </script>
</body>
</html>