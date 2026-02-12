<?php
// ==========================================
// BACKEND LOGIC
// ==========================================

// 1. Database Connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "carder";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Hide Warnings
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$message = ""; 

// --- LOGIC 1: SAVE NEW DATA ---
if (isset($_POST['save_db'])) {
    $selected_date = $_POST['date_val'];
    $records = $_POST['records']; 

    // Check duplicates
    $checkStmt = $conn->prepare("SELECT count(*) FROM empd WHERE date = ?");
    $checkStmt->bind_param("s", $selected_date);
    $checkStmt->execute();
    $checkStmt->bind_result($countExists);
    $checkStmt->fetch();
    $checkStmt->close();

    if ($countExists > 0) {
        $message = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-sm' role='alert'><p class='font-bold'>Error</p><p>Records already exist for $selected_date.</p></div>";
    } elseif (!empty($records) && !empty($selected_date)) {
        
        $stmt = $conn->prepare("INSERT INTO empd (date, category, actual_direct, attendance_direct, actual_indirect, attendance_indirect) VALUES (?, ?, ?, ?, ?, ?)");
        
        $inserted_count = 0;
        foreach ($records as $category => $data) {
            $cat = $category;
            $act_d = floatval($data['act_d']);
            $att_d = floatval($data['att_d']);
            $act_i = floatval($data['act_i']);
            $att_i = floatval($data['att_i']);

            $stmt->bind_param("ssdddd", $selected_date, $cat, $act_d, $att_d, $act_i, $att_i);
            if ($stmt->execute()) { $inserted_count++; }
        }
        $stmt->close();
        $message = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm' role='alert'><p class='font-bold'>Success</p><p>Data successfully saved to database.</p></div>";
    }
}

