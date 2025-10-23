<?php
// fetch_users.php - Dedicated AJAX endpoint for user table data

// Start Output Buffering to prevent unwanted output before HTML
ob_start();

// Include necessary files (adjust path as needed)
// IMPORTANT: db.php MUST NOT echo or output anything.
include('../../includes/db.php');

// Initialize filter variable using a generic 'q' for query in AJAX
$search_emp_id = isset($_GET['q']) ? trim($_GET['q']) : '';

// Base SQL query
// NOTE: Added 'issued' column to the SELECT statement
$sql = "SELECT emp_id, route_code, pin, purpose, qr_token, issued FROM `user`"; 

$conditions = [];
$params = [];
$types = "";

if (!empty($search_emp_id)) {
    // Note: The LIKE query with '%' is still used for partial matching
    $conditions[] = "emp_id LIKE ?";
    // Use concatenated wildcards for searching
    $params[] = "%" . $search_emp_id . "%";
    $types .= "s";
}

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY emp_id ASC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    // Clear the buffer and output the error row
    ob_end_clean(); 
    // Colspan is 7: (Checkbox, Emp ID, Route Code, PIN, Purpose, QR Issued, Action)
    echo "<tr><td colspan='7' class='border px-4 py-2 text-center text-red-500'>SQL Prepare Error: " . htmlspecialchars($conn->error) . "</td></tr>";
    exit;
}

if ($types) {
    // Correctly bind parameters using the splat operator (...) for arrays
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Variable to hold all generated HTML
$output_html = "";

// Output the table rows directly
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $masked_pin = str_repeat('*', strlen($row['pin']));
        $safe_emp_id = htmlspecialchars($row['emp_id']);
        $issued_status = (int)$row['issued']; // 0 or 1
        
        // --- BUTTON LOGIC STARTS HERE ---
        // Determine button styling and text based on status
        if ($issued_status === 1) {
            $button_class = "bg-green-500 hover:bg-green-600";
            $button_text = "Issued";
        } else {
            $button_class = "bg-red-500 hover:bg-red-600";
            $button_text = "Not Issued";
        }

        // Generate the simple action button for toggling status
        $toggle_html = "<button 
                                data-id='{$safe_emp_id}' 
                                data-status='{$issued_status}'
                                class='toggle-status-btn text-white px-3 py-1 rounded-full text-sm font-medium transition-colors duration-150 {$button_class}'>
                                {$button_text}
                                </button>";
        // --- BUTTON LOGIC ENDS HERE ---

        $output_html .= "<tr class='hover:bg-gray-100' data-pin='{$row['pin']}'>";
        
        // Checkbox Column (Column 1)
        $output_html .= "<td class='border px-2 py-2 text-center'>";
        $output_html .= "<input type='checkbox' class='emp-checkbox h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded' value='{$safe_emp_id}'>";
        $output_html .= "</td>";
        
        $output_html .= "<td class='border px-4 py-2'>{$safe_emp_id}</td>"; // Column 2: Emp ID
        $output_html .= "<td class='border px-4 py-2'>{$row['route_code']}</td>"; // Column 3: Route Code
        
        // PIN (Column 4)
        $output_html .= "<td class='border px-4 py-2' id='pin-{$safe_emp_id}' data-masked-pin='{$masked_pin}'>{$masked_pin}</td>"; 
        
        $output_html .= "<td class='border px-4 py-2'>{$row['purpose']}</td>"; // Column 5: Purpose
        
        // NEW: QR Issued Button (Column 6)
        $output_html .= "<td class='border px-4 py-2 text-center' id='issued-status-{$safe_emp_id}'>{$toggle_html}</td>"; 
        
        // Action Column (Column 7)
        $output_html .= "<td class='border px-4 py-2 text-center'>"; 
        $output_html .= "<button data-id='{$safe_emp_id}' data-is-visible='false' title='View PIN' class='view-pin-btn text-blue-500 hover:text-blue-700 mx-1 focus:outline-none'><i class='fas fa-eye'></i></button>";
        $output_html .= "<button data-id='{$safe_emp_id}' title='Edit PIN' class='edit-pin-btn text-yellow-500 hover:text-yellow-700 mx-1 focus:outline-none'><i class='fas fa-edit'></i></button>";
        $output_html .= "<button data-id='{$safe_emp_id}' title='Delete User' class='delete-user-btn text-red-500 hover:text-red-700 mx-1 focus:outline-none'><i class='fas fa-trash-alt'></i></button>";
        $output_html .= "</td>";
        $output_html .= "</tr>";
    }
} else {
    // Colspan is 7 now
    $output_html = "<tr><td colspan='7' class='border px-4 py-2 text-center'>No users found.</td></tr>";
}

$stmt->close();
$conn->close();

// Clear the buffer and output only the desired HTML
ob_end_clean();
echo $output_html;

// Omit closing PHP tag
