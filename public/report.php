<?php
require_once '../includes/session_check.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is NOT logged in (adjust 'loggedin' to your actual session variable)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../includes/login.php");
    exit();
}

include('../includes/db.php');  // Include MySQLi connection file
include('../includes/header.php');
include('../includes/navbar.php');

// Fetch all vehicles for the dropdown
$vehicles_sql = "SELECT vehicle_no FROM vehicle";
$vehicles_result = mysqli_query($conn, $vehicles_sql);

?>
<script>
    // 9 hours in milliseconds (32,400,000 ms)
    const SESSION_TIMEOUT_MS = 32400000; 
    const LOGIN_PAGE_URL = "/TMS/includes/client_logout.php"; // Browser path

    setTimeout(function() {
        // Alert and redirect
        alert("Your session has expired due to 9 hours of inactivity. Please log in again.");
        window.location.href = LOGIN_PAGE_URL; 
        
    }, SESSION_TIMEOUT_MS);
</script>
<div class="container">
    <h2>Generate Bill</h2>
    <form method="POST">
        <div class="form-group">
            <label for="vehicle_no">Vehicle No</label>
            <select class="form-control" id="vehicle_no" name="vehicle_no" required>
                <option value="">Select Vehicle</option>
                <?php while ($vehicle = mysqli_fetch_assoc($vehicles_result)): ?>
                    <option value="<?php echo htmlspecialchars($vehicle['vehicle_no']); ?>"><?php echo htmlspecialchars($vehicle['vehicle_no']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="start_date">Start Date</label>
            <input type="date" class="form-control" id="start_date" name="start_date" required>
        </div>
        
        <div class="form-group">
            <label for="end_date">End Date</label>
            <input type="date" class="form-control" id="end_date" name="end_date" required>
        </div>

        <button type="submit" class="btn btn-primary">Generate Bill</button>
    </form>

    <?php
    // Bill Generation Logic
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $vehicle_no = $_POST['vehicle_no'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];

        // Fetch data based on vehicle number and date range
        $sql = "SELECT * FROM running_chart 
                WHERE vehicle_no = '$vehicle_no' 
                AND date BETWEEN '$start_date' AND '$end_date'";

        $result = mysqli_query($conn, $sql);
        $total_reading = 0;
        
        if (mysqli_num_rows($result) > 0) {
            echo "<h3 class='mt-5'>Bill for Vehicle No: $vehicle_no</h3>";
            echo "<h4>Date Range: $start_date to $end_date</h4>";
            echo "<table class='table table-bordered mt-3'>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Start Meter Reading</th>
                            <th>Finish Meter Reading</th>
                            <th>Usage (Km)</th>
                        </tr>
                    </thead>
                    <tbody>";

            while ($row = mysqli_fetch_assoc($result)) {
                $usage = $row['finish_meter_reading'] - $row['start_meter_reading'];
                $total_reading += $usage;
                echo "<tr>
                        <td>" . htmlspecialchars($row['date']) . "</td>
                        <td>" . htmlspecialchars($row['start_meter_reading']) . "</td>
                        <td>" . htmlspecialchars($row['finish_meter_reading']) . "</td>
                        <td>" . $usage . " km</td>
                    </tr>";
            }

            echo "</tbody></table>";
            echo "<h4>Total Usage: $total_reading km</h4>";

            // Optionally, you can add the calculation for the total amount based on usage
            $rate_per_km = 100; // Example rate per km
            $total_amount = $total_reading * $rate_per_km;
            echo "<h4>Total Bill: LKR $total_amount</h4>";
        } else {
            echo "<p>No records found for the selected vehicle and date range.</p>";
        }
    }
    ?>
</div>

<?php include('../includes/footer.php'); ?>
