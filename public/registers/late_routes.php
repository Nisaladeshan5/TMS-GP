<?php
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

$filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// SQL Query
$sql = "SELECT f.route AS route_code, r.route AS route_name, f.in_time, f.date
        FROM factory_transport_vehicle_register f
        LEFT JOIN route r ON f.route = r.route_code
        WHERE DATE(f.date) = ? 
        AND TIME(f.in_time) >= '16:40:00'
        ORDER BY f.in_time ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $filterDate);
$stmt->execute();
$result = $stmt->get_result();

$evening_data = [];
while ($row = $result->fetch_assoc()) {
    $evening_data[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>

    <script>
        async function exportEveningExcel() {
            const workbook = new ExcelJS.Workbook();
            const worksheet = workbook.addWorksheet('Late Routes');

            // 1. Add Title Row
            const titleRow = worksheet.addRow(["LATE ROUTE REPORT - <?php echo $filterDate; ?>"]);
            worksheet.mergeCells('A1:D1');
            titleRow.font = { name: 'Arial Black', size: 16, color: { argb: 'FF2D3748' } };
            titleRow.alignment = { vertical: 'middle', horizontal: 'center' };
            titleRow.height = 30;

            // Add an empty row for spacing
            worksheet.addRow([]);

            // 2. Add Header Row
            const headerRow = worksheet.addRow(["No", "In Time", "Route Code", "Route Name"]);
            
            // Style Header Row
            headerRow.eachCell((cell) => {
                cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF2D3748' } };
                cell.font = { color: { argb: 'FFFFFFFF' }, bold: true, size: 12 };
                cell.border = {
                    top: {style:'thin'}, left: {style:'thin'}, bottom: {style:'thin'}, right: {style:'thin'}
                };
                cell.alignment = { horizontal: 'center' };
            });

            // 3. Add Data Rows
            <?php foreach ($evening_data as $index => $row): ?>
            const rowData = [
                <?php echo $index + 1; ?>,
                "<?php echo date('h:i A', strtotime($row['in_time'])); ?>",
                "<?php echo $row['route_code']; ?>",
                "<?php echo $row['route_name'] ?: '---'; ?>"
            ];
            const dataRow = worksheet.addRow(rowData);
            
            // Style Data Rows (Borders and Alignment)
            dataRow.eachCell((cell, colNumber) => {
                cell.border = {
                    top: {style:'thin'}, left: {style:'thin'}, bottom: {style:'thin'}, right: {style:'thin'}
                };
                cell.alignment = { horizontal: (colNumber === 4 ? 'left' : 'center') };
                // Highlight In-Time with Red if it's data
                if(colNumber === 2) { cell.font = { color: { argb: 'FFC53030' }, bold: true }; }
            });
            <?php endforeach; ?>

            // 4. Set Column Widths
            worksheet.getColumn(1).width = 8;
            worksheet.getColumn(2).width = 15;
            worksheet.getColumn(3).width = 20;
            worksheet.getColumn(4).width = 45;

            // 5. Generate and Download
            const buffer = await workbook.xlsx.writeBuffer();
            saveAs(new Blob([buffer]), "Evening_Late_Routes_<?php echo $filterDate; ?>.xlsx");
        }
    </script>
</head>

<body class="bg-gray-100 font-sans">
    <div class="bg-gradient-to-r from-gray-900 to-indigo-900 text-white h-16 flex justify-between items-center shadow-lg w-[85%] ml-[15%] px-6 sticky top-0 z-40 border-b border-gray-700">
        <div class="flex items-center space-x-2 w-fit">
            <a href="factory_transport_vehicle_register.php" class="text-md font-bold tracking-wide bg-gradient-to-r from-yellow-200 via-yellow-400 to-yellow-200 bg-clip-text text-transparent hover:opacity-80 transition">
                Factory Transport Vehicle Registers
            </a>

            <i class="fa-solid fa-angle-right text-gray-300 text-sm mt-0.5"></i>

            <span class="text-sm font-bold text-white uppercase tracking-wider px-1 py-1 rounded-full">
                Late Evening Routes
            </span>
        </div>

        <div class="flex items-center gap-4">
            <div class="flex items-center bg-gray-700 rounded-lg p-1 border border-gray-600 shadow-inner">
                <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' -1 day')); ?>" 
                class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150" 
                title="Previous Day">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>

                <form method="GET" class="flex items-center bg-gray-700/50 rounded-lg p-1">
                    <input type="date" name="date" value="<?php echo $filterDate; ?>" onchange="this.form.submit()" class="bg-transparent text-white text-sm p-1 outline-none cursor-pointer">
                </form>

                <a href="?date=<?php echo date('Y-m-d', strtotime($filterDate . ' +1 day')); ?>" 
                class="p-2 text-gray-400 hover:text-white hover:bg-gray-600 rounded-md transition duration-150" 
                title="Next Day">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            <button onclick="exportEveningExcel()" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold px-2 py-1 rounded-lg shadow-md transition-all flex items-center gap-2 border border-emerald-700 hover:border-emerald-500">
                <i class="fas fa-file-excel text-lg"></i> EXPORT TO EXCEL
            </button>

            <div class="flex items-center gap-4 text-sm font-medium"> 
                <?php if ($is_logged_in): ?>
                    <a href="factory_transport_vehicle_register.php" class="hover:text-yellow-600">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <main class="w-[85%] ml-[15%] p-6">
        <?php if (!empty($evening_data)): ?>
            
            <div class="bg-indigo-50 border-l-4 border-indigo-500 p-4 mb-6 rounded-r-lg shadow-sm flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-indigo-800 flex items-center gap-2">
                        <i class="fas fa-clock text-indigo-600"></i> Evening Arrivals (After 4:40 PM)
                    </h3>
                    <p class="text-sm text-indigo-600 mt-1 italic">Records found for <?php echo $filterDate; ?></p>
                </div>
                <div class="flex flex-col items-center justify-center bg-white px-8 py-2 rounded-xl shadow-inner border border-indigo-100">
                    <span class="text-[10px] text-gray-400 uppercase font-black tracking-widest">Total Count</span>
                    <span class="text-4xl font-black text-indigo-600 leading-none"><?php echo count($evening_data); ?></span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5">
                <?php foreach ($evening_data as $row): ?>
                    <div class="bg-white p-5 border border-gray-200 shadow-sm rounded-2xl relative overflow-hidden transition-all hover:shadow-xl hover:-translate-y-1">
                        <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-indigo-600"></div>
                        
                        <div class="flex flex-col h-full">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-tighter">In Time</span>
                                    <div class="text-2xl font-black text-indigo-800 tracking-tighter">
                                        <?php echo date('h:i A', strtotime($row['in_time'])); ?>
                                    </div>
                                </div>
                                <i class="fas fa-shuttle-van text-indigo-100 text-3xl"></i>
                            </div>

                            <div class="mt-auto space-y-2 pt-4 border-t border-gray-50">
                                <div>
                                    <span class="text-[9px] font-bold text-gray-400 uppercase">Route Code</span>
                                    <div class="font-mono font-black text-gray-700 text-md"><?php echo $row['route_code']; ?></div>
                                </div>
                                <div>
                                    <span class="text-[9px] font-bold text-gray-400 uppercase">Destination</span>
                                    <div class="text-xs text-gray-500 font-bold truncate leading-tight" title="<?php echo $row['route_name']; ?>">
                                        <?php echo $row['route_name'] ?: '---'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="bg-white p-16 rounded-3xl shadow-xl border border-dashed border-gray-200 text-center max-w-2xl mx-auto mt-10">
                <div class="inline-flex p-5 rounded-full bg-indigo-50 text-indigo-400 mb-6">
                    <i class="fas fa-calendar-check text-6xl"></i>
                </div>
                <h3 class="text-2xl font-black text-gray-800 mb-2">No Late Entries</h3>
                <p class="text-gray-400">Everything is on track for <?php echo $filterDate; ?>.</p>
            </div>
        <?php endif; ?>
    </main>