// --- LOGIC 2: UPDATE EXISTING DATA ---
if (isset($_POST['update_existing'])) {
    $updates = $_POST['updates']; 
    $updated_count = 0;

    if (!empty($updates)) {
        $stmt = $conn->prepare("UPDATE empd SET actual_direct=?, attendance_direct=?, actual_indirect=?, attendance_indirect=? WHERE id=?");
        
        foreach ($updates as $id => $data) {
            $u_act_d = floatval($data['act_d']);
            $u_att_d = floatval($data['att_d']);
            $u_act_i = floatval($data['act_i']);
            $u_att_i = floatval($data['att_i']);
            $row_id = intval($id);

            $stmt->bind_param("ddddi", $u_act_d, $u_att_d, $u_act_i, $u_att_i, $row_id);
            if ($stmt->execute()) { $updated_count++; }
        }
        $stmt->close();
        $message = "<div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6 rounded shadow-sm' role='alert'><p class='font-bold'>Updated</p><p>Successfully updated $updated_count records.</p></div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TMS - Cardre Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { font-family: 'Inter', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">

    <div class="flex min-h-screen">

        <aside class="w-64 bg-slate-900 text-white hidden md:block flex-shrink-0">
            <div class="h-16 flex items-center justify-center border-b border-slate-700">
                <h1 class="text-xl font-bold tracking-wider"><i class="fa-solid fa-layer-group text-emerald-400 mr-2"></i> Carder <span class="text-emerald-400">System</span></h1>
            </div>
            <nav class="mt-6 px-4 space-y-2">
                <a href="#" class="flex items-center px-4 py-3 bg-slate-800 text-white rounded-lg transition-colors">
                    <i class="fa-solid fa-chart-pie w-6"></i>
                    <span class="font-medium">Dashboard</span>
                </a>
            </nav>
        </aside>

        <main class="flex-1 flex flex-col">
            
            <header class="h-16 bg-white shadow-sm flex items-center justify-between px-6 lg:px-10">
                <div class="flex items-center">
                    <button class="md:hidden text-slate-600 focus:outline-none mr-4">
                        <i class="fa-solid fa-bars text-xl"></i>
                    </button>
                    <h2 class="text-xl font-semibold text-slate-700">Daily Cardre Management</h2>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2">
                        <div class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 font-bold">A</div>
                        <span class="text-sm font-medium text-slate-600">Admin User</span>
                    </div>
                </div>
            </header>

            <div class="p-6 lg:p-10 space-y-8 overflow-y-auto">

                <?php if($message) echo $message; ?>

                <div class="bg-white rounded-xl shadow-md border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                        <h3 class="text-lg font-bold text-slate-700"><i class="fa-solid fa-cloud-arrow-up mr-2 text-blue-500"></i> Import Data</h3>
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Step 1</span>
                    </div>
                    
                    <div class="p-6">
                        <form action="" method="post" enctype="multipart/form-data" class="flex flex-col md:flex-row md:items-end gap-6">
                            
                            <div class="w-full md:w-1/3">
                                <label class="block text-sm font-medium text-slate-600 mb-2">Select Report Date</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fa-regular fa-calendar text-slate-400"></i>
                                    </div>
                                    <input type="date" name="report_date" required value="<?php echo date('Y-m-d'); ?>" 
                                        class="w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                                </div>
                            </div>

                            <div class="w-full md:w-1/3">
                                <label class="block text-sm font-medium text-slate-600 mb-2">Upload Excel File</label>
                                <input type="file" name="excel_file" required accept=".xls,.xlsx" 
                                    class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all">
                            </div>

                            <div class="w-full md:w-auto">
                                <button type="submit" name="preview" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-lg shadow-sm transition-all flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-eye"></i> Preview & Verify
                                </button>
                            </div>
                        </form>

                        <?php
                        if (isset($_POST['preview']) && isset($_FILES['excel_file'])) {
                            $inputFileName = $_FILES['excel_file']['tmp_name'];
                            $reportDate = $_POST['report_date'];

                            // Check existence
                            $checkStmt = $conn->prepare("SELECT count(*) FROM empd WHERE date = ?");
                            $checkStmt->bind_param("s", $reportDate);
                            $checkStmt->execute();
                            $checkStmt->bind_result($dateCount);
                            $checkStmt->fetch();
                            $checkStmt->close();

                            $dateExists = ($dateCount > 0);

                            try {
                                $reader = IOFactory::createReaderForFile($inputFileName);
                                $reader->setReadDataOnly(true);
                                $spreadsheet = $reader->load($inputFileName);
                                $rows = $spreadsheet->getActiveSheet()->toArray();
                                
                                $mergedData = [];
                                $isBottomPart = false; 

                                foreach ($rows as $row) {
                                    if (!isset($row[0])) continue;
                                    $catName = trim((string)$row[0]);
                                    
                                    // -----------------------------------------------------
                                    // ⚡ MODIFIED HERE: Added 'Total', '1', '1.0' to ignore list
                                    // -----------------------------------------------------
                                    $ignoreWords = [
                                        'Payroll', 
                                        'Indirect', 
                                        'Total Employees', 
                                        'Employee Category', 
                                        'Total into total Cardre', 
                                        'Direct Employees',
                                        'Total',  // <-- NEW: Removes Total Row
                                        '1',      // <-- NEW: Removes '1' Row
                                        '1.0'     // <-- NEW: Just in case
                                    ];
                                    
                                    if (in_array($catName, $ignoreWords) || empty($catName)) continue;

                                    if ($catName == 'Attendance') { $isBottomPart = true; continue; }
                                    if (!isset($mergedData[$catName])) { $mergedData[$catName] = ['act_d'=>0, 'act_i'=>0, 'att_d'=>0, 'att_i'=>0]; }

                                    $dVal = isset($row[1]) ? (float)$row[1] : 0;
                                    $iVal = isset($row[3]) ? (float)$row[3] : 0;

                                    if ($isBottomPart) {
                                        $mergedData[$catName]['att_d'] = $dVal;
                                        $mergedData[$catName]['att_i'] = $iVal;
                                    } else {
                                        $mergedData[$catName]['act_d'] = $dVal;
                                        $mergedData[$catName]['act_i'] = $iVal;
                                    }
                                }

                                if (!empty($mergedData)) {
                                    echo "<div class='mt-6 p-4 bg-slate-50 border border-slate-200 rounded-lg'>";
                                    if ($dateExists) {
                                        echo "<div class='flex items-center text-amber-600 bg-amber-50 p-3 rounded mb-4 border border-amber-200'>
                                                <i class='fa-solid fa-triangle-exclamation mr-2'></i> 
                                                <span>Data for <b>$reportDate</b> already exists. Please use the Edit section below.</span>
                                              </div>";
                                    } else {
                                        echo "<h4 class='font-bold text-slate-700 mb-2'>Ready to Import</h4>";
                                        echo "<p class='text-sm text-slate-500 mb-4'>We found <b>".count($mergedData)."</b> categories in the uploaded file.</p>";
                                        
                                        echo "<form action='' method='POST'>";
                                        echo "<input type='hidden' name='date_val' value='$reportDate'>";
                                        
                                        foreach ($mergedData as $cat => $vals) {
                                             echo "<input type='hidden' name='records[$cat][act_d]' value='{$vals['act_d']}'>";
                                             echo "<input type='hidden' name='records[$cat][att_d]' value='{$vals['att_d']}'>";
                                             echo "<input type='hidden' name='records[$cat][act_i]' value='{$vals['act_i']}'>";
                                             echo "<input type='hidden' name='records[$cat][att_i]' value='{$vals['att_i']}'>";
                                        }
                                        
                                        echo "<button type='submit' name='save_db' class='bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-2 px-6 rounded shadow flex items-center gap-2'>
                                                <i class='fa-solid fa-check'></i> Confirm & Save to Database
                                              </button>";
                                        echo "</form>";
                                    }
                                    echo "</div>";
                                }
                            } catch (Exception $e) { echo "<p class='text-red-500 mt-2'>Error: " . $e->getMessage() . "</p>"; }
                        }
                        ?>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                        <h3 class="text-lg font-bold text-slate-700"><i class="fa-solid fa-pen-to-square mr-2 text-pink-500"></i> View & Edit Records</h3>
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Step 2</span>
                    </div>

                    <div class="p-6">
                        <form action="" method="get" class="flex flex-col md:flex-row md:items-end gap-6 mb-6">
                             <div class="w-full md:w-1/3">
                                <label class="block text-sm font-medium text-slate-600 mb-2">Select Date to Edit</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fa-solid fa-filter text-slate-400"></i>
                                    </div>
                                    <input type="date" name="view_date" required value="<?php echo isset($_GET['view_date']) ? $_GET['view_date'] : date('Y-m-d'); ?>" 
                                        class="w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500 outline-none transition-all">
                                </div>
                            </div>
                            <div class="w-full md:w-auto">
                                <button type="submit" class="w-full bg-slate-700 hover:bg-slate-800 text-white font-medium py-2 px-6 rounded-lg shadow-sm transition-all">
                                    Load Data
                                </button>
                            </div>
                        </form>

                        <div class="border-t border-slate-100 pt-6">
                            <?php
                            $view_date = isset($_GET['view_date']) ? $_GET['view_date'] : date('Y-m-d');
                            
                            $sql = "SELECT * FROM empd WHERE date = '$view_date' ORDER BY category ASC";
                            $result = $conn->query($sql);

                            if ($result->num_rows > 0) {
                                echo "<div class='flex justify-between items-center mb-4'>";
                                echo "<h4 class='text-slate-700 font-semibold'>Editing records for: <span class='text-pink-600 bg-pink-50 px-2 py-1 rounded'>$view_date</span></h4>";
                                echo "<span class='text-xs text-slate-400'>Make changes and click 'Update Changes' at bottom</span>";
                                echo "</div>";
                                
                                echo "<form action='' method='POST'>";
                                
                                echo "<div class='overflow-x-auto custom-scrollbar rounded-lg border border-slate-200'>";
                                echo "<table class='min-w-full divide-y divide-slate-200'>";
                                echo "<thead class='bg-slate-50'>
                                        <tr>
                                            <th class='px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider'>Category</th>
                                            <th class='px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider'>Actual Direct</th>
                                            <th class='px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider'>Attend Direct</th>
                                            <th class='px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider'>Actual Indirect</th>
                                            <th class='px-6 py-3 text-center text-xs font-medium text-slate-500 uppercase tracking-wider'>Attend Indirect</th>
                                        </tr>
                                      </thead>";
                                echo "<tbody class='bg-white divide-y divide-slate-200'>";
                                
                                while($row = $result->fetch_assoc()) {
                                    $id = $row['id'];
                                    echo "<tr class='hover:bg-slate-50 transition-colors'>";
                                    echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900'>" . htmlspecialchars($row["category"]) . "</td>";
                                    
                                    $inputClass = "w-24 text-center border-slate-300 rounded-md shadow-sm focus:ring-pink-500 focus:border-pink-500 sm:text-sm py-1 border";
                                    
                                    echo "<td class='px-6 py-2 text-center'><input type='number' step='any' class='$inputClass' name='updates[$id][act_d]' value='" . $row["actual_direct"] . "'></td>";
                                    echo "<td class='px-6 py-2 text-center'><input type='number' step='any' class='$inputClass' name='updates[$id][att_d]' value='" . $row["attendance_direct"] . "'></td>";
                                    echo "<td class='px-6 py-2 text-center'><input type='number' step='any' class='$inputClass' name='updates[$id][act_i]' value='" . $row["actual_indirect"] . "'></td>";
                                    echo "<td class='px-6 py-2 text-center'><input type='number' step='any' class='$inputClass' name='updates[$id][att_i]' value='" . $row["attendance_indirect"] . "'></td>";
                                    
                                    echo "</tr>";
                                }

                                echo "</tbody></table></div>";
                                
                                echo "<div class='mt-6 flex justify-end'>";
                                echo "<button type='submit' name='update_existing' class='bg-pink-600 hover:bg-pink-700 text-white font-bold py-3 px-8 rounded-lg shadow-lg transform hover:-translate-y-0.5 transition-all flex items-center gap-2'>
                                        <i class='fa-solid fa-floppy-disk'></i> Save Updates
                                      </button>";
                                echo "</div>";
                                echo "</form>";

                            } else {
                                if(isset($_GET['view_date'])) {
                                    echo "<div class='text-center py-10 text-slate-400'>
                                            <i class='fa-solid fa-folder-open text-4xl mb-3 opacity-50'></i>
                                            <p>No records found for selected date.</p>
                                          </div>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

            </div>
            
            <footer class="bg-white border-t border-slate-200 py-4 px-10 text-center text-sm text-slate-400">
                &copy; <?php echo date('Y'); ?> GP Garments (Pvt) Ltd.
            </footer>

        </main>
    </div>

</body>
</html>