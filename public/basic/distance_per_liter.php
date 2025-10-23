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

// Handle form submissions (POST requests) for individual database updates or insertion
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // --- Existing: Handle individual distance update ---
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
    // --- New: Handle insertion of a new record ---
    } elseif (isset($_POST['action']) && $_POST['action'] === 'add_new_consumption') {
        $c_type_raw   = trim($_POST['new_c_type'] ?? '');
        $distance_raw = $_POST['new_distance'] ?? '';

        // Sanitize / validate
        // Strip tags and ensure it's not empty for the type
        $c_type = filter_var($c_type_raw, FILTER_SANITIZE_STRING);
        // Validate float for distance
        $distance = filter_var($distance_raw, FILTER_VALIDATE_FLOAT);

        if (empty($c_type) || $distance === false || $distance < 0) {
            $toast_message = "Vehicle Type must be provided, and Distance must be a valid positive number.";
            $toast_type = "error";
        } else {
            try {
                // 1. Check if c_type already exists
                $chk_stmt = $conn->prepare("SELECT c_id FROM consumption WHERE c_type = ?");
                $chk_stmt->bind_param("s", $c_type);
                $chk_stmt->execute();
                $chk_stmt->store_result();

                if ($chk_stmt->num_rows > 0) {
                    $toast_message = "Vehicle Type '{$c_type}' already exists.";
                    $toast_type = "error";
                    $chk_stmt->close();
                } else {
                    $chk_stmt->close(); // Close previous statement
                    
                    // 2. Insert new record
                    $stmt = $conn->prepare("INSERT INTO consumption (c_type, distance) VALUES (?, ?)");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    // c_type (string), distance (double)
                    $stmt->bind_param("sd", $c_type, $distance);

                    if ($stmt->execute()) {
                        $toast_message = "New consumption rate for '{$c_type}' added successfully!";
                        $toast_type = "success";
                    } else {
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $toast_message = "Error: " . $e->getMessage();
                $toast_type = "error";
            }
        }
    }

    // Redirect to stay on the same page and show the toast message
    header("Location: distance_per_liter.php?toast_message=" . urlencode($toast_message) . "&toast_type=" . urlencode($toast_type));
    exit();
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
        
        /* * Custom Styles for Fixed Header/Scrollable Body 
         */

        /* This container is for the table body (tbody) vertical scrolling */
        .tbody-scroll-wrapper {
            max-height: 400px; /* Set a maximum height before scrolling kicks in */
            overflow-y: auto;  /* Enable vertical scrolling */
            overflow-x: auto;  /* Also enable horizontal scrolling for the body */
        }
        
        /* Ensure the main table components have min-width to trigger horizontal scroll */
        .min-w-custom {
            min-width: 500px; /* Adjust this value if needed, or stick to min-w-full if content dictates width */
        }

        /* Set specific column widths for alignment (must match between header and body!) */
        .col-vehicle-type { width: 40%; }
        .col-distance { width: 30%; }
        .col-action { width: 30%; }

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

            <div class="add-form p-3 mb-4 rounded-lg border border-gray-300 bg-green-50">
                <h3 class="text-xl font-semibold mb-3 text-green-800">Add New Fuel Efficiency (km/L)</h3>
                <form action="" method="POST" class="flex flex-col sm:flex-row gap-3 items-end">
                    <input type="hidden" name="action" value="add_new_consumption">
                    
                    <div class="flex-1 w-full">
                        <label for="new_c_type" class="block text-sm font-medium text-gray-700">Vehicle Type</label>
                        <input
                            type="text"
                            name="new_c_type"
                            id="new_c_type"
                            required
                            placeholder="e.g., Bus, Car, Bike"
                            class="mt-1 w-full px-3 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                            maxlength="50"
                        >
                    </div>

                    <div class="flex-1 w-full">
                        <label for="new_distance" class="block text-sm font-medium text-gray-700">Distance (km/L)</label>
                        <input
                            type="number"
                            step="0.01"
                            name="new_distance"
                            id="new_distance"
                            required
                            class="mt-1 w-full px-3 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                            value=""
                        >
                    </div>

                    <button type="submit" class="w-full sm:w-auto bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-green-700 transition-colors flex-shrink-0 flex items-center justify-center space-x-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-plus-circle-fill" viewBox="0 0 16 16">
                            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM8.5 4.5a.5.5 0 0 0-1 0v3h-3a.5.5 0 0 0 0 1h3v3a.5.5 0 0 0 1 0v-3h3a.5.5 0 0 0 0-1h-3v-3z"/>
                        </svg>
                        <span>Add New</span>
                    </button>
                </form>
            </div>
            
            <hr class="my-3">
            
            <div class="update-form p-3 rounded-lg border border-gray-300 bg-blue-50">
                <h3 class="text-xl font-semibold mb-3 text-blue-800">Update Fuel Effiency (km/L)</h3>
                
                <div class="overflow-x-auto rounded-t-lg">
                    <table class="min-w-custom w-full bg-gray-200 border-b border-gray-300 shadow-sm">
                        <thead>
                            <tr>
                                <th class="py-2 px-4 text-left col-vehicle-type">Vehicle Type</th>
                                <th class="py-2 px-2 text-left col-distance">Distance (km/L)</th>
                                <th class="py-2 px-4 text-center col-action">Action</th>
                            </tr>
                        </thead>
                    </table>
                </div>

                <div class="tbody-scroll-wrapper rounded-b-lg border border-t-0 border-gray-300">
                    <table class="min-w-custom w-full bg-white shadow-sm">
                        <tbody>
                            <?php if (!empty($consumptions)): ?>
                                <?php foreach ($consumptions as $row): ?>
                                    <tr>
                                        <form action="" method="POST" class="contents">
                                            <input type="hidden" name="action" value="update_distance_individual">
                                            <input type="hidden" name="c_id" value="<?php echo (int)$row['c_id']; ?>">
                                            <td class="py-2 px-4 border-b hover:bg-gray-50 col-vehicle-type">
                                                <?php echo htmlspecialchars($row['c_type']); ?>
                                            </td>
                                            <td class="py-2 px-2 border-b hover:bg-gray-50 col-distance">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    name="distance"
                                                    required
                                                    class="w-full px-2 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                    value="<?php echo htmlspecialchars((string)$row['distance']); ?>"
                                                >
                                            </td>
                                            <td class="py-2 px-4 border-b text-center hover:bg-gray-50 col-action">
                                                <button type="submit" class="bg-blue-500 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-blue-600 transition-colors">
                                                    Update
                                                </button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="py-4 px-4 text-center text-gray-500">
                                        No consumption records found. Please use the **Add New Distance Rate** form above.
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