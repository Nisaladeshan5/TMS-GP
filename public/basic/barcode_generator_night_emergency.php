<?php
require_once '../../includes/session_check.php';
// barcode.php (Form page)
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
include('../../includes/config.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Barcode Generator</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
<body class="bg-gray-50 p-6">
    <div class="w-[85%] ml-[15%] mt-[10%]">
        <div class="max-w-2xl mx-auto bg-white shadow-lg rounded-lg p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">Generate Supplier Barcodes</h1>

            <form method="POST" action="generate_barcode/generate_barcodes_night_emergency.php" target="_blank" id="barcodeForm">
            <table class="w-full border border-gray-200 rounded-lg overflow-hidden">
                <thead class="bg-gray-100 text-gray-700">
                <tr>
                    <th class="px-4 py-2">
                    <input type="checkbox" id="selectAll" class="cursor-pointer">
                    </th>
                    <th class="px-4 py-2 text-left">Supplier Code</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                <?php
                // Changed SQL query to select supplier_code from the supplier table
                $result = $conn->query("SELECT supplier_code FROM supplier ORDER BY supplier_code ASC");
                while ($row = $result->fetch_assoc()): ?>
                    <tr class="vehicle-row">
                    <td class="px-4 py-2">
                        <input type="checkbox" name="suppliers[]" value="<?= $row['supplier_code'] ?>" class="vehicle-checkbox cursor-pointer">
                    </td>
                    <td class="px-4 py-2 flex items-center gap-2">
                        <span class="vehicle-text"><?= $row['supplier_code'] ?></span>
                        <span class="selected-badge hidden text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Selected</span>
                    </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>

            <div class="mt-6">
                <button type="submit" id="generateBarcodesBtn" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition">
                Generate Barcode PDF
                </button>
            </div>
            </form>
        </div>
    </div>

    <script>
    // Update row style and badge when selected
    function updateRowStyles() {
      document.querySelectorAll('.vehicle-row').forEach(row => {
        const checkbox = row.querySelector('.vehicle-checkbox');
        const badge = row.querySelector('.selected-badge');
        if (checkbox.checked) {
          row.classList.add('bg-blue-50');
          badge.classList.remove('hidden');
        } else {
          row.classList.remove('bg-blue-50');
          badge.classList.add('hidden');
        }
      });
    }

    // Listen to checkbox changes
    document.querySelectorAll('.vehicle-checkbox').forEach(cb => {
      cb.addEventListener('change', updateRowStyles);
    });

    // Select all functionality
    document.getElementById('selectAll').addEventListener('change', function() {
      document.querySelectorAll('.vehicle-checkbox').forEach(cb => {
        cb.checked = this.checked;
      });
      updateRowStyles();
    });

    // Prevent empty submission
    document.getElementById('generateBarcodesBtn').addEventListener('click', function(event) {
      const selected = document.querySelectorAll('.vehicle-checkbox:checked').length;
      if (selected === 0) {
        event.preventDefault();
        alert('⚠️ Please select at least one supplier before generating PDF.');
      }
    });
    </script>
</body>
</html>