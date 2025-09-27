<?php
// Include the database connection
include('../../../includes/db.php');
include('../../../includes/header.php');
include('../../../includes/navbar.php');
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
<body class="bg-gray-100 m-0" >

    <div class="h-screen flex justify-center items-center w-[85%] ml-[15%]">
        <div class="w-2xl text-center p-6 bg-white rounded-xl shadow-lg">
            <h2 class="text-4xl font-bold text-gray-800 pb-4">Choose One</h2>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-6">
                <a href="absent_extra.php" class="bg-teal-500 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Extra Transport</a>
                <a href="absent_petty.php" class="bg-[oklch(60.6%_0.25_292.717)] text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Petty Cash</a>
            </div>

            <div class="mt-8">
                <a href="javascript:history.back()" class="bg-gray-500 text-white  py-2 px-4 rounded-lg text-lg shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">
                    Back
                </a>
            </div>
        </div>
    </div>
</body>
</html>