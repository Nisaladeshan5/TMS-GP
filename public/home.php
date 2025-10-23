<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Button Navigation</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100" >

    <!-- Container to center everything in the middle -->
    <div class="h-screen flex justify-center items-center w-[85%] ml-[15%]">
        <div class="w-4xl text-center p-6 bg-white rounded-xl shadow-lg">
            <h2 class="text-4xl font-bold text-gray-800 pb-4">Registers</h2>
            
            <!-- Buttons to Navigate to Different Pages -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 ">
                <a href="registers/Staff transport vehicle register.php" class="bg-blue-500 text-white py-12 px-10 rounded-lg text-xl shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl font-semibold">Staff transport vehicle register ✅</a>
                <a href="" class="bg-gray-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Workers transport vehicle register</a>
                <a href="" class="bg-green-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Day time vehicle register</a>
                <a href="" class="bg-red-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Night time vehicle register</a>
                <a href="registers/night_emergency.php" class="bg-yellow-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Night emergency vehicle register</a>
                <a href="registers/extra_transport_vehicle_register.php" class="bg-teal-500 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Extra vehicle register</a>
                <a href="registers/petty_cash.php" class="bg-[oklch(60.6%_0.25_292.717)] text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl">Petty cash ✅</a>
                <a href="registers/non_paid_vehicle_register.php" class="bg-gray-800 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl disabled">Non Paid vehicle register</a>
                <a href="registers/varification.php" class="bg-pink-800 text-white py-12 px-10 rounded-lg text-xl font-semibold shadow-md transform transition-all duration-300 hover:scale-105 hover:shadow-xl disabled">Varification</a>
            </div>
        </div>
    </Div>
</body>
</html>
