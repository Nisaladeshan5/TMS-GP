// Function to show toast notifications
function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<svg class="toast-icon" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                            ${type === 'success' ? 
                                '<path d="M12.736 3.97a.733.733 0 0 1 1.047 0c.286.289.29.756.01 1.05L7.293 12.5a1.003 1.003 0 0 1-1.417 0L2.354 8.7a.733.733 0 0 1 1.047-1.05l3.245 3.246 6.095-6.094z"/>' :
                                '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/> <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>'
                            }
                        </svg>
                        <p class="font-semibold">${message}</p>`;
    toastContainer.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => toast.classList.remove('show'), 3000);
    setTimeout(() => toast.remove(), 3500);
}

// Function to handle opening a modal
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.style.display = 'flex';
}

// Function to handle closing a modal
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.style.display = 'none';
}

function openEditModal(button) {
    // Get the JSON string from the button's data attribute and parse it
    const driverData = JSON.parse(button.dataset.driver);
    const editModal = document.getElementById('editModal');
    
    // Populate the modal fields using the parsed data
    document.getElementById('editDriverNicTitle').textContent = driverData.driver_NIC;
    document.getElementById('edit_driver_NIC').value = driverData.driver_NIC;
    document.getElementById('edit_calling_name').value = driverData.calling_name;
    document.getElementById('edit_full_name').value = driverData.full_name;
    document.getElementById('edit_phone_no').value = driverData.phone_no;
    document.getElementById('edit_license_expiry_date').value = driverData.license_expiry_date;

    const vehicleSelect = document.getElementById('edit_vehicle_no');

    // Check if the assigned vehicle option already exists
    const assignedVehicleExists = Array.from(vehicleSelect.options).some(option => option.value === driverData.vehicle_no);

    // If a vehicle is assigned and the option doesn't exist, dynamically add it
    if (driverData.vehicle_no && !assignedVehicleExists) {
        const newOption = document.createElement('option');
        newOption.value = driverData.vehicle_no;
        newOption.textContent = driverData.vehicle_no + " (Currently Assigned)";
        
        // Insert the new option right after the "Unassign" option
        vehicleSelect.insertBefore(newOption, vehicleSelect.options[1]);
    }

    // Set the selected value. This will work for both existing and newly added options.
    vehicleSelect.value = driverData.vehicle_no || '';

    editModal.style.display = 'flex';
}

// Function to handle form submission for editing
function handleEditSubmit(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    fetch('driver.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        showToast(data.message, data.status);
        if (data.status === 'success') {
            closeModal('editModal');
            setTimeout(() => location.reload(), 1000);
        }
    })
    .catch(error => {
        showToast('An error occurred.', 'error');
        console.error('Error:', error);
    });
}

// Function to handle status toggling
function confirmToggleStatus(driverNic, newStatus) {
    if (confirm(`Are you sure you want to ${newStatus === 1 ? 'enable' : 'disable'} this driver?`)) {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('driver_NIC', driverNic);
        formData.append('is_active', newStatus);

        fetch('driver.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showToast(data.message, data.status);
            if (data.status === 'success') {
                setTimeout(() => location.reload(), 1000);
            }
        })
        .catch(error => {
            showToast('An error occurred.', 'error');
            console.error('Error:', error);
        });
    }
}

// Function to filter drivers by status
function filterStatus(status) {
    const url = new URL(window.location.href);
    url.searchParams.set('status', status);
    window.location.href = url.toString();
}