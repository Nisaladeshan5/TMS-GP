<?php 
 include('../../includes/db.php'); 

 if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
     $driver_NIC = $_POST['driver_NIC']; 
     $calling_name = $_POST['calling_name']; 
     $full_name = $_POST['full_name']; 
     $phone_no = $_POST['phone_no']; 
     $license_expiry_date = $_POST['license_expiry_date']; 
     $vehicle_no = $_POST['vehicle_no'] ?? null; 

     try { 
         // Start a transaction to ensure both inserts succeed or fail together 
         $conn->begin_transaction(); 

         // 1. Insert the new driver 
         $sql_driver = "INSERT INTO driver (driver_NIC, calling_name, full_name, phone_no, license_expiry_date) VALUES (?, ?, ?, ?, ?)"; 
         $stmt_driver = $conn->prepare($sql_driver); 
         $stmt_driver->bind_param('sssss', $driver_NIC, $calling_name, $full_name, $phone_no, $license_expiry_date); 
          
         if (!$stmt_driver->execute()) { 
             throw new Exception('Driver insertion error: ' . $stmt_driver->error); 
         } 

         // 2. Assign the vehicle if one was selected 
         if (!empty($vehicle_no)) { 
             $sql_update_vehicle = "UPDATE vehicle SET driver_NIC = ? WHERE vehicle_no = ?"; 
             $stmt_update_vehicle = $conn->prepare($sql_update_vehicle); 
             $stmt_update_vehicle->bind_param('ss', $driver_NIC, $vehicle_no); 
             if (!$stmt_update_vehicle->execute()) { 
                 throw new Exception('Vehicle assignment error: ' . $stmt_update_vehicle->error); 
             } 
         } 

         // Commit the transaction 
         $conn->commit(); 
          
         // Redirect to driver.php with a success message query parameter 
         header("Location: driver.php?status=success&message=" . urlencode("New driver added successfully!")); 
         exit(); 

     } catch (Exception $e) { 
         // Rollback on error 
         $conn->rollback(); 
         $message = "Error: " . $e->getMessage(); 
         $status = "error"; 
         // Fallback for non-redirected error display 
         include('../../includes/header.php'); 
         include('../../includes/navbar.php'); 
     } 
 } 

 // Check for incoming status messages from the redirect 
 if (isset($_GET['status'])) { 
     $message = $_GET['message']; 
     $status = $_GET['status']; 
 } 

 include('../../includes/header.php'); 
 include('../../includes/navbar.php'); 

 // Fetch available vehicles (those not assigned to a driver) 
 $available_vehicles = []; 
 $sql = "SELECT vehicle_no FROM vehicle WHERE driver_NIC IS NULL"; 
 $result = $conn->query($sql); 
 if ($result) { 
     while ($vehicle = $result->fetch_assoc()) { 
         $available_vehicles[] = $vehicle['vehicle_no']; 
     } 
 } 
 ?> 

 <!DOCTYPE html> 
 <html lang="en"> 
 <head> 
     <meta charset="UTF-8"> 
     <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
     <title>Add New Driver</title> 
     <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet"> 
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
 <body class="bg-gray-100 font-sans"> 

 <div id="toast-container"></div> 

 <div class="w-[85%] ml-[15%]">
     <div class="container max-w-2xl p-6 md:p-10 bg-white shadow-lg rounded-lg mt-10">
         <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 border-b pb-2">Add New Driver</h1>
         
         <form action="add_driver.php" method="POST" class="space-y-6"> 
             <div class="grid md:grid-cols-2 gap-6">
                 <div> 
                     <label for="driver_NIC" class="block text-sm font-medium text-gray-700">License ID:</label> 
                     <input type="text" id="driver_NIC" name="driver_NIC" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"> 
                 </div> 
                 <div> 
                     <label for="calling_name" class="block text-sm font-medium text-gray-700">Calling Name:</label> 
                     <input type="text" id="calling_name" name="calling_name" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"> 
                 </div> 
             </div>
             
             <div class="grid md:grid-cols-2 gap-6">
                 <div> 
                     <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name:</label> 
                     <input type="text" id="full_name" name="full_name" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"> 
                 </div> 
                 <div> 
                     <label for="phone_no" class="block text-sm font-medium text-gray-700">Phone No:</label> 
                     <input type="text" id="phone_no" name="phone_no" required class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"> 
                 </div> 
             </div>

             <div class="grid md:grid-cols-2 gap-6">
                 <div> 
                     <label for="license_expiry_date" class="block text-sm font-medium text-gray-700">License Expiry Date:</label> 
                     <input type="date" id="license_expiry_date" name="license_expiry_date" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"> 
                 </div> 
                 <div> 
                     <label for="vehicle_no" class="block text-sm font-medium text-gray-700">Assign Vehicle:</label> 
                     <select id="vehicle_no" name="vehicle_no" class="mt-1 block w-full rounded-md border-1 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2"> 
                         <option value="">-- Select a Vehicle --</option> 
                         <?php foreach ($available_vehicles as $vehicle): ?> 
                             <option value="<?php echo htmlspecialchars($vehicle); ?>"><?php echo htmlspecialchars($vehicle); ?></option> 
                         <?php endforeach; ?> 
                     </select> 
                 </div> 
             </div>
             
             <div class="flex justify-end mt-6"> 
                 <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-md shadow-md transition duration-300 transform hover:scale-105">
                     Add Driver
                 </button> 
             </div> 
         </form> 
     </div>
 </div>

 <script> 
     /** * Displays a toast notification. 
      * @param {string} message The message to display. 
      * @param {string} type The type of toast ('success' or 'error'). 
      */ 
     function showToast(message, type) { 
         const toastContainer = document.getElementById('toast-container'); 
         const toast = document.createElement('div'); 
         toast.className = `toast ${type}`; 
         toast.innerHTML = ` 
             <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon"> 
                 ${type === 'success' 
                 ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />' 
                 : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />' 
             } 
             </svg> 
             <span>${message}</span> 
         `; 
         
         toastContainer.appendChild(toast); 

         // Show the toast with a slight delay for the transition effect 
         setTimeout(() => toast.classList.add('show'), 10); 

         // Automatically hide and remove the toast after 1.3 seconds 
         setTimeout(() => { 
             toast.classList.remove('show'); 
             toast.addEventListener('transitionend', () => toast.remove(), { once: true }); 
         }, 1300); 
     } 
 </script> 

 <?php if (isset($message)): ?> 
 <script> 
     showToast('<?php echo htmlspecialchars($message); ?>', '<?php echo htmlspecialchars($status); ?>'); 
 </script> 
 <?php endif; ?> 

 </body> 
 </html> 

 <?php $conn->close(); ?>