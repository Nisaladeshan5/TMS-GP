<?php
require_once '../../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../../includes/login.php");
    exit();
}

include('../../includes/db.php');

// ----------------------------------------
// HELPER: GET ROUTE
// ----------------------------------------
function get_vehicle_route($conn, $vehicle_no) {
    if (empty($vehicle_no)) return "N/A";
    $sql = "SELECT route FROM route WHERE vehicle_no = ? LIMIT 1";
    if (!$stmt = mysqli_prepare($conn, $sql)) return "DB Error"; 
    mysqli_stmt_bind_param($stmt, "s", $vehicle_no);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        return !empty($row['route']) ? htmlspecialchars($row['route']) : "None";
    }
    return "None";
}

// ----------------------------------------
// HELPER: GET FULL CARD STYLES BY PURPOSE
// ----------------------------------------
function getPurposeStyles($purpose) {
    $p = strtolower($purpose);
    if (strpos($p, 'staff') !== false) {
        return [
            'card_bg' => 'bg-indigo-50',         // Light Blue Background
            'card_border' => 'border-indigo-200', // Blue Border
            'title_text' => 'text-indigo-900',    // Dark Blue Text
            'icon_bg' => 'bg-white',
            'icon_text' => 'text-indigo-500',
            'icon_class' => 'fa-user-tie'
        ];
    } elseif (strpos($p, 'factory') !== false) {
        return [
            'card_bg' => 'bg-amber-50',          // Light Orange Background
            'card_border' => 'border-amber-200',  // Orange Border
            'title_text' => 'text-amber-900',     // Dark Orange Text
            'icon_bg' => 'bg-white',
            'icon_text' => 'text-amber-500',
            'icon_class' => 'fa-industry'
        ];
    } elseif (strpos($p, 'sub') !== false) {
        return [
            'card_bg' => 'bg-emerald-50',        // Light Green Background
            'card_border' => 'border-emerald-200',// Green Border
            'title_text' => 'text-emerald-900',   // Dark Green Text
            'icon_bg' => 'bg-white',
            'icon_text' => 'text-emerald-500',
            'icon_class' => 'fa-route'
        ];
    } else {
        return [
            'card_bg' => 'bg-white',
            'card_border' => 'border-slate-200',
            'title_text' => 'text-slate-800',
            'icon_bg' => 'bg-slate-100',
            'icon_text' => 'text-slate-500',
            'icon_class' => 'fa-car'
        ];
    }
}

include('../../includes/header.php');
include('../../includes/navbar.php');
// ----------------------------------------
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expiration Dashboard</title>
    
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
    
    <script>
        const SESSION_TIMEOUT_MS = 32400000;
        const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php";
        setTimeout(() => {
            alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
            window.location.href = LOGIN_PAGE_URL;
        }, SESSION_TIMEOUT_MS);
    </script>
</head>

<body class="bg-gray-100">

<div class="fixed top-0 left-[15%] w-[85%] bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center px-6 shadow-lg z-50 border-b border-gray-700">
    <div class="flex items-center gap-3">
        <div class="text-lg font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent">
            Notice Dashboard
        </div>
    </div>
    
    <div class="flex items-center gap-6 text-sm font-medium">
        <div class="flex gap-4 text-xs text-gray-300 mr-4 border-r border-gray-600 pr-4">
            <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-indigo-200 border border-indigo-400"></span> Staff</div>
            <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-amber-200 border border-amber-400"></span> Factory</div>
            <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-200 border border-emerald-400"></span> Sub-Route</div>
        </div>
        
        <a href="generate_report.php" target="_blank" 
           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md shadow-md transition-all duration-200 flex items-center gap-2 font-semibold text-xs tracking-wide transform hover:scale-105">
            <i class="fas fa-file-download"></i> Generate Report
        </a>
    </div>
</div>

