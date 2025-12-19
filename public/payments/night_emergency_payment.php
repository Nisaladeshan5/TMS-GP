<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$payment_data = [];
$table_headers = [
    "Supplier", "Supplier Code", "Total Worked Days", "Total Payment (LKR)", "Actions"
]; // Removed "Day Rate (LKR)"
$page_title = "Night Emergency Payments Summary";

// SQL query to fetch payment from monthly_payment_ne AND count attendance
$sql = "SELECT
            s.supplier,
            s.supplier_code,
            mpn.monthly_payment AS total_payment,
            (
                SELECT
                    COUNT(DISTINCT nea.date) -- එක දිනකට එකම සැපයුම්කරුගෙන් වාර්තා කිහිපයක් තිබුණත්, වැඩ කළ දින ගණන නිවැරදිව ලබා ගැනීමට DISTINCT භාවිතා කරයි.
                FROM
                    night_emergency_attendance AS nea
                JOIN
                    op_services AS ops ON nea.op_code = ops.op_code
                JOIN
                    vehicle AS v ON ops.vehicle_no = v.vehicle_no
                WHERE
                    v.supplier_code = s.supplier_code -- මෙතැනදී ප්‍රධාන විමසුමේ (Outer Query) 's' ගේ supplier_code භාවිතා කරයි.
                    AND MONTH(nea.date) = ?
                    AND YEAR(nea.date) = ?
            ) AS total_worked_days
        FROM
            monthly_payment_ne AS mpn
        JOIN
            supplier AS s ON mpn.supplier_code = s.supplier_code
        WHERE
            mpn.month = ?
            AND mpn.year = ?
        ORDER BY
            s.supplier ASC";

$stmt = $conn->prepare($sql);
// Bind parameters: (Month, Year) for subquery COUNT, then (Month, Year) for main query WHERE
$stmt->bind_param("siss", $selected_month, $selected_year, $selected_month, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $payment_data[] = [
            'supplier' => $row['supplier'],
            'supplier_code' => $row['supplier_code'],
            'total_worked_days' => $row['total_worked_days'], // Now fetched from the subquery
            // 'day_rate' is no longer needed in the data array or passed to the view
            'total_payment' => !is_null($row['total_payment']) ? $row['total_payment'] : 0
        ];
    }
}
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Night Emergency Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
<body class="bg-gray-50 text-gray-800 h-screen">

    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
        <div class="text-lg font-semibold ml-3">Payments</div>
        <div class="flex gap-4">
            <a href="payments_category.php" class="hover:text-yellow-600">Staff</a>
            <a href="factory/factory_route_payments.php" class="hover:text-yellow-600">Factory</a>
            <a href="factory/sub/sub_route_payments.php" class="hover:text-yellow-600">Sub Route</a>
            <a href="DH/day_heldup_payments.php" class="hover:text-yellow-600">Day Heldup</a>
            <a href="" class="hover:text-yellow-600">Night Heldup</a>
            <p class="hover:text-yellow-600 text-yellow-500 font-bold">Night Emergency</p>
            <a href="" class="hover:text-yellow-600">Extra Vehicle</a>
            <a href="own_vehicle_payments.php" class="hover:text-yellow-600">Fuel Allowance</a>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-4 h-[95%]">
        <div class="flex justify-between items-center mb-2">
            <h2 class="text-3xl font-bold text-gray-800"><?php echo $page_title; ?></h2>
            <div class="flex justify-end p-2 rounded-lg mb-2">
                <form method="get" action="" class="flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-2">
                    <a href="add_day_rate.php"
                    class="text-white font-bold hover:text-blue-700 bg-yellow-600 py-2 px-3 rounded-lg"   
                    title="Manage Day Rate">
                        Day Rate
                    </a>
                    <a href="download_night_emergency_payments.php?month=<?php echo htmlspecialchars($selected_month); ?>&year=<?php echo htmlspecialchars($selected_year); ?>"
                        class="w-full sm:w-auto px-3 py-2 bg-green-600 text-white font-semibold rounded-md shadow-lg hover:bg-green-700 text-center"
                        id="download-link">
                        <i class="fas fa-download"></i>
                    </a>

                    <div class="relative border border-gray-400 rounded-lg">
                        <select name="month" id="month" class="w-full pl-3 pr-10 py-2 text-base border-gray-300 rounded-md">
                            <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($selected_month == sprintf('%02d', $m)) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 10)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="relative border border-gray-400 rounded-lg">
                        <select name="year" id="year" class="w-full pl-3 pr-10 py-2 text-base border-gray-300 rounded-md">
                            <?php for ($y=date('Y'); $y>=2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700">
                        <i class="fas fa-filter mr-2"></i> Filter
                    </button>
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto bg-white rounded-lg shadow-xl border border-gray-200">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr class="bg-blue-600 text-white text-sm font-semibold tracking-wider">
                        <?php foreach ($table_headers as $header): ?>
                            <th class="py-2 px-6 text-left"><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-light divide-y divide-gray-200">
                    <?php if (!empty($payment_data)): ?>
                        <?php foreach ($payment_data as $data): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150 ease-in-out">
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['supplier']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['supplier_code']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap"><?php echo htmlspecialchars($data['total_worked_days']); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap font-bold text-blue-700 text-lg"><?php echo number_format($data['total_payment'], 2); ?></td>
                                <td class="py-3 px-6 whitespace-nowrap">
                                    <a href="download_night_emergency_pdf.php?supplier_code=<?php echo urlencode($data['supplier_code']); ?>&month=<?php echo urlencode($selected_month); ?>&year=<?php echo urlencode($selected_year); ?>&worked_days=<?php echo urlencode($data['total_worked_days']); ?>&day_rate=0" 
                                    class="text-red-500 hover:text-red-700 individual-download-link"
                                    title="Download Supplier Summary PDF">
                                        <i class="fas fa-file-pdf fa-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo count($table_headers); ?>" class="py-6 text-center text-gray-500 text-base">No night emergency payments found for this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>

<?php
$conn->close();
?>