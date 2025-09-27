<?php
include('../../includes/db.php');
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_d_name'])) {
    $new_d_name = trim($_POST['new_d_name']);
    
    if (!empty($new_d_name)) {
        $stmt = $conn->prepare("INSERT INTO department (d_name) VALUES (?)");
        $stmt->bind_param("s", $new_d_name);
        
        if ($stmt->execute()) {
            // Set JavaScript call for a success message
            $toast_message = "Department added successfully!";
            $toast_type = "success";
        } else {
            // Set JavaScript call for an error message
            $toast_message = "Error: " . $stmt->error;
            $toast_type = "error";
        }
        
        $stmt->close();
    }
}

// 3. Fetch All Departments from the Database
$sql = "SELECT d_name FROM department ORDER BY d_name ASC";
$result = $conn->query($sql);

$departments = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $departments[] = $row['d_name'];
    }
}

// Close the database connection (this is already done by your code)
// $conn->close();

include('../../includes/header.php');
include('../../includes/navbar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .toast {
            display: flex;
            align-items: center;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            color: white;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
            transform: translateY(-20px);
            opacity: 0;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast.success {
            background-color: #4CAF50;
        }

        .toast.error {
            background-color: #F44336;
        }

        .toast-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="h-screen">
        <div class="bg-gray-800 text-white p-2 flex justify-between items-center shadow-lg w-[85%] ml-[15%] h-[5%]">
            <div class="text-lg font-semibold ml-3">Organizations</div>
            <div class="flex gap-4">
                <p class="hover:text-yellow-600 text-yellow-500 font-bold">Departments</p>
                <a href="reason.php" class="hover:text-yellow-600">Reason</a>
            </div>
        </div>

        <div class="flex justify-center items-center w-[85%] ml-[15%] h-[95%]">
            <div class="max-w-xl mx-auto my-auto p-6 bg-white rounded-lg shadow-md ">
                <h2 class="text-3xl font-bold mb-6 text-center text-blue-600">Department List</h2>

                <?php if (isset($message)): ?>
                    <div class="p-4 rounded-lg text-center mb-4 
                    <?php echo strpos($message, 'Error') !== false ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                    <?php if (!empty($departments)): ?>
                        <?php foreach ($departments as $department): ?>
                            <div class="bg-white p-3 text-center rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow">
                                <span class="font-semibold text-md text-gray-700"><?php echo htmlspecialchars($department); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="col-span-full p-4 text-center text-gray-500">No departments found.</p>
                    <?php endif; ?>
                </div>
                <hr class="my-2 border-gray-300">

                <div class="add-form bg-blue-50 p-6 rounded-lg border border-blue-200">
                    <h3 class="text-xl font-semibold mb-2 text-blue-800">Add New Department</h3>
                    <form action="operations.php" method="POST">
                        <div class="mb-2">
                            <label for="new_d_name" class="block text-gray-700 font-medium mb-1">Department Name:</label>
                            <input type="text" id="new_d_name" name="new_d_name" required 
                                class="w-full px-4 py-2 border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-blue-700 transition-colors">
                            Add Department
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div id="toast-container"></div>
    <script>
        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                                    ${type === 'success' ? 
                                        '<path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.293 12.5a1.003 1.003 0 0 1-1.417 0L2.354 8.7a.733.733 0 0 1 1.047-1.05l3.245 3.246 6.095-6.094z"/>' :
                                        '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/> <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>'
                                    }
                                </svg>
                                <p class="font-semibold">${message}</p>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => toast.classList.remove('show'), 3000);
            setTimeout(() => toast.remove(), 3500);
        }

        // Call the showToast function if a message exists from the PHP backend
        <?php if (isset($toast_message) && isset($toast_type)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast("<?php echo htmlspecialchars($toast_message, ENT_QUOTES, 'UTF-8'); ?>", "<?php echo htmlspecialchars($toast_type, ENT_QUOTES, 'UTF-8'); ?>");
            });
        <?php endif; ?>
    </script>
</body>
</html>