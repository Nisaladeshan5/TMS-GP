<?php
include('../includes/db.php');  // Include MySQLi connection file
include('../includes/header.php');
include('../includes/navbar.php');

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Collect form data
    $vehicle_no = $_POST['vehicle_no'];
    $date = $_POST['date'];
    $start_time = $_POST['start_time'];
    $finish_time = $_POST['finish_time'];
    $start_meter_reading = $_POST['start_meter_reading'];
    $finish_meter_reading = $_POST['finish_meter_reading'];
    $purpose = $_POST['purpose'];

    // Insert data into the running_chart table using MySQLi
    $sql = "INSERT INTO running_chart (vehicle_no, date, start_time, finish_time, start_meter_reading, finish_meter_reading, purpose) 
            VALUES ('$vehicle_no', '$date', '$start_time', '$finish_time', '$start_meter_reading', '$finish_meter_reading', '$purpose')";

    // Execute query
    if (mysqli_query($conn, $sql)) {
        // Redirect to the home page (index.php) after successful insertion
        header('Location: index.php'); // Redirect to home page
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
    <h2>Add Running Chart</h2>
    <form method="POST">
        <div class="form-group">
            <label for="vehicle_no">Vehicle No</label>
            <input type="text" class="form-control" id="vehicle_no" name="vehicle_no" required>
        </div>
        <div class="form-group">
            <label for="date">Date</label>
            <input type="date" class="form-control" id="date" name="date" required>
        </div>
        <div class="form-group">
            <label for="start_time">Start Time</label>
            <input type="time" class="form-control" id="start_time" name="start_time" required>
        </div>
        <div class="form-group">
            <label for="finish_time">Finish Time</label>
            <input type="time" class="form-control" id="finish_time" name="finish_time" required>
        </div>
        <div class="form-group">
            <label for="start_meter_reading">Start Meter Reading</label>
            <input type="number" class="form-control" id="start_meter_reading" name="start_meter_reading" required>
        </div>
        <div class="form-group">
            <label for="finish_meter_reading">Finish Meter Reading</label>
            <input type="number" class="form-control" id="finish_meter_reading" name="finish_meter_reading" required>
        </div>
        <div class="form-group">
            <label for="purpose">Purpose</label>
            <input type="text" class="form-control" id="purpose" name="purpose" required>
        </div>
        <button type="submit" class="btn btn-primary">Add Running Chart</button>
    </form>
</div>

<?php include('../includes/footer.php'); ?>
