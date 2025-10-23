<?php
include('../../includes/db.php');

$reportType = $_POST['report_type'] ?? '';
$month = $_POST['month_input'] ?? '';
$year = $_POST['year_input'] ?? '';

if ($reportType !== 'gl_cost' || empty($month) || empty($year)) {
    echo '<div class="p-4 bg-yellow-50 text-yellow-700 rounded-md font-semibold">Invalid request. Please select a valid month and year.</div>';
    exit;
}

$month = (int)$month;
$year = (int)$year;

$sql = "
    SELECT 
        m.supplier_code,
        m.gl_code,
        g.gl_name,
        m.department,
        SUM(m.monthly_allocation) AS total_cost,
        SUM(CASE WHEN m.direct = 'YES' THEN m.monthly_allocation ELSE 0 END) AS total_direct_cost,
        SUM(CASE WHEN m.direct = 'NO' THEN m.monthly_allocation ELSE 0 END) AS total_indirect_cost
    FROM 
        monthly_cost_allocation m
    LEFT JOIN
        gl g ON m.gl_code = g.gl_code
    WHERE 
        m.month = ? AND m.year = ?
    GROUP BY 
        m.supplier_code, m.gl_code, g.gl_name, m.department
    ORDER BY 
        m.supplier_code, m.department, m.gl_code
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

$output = '';

if ($result->num_rows > 0) {
    $output .= '
    <div class="border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="max-h-[70vh] overflow-y-auto">
            <table class="min-w-full border-collapse table-fixed">
                <thead class="bg-gray-100 sticky top-0 z-10">
                    <tr>
                        <th class="w-[15%] text-left text-xs font-semibold text-gray-600 uppercase tracking-wider px-4 py-2">Supplier Code</th>
                        <th class="w-[10%] text-left text-xs font-semibold text-gray-600 uppercase tracking-wider px-4 py-2">GL Code</th>
                        <th class="w-[20%] text-left text-xs font-semibold text-gray-600 uppercase tracking-wider px-4 py-2">GL Name</th>
                        <th class="w-[15%] text-left text-xs font-semibold text-gray-600 uppercase tracking-wider px-4 py-2">Department</th>
                        <th class="w-[12%] text-right text-xs font-semibold text-gray-600 uppercase tracking-wider px-4 py-2">Total Cost (LKR)</th>
                        <th class="w-[14%] text-right text-xs font-semibold text-gray-600 uppercase tracking-wider px-4 py-2">Direct Cost (LKR)</th>
                        <th class="w-[14%] text-right text-xs font-semibold text-gray-600 uppercase tracking-wider px-4 py-2">Indirect Cost (LKR)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
    ';

    $grandTotalCost = $grandTotalDirect = $grandTotalIndirect = 0;

    while ($row = $result->fetch_assoc()) {
        $totalCost = (float)$row['total_cost'];
        $totalDirect = (float)$row['total_direct_cost'];
        $totalIndirect = (float)$row['total_indirect_cost'];

        $grandTotalCost += $totalCost;
        $grandTotalDirect += $totalDirect;
        $grandTotalIndirect += $totalIndirect;

        $directClass = $totalDirect > 0 ? 'text-gray-700' : 'text-gray-400 italic';
        $indirectClass = $totalIndirect > 0 ? 'text-gray-700' : 'text-gray-400 italic';

        $output .= '
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-900 font-medium">' . htmlspecialchars($row['supplier_code']) . '</td>
                <td class="px-4 py-2 text-gray-700">' . htmlspecialchars($row['gl_code']) . '</td>
                <td class="px-4 py-2 text-gray-700 text-sm">' . htmlspecialchars($row['gl_name'] ?? 'N/A') . '</td>
                <td class="px-4 py-2 text-gray-700">' . htmlspecialchars($row['department']) . '</td>
                <td class="px-4 py-2 text-right font-extrabold text-gray-900">' . number_format($totalCost, 2) . '</td>
                <td class="px-4 py-2 text-right ' . $directClass . '">' . number_format($totalDirect, 2) . '</td>
                <td class="px-4 py-2 text-right ' . $indirectClass . '">' . number_format($totalIndirect, 2) . '</td>
            </tr>';
    }

    $output .= '
                </tbody>
                <tfoot class="bg-gray-50 border-t border-gray-300">
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-right font-bold text-gray-800 uppercase">Grand Total:</td>
                        <td class="px-4 py-3 text-right font-extrabold text-blue-700">' . number_format($grandTotalCost, 2) . '</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-700">' . number_format($grandTotalDirect, 2) . '</td>
                        <td class="px-4 py-3 text-right font-bold text-gray-700">' . number_format($grandTotalIndirect, 2) . '</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>';
} else {
    $output .= '
        <div class="p-4 bg-blue-50 text-blue-700 rounded-md font-semibold border border-blue-200">
            No General Ledger cost data found for the selected month and year.
        </div>';
}

echo $output;

$stmt->close();
?>