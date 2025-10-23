<?php
// add_day_rate.php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

$message = '';
$status = '';
// We won't use GET supplier to render the page now since history is fetched via AJAX.
// But keep a default so if someone links with ?supplier=.. it can preselect via JS.
$selected_supplier_code = isset($_GET['supplier']) ? $_GET['supplier'] : '';

// Fetch suppliers (for the <select>)
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
    // If fetching suppliers fails, show a message in JS toast after page load
    $message = "Error fetching suppliers: " . $conn->error;
    $status = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Manage Day Rate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: flex; flex-direction: column; align-items: flex-end; }
        .toast { display: flex; align-items: center; padding: 1rem; margin-bottom: 0.5rem; border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1); color: white; transition: transform 0.3s, opacity 0.3s; transform: translateY(-20px); opacity: 0; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { background-color: #4CAF50; }
        .toast.error { background-color: #F44336; }
        .toast-icon { width: 1.5rem; height: 1.5rem; margin-right: 0.75rem; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <div id="toast-container"></div>

    <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
        <div class="text-lg font-semibold ml-3">Day Rate Management</div>
    </div>

    <main class="w-[85%] ml-[15%] p-4">
        <div class="w-2xl mx-auto">
            <!-- Form card -->
            <div class="bg-white p-6 rounded-lg shadow-xl border border-gray-200 mb-6">
                <form id="dayRateForm" method="post" action="add_day_rate_ajax.php">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div>
                            <label for="supplier" class="block text-sm font-medium text-gray-700">Supplier</label>
                            <select name="supplier_code" id="supplier" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['supplier_code']); ?>">
                                        <?php echo htmlspecialchars($s['supplier']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="day_rate" class="block text-sm font-medium text-gray-700">Day Rate (LKR)</label>
                            <input type="number" name="day_rate" id="day_rate" step="0.01" required 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border" />
                        </div>

                        <div>
                            <label for="last_updated_date" class="block text-sm font-medium text-gray-700">Last Updated Date</label>
                            <input type="date" name="last_updated_date" id="last_updated_date" required 
                                value="<?php echo date('Y-m-d'); ?>" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border" />
                        </div>
                    </div>

                    <div class="mt-4 flex gap-x-2 justify-end">
                        <a href="night_emergency_payment.php" class="w-[15%] px-4 py-2 bg-gray-300 text-black font-semibold rounded-md shadow-lg hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 ease-in-out">
                            Back
                        </a>
                        <button id="saveRateBtn" type="submit" class="w-[20%] px-4 py-2 bg-blue-600 text-white font-semibold rounded-md shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 ease-in-out">
                            Save Rate
                        </button>
                    </div>
                </form>
            </div>

            <!-- History card (dynamically loaded) -->
            <div class="bg-white p-6 rounded-lg shadow-xl border border-gray-200" id="historyCard">
                <h3 class="text-xl font-bold text-gray-700 mb-4">Rate History for <span id="history-supplier-name">—</span></h3>
                <div class="overflow-x-auto" id="rate-history-container">
                    <!-- AJAX will fill this with the <table> HTML from fetch_rate_history.php -->
                    <div class="py-6 text-center text-gray-500">Select a supplier to load rate history.</div>
                </div>
            </div>
        </div>
    </main>

    <script>
    // Toast helper
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                ${type === 'success'
                    ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                    : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'}
            </svg>
            <span>${message}</span>
        `;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 3000);
    }

    // Fetch and render history for a supplier
    async function fetchHistory(supplierCode) {
        if (!supplierCode) {
            document.getElementById('rate-history-container').innerHTML = '<div class="py-6 text-center text-gray-500">Select a supplier to load rate history.</div>';
            document.getElementById('history-supplier-name').textContent = '—';
            return;
        }

        try {
            const res = await fetch(`fetch_rate_history.php?supplier=${encodeURIComponent(supplierCode)}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) throw new Error('Network response was not ok');
            const html = await res.text();
            document.getElementById('rate-history-container').innerHTML = html;
            // fetch_rate_history.php echoes supplier name into an element with id history-supplier-title (if present)
            const supplierTitleEl = document.getElementById('history-supplier-title');
            if (supplierTitleEl) {
                document.getElementById('history-supplier-name').textContent = supplierTitleEl.textContent;
            } else {
                // fallback: leave the previous name if not returned
            }
        } catch (err) {
            document.getElementById('rate-history-container').innerHTML = `<div class="py-6 text-center text-red-500">Error loading history.</div>`;
            showToast('Failed to load history: ' + err.message, 'error');
        }
    }

    // On supplier change, fetch history
    document.getElementById('supplier').addEventListener('change', function() {
        const code = this.value;
        fetchHistory(code);
    });

    // Preselect supplier if present in URL param (so ?supplier=... works)
    (function preselectFromQuery() {
        const params = new URLSearchParams(window.location.search);
        const s = params.get('supplier');
        if (s) {
            const select = document.getElementById('supplier');
            for (let opt of select.options) {
                if (opt.value === s) {
                    opt.selected = true;
                    fetchHistory(s);
                    break;
                }
            }
        }
    })();

    // AJAX form submit
    document.getElementById('dayRateForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('saveRateBtn');
        const formData = new FormData(form);

        // Simple client-side validation
        if (!formData.get('supplier_code')) {
            showToast('Please select a supplier.', 'error');
            return;
        }
        if (!formData.get('day_rate')) {
            showToast('Please enter a day rate.', 'error');
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Saving...';

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) throw new Error('Network response was not ok');
            const json = await res.json();

            if (json.status === 'success') {
                showToast(json.message, 'success');
                // update history for the supplier (immediately)
                fetchHistory(formData.get('supplier_code'));
                // keep the form values (no reset) — user requested that
            } else {
                showToast(json.message || 'Error saving rate', 'error');
            }
        } catch (err) {
            showToast('Failed to save rate: ' + err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save Rate';
        }
    });

    // Show server-side messages (e.g. suppliers fetch failure)
    <?php if ($message && $status): ?>
        showToast("<?php echo htmlspecialchars($message); ?>", "<?php echo htmlspecialchars($status); ?>");
    <?php endif; ?>
    </script>
</body>
</html>

<?php
$conn->close();
?>
