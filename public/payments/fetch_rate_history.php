<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

// fetch_rate_history.php
// Returns an HTML fragment for the rate history table for a supplier.
// Expects GET parameter: supplier
include('../../includes/db.php');

if (!isset($_GET['supplier']) || empty($_GET['supplier'])) {
    http_response_code(400);
    echo '<div class="py-6 text-center text-gray-500">No supplier selected.</div>';
    exit;
}

$supplier_code = $_GET['supplier'];

// Get supplier name (for header)
$supplier_name_for_history = '';
$name_sql = "SELECT supplier FROM supplier WHERE supplier_code = ?";
if ($name_stmt = $conn->prepare($name_sql)) {
    $name_stmt->bind_param("s", $supplier_code);
    $name_stmt->execute();
    $name_result = $name_stmt->get_result();
    if ($row = $name_result->fetch_assoc()) {
        $supplier_name_for_history = $row['supplier'];
    }
    $name_stmt->close();
}

// Fetch rates
$rates_data = [];
$rates_sql = "SELECT last_updated_date, day_rate FROM night_emergency_day_rate WHERE supplier_code = ? ORDER BY last_updated_date DESC";
if ($rates_stmt = $conn->prepare($rates_sql)) {
    $rates_stmt->bind_param("s", $supplier_code);
    $rates_stmt->execute();
    $rates_result = $rates_stmt->get_result();
    while ($row = $rates_result->fetch_assoc()) {
        $rates_data[] = $row;
    }
    $rates_stmt->close();
}

// Build HTML fragment (table)
?>
<!-- echo supplier name for parent script to read -->
<span id="history-supplier-title" style="display:none;"><?php echo htmlspecialchars($supplier_name_for_history ?: '—'); ?></span>

<?php if (!empty($rates_data)): ?>
    <table class="min-w-full leading-normal">
        <thead>
            <tr class="bg-gray-200 text-gray-600 text-sm font-semibold tracking-wider">
                <th class="py-2 px-6 text-left">Last Updated Date</th>
                <th class="py-2 px-6 text-right">Day Rate (LKR)</th>
            </tr>
        </thead>
        <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
            <?php foreach ($rates_data as $rate):
                $display_date = date('Y-m-d', strtotime($rate['last_updated_date']));
            ?>
            <tr class="hover:bg-gray-50">
                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($display_date); ?></td>
                <td class="py-3 px-6 whitespace-nowrap text-right"><?php echo number_format($rate['day_rate'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="py-6 text-center text-gray-500">No rate history found for this supplier.</div>
<?php endif; ?>

<?php
$conn->close();
?>
