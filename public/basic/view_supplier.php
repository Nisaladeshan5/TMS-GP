<?php
// view_supplier.php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

$supplier_code = $_GET['code'] ?? null;
$supplier_data = null;

// --- 1. Fetch Existing Supplier Data (Secure with Prepared Statements) ---
if ($supplier_code) {
    $sql = "SELECT * FROM supplier WHERE supplier_code = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $supplier_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplier_data = $result->fetch_assoc();
    $stmt->close();

    if (!$supplier_data) {
        header("Location: suppliers.php?status=error&message=" . urlencode("Supplier not found."));
        exit();
    }

    // Determine Status text and color
    $status_text = ($supplier_data['is_active'] == 1) ? 'Active' : 'Inactive';
    $status_color_class = ($supplier_data['is_active'] == 1) ? 'bg-green-600' : 'bg-red-600';

} else {
    header("Location: suppliers.php?status=error&message=" . urlencode("Supplier code missing."));
    exit();
}

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<style>
    /* Styling for read-only fields for a clean look - Minimum Height Kept for consistency */
    .display-field {
        background-color: #e5e7eb; /* Tailwind gray-200 */
        border: 1px solid #d1d5db; /* Tailwind gray-300 */
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem; /* Tailwind rounded-md */
        color: #1f2937; /* Tailwind gray-900 */
        font-weight: 500;
        line-height: 1.5;
        min-height: 2.5rem; /* Ensure consistent height */
        display: flex;
        align-items: center;
    }
    .label-style {
        display: block;
        font-size: 0.875rem; /* text-sm */
        font-weight: 501; /* font-medium */
        color: #4b5563; /* text-gray-700 */
        margin-bottom: 0.125rem; /* Reduced margin */
    }
</style>
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
    <div class="max-w-4xl p-8 bg-white shadow-lg rounded-lg mt-10 mx-auto">
        
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 **mb-3** **pb-1** flex justify-between items-center">
            Supplier Details: <?= htmlspecialchars($supplier_data['supplier_code']) ?>
            <span class="text-sm font-semibold py-1 px-3 rounded-full <?= $status_color_class ?> text-white"><?= $status_text ?></span>
        </h1>
        
        <div class="space-y-3">
            
            <div class="grid md:grid-cols-2 gap-3">
                <div class="md:col-span-2">
                    <h3 class="text-2xl font-semibold text-gray-500"><?= htmlspecialchars($supplier_data['supplier']) ?></h3>
                </div>
            </div>

            <div class="border rounded-md p-3 mt-2 bg-gray-50">
                <h2 class="text-xl font-bold text-gray-800 mb-2 border-b pb-1">Basic Information</h2>
                <div class="grid md:grid-cols-2 gap-3">
                    
                    <div>
                        <label class="label-style">Supplier Code:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['supplier_code']) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Supplier Name:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['supplier']) ?></div>
                    </div>
                    
                    <div>
                        <label class="label-style">Phone No:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['s_phone_no']) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Email:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['email']) ?></div>
                    </div>

                </div>
            </div>

            <div class="border rounded-md p-3 mt-2 bg-gray-50">
                <h2 class="text-xl font-bold text-gray-800 mb-2 border-b pb-1">Bank Details</h2>
                <div class="grid md:grid-cols-2 gap-3">

                    <div>
                        <label class="label-style">Beneficiary's Name:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['beneficiaress_name']) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Account No:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['acc_no']) ?></div>
                    </div>

                    <div>
                        <label class="label-style">Bank Name:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['bank']) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Bank Code:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['bank_code']) ?></div>
                    </div>

                    <div>
                        <label class="label-style">Branch Name:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['branch']) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Branch Code:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['branch_code']) ?></div>
                    </div>

                    <div>
                        <label class="label-style">Swift Code:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['swift_code']) ?></div>
                    </div>
                    <div>
                        <label class="label-style">Account Currency Type:</label>
                        <div class="display-field"><?= htmlspecialchars($supplier_data['acc_currency_type']) ?></div>
                    </div>
                    
                </div>
            </div>

        </div>
        
        <div class="flex justify-end mt-2 pt-2 space-x-4">
            <a href="suppliers.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                Close
            </a>
        </div>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ?>