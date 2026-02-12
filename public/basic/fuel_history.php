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

// --- HELPER FUNCTION: GET DATA ---
function getFuelData($conn, $filters) {
    $rate_id = $filters['rate_id'] ?? 'all';
    $from_date = $filters['from_date'] ?? '';
    $to_date = $filters['to_date'] ?? '';

    $sql = "SELECT rate_id, type, rate, date FROM fuel_rate WHERE 1=1";
    $params = [];
    $types = '';

    if ($rate_id != 'all' && !empty($rate_id)) {
        $sql .= " AND rate_id = ?";
        $params[] = $rate_id;
        $types .= 'i';
    }

    if (!empty($from_date)) {
        $sql .= " AND date >= ?";
        $params[] = $from_date;
        $types .= 's';
    }

    if (!empty($to_date)) {
        $sql .= " AND date <= ?";
        $params[] = $to_date;
        $types .= 's';
    }

    // Order by Date DESC (Newest first), then by Type
    $sql .= " ORDER BY date DESC, type ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

// --- HELPER FUNCTION: RENDER ROWS ---
function renderTableRows($result) {
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            
            // Fuel Icon based on type (Just for visual)
            $icon_color = 'text-gray-500 bg-gray-100';
            if (stripos($row['type'], 'petrol') !== false) $icon_color = 'text-yellow-600 bg-yellow-100';
            elseif (stripos($row['type'], 'diesel') !== false) $icon_color = 'text-blue-600 bg-blue-100';
            
            echo "<tr class='hover:bg-blue-50 transition duration-150 border-b border-gray-200 group'>
                    <td class='px-6 py-4 text-left font-mono text-gray-600 text-sm'>
                        " . date('Y-m-d', strtotime($row['date'])) . "
                    </td>
                    <td class='px-6 py-4'>
                        <div class='flex items-center gap-3'>
                            <div class='w-8 h-8 rounded-full flex items-center justify-center {$icon_color}'>
                                <i class='fas fa-gas-pump text-xs'></i>
                            </div>
                            <span class='font-medium text-gray-800 text-sm'>" . htmlspecialchars($row['type']) . "</span>
                        </div>
                    </td>
                    <td class='px-6 py-4 text-right'>
                        <span class='font-bold text-emerald-600 font-mono text-sm bg-emerald-50 px-2 py-1 rounded border border-emerald-100'>
                            Rs. " . number_format($row['rate'], 2) . "
                        </span>
                    </td>
                    <td class='px-6 py-4 text-center'>
                         <span class='inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-green-100 text-green-700 border border-green-200'>
                            Recorded
                        </span>
                    </td>
                  </tr>";
        }
    } else {
        echo '<tr>
                <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                    <div class="flex flex-col items-center justify-center">
                        <i class="fas fa-search-minus text-3xl mb-2 text-gray-300"></i>
                        <p class="text-sm font-medium">No history records found.</p>
                        <p class="text-xs text-gray-400">Try adjusting the filters.</p>
                    </div>
                </td>
              </tr>';
    }
}

// --- 1. AJAX FILTER HANDLER ---
if (isset($_GET['ajax_filter'])) {
    $filters = [
        'rate_id' => $_GET['rate_id'] ?? 'all',
        'from_date' => $_GET['from_date'] ?? '',
        'to_date' => $_GET['to_date'] ?? ''
    ];
    $result = getFuelData($conn, $filters);
    renderTableRows($result);
    exit; 
}

// --- 2. PAGE LOAD DATA ---
// Fetch Fuel Types for Dropdown
$types_sql = "SELECT DISTINCT rate_id, type FROM fuel_rate ORDER BY type ASC";
$types_result = $conn->query($types_sql);
$fuel_types = [];
if ($types_result->num_rows > 0) {
    while ($row = $types_result->fetch_assoc()) {
        $fuel_types[] = $row;
    }
}

