<?php
include('../includes/db.php');  // Include MySQLi connection file
include('../includes/header.php');
include('../includes/navbar.php');

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Collect form data
    $vehicle_no = $_POST['vehicle_no'];
    $owner = $_POST['owner'];
    $capacity = $_POST['capacity'];
    $type = $_POST['type'];
    $purpose = $_POST['purpose'];

    // Insert data into the vehicles table using MySQLi
    $sql = "INSERT INTO vehicle (vehicle_no, owner, capacity, type, purpose) 
            VALUES ('$vehicle_no', '$owner', '$capacity', '$type', '$purpose')";

    // Execute query
    if (mysqli_query($conn, $sql)) {
        // Redirect to vehicle details page after successful insertion
        header('Location: vehicle.php');
        exit();
    } else {
        // Handle error (optional)
        echo "Error: " . $sql . "<br>" . mysqli_error($conn);
    }
}

// Close the connection after use
mysqli_close($conn);
?>

<div class="container">
    <h2>Add Vehicle</h2>
    <form method="POST">
        <div class="form-group">
            <label for="vehicle_no">Vehicle No</label>
            <input type="text" class="form-control" id="vehicle_no" name="vehicle_no" required>
        </div>
        <div class="form-group">
            <label for="owner">Owner</label>
            <input type="text" class="form-control" id="owner" name="owner" required>
        </div>
        <div class="form-group">
            <label for="capacity">Capacity</label>
            <input type="number" class="form-control" id="capacity" name="capacity" required>
        </div>
        <div class="form-group">
            <label for="type">Vehicle Type</label>
            <input type="text" class="form-control" id="type" name="type" required>
        </div>
        <div class="form-group">
            <label for="purpose">Purpose</label>
            <input type="text" class="form-control" id="purpose" name="purpose" required>
        </div>
        <button type="submit" class="btn btn-primary">Add Vehicle</button>
    </form>
</div>

<?php include('../includes/footer.php'); ?>
