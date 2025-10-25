<?php

// Include necessary files (assuming these handle setup and database connection)
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');

// Define the function to generate month options
function generateMonthOptions($selectedMonth = null) {
    // Array mapping Month Number (DB value) to Month Name (UI text)
    $months = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    $output = '';

    // Get current month number (1-12) for default selection
    $currentMonthNum = (int)date('n');

    foreach ($months as $num => $name) {
        // Check if the current month number matches the loop's month number for default
        $selected = ($selectedMonth == $num || (!isset($selectedMonth) && $num === $currentMonthNum)) ? 'selected' : '';
        $output .= "<option value=\"{$num}\" {$selected}>{$name}</option>";
    }
    return $output;
}

// Define the function to generate year options
function generateYearOptions($selectedYear = null) {
    $currentYear = date('Y');
    // Start from 2025 as required, and go up to 5 years past the current year
    // NOTE: Current year is 2025 based on your logic, but I'll honor the 2025 start for consistency
    $startYear = 2025;
    $endYear = $currentYear + 5;
    $output = '';

    for ($year = $startYear; $year <= $endYear; $year++) {
        // Set current year as default if no selection is passed
        $selected = ($selectedYear === (string)$year || (!isset($selectedYear) && $year == $currentYear)) ? 'selected' : '';
        $output .= "<option value=\"{$year}\" {$selected}>{$year}</option>";
    }
    return $output;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Modern Cost Analysis Reports - General Ledger View</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <style>
        :root{
            --primary-500: #2563eb;
            --primary-400: #3b82f6;
            --gold-500: #d4af37;
            --muted: #6b7280;
            --glass-bg: rgba(255,255,255,0.65);
        }

        .tab-button.active {
            background: linear-gradient(90deg, var(--primary-500), var(--primary-400));
            color: white !important;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(37,99,235,0.18);
        }

        .report-table th, .report-table td {
            padding: 12px 14px;
        }

        .scrollable-body-table thead,
        .scrollable-body-table tbody tr,
        .scrollable-body-table tfoot tr {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .scrollable-body-table tbody {
            display: block;
            max-height: 400px;
            overflow-y: auto;
            overflow-x: hidden;
            border-bottom: 1px solid #e5e7eb;
        }

        .scrollable-body-table thead {
            width: calc(100% - 15px);
        }

        .sticky-header thead th {
            position: sticky;
            top: 0;
            z-index: 30;
        }

        .report-table tr:nth-child(even) td {
            background-color: #fbfdff;
        }
        .report-table tr:hover td {
            background-color: #f1f5f9;
            transition: background-color .18s ease-in-out;
        }

        .badge-blue {
            background: linear-gradient(90deg, rgba(59,130,246,0.12), rgba(59,130,246,0.08));
            color: #3b82f6;
            border: 1px solid rgba(59,130,246,0.12);
        }

        .section-underline {
            height: 3px;
            width: 68px;
            background: linear-gradient(90deg, var(--primary-400), var(--gold-500));
            border-radius: 6px;
            box-shadow: 0 6px 18px rgba(59,130,246,0.08);
        }

        .glass-card {
            background: var(--glass-bg);
            border: 1px solid rgba(203,213,225,0.45);
            box-shadow: 0 12px 30px rgba(2,6,23,0.06);
            backdrop-filter: blur(6px);
        }

        .loader-line {
            height: 12px;
            border-radius: 6px;
            background: linear-gradient(90deg,#e6eefc 25%, #f7f9fb 50%, #eaf0ff 75%);
            background-size: 200% 100%;
            animation: shimmer 1.2s linear infinite;
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        @media (max-width: 900px) {
            .w-[85%] { width: 95% !important; margin-left: 0 !important; }
            .ml-[15%] { margin-left: 0 !important; }
            .tab-button { padding-left: 12px; padding-right: 12px; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen font-sans text-gray-800">

    <div class="w-[85%] ml-[15%] py-3">
        <div class="mx-auto glass-card p-8 rounded-2xl shadow-xl border-gray-200/60 w-[95%]">
            <div class="flex items-start justify-between gap-6 mb-6">
                <div>
                    <h1 class="text-3xl font-extrabold text-gray-800 flex items-center gap-3">
                        <span class="text-3xl">📊</span>
                        <span>General Ledger Cost Report</span>
                    </h1>
                    <div class="mt-2 flex items-center gap-3">
                        <div class="text-sm text-gray-500">Detailed GL account level totals by Supplier and Department</div>
                        <div class="section-underline"></div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <div class="text-xs text-gray-400">As of</div>
                        <div class="text-sm font-medium text-gray-700"><?php echo date('F j, Y'); ?></div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl p-4 shadow-sm flex flex-wrap items-end gap-4 mb-6">
                <div class="w-44">
                    <label for="month_filter" class="block text-xs font-medium text-gray-600 mb-1">Month</label>
                    <select id="month_filter" required
                            class="mt-1 block w-full py-2 px-3 border border-gray-200 bg-white rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 sm:text-sm">
                        <?php echo generateMonthOptions(); ?>
                    </select>
                </div>

                <div class="w-40">
                    <label for="year_filter" class="block text-xs font-medium text-gray-600 mb-1">Year</label>
                    <select id="year_filter" required
                            class="mt-1 block w-full py-2 px-3 border border-gray-200 bg-white rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400 sm:text-sm">
                        <?php echo generateYearOptions(); ?>
                    </select>
                </div>

                <div class="flex items-center gap-3 lg:ml-auto">
                    <button id="generate_report_btn"
                        style="background: linear-gradient(to right, #3b82f6, #6366f1);"  
                        class="px-5 py-2 text-white font-semibold rounded-lg shadow-md 
                                hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-150 
                                flex items-center gap-2 relative z-[9999]">
                        Generate Report
                    </button>

                    <button id="export_general_excel" title="Export Full GL Report to Excel" 
                        class="px-3 py-2 border border-gray-200 rounded-md text-sm text-gray-600 hover:bg-gray-50">
                        Export Full GL Excel
                    </button>
                    
                    <button id="export_employee_counts" title="Export current employee counts for Route F" 
                        class="px-3 py-2 bg-green-500 text-white font-medium rounded-md text-sm hover:bg-green-600 shadow-md transition-colors duration-150">
                        Export Employee Counts
                    </button>
                    <button id="export_transport_excel" title="Export FACTORY EMPLOYEE TRANSPORT COST (623401)" 
                        class="px-3 py-2 bg-indigo-500 text-white font-medium rounded-md text-sm hover:bg-indigo-600 ml-2 shadow-md transition-colors duration-150">
                        Export Purchase Journal (623401)
                    </button>
                </div>
            </div>

            <div id="GLCostTab" class="tab-content border border-t-0 border-gray-200 p-5 bg-white rounded-b-xl min-h-[340px]">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold tracking-wide text-gray-700 uppercase">General Ledger (GL) Cost Details</h3>
                    <div class="text-sm text-gray-500">Supplier, GL Code, Department, and Direct/Indirect Breakdown</div>
                </div>

                <div id="gl_cost_results" class="min-h-[200px]">
                    <p class="text-gray-500">Please select the Month and Year above and click 'Generate Report'.</p>
                </div>
            </div>

        </div>
    </div>

    <script>
        $('#generate_report_btn').click(function() {
            var month = $('#month_filter').val();
            var year = $('#year_filter').val();
            loadReport('gl_cost', month, year, '#gl_cost_results');
        });

        function showShimmer(target) {
            var html = `
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-1/3 loader-line"></div>
                        <div class="w-1/5 loader-line"></div>
                    </div>
                    <div class="space-y-3">
                        <div class="h-12 loader-line"></div>
                        <div class="h-12 loader-line"></div>
                        <div class="h-12 loader-line"></div>
                    </div>
                </div>
            `;
            $(target).html(html);
        }

        function loadReport(reportType, month, year, resultDiv) {
            showShimmer(resultDiv);
            $.ajax({
                url: 'get_report_data.php',
                method: 'POST',
                data: { report_type: reportType, month_input: month, year_input: year },
                success: function(response) {
                    $(resultDiv).html(response);
                    $(resultDiv).find('table.report-table').addClass('min-w-full divide-y divide-gray-200 text-sm');
                },
                error: function() {
                    $(resultDiv).html('<div class="p-4 bg-red-50 text-red-700 rounded-md font-semibold">Error retrieving data. Please try again.</div>');
                }
            });
        }

        $(document).ready(function() {
            var initialMonth = $('#month_filter').val();
            var initialYear = $('#year_filter').val();
            loadReport('gl_cost', initialMonth, initialYear, '#gl_cost_results');
        });

        $('#export_general_excel').click(function() {
            var table = document.querySelector('#gl_cost_results table');
            if (!table) {
                alert('No GL report data to export! Please generate the report first.');
                return;
            }

            var tableClone = table.cloneNode(true);
            tableClone.classList.remove('scrollable-body-table');
            tableClone.setAttribute('border', '1');
            tableClone.setAttribute('cellspacing', '0');
            tableClone.setAttribute('cellpadding', '4');
            $(tableClone).find('tbody').css({'display': 'table-row-group', 'max-height': 'none', 'overflow-y': 'visible'});
            var tableHTML = tableClone.outerHTML.replace(/ /g, '%20');
            var monthName = $('#month_filter option:selected').text();
            var year = $('#year_filter').val();
            var filename = 'GL_Cost_Report_' + monthName + '_' + year + '.xls';

            var downloadLink = document.createElement("a");
            downloadLink.href = 'data:application/vnd.ms-excel,' + tableHTML;
            downloadLink.download = filename;
            downloadLink.click();
        });

        $('#export_transport_excel').click(function() {
            var month = $('#month_filter').val();
            var year = $('#year_filter').val();
            var form = $('<form action="export_transport_cost.php" method="post" style="display:none;"></form>');
            form.append('<input type="hidden" name="month_input" value="' + month + '">');
            form.append('<input type="hidden" name="year_input" value="' + year + '">');
            $('body').append(form);
            form.submit();
            form.remove();
        });
        
        // NEW EXPORT LOGIC: Uses month/year for filename but the PHP script won't filter the data by them.
        $('#export_employee_counts').click(function() {
            var month = $('#month_filter').val();
            var year = $('#year_filter').val();
            var form = $('<form action="export_employee_report.php" method="post" style="display:none;"></form>');
            form.append('<input type="hidden" name="month_input" value="' + month + '">');
            form.append('<input type="hidden" name="year_input" value="' + year + '">');
            $('body').append(form);
            form.submit();
            form.remove();
        });
    </script>
</body>
</html>