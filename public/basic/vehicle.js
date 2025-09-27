function showToast(message, type) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type} show`;
    toast.innerHTML = `
        <div class="toast-content">
            <p class="font-semibold">${message}</p>
        </div>
    `;
    container.appendChild(toast);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function openEditModal(vehicleNo) {
    fetch(`vehicle.php?view_vehicle_no=${vehicleNo}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data) {
                // Set the hidden input for vehicle_no
                document.getElementById('edit_vehicle_no').value = data.vehicle_no;
                document.getElementById('editVehicleNoTitle').textContent = data.vehicle_no;

                // Populate all dropdown fields with the fetched values
                document.getElementById('edit_supplier_code').value = data.supplier_code;
                document.getElementById('edit_capacity').value = data.capacity;
                document.getElementById('edit_km_per_liter').value = data.km_per_liter;
                document.getElementById('edit_type').value = data.type;
                document.getElementById('edit_rate_id').value = data.rate_id;
                document.getElementById('edit_purpose').value = data.purpose;
                
                // Populate the date inputs
                document.getElementById('edit_license_expiry_date').value = data.license_expiry_date;
                document.getElementById('edit_insurance_expiry_date').value = data.insurance_expiry_date;

                document.getElementById('editModal').style.display = 'flex';
            } else {
                showToast('Vehicle not found.', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to fetch vehicle details.', 'error');
        });
}

function handleEditSubmit(event) {
    event.preventDefault();
    const form = document.getElementById('editForm');
    const formData = new FormData(form);

    fetch('vehicle.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, 'success');
            closeModal('editModal');
            // Refresh the page or the relevant section
            window.location.reload();
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An unexpected error occurred.', 'error');
    });
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function viewVehicleDetails(vehicleNo) {
    fetch(`vehicle.php?view_vehicle_no=${vehicleNo}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(response => response.json())
        .then(data => {
            if (data) {
                document.getElementById('viewVehicleNo').textContent = data.vehicle_no;
                document.getElementById('viewSupplier').textContent = data.supplier;
                document.getElementById('viewCapacity').textContent = data.capacity;
                document.getElementById('viewKmPerLiter').textContent = data.km_per_liter;
                document.getElementById('viewType').textContent = data.type;
                document.getElementById('viewFuelType').textContent = data.fuel_type;
                document.getElementById('viewPurpose').textContent = data.purpose;
                document.getElementById('viewLicenseExpiry').textContent = data.license_expiry_date;
                document.getElementById('viewInsuranceExpiry').textContent = data.insurance_expiry_date;
                document.getElementById('viewModal').style.display = 'flex';
            } else {
                showToast('Vehicle not found.', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to fetch vehicle details.', 'error');
        });
}

let vehicleToToggle = null;
let newStatus = null;

function confirmToggleStatus(vehicleNo, status) {
    vehicleToToggle = vehicleNo;
    newStatus = status;
    const action = status === 1 ? 'Enable' : 'Disable';
    document.getElementById('confirmationTitle').textContent = `${action} Vehicle`;
    document.getElementById('confirmationMessage').textContent = `Are you sure you want to ${action.toLowerCase()} vehicle number ${vehicleNo}? This will change its status in the system.`;
    document.getElementById('confirmButton').textContent = action;
    document.getElementById('confirmationModal').style.display = 'flex';
}

document.getElementById('confirmButton').addEventListener('click', () => {
    if (vehicleToToggle && newStatus !== null) {
        toggleStatus(vehicleToToggle, newStatus);
    }
});

function toggleStatus(vehicleNo, newStatus) {
    closeModal('confirmationModal');

    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('vehicle_no', vehicleNo);
    formData.append('is_active', newStatus);

    fetch('vehicle.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, 'success');
            // Refresh the page to show the updated status
            window.location.reload();
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An unexpected error occurred.', 'error');
    });
}

function filterVehicles(purpose) {
    const currentStatus = document.getElementById('status-filter').value;
    window.location.href = `vehicle.php?purpose=${purpose}&status=${currentStatus}`;
}

function filterStatus(status) {
    const currentPurpose = document.getElementById('purpose-filter').value;
    window.location.href = `vehicle.php?purpose=${currentPurpose}&status=${status}`;
}