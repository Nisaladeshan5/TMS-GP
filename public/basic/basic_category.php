<?php
// Include the database connection
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/navbar.php');
// Close the database connection
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Button Navigation</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 m-0">

    <div class="h-screen flex justify-center items-center w-[85%] ml-[15%]">
        <div class="w-4xl text-center p-6 bg-white rounded-xl shadow-lg">
            <h2 class="text-4xl font-bold text-gray-800 pb-4">Basic Data</h2>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 ">
                <a href="routes_staff2.php" class="bg-blue-600 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Routes ✅</a>
                <a href="sub_routes.php" class="bg-teal-500 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Sub Routes</a>
                <a href="suppliers.php" class="bg-purple-600 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Suppliers ✅</a>
                <a href="vehicle.php" class="bg-green-600 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Vehicles ✅</a>
                <a href="driver.php" class="bg-red-600 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Drivers ✅</a>
                <a href="employee.php" class="bg-yellow-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Employees - Api</a>
                <a href="operations.php" class="bg-cyan-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Operations - Api</a>
                <a href="barcode_generator_staff.php" class="bg-indigo-600 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Barcode Generator</a>
                <a href="fuel.php" class="bg-gray-800 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Fuel ✅</a>
            </div>
        </div>
    </div>
</body>
</html>