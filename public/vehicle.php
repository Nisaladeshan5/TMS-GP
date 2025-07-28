<?php
include('../includes/db.php');  // Include MySQLi connection file
include('../includes/header.php');
include('../includes/navbar.php');

// Fetch all vehicles from the database
$vehicles_sql = "SELECT * FROM vehicle";
$vehicles_result = mysqli_query($conn, $vehicles_sql);

// Close the connection after use
mysqli_close($conn);
?>

<div class="container mt-3 mx-10" style="width: 70%; margin-left: 22%; margin-right: 2.5%; display: flex; flex-direction: column; align-items: center;">
    <h2>Vehicle Details</h2>
    <a href="add_vehicle.php" class="btn btn-primary mb-3" style="align-self: flex-start;">Add Vehicle</a>
    <!-- Display Vehicles -->
    <table class="table table-bordered mt-3">
        <thead>
            <tr>
                <th>Vehicle No</th>
                <th>Owner</th>
                <th>Capacity</th>
                <th>Type</th>
                <th>Purpose</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($vehicle = mysqli_fetch_assoc($vehicles_result)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($vehicle['vehicle_no']); ?></td>
                    <td><?php echo htmlspecialchars($vehicle['owner']); ?></td>
                    <td><?php echo htmlspecialchars($vehicle['capacity']); ?></td>
                    <td><?php echo htmlspecialchars($vehicle['type']); ?></td>
                    <td><?php echo htmlspecialchars($vehicle['purpose']); ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php include('../includes/footer.php'); ?>
