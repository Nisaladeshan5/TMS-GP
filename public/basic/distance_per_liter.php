<?php
include('../../includes/db.php');

$toast_message = null;
$toast_type = null;

/**
 * Fetch all consumption rows (id, type, distance)
 */
function getAllConsumption($conn) {
    $rows = [];
    try {
        $sql = "SELECT c_id, c_type, distance FROM consumption ORDER BY c_id";
        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
    } catch (Throwable $e) {
        error_log("Error fetching consumption rows: " . $e->getMessage());
    }
    return $rows;
}

// Handle form submissions (POST requests) for individual database updates
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['action']) && $_POST['action'] === 'update_distance_individual') {
        // Get posted values
        $c_id_raw   = $_POST['c_id'] ?? '';
        $distance_raw = $_POST['distance'] ?? '';

        // Sanitize / validate
        $c_id = filter_var($c_id_raw, FILTER_VALIDATE_INT);
        $distance = filter_var($distance_raw, FILTER_VALIDATE_FLOAT);

        if ($c_id === false || $distance === false) {
            $toast_message = "Valid ID and numeric distance are required.";
            $toast_type = "error";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE consumption SET distance = ? WHERE c_id = ?");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                // distance (double), c_id (int)
                $stmt->bind_param("di", $distance, $c_id);

                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $toast_message = "Distance updated successfully!";
                        $toast_type = "success";
                    } else {
                        // Might be same value or row not found
                        // Check if row exists
                        $chk = $conn->prepare("SELECT 1 FROM consumption WHERE c_id = ?");
                        $chk->bind_param("i", $c_id);
                        $chk->execute();
                        $chk->store_result();
                        if ($chk->num_rows === 0) {
                            $toast_message = "No record found to update. Please insert the record first.";
                            $toast_type = "error";
                        } else {
                            $toast_message = "No changes detected (same value).";
                            $toast_type = "success";
                        }
                        $chk->close();
                    }
                } else {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $stmt->close();
            } catch (Throwable $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }

        // Redirect to stay on the same page and show the toast message
        header("Location: distance_per_liter.php?toast_message=" . urlencode($toast_message) . "&toast_type=" . urlencode($toast_type));
        exit();
    }
}

// Fetch all data needed for the page
$consumptions = getAllConsumption($conn);

// Get toast message from URL parameters after redirect
if (isset($_GET['toast_message']) && isset($_GET['toast_type'])) {
    $toast_message = $_GET['toast_message'];
    $toast_type = $_GET['toast_type'];
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distance Rates</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 ">
    <div class="flex justify-center items-center w-[85%] ml-[15%] mt-3">
        <div class="w-full max-w-xl mx-auto p-3 bg-white rounded-lg shadow-md">
            <h2 class="text-3xl font-bold mb-3 text-center text-blue-600">Update Distance Efficiency</h2>
            <div class="flex justify-center gap-4 mb-4">
                <a href="fuel.php" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-green-700 transition-colors flex items-center justify-center">
                    View All Fuel Rates
                </a>
                <p  class="bg-gray-300 text-white font-bold py-2 px-4 rounded-lg shadow-md transition-colors flex items-center justify-center">
                    Update Distance
                </p>
            </div>

            <hr class="my-3">
            <div class="update-form p-3 rounded-lg border border-gray-300 bg-blue-50">
                <h3 class="text-xl font-semibold mb-3 text-blue-800">Distance per Liter (km/L)</h3>

                <div class="overflow-x-auto rounded-lg">
                    <table class="min-w-full bg-white border border-gray-200 shadow-sm">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="py-2 px-4 border-b text-left">Vehicle Type</th>
                                <th class="py-2 px-2 border-b text-left">Distance (km/L)</th>
                                <th class="py-2 px-4 border-b text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($consumptions)): ?>
                                <?php foreach ($consumptions as $row): ?>
                                    <tr>
                                        <form action="" method="POST">
                                            <input type="hidden" name="action" value="update_distance_individual">
                                            <input type="hidden" name="c_id" value="<?php echo (int)$row['c_id']; ?>">
                                            <td class="py-2 px-4 border-b hover:bg-gray-50">
                                                <?php echo htmlspecialchars($row['c_type']); ?>
                                            </td>
                                            <td class="py-2 px-2 border-b hover:bg-gray-50">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    name="distance"
                                                    required
                                                    class="w-full px-2 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                    value="<?php echo htmlspecialchars((string)$row['distance']); ?>"
                                                >
                                            </td>
                                            <td class="py-2 px-4 border-b text-center hover:bg-gray-50">
                                                <button type="submit" class="bg-green-500 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-green-600 transition-colors">
                                                    Update
                                                </button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="py-4 px-4 text-center text-gray-500">
                                        No consumption records found. Please insert records into the <code>consumption</code> table.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>

    <div id="toast-container"></div>
    <script>
        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                                ${type === 'success' ?
                                    '<path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.293 12.5a1.003 1.003 0 0 1-1.417 0L2.354 8.7a.733.733 0 0 1 1.047-1.05l3.245 3.246 6.095-6.094z"/>' :
                                    '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/> <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>'
                                }
                            </svg>
                            <p class="font-semibold">${message}</p>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => toast.classList.remove('show'), 3000);
            setTimeout(() => toast.remove(), 3500);
        }

        <?php if (isset($toast_message) && isset($toast_type)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showToast("<?php echo htmlspecialchars($toast_message, ENT_QUOTES, 'UTF-8'); ?>",
                      "<?php echo htmlspecialchars($toast_type, ENT_QUOTES, 'UTF-8'); ?>");
        });
        <?php endif; ?>
    </script>
</body>
</html>
