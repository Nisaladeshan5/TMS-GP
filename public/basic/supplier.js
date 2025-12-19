function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="toast-icon">
                ${type === 'success'
                    ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                    : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.02 3.377 1.77 3.377h14.464c1.75 0 2.636-1.877 1.77-3.377L13.523 5.373a1.75 1.75 0 00-3.046 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />'
                }
            </svg>
            <span>${message}</span>
        `;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 1300);
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    function filterStatus(status) {
        window.location.href = `suppliers.php?status=${status}`;
    }

    function confirmToggleStatus(supplierCode, newStatus) {
        if (confirm(`Are you sure you want to ${newStatus == 1 ? 'enable' : 'disable'} this supplier?`)) {
            toggleStatus(supplierCode, newStatus);
        }
    }

    async function toggleStatus(supplierCode, newStatus) {
        try {
            const response = await fetch('suppliers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=toggle_status&supplier_code=${encodeURIComponent(supplierCode)}&is_active=${newStatus}`
            });
            const result = await response.json();
            if (result.status === 'success') {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast('An unexpected error occurred.', 'error');
        }
    }

    let currentStep = 0;
    const totalSteps = 2; // For both edit and view modals

    function showStep(modalType, step) {
        const steps = document.querySelectorAll(`.${modalType}-step`);
        steps.forEach(s => s.style.display = 'none');
        if (steps[step]) {
            steps[step].style.display = 'block';
        }

        // Call the new helper function to update buttons
        updateButtons(modalType, step, totalSteps);

        currentStep = step;
    }

    function showNextStep(modalType) {
        if (currentStep < totalSteps - 1) {
            showStep(modalType, currentStep + 1);
        }
    }

    function showPrevStep(modalType) {
        if (currentStep > 0) {
            showStep(modalType, currentStep - 1);
        }
    }

    function updateButtons(modalType, currentStep, totalSteps) {
        const modalId = modalType === 'edit' ? 'editModal' : 'viewModal';
        const modal = document.getElementById(modalId);
        if (!modal) return; // Guard clause in case the modal isn't found

        const backButton = modal.querySelector(`#${modalType}BackButton`);
        const nextButton = modal.querySelector(`#${modalType}NextButton`);
        const saveButton = modal.querySelector(`#${modalType}SaveChangesButton`);
        const closeViewButton = modal.querySelector('#closeViewButton');
        const buttonContainer = modal.querySelector('.flex.justify-between, .flex.justify-end');

        if (backButton) {
            backButton.style.display = currentStep > 0 ? 'block' : 'none';
        }

        if (modalType === 'edit') {
            if (nextButton) nextButton.style.display = (currentStep < totalSteps - 1) ? 'block' : 'none';
            if (saveButton) saveButton.style.display = (currentStep === totalSteps - 1) ? 'block' : 'none';
        }

        if (modalType === 'view') {
            if (nextButton) nextButton.style.display = (currentStep < totalSteps - 1) ? 'block' : 'none';
            if (closeViewButton) closeViewButton.style.display = (currentStep === totalSteps - 1) ? 'block' : 'none';
        }

        if (buttonContainer) {
            if (currentStep === 0) {
                buttonContainer.classList.remove('justify-between');
                buttonContainer.classList.add('justify-end');
            } else {
                buttonContainer.classList.remove('justify-end');
                buttonContainer.classList.add('justify-between');
            }
        }
    }

    async function openEditModal(supplierCode) {
        try {
            const response = await fetch(`suppliers.php?view_supplier_code=${encodeURIComponent(supplierCode)}`);
            const supplier = await response.json();
            
            if (supplier) {
                document.getElementById('edit_supplier_code').value = supplier.supplier_code;
                document.getElementById('editSupplierCodeTitle').innerText = supplier.supplier_code;
                document.getElementById('edit_supplier').value = supplier.supplier;
                document.getElementById('edit_s_phone_no').value = supplier.s_phone_no;
                document.getElementById('edit_email').value = supplier.email;
                document.getElementById('edit_beneficiaress_name').value = supplier.beneficiaress_name;
                document.getElementById('edit_bank').value = supplier.bank;
                document.getElementById('edit_bank_code').value = supplier.bank_code;
                document.getElementById('edit_branch').value = supplier.branch;
                document.getElementById('edit_branch_code').value = supplier.branch_code;
                document.getElementById('edit_acc_no').value = supplier.acc_no;
                document.getElementById('edit_swift_code').value = supplier.swift_code;
                document.getElementById('edit_acc_currency_type').value = supplier.acc_currency_type;

                showStep('edit', 0);
                document.getElementById('editModal').style.display = 'flex';
            } else {
                showToast('Supplier not found.', 'error');
            }
        } catch (error) {
            showToast('Failed to fetch supplier data.', 'error');
        }
    }

    async function viewSupplierDetails(supplierCode) {
        try {
            const response = await fetch(`suppliers.php?view_supplier_code=${encodeURIComponent(supplierCode)}`);
            const supplier = await response.json();
            
            if (supplier) {
                document.getElementById('viewSupplierCode').innerText = supplier.supplier_code;
                document.getElementById('viewSupplier').innerText = supplier.supplier;
                document.getElementById('viewSPhoneNo').innerText = supplier.s_phone_no;
                document.getElementById('viewEmail').innerText = supplier.email;
                document.getElementById('viewBeneficiaressName').innerText = supplier.beneficiaress_name;
                document.getElementById('viewBank').innerText = supplier.bank;
                document.getElementById('viewBankCode').innerText = supplier.bank_code;
                document.getElementById('viewBranch').innerText = supplier.branch;
                document.getElementById('viewBranchCode').innerText = supplier.branch_code;
                document.getElementById('viewAccNo').innerText = supplier.acc_no;
                document.getElementById('viewSwiftCode').innerText = supplier.swift_code;
                document.getElementById('viewAccCurrencyType').innerText = supplier.acc_currency_type;

                showStep('view', 0);
                document.getElementById('viewModal').style.display = 'flex';
            } else {
                showToast('Supplier not found.', 'error');
            }
        } catch (error) {
            showToast('Failed to fetch supplier data.', 'error');
        }
    }

    async function handleEditSubmit(event) {
        event.preventDefault();

        const form = document.getElementById('editForm');
        const formData = new FormData(form);

        try {
            const response = await fetch('suppliers.php', {
                method: 'POST',
                body: new URLSearchParams(formData).toString(),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            });

            const result = await response.json();
            if (result.status === 'success') {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast('An unexpected error occurred.', 'error');
        }
    }