<div class="w-[85%] ml-[15%] pt-16 min-h-screen flex flex-col relative">
    
    <div class="flex-grow p-8 bg-gray-50">
        <div class="w-7xl mx-auto space-y-10">

            <section>
                <div class="flex items-center gap-3 mb-6 border-b border-gray-200 pb-3">
                    <span class="bg-blue-100 text-blue-600 p-2 rounded-lg shadow-sm"><i class="fas fa-id-card text-xl"></i></span>
                    <h2 class="text-xl font-bold text-gray-800">Drivers with Expiring Licenses</h2>
                </div>

                <?php
                $sql_drivers = "
                    SELECT d.calling_name, d.phone_no, d.license_expiry_date,
                           DATEDIFF(d.license_expiry_date, CURDATE()) AS days_left,
                           v.vehicle_no, v.purpose
                    FROM driver d
                    LEFT JOIN vehicle v ON d.driver_NIC = v.driver_NIC
                    WHERE DATEDIFF(d.license_expiry_date, CURDATE()) <= 15 AND d.is_active = 1
                    ORDER BY d.license_expiry_date ASC
                ";
                $result_drivers = mysqli_query($conn, $sql_drivers);

                if (mysqli_num_rows($result_drivers) > 0) {
                    echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">';
                    while ($row = mysqli_fetch_assoc($result_drivers)) {
                        $days_left = (int)$row['days_left'];
                        $isExpired = $days_left < 0;

                        // Expiry Status (White Badge on Colored Card)
                        $status_bg = $isExpired ? 'bg-red-500 text-white' : 'bg-white text-red-600 border border-red-200';
                        $status_text = $isExpired ? "Expired " . abs($days_left) . " days ago" : "Expiring in " . $days_left . " Days";
                        
                        // Card Coloring Logic
                        $purpose = htmlspecialchars($row["purpose"] ?? 'Unknown');
                        $s = getPurposeStyles($purpose); // Get styles

                        $vehicle_display = !empty($row["vehicle_no"]) ? $row["vehicle_no"] : "No Vehicle";
                        $route_name = !empty($row["vehicle_no"]) ? get_vehicle_route($conn, $row["vehicle_no"]) : "-";

                        echo '
                        <div class="' . $s['card_bg'] . ' rounded-xl shadow-sm border ' . $s['card_border'] . ' hover:shadow-lg transition-all duration-300 p-5 group relative overflow-hidden">
                            
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <h3 class="text-lg font-bold ' . $s['title_text'] . '">' . htmlspecialchars($row["calling_name"]) . '</h3>
                                    <p class="text-xs font-semibold uppercase opacity-75 ' . $s['title_text'] . '">' . $purpose . '</p>
                                </div>
                                <div class="' . $s['icon_bg'] . ' px-2.5 py-1.5 rounded-full shadow-sm ' . $s['icon_text'] . '">
                                    <i class="fas ' . $s['icon_class'] . '"></i>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <span class="px-3 py-1 rounded-md text-xs font-bold shadow-sm flex w-full justify-center items-center gap-1.5 ' . $status_bg . '">
                                    <i class="fas fa-clock"></i> ' . $status_text . '
                                </span>
                            </div>

                            <div class="space-y-2 text-sm text-gray-700 bg-white/60 p-3 rounded-lg border border-white/50 backdrop-blur-sm">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-phone text-gray-400 w-4"></i> ' . htmlspecialchars($row["phone_no"]) . '
                                </div>
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-car text-gray-400 w-4"></i> <span class="font-semibold">' . htmlspecialchars($vehicle_display) . '</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-map-signs text-gray-400 w-4"></i> ' . htmlspecialchars($route_name) . '
                                </div>
                            </div>
                            
                            <div class="mt-3 pt-2 border-t border-black/5 flex items-center justify-between text-xs">
                                <span class="text-gray-500 font-medium">License Expiry:</span>
                                <span class="font-mono font-bold text-gray-800">' . htmlspecialchars($row["license_expiry_date"]) . '</span>
                            </div>
                        </div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="bg-white p-8 rounded-xl border border-gray-200 text-center text-gray-400 flex flex-col items-center">
                            <div class="bg-green-50 p-4 rounded-full mb-3"><i class="fas fa-check-circle text-green-500 text-3xl"></i></div>
                            <p class="font-medium">All driver licenses are up to date.</p>
                          </div>';
                }
                ?>
            </section>

            <section>
                <div class="flex items-center gap-3 mb-6 border-b border-gray-200 pb-3">
                    <span class="bg-indigo-100 text-indigo-600 p-2 rounded-lg shadow-sm"><i class="fas fa-file-contract text-xl"></i></span>
                    <h2 class="text-xl font-bold text-gray-800">Vehicle Documents Expiring</h2>
                </div>

                <div class="mb-8">
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <span class="w-1.5 h-4 bg-blue-500 rounded-full"></span> Revenue Licenses
                    </h3>

                    <?php
                    $sql_vehicle_license = "
                        SELECT v.vehicle_no, v.purpose, v.driver_NIC,
                               DATEDIFF(v.license_expiry_date, CURDATE()) AS days_left,
                               s.s_phone_no AS supplier_phone, d.phone_no AS driver_phone
                        FROM vehicle v
                        JOIN supplier s ON v.supplier_code = s.supplier_code
                        LEFT JOIN driver d ON v.driver_NIC = d.driver_NIC
                        WHERE DATEDIFF(v.license_expiry_date, CURDATE()) <= 15 AND v.is_active = 1
                    ";
                    $result_vehicle_license = mysqli_query($conn, $sql_vehicle_license);

                    if (mysqli_num_rows($result_vehicle_license) > 0) {
                        echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">';
                        while ($row = mysqli_fetch_assoc($result_vehicle_license)) {
                            $days_left = $row['days_left'];
                            $isExpired = $days_left < 0;
                            $status_bg = $isExpired ? 'bg-red-500 text-white' : 'bg-white text-red-600 border border-red-200';
                            $status_text = $isExpired ? "Expired " . abs($days_left) . " days ago" : $days_left . " Days Left";
                            
                            $purpose = htmlspecialchars($row["purpose"] ?? 'Unknown');
                            $s = getPurposeStyles($purpose);
                            
                            $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                            $route_name = get_vehicle_route($conn, $vehicle_no);
                            $driver_phone = !empty($row["driver_phone"]) ? htmlspecialchars($row["driver_phone"]) : "Not Assigned";

                            echo '
                            <div class="' . $s['card_bg'] . ' rounded-xl shadow-sm border ' . $s['card_border'] . ' hover:shadow-lg transition-all duration-300 p-5 group">
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <h3 class="text-lg font-bold ' . $s['title_text'] . ' font-mono tracking-tight">' . $vehicle_no . '</h3>
                                        <p class="text-xs font-semibold uppercase opacity-75 ' . $s['title_text'] . '">' . $purpose . '</p>
                                    </div>
                                    <div class="' . $s['icon_bg'] . ' px-2.5 py-1.5 rounded-full shadow-sm ' . $s['icon_text'] . '">
                                        <i class="fas ' . $s['icon_class'] . '"></i>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <span class="px-2 py-1 rounded text-xs font-bold ' . $status_bg . ' block text-center shadow-sm">
                                        ' . $status_text . '
                                    </span>
                                </div>

                                <div class="space-y-1.5 text-xs text-gray-700 bg-white/60 p-3 rounded-lg border border-white/50 backdrop-blur-sm">
                                    <p class="flex justify-between"><span class="font-semibold text-gray-500">Route:</span> <span>' . $route_name . '</span></p>
                                    <p class="flex justify-between"><span class="font-semibold text-gray-500">Supplier:</span> <span>' . htmlspecialchars($row["supplier_phone"]) . '</span></p>
                                    <p class="flex justify-between"><span class="font-semibold text-gray-500">Driver:</span> <span>' . $driver_phone . '</span></p>
                                </div>
                            </div>';
                        }
                        echo '</div>';
                    } else {
                        echo '<p class="text-gray-400 italic text-sm ml-2 mb-4">No vehicle licenses expiring soon.</p>';
                    }
                    ?>
                </div>

                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <span class="w-1.5 h-4 bg-purple-500 rounded-full"></span> Insurance Policies
                    </h3>

                    <?php
                    $sql_vehicle_insurance = "
                        SELECT v.vehicle_no, v.purpose, v.driver_NIC,
                               DATEDIFF(v.insurance_expiry_date, CURDATE()) AS days_left,
                               s.s_phone_no AS supplier_phone, d.phone_no AS driver_phone
                        FROM vehicle v
                        JOIN supplier s ON v.supplier_code = s.supplier_code
                        LEFT JOIN driver d ON v.driver_NIC = d.driver_NIC
                        WHERE DATEDIFF(v.insurance_expiry_date, CURDATE()) <= 15 AND v.is_active = 1
                    ";
                    $result_vehicle_insurance = mysqli_query($conn, $sql_vehicle_insurance);

                    if (mysqli_num_rows($result_vehicle_insurance) > 0) {
                        echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">';
                        while ($row = mysqli_fetch_assoc($result_vehicle_insurance)) {
                            $days_left = $row['days_left'];
                            $isExpired = $days_left < 0;
                            $status_bg = $isExpired ? 'bg-red-500 text-white' : 'bg-white text-red-600 border border-red-200';
                            $status_text = $isExpired ? "Expired " . abs($days_left) . " days ago" : $days_left . " Days Left";
                            
                            $purpose = htmlspecialchars($row["purpose"] ?? 'Unknown');
                            $s = getPurposeStyles($purpose);
                            $vehicle_no = htmlspecialchars($row["vehicle_no"]);
                            $driver_phone = !empty($row["driver_phone"]) ? htmlspecialchars($row["driver_phone"]) : "Not Assigned";

                            echo '
                            <div class="' . $s['card_bg'] . ' rounded-xl shadow-sm border ' . $s['card_border'] . ' hover:shadow-lg transition-all duration-300 p-5 group">
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <h3 class="text-lg font-bold ' . $s['title_text'] . ' font-mono tracking-tight">' . $vehicle_no . '</h3>
                                        <p class="text-xs font-semibold uppercase opacity-75 ' . $s['title_text'] . '">' . $purpose . '</p>
                                    </div>
                                    <div class="' . $s['icon_bg'] . ' px-2.5 py-1.5 rounded-full shadow-sm ' . $s['icon_text'] . '">
                                        <i class="fas ' . $s['icon_class'] . '"></i>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <span class="px-2 py-1 rounded text-xs font-bold ' . $status_bg . ' block text-center shadow-sm">
                                        ' . $status_text . '
                                    </span>
                                </div>

                                <div class="space-y-1.5 text-xs text-gray-700 bg-white/60 p-3 rounded-lg border border-white/50 backdrop-blur-sm">
                                    <p class="flex justify-between"><span class="font-semibold text-gray-500">Supplier:</span> <span>' . htmlspecialchars($row["supplier_phone"]) . '</span></p>
                                    <p class="flex justify-between"><span class="font-semibold text-gray-500">Driver:</span> <span>' . $driver_phone . '</span></p>
                                </div>
                            </div>';
                        }
                        echo '</div>';
                    } else {
                        echo '<p class="text-gray-400 italic text-sm ml-2">No insurance policies expiring soon.</p>';
                    }
                    ?>
                </div>
            </section>

            <section>
                <div class="flex items-center gap-3 mb-6 border-b border-gray-200 pb-3">
                    <span class="bg-cyan-100 text-cyan-600 p-2 rounded-lg shadow-sm"><i class="fas fa-tools text-xl"></i></span>
                    <h2 class="text-xl font-bold text-gray-800">Pending Vehicle Inspections</h2>
                </div>

                <?php
                $sql_inspection = "
                    SELECT s.supplier AS supplier_name,
                           s.s_phone_no AS supplier_phone,
                           c.supplier_code,
                           DATEDIFF(c.date, CURDATE()) AS days_left,
                           v.purpose
                    FROM checkUp c
                    JOIN supplier s ON c.supplier_code = s.supplier_code
                    LEFT JOIN vehicle v ON s.supplier_code = v.supplier_code
                    WHERE DATEDIFF(c.date, CURDATE()) <= 15 AND v.is_active = 1
                    ORDER BY s.supplier, days_left
                ";
                $result_inspection = mysqli_query($conn, $sql_inspection);

                if (mysqli_num_rows($result_inspection) > 0) {
                    $grouped_data = [];
                    while ($row = mysqli_fetch_assoc($result_inspection)) {
                        $code = $row['supplier_code'];
                        if (!isset($grouped_data[$code])) {
                            $grouped_data[$code] = [
                                'name'      => $row['supplier_name'],
                                'phone'     => $row['supplier_phone'],
                                'days_left' => $row['days_left'],
                                'purpose'   => $row['purpose'] ?? ''
                            ];
                        } else {
                            if ($row['days_left'] < $grouped_data[$code]['days_left']) {
                                $grouped_data[$code]['days_left'] = $row['days_left'];
                            }
                            if (!str_contains($grouped_data[$code]['purpose'], $row['purpose'])) {
                                $grouped_data[$code]['purpose'] .= " / " . ($row['purpose'] ?? '');
                            }
                        }
                    }

                    echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">';
                    foreach ($grouped_data as $data) {
                        $days_left = $data['days_left'];
                        $isExpired = $days_left < 0;
                        $status_bg = $isExpired ? 'bg-red-500 text-white' : 'bg-white text-red-600 border border-red-200';
                        $status_text = $isExpired ? "Due " . abs($days_left) . " days ago" : "Due in " . $days_left . " days";
                        
                        // Default inspection card style (Cyan)
                        $card_bg = 'bg-cyan-50';
                        $card_border = 'border-cyan-200';
                        $title_text = 'text-cyan-900';

                        echo '
                        <div class="' . $card_bg . ' rounded-xl shadow-sm border ' . $card_border . ' hover:shadow-lg transition-all duration-300 p-5 group">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="bg-white text-cyan-600 w-10 h-10 rounded-full flex items-center justify-center shadow-sm">
                                    <i class="fas fa-wrench"></i>
                                </div>
                                <div>
                                    <h3 class="text-md font-bold ' . $title_text . ' leading-tight">' . htmlspecialchars($data["name"]) . '</h3>
                                    <p class="text-xs text-gray-500 mt-0.5 truncate max-w-[150px]" title="' . htmlspecialchars($data["purpose"]) . '">' . htmlspecialchars($data["purpose"]) . '</p>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <span class="px-3 py-1 rounded-full text-xs font-bold ' . $status_bg . ' block text-center shadow-sm">
                                    ' . $status_text . '
                                </span>
                            </div>

                            <div class="space-y-2 text-sm text-gray-700 bg-white/60 p-3 rounded-lg border border-white/50 backdrop-blur-sm">
                                <p><i class="fas fa-phone text-gray-400 mr-2"></i> ' . htmlspecialchars($data["phone"]) . '</p>
                            </div>
                        </div>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="bg-white p-8 rounded-xl border border-gray-200 text-center text-gray-400 flex flex-col items-center">
                            <div class="bg-green-50 p-4 rounded-full mb-3"><i class="fas fa-check-circle text-green-500 text-3xl"></i></div>
                            <p class="font-medium">No pending inspections.</p>
                          </div>';
                }
                ?>
            </section>

        </div>
    </div>
</div>

<?php 
if (isset($conn)) { mysqli_close($conn); } 
?>
</body>
</html>