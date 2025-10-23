
function showToast(status, message) {
    // Basic showToast implementation (ensure your full implementation is here)
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${status} flex items-center p-4 mb-2 rounded-lg shadow-lg`;
    toast.innerHTML = `<div class="font-medium">${message}</div>`;
    container.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

document.addEventListener('DOMContentLoaded', function() {
    const clearBtn = document.getElementById('clearFiltersBtn');
    const form = document.querySelector('form[method="GET"]'); // Assuming the filter form is the only GET form

    if (clearBtn && form) {
        clearBtn.addEventListener('click', function() {
            // 1. Clear all input fields in the filter form
            form.querySelectorAll('input[type="text"], select').forEach(element => {
                // Set text inputs to empty string
                if (element.tagName === 'INPUT') {
                    element.value = '';
                }
                // Set selects to the default/first option (which should be "-- All --")
                else if (element.tagName === 'SELECT') {
                    element.value = ''; 
                }
            });
            
            // 2. Clear URL query parameters (Optional, but gives a cleaner look)
            window.history.pushState({}, '', window.location.pathname);

            // 3. If you were using AJAX for filtering, you would call the data loading function here:
            // loadEmployeeData(); 

            // SINCE YOU ARE CURRENTLY USING PURE PHP:
            // The simplest "no reload" clear with your existing PHP logic
            // is to simulate a click on the submit button after clearing.
            // However, this will still submit the form and cause a reload.

            // To truly clear without a reload, you *must* implement AJAX for the form submission.
            // Until then, the original anchor tag is the quickest solution for pure PHP.
        });
    }
});