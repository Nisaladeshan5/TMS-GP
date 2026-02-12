<?php
// fetch_users.php - Dedicated AJAX endpoint for user table data
ob_start();

include('../../includes/db.php');

// 1. Capture Search Query and Route Filter
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$route_filter = isset($_GET['route']) ? trim($_GET['route']) : ''; // <--- NEW: Capture Route

// Base SQL query with LEFT JOIN to get calling_name
$sql = "SELECT u.*, e.calling_name 
        FROM user u 
        LEFT JOIN employee e ON u.emp_id = e.emp_id";

$conditions = [];
$params = [];
$types = "";

// 2. Apply Search Filter
if (!empty($search_query)) {
    // Search both Emp ID and Calling Name
    $conditions[] = "(u.emp_id LIKE ? OR e.calling_name LIKE ?)";
    $params[] = "%" . $search_query . "%";
    $params[] = "%" . $search_query . "%";
    $types .= "ss";
}

// 3. Apply Route Filter (NEW LOGIC)
if (!empty($route_filter)) {
    // Filter by route_code in the user table
    $conditions[] = "u.route_code = ?";
    $params[] = $route_filter;
    $types .= "s";
}

// 4. Combine Conditions
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY u.emp_id ASC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    ob_end_clean(); 
    echo "<tr><td colspan='8' class='border px-4 py-2 text-center text-red-500'>SQL Prepare Error: " . htmlspecialchars($conn->error) . "</td></tr>";
    exit;
}

// Bind parameters dynamically
if ($types) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$output_html = "";

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $masked_pin = str_repeat('*', strlen($row['pin']));
        $safe_emp_id = htmlspecialchars($row['emp_id']);
        
        // Handle Name (Display 'Unknown' if null)
        $emp_name = !empty($row['calling_name']) ? htmlspecialchars($row['calling_name']) : '<span class="text-gray-400 italic">Unknown</span>';

        $issued_status = (int)$row['issued']; // 0 or 1
        
        // --- BUTTON LOGIC ---
        if ($issued_status === 1) {
            $button_class = "bg-green-100 text-green-700 border-green-200 hover:bg-green-200";
            $icon = '<i class="fas fa-check-circle mr-1"></i>';
            $button_text = "Issued";
        } else {
            $button_class = "bg-yellow-100 text-yellow-700 border-yellow-200 hover:bg-yellow-200";
            $icon = '<i class="fas fa-clock mr-1"></i>';
            $button_text = "Pending";
        }

        $toggle_html = "<button 
                            data-id='{$safe_emp_id}' 
                            data-status='{$issued_status}'
                            class='toggle-status-btn px-3 py-1 rounded-full text-xs font-semibold border shadow-sm transition-all duration-200 flex items-center justify-center mx-auto w-24 {$button_class}'>
                            {$icon} {$button_text}
                        </button>";
        // --------------------

        $output_html .= "<tr class='hover:bg-blue-50 transition duration-150 border-b border-gray-100 group' data-pin='{$row['pin']}'>";
        
        // 1. Checkbox
        $output_html .= "<td class='px-4 py-3 text-center'>";
        $output_html .= "<input type='checkbox' class='emp-checkbox h-4 w-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500 cursor-pointer' value='{$safe_emp_id}'>";
        $output_html .= "</td>";
        
        // 2. Emp ID
        $output_html .= "<td class='px-6 py-3 font-medium text-gray-900 whitespace-nowrap'>{$safe_emp_id}</td>";
        
        // 3. Name
        $output_html .= "<td class='px-6 py-3 text-gray-700 whitespace-nowrap font-medium'>{$emp_name}</td>";

        // 4. Route Code
        $output_html .= "<td class='px-6 py-3 text-gray-600'>{$row['route_code']}</td>";
        
        // 5. PIN (Masked)
        $output_html .= "<td class='px-6 py-3'>";
        $output_html .= "<span id='pin-{$safe_emp_id}' class='font-mono text-gray-600 bg-gray-100 px-2 py-0.5 rounded tracking-widest' data-masked-pin='{$masked_pin}'>{$masked_pin}</span>";
        $output_html .= "</td>";
        
        // 6. Purpose
        $output_html .= "<td class='px-6 py-3 text-gray-600'>{$row['purpose']}</td>";
        
        // 7. QR Issued
        $output_html .= "<td class='px-6 py-3 text-center' id='issued-status-{$safe_emp_id}'>{$toggle_html}</td>"; 
        
        // 8. Action
        $output_html .= "<td class='px-6 py-3 text-center'>"; 
        $output_html .= "<button data-id='{$safe_emp_id}' data-is-visible='false' title='View PIN' class='view-pin-btn text-blue-400 hover:text-blue-600 transition focus:outline-none mr-2'><i class='fas fa-eye'></i></button>";
        $output_html .= "<button data-id='{$safe_emp_id}' title='Edit PIN' class='edit-pin-btn text-yellow-400 hover:text-yellow-600 transition focus:outline-none mr-2'><i class='fas fa-edit'></i></button>";
        $output_html .= "<button data-id='{$safe_emp_id}' title='Delete User' class='delete-user-btn text-red-500 hover:text-red-700 transition hover:bg-red-50 rounded-full'><i class='fas fa-trash-alt'></i></button>";
        $output_html .= "</td>";
        
        $output_html .= "</tr>";
    }
} else {
    $output_html = "<tr><td colspan='8' class='px-6 py- text-center text-gray-500 bg-white'>
                        <div class='flex flex-col items-center justify-center'>
                            <p>No bus leaders found.</p>
                        </div>
                    </td></tr>";
}

$stmt->close();
$conn->close();

ob_end_clean();
echo $output_html;
?>