</body>
<script>
    function exportEveningExcel() {
        // 1. Excel එකේ පෙනුම වෙනුවෙන් Header එක සහ Styles define කරනවා
        var header = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <style>
                table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 12px; }
                th { background-color: #2d3748; color: #ffffff; border: 1px solid #000000; padding: 10px; text-align: center; font-size: 13px; font-weight: bold; }
                td { border: 1px solid #cbd5e0; padding: 8px; text-align: center; vertical-align: middle; color: #333; }
                .text-left { text-align: left !important; padding-left: 10px; }
                .title-bg { background-color: #edf2f7; border: 1px solid #000; font-size: 18px; font-weight: bold; text-align: center; padding: 15px; }
                .late-time { color: #c53030; font-weight: bold; }
            </style>
        </head>
        <body>
            <table>
                <tr>
                    <td colspan="4" class="title-bg">Late Evening Routes Report - <?php echo $filterDate; ?></td>
                </tr>
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th style="width: 120px;">In Time</th>
                        <th style="width: 150px;">Route Code</th>
                        <th style="width: 300px;">Route Name</th>
                    </tr>
                </thead>
                <tbody>`;

        var rows = "";
        <?php if (!empty($evening_data)): ?>
            <?php foreach ($evening_data as $index => $row): ?>
                rows += `<tr>
                    <td><?php echo $index + 1; ?></td>
                    <td class="late-time"><?php echo date('h:i A', strtotime($row['in_time'])); ?></td>
                    <td style="font-weight:bold;"><?php echo $row['route_code']; ?></td>
                    <td class="text-left"><?php echo $row['route_name'] ?: '---'; ?></td>
                </tr>`;
            <?php endforeach; ?>
            
            // Total Count එක අන්තිමට එකතු කරනවා
            rows += `<tr>
                <td colspan="3" style="background-color:#f7fafc; font-weight:bold; text-align:right;">Total Late Routes:</td>
                <td style="background-color:#f7fafc; font-weight:bold; text-align:left; color:#2b6cb0;"><?php echo count($evening_data); ?></td>
            </tr>`;
        <?php endif; ?>

        var footer = "</tbody></table></body></html>";

        // 2. Blob එකක් හරහා Excel ෆයිල් එකක් විදිහට ඩවුන්ලෝඩ් කරනවා
        var htmlContent = header + rows + footer;
        var blob = new Blob([htmlContent], { type: 'application/vnd.ms-excel' });
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "Evening_Late_Routes_<?php echo $filterDate; ?>.xls";
        link.click();
    }
</script>
</html>