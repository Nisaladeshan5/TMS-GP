<?php
// Include the database connection
include('../includes/db.php');

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
<body class="bg-gray-100 flex justify-center items-center h-screen m-0" >

    <!-- Container to center everything in the middle -->
    <div class="text-center p-6 bg-white rounded-xl shadow-lg w-[80%] mt-12 h-160" style="width: 55%; margin-left: 30%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
        <h2 class="text-4xl font-bold text-gray-800 pb-4">Registers</h2>
        
        <!-- Buttons to Navigate to Different Pages -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 ">
            <a href="registers/Staff transport vehicle register.php" class="bg-blue-500 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Staff transport vehicle register</a>
            <a href="" class="bg-gray-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Workers transport vehicle register</a>
            <a href="page3.php" class="bg-green-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Day time vehicle register</a>
            <a href="page4.php" class="bg-red-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Night time vehicle register</a>
            <a href="page5.php" class="bg-yellow-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Night emergency vehicle register</a>
            <a href="page6.php" class="bg-teal-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Extra vehicle register</a>
            <a href="" class="bg-white text-white py-12 px-10"></a>
            <a href="" class="bg-gray-800 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl disabled">Non Paid vehicle register</a>
        </div>
    </div>

</body>
</html>