// Initial Data Load
$filters = [
    'rate_id' => 'all',
    'from_date' => '',
    'to_date' => ''
];
$initial_result = getFuelData($conn, $filters);

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Rate History</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        #filterDrawer { transition: max-height 0.4s ease-in-out, opacity 0.3s ease-in-out; overflow: hidden; }
        .drawer-closed { max-height: 0; opacity: 0; padding-top: 0 !important; padding-bottom: 0 !important; border-bottom-width: 0 !important; pointer-events: none; }
        .drawer-open { max-height: 400px; opacity: 1; pointer-events: auto; }
        .table-loading { opacity: 0.5; pointer-events: none; }
    </style>
    
    <script>
        const SESSION_TIMEOUT_MS = 32400000; 
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; 
        setTimeout(function() {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL; 
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100 overflow-hidden text-sm">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-14 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <!-- <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Fuel Rate History
        </div> -->
        <div class="flex items-center space-x-2 w-fit">
            <a href="" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Fuel
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                History
            </span>
        </div>
    </div>
    
    <div class="flex items-center gap-3 text-sm font-medium">
        <button onclick="toggleFilters()" id="filterToggleBtn" class="flex items-center gap-2 bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded-md shadow-md transition border border-gray-500 focus:outline-none focus:ring-1 focus:ring-yellow-400">
            <i class="fas fa-filter text-yellow-400"></i> 
            <span id="filterBtnText">Show Filters</span>
            <i id="filterArrow" class="fas fa-chevron-down text-[10px] transition-transform duration-300"></i>
        </button>

        <span class="text-gray-500">|</span>

        <a href="fuel.php" class="text-gray-300 hover:text-white transition flex items-center gap-1">
            <i class="fas fa-gas-pump"></i> Fuel Rates
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-14 h-screen flex flex-col relative">
    
    <div id="filterDrawer" class="bg-white shadow-lg border-b border-gray-300 drawer-closed absolute top-14 left-0 w-full z-40 px-6 py-4">
        <div class="flex justify-between items-center mb-3">
            <h3 class="text-xs font-bold text-gray-700 uppercase flex items-center gap-2">
                <i class="fas fa-search text-blue-500"></i> Filter History
            </h3>
            <div class="flex gap-2">
                <button type="button" id="clearFiltersBtn" class="text-[10px] font-semibold text-gray-500 hover:text-red-600 px-3 py-1 bg-gray-100 rounded hover:bg-gray-200 transition">
                    Clear All
                </button>
                <button type="button" id="downloadPdfBtn" class="text-[10px] font-semibold text-white bg-red-600 hover:bg-red-700 px-3 py-1 rounded shadow transition flex items-center gap-1">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </button>
            </div>
        </div>
        
        <form id="filterForm" class="grid grid-cols-1 md:grid-cols-3 gap-4 pb-2" onsubmit="return false;">
            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">Fuel Type</label>
                <select id="filter_rate_id" onchange="applyFilters()" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none cursor-pointer bg-white">
                    <option value="all">All Types</option>
                    <?php foreach ($fuel_types as $type): ?>
                        <option value="<?php echo $type['rate_id']; ?>">
                            <?php echo htmlspecialchars($type['type']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">From Date</label>
                <input type="date" id="filter_from_date" onchange="applyFilters()" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none">
            </div>

            <div>
                <label class="block text-[10px] font-semibold text-gray-500 mb-1">To Date</label>
                <input type="date" id="filter_to_date" onchange="applyFilters()" class="w-full border border-gray-300 rounded p-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none">
            </div>
        </form>
    </div>
    
    <div class="flex-grow overflow-hidden bg-gray-100 p-2 mt-1 transition-all duration-300">
        <div class="bg-white shadow-lg rounded-lg border border-gray-200 h-full flex flex-col">
            <div id="tableScrollContainer" class="overflow-auto flex-grow rounded-lg">
                <table class="w-full table-auto border-collapse relative">
                    <thead class="bg-gradient-to-r from-blue-600 to-blue-700 text-white text-xs sticky top-0 z-10 shadow-md">
                        <tr>
                            <th class="px-6 py-3 text-left font-semibold tracking-wide w-1/4">Effective Date</th>
                            <th class="px-6 py-3 text-left font-semibold tracking-wide w-1/4">Fuel Type</th>
                            <th class="px-6 py-3 text-right font-semibold tracking-wide w-1/4">Rate (Rs.)</th>
                            <th class="px-6 py-3 text-center font-semibold tracking-wide w-1/4">Status</th>
                        </tr>
                    </thead>
                    <tbody id="fuelTableBody" class="text-gray-700 divide-y divide-gray-200 text-sm bg-white">
                        <?php renderTableRows($initial_result); ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="mt-2 text-[10px] text-gray-400 text-right pr-2">
            Showing records based on current filters
        </div>
    </div>
</div>

<script>
    // --- UI Logic (Drawer) ---
    const filterDrawer = document.getElementById('filterDrawer');
    const filterBtnText = document.getElementById('filterBtnText');
    const filterArrow = document.getElementById('filterArrow');
    const tableScrollContainer = document.getElementById('tableScrollContainer');
    const isFilterOpen = localStorage.getItem('fuelFilterOpen') === 'true';

    // Set initial state
    if (isFilterOpen) {
        openFiltersUI();
    }

    function toggleFilters() {
        if (filterDrawer.classList.contains('drawer-closed')) {
            openFiltersUI();
            localStorage.setItem('fuelFilterOpen', 'true');
        } else {
            closeFiltersUI();
            localStorage.setItem('fuelFilterOpen', 'false');
        }
    }

    function openFiltersUI() {
        filterDrawer.classList.remove('drawer-closed');
        filterDrawer.classList.add('drawer-open');
        filterBtnText.innerText = "Hide Filters";
        filterArrow.style.transform = "rotate(180deg)";
    }

    function closeFiltersUI() {
        filterDrawer.classList.remove('drawer-open');
        filterDrawer.classList.add('drawer-closed');
        filterBtnText.innerText = "Show Filters";
        filterArrow.style.transform = "rotate(0deg)";
    }

    // Close drawer when scrolling table
    if (tableScrollContainer) {
        tableScrollContainer.addEventListener('scroll', function() {
            if (!filterDrawer.classList.contains('drawer-closed')) {
                closeFiltersUI();
                localStorage.setItem('fuelFilterOpen', 'false');
            }
        });
        tableScrollContainer.addEventListener('click', function() {
            if (!filterDrawer.classList.contains('drawer-closed')) {
                closeFiltersUI();
                localStorage.setItem('fuelFilterOpen', 'false');
            }
        });
    }

    // --- AJAX Filter Logic ---
    function applyFilters() {
        const rateId = document.getElementById('filter_rate_id').value;
        const fromDate = document.getElementById('filter_from_date').value;
        const toDate = document.getElementById('filter_to_date').value;

        const params = new URLSearchParams();
        if (rateId) params.append('rate_id', rateId);
        if (fromDate) params.append('from_date', fromDate);
        if (toDate) params.append('to_date', toDate);

        // Update URL
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({path: newUrl}, '', newUrl);

        // Add AJAX flag
        params.append('ajax_filter', '1');

        const tbody = document.getElementById('fuelTableBody');
        tbody.classList.add('table-loading');

        fetch(window.location.pathname + '?' + params.toString())
            .then(response => response.text())
            .then(html => {
                tbody.innerHTML = html;
                tbody.classList.remove('table-loading');
            })
            .catch(error => {
                console.error('Error fetching data:', error);
                tbody.classList.remove('table-loading');
            });
    }

    // --- Clear Filters ---
    document.getElementById('clearFiltersBtn').addEventListener('click', function() {
        document.getElementById('filter_rate_id').value = 'all';
        document.getElementById('filter_from_date').value = '';
        document.getElementById('filter_to_date').value = '';
        applyFilters();
    });

    // --- PDF Download (Opens in new tab with current filters) ---
    document.getElementById('downloadPdfBtn').addEventListener('click', function() {
        const rateId = document.getElementById('filter_rate_id').value;
        const fromDate = document.getElementById('filter_from_date').value;
        const toDate = document.getElementById('filter_to_date').value;
        
        const params = new URLSearchParams();
        params.append('rate_id', rateId);
        params.append('from_date', fromDate);
        params.append('to_date', toDate);
        
        window.open('generate_fuel_pdf.php?' + params.toString(), '_blank');
    });

</script>

</body>
</html>
<?php $conn->close(); ?>