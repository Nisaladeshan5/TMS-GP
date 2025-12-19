$(document).ready(function() {
    
    // --- ELEMENT DECLARATIONS ---
    const routeSelect = $('#route_code');
    const opSelect = $('#op_code');
    const supplierSelect = $('#supplier_code');
    const vehicleInput = $('#vehicle_no');
    const distanceInput = $('#distance');
    const amountInput = $('#amount'); // Always readonly now
    const statusSpan = $('#supplier_loading_status');
    const amountStatusSpan = $('#amount_status'); 
    const acStatusSelect = $('#ac_status'); 
    
    // Get the hidden supplier input
    const hiddenSupplierInput = $('#hidden_supplier_code');

    // Global variables to store the current rate logic
    let currentRate = 0;
    let currentCodeType = '';

    // --- HELPER FUNCTIONS ---

    function clearDetails() {
        // Clear/re-enable the supplier and amount logic
        supplierSelect.removeClass('bg-gray-200').val(''); 
        hiddenSupplierInput.val('');
        amountInput.val('0.00'); // Keep amount at 0.00
        amountStatusSpan.text('');
        statusSpan.addClass('hidden').removeClass('text-green-500 text-red-500');
        currentRate = 0;
        currentCodeType = '';
    }

    // *** CALCULATE AMOUNT FUNCTION ***
    function calculateAmount() {
        const distance = parseFloat(distanceInput.val()) || 0;
        let calculatedAmount = 0;

        amountStatusSpan.text('');
        amountInput.val('0.00'); // Default to 0.00

        if (currentCodeType) { 
            if (currentRate > 0) {
                
                if (distance > 0) {
                    calculatedAmount = distance * currentRate;
                    amountInput.val(calculatedAmount.toFixed(2));
                    
                    let rateContext = '';
                    
                    // Logic to display A/C status based on 1 or 0 value
                    let acStatusDisplay = '';
                    if (acStatusSelect.val() === '1') {
                        acStatusDisplay = ' (A/C)';
                    } else if (acStatusSelect.val() === '0') {
                        acStatusDisplay = ' (Non A/C)';
                    }
                    
                    if (currentCodeType === 'Route') {
                        rateContext = `(Route Base Rate)`;
                    } else if (currentCodeType === 'Operation') {
                         rateContext = acStatusDisplay ? `(Op Rate - ${acStatusDisplay.replace(/[\(\)]/g, '')})` : `(Op Rate)`;
                    }

                    amountStatusSpan.text(`Calculated: ${distance} Km x ${currentRate.toFixed(2)} Rate ${rateContext}`).removeClass('text-red-500').addClass('text-green-600');
                } else {
                    amountInput.val('0.00'); 
                    let acStatusDisplay = '';
                    if (acStatusSelect.val() === '1') {
                        acStatusDisplay = ' (A/C)';
                    } else if (acStatusSelect.val() === '0') {
                        acStatusDisplay = ' (Non A/C)';
                    }
                    
                    let rateContext = (currentCodeType === 'Operation' && acStatusSelect.val() !== '') ? acStatusDisplay : '';
                    amountStatusSpan.text(`Rate: ${currentRate.toFixed(2)} LKR/Km ${rateContext}. Enter distance for calculation.`).removeClass('text-red-500').addClass('text-gray-500');
                }

            } else {
                amountInput.val('0.00');
                amountStatusSpan.text('No rate found for this selection. Amount set to 0.00.').addClass('text-red-500');
            }
        } else {
            amountInput.val('0.00');
            amountStatusSpan.text('No code selected. Amount set to 0.00.').removeClass('text-red-500').addClass('text-gray-500');
        }
    }


    // 1. Lookup Supplier/Vehicle Details (Fix implemented here)
    function lookupDetails(codeType, tripCode) {
        
        if (!tripCode) {
            clearDetails();
            return;
        }
        
        statusSpan.removeClass('hidden').removeClass('text-green-500').addClass('text-red-500').text('Searching for Supplier and Vehicle...');
        
        // *** FIX: Make the visible select look disabled (add class), but KEEP IT ENABLED (no prop('disabled', true)) ***
        supplierSelect.addClass('bg-gray-200'); 

        $.ajax({
            url: 'fetch_details.php', 
            method: 'GET',
            data: { code_type: codeType, trip_code: tripCode },
            dataType: 'json',
            success: function(response) {
                statusSpan.addClass('hidden');
                
                if (response.success) {
                    // 1. Update the visible select box (only for display)
                    supplierSelect.val(response.supplier_code);
                    
                    // 2. *** FIX: Update the HIDDEN input with the required value for submission ***
                    hiddenSupplierInput.val(response.supplier_code);
                    
                    vehicleInput.val(response.vehicle_no || ''); 
                    
                    if (supplierSelect.val()) {
                        statusSpan.removeClass('text-red-500').addClass('text-green-500').text('Details auto-filled! (Vehicle No is editable)').removeClass('hidden');
                    }
                } else {
                    statusSpan.removeClass('text-green-500').addClass('text-red-500').text(response.message || 'Code not found.').removeClass('hidden');
                    
                    // If failed, clear fields
                    supplierSelect.val('').removeClass('bg-gray-200'); 
                    hiddenSupplierInput.val('');
                }
            },
            error: function() {
                statusSpan.removeClass('hidden').removeClass('text-green-500').addClass('text-red-500').text('Error fetching details from server.');
                supplierSelect.removeClass('bg-gray-200'); 
                hiddenSupplierInput.val('');
            }
        });
    }

    // 2. Lookup Rate
    function lookupRate(codeType, tripCode) {
        // acStatus will be '1', '0', or ''
        const acStatus = acStatusSelect.val(); 
        
        if (!tripCode || (codeType === 'Operation' && acStatus === '')) { 
             currentRate = 0;
             currentCodeType = '';
             calculateAmount();
             
             if (codeType === 'Operation' && acStatus === '') {
                 amountStatusSpan.text('Please select A/C Status to determine the Operation rate.').addClass('text-red-500');
             }
             return;
        }

        currentCodeType = codeType;
        
        $.ajax({
            url: 'fetch_rate.php', 
            method: 'GET',
            data: { 
                code_type: codeType, 
                trip_code: tripCode, 
                ac_status: acStatus 
            }, 
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    currentRate = response.rate; 
                } else {
                    currentRate = 0;
                }
                calculateAmount(); 
            },
            error: function() {
                currentRate = 0;
                calculateAmount(); 
            }
        });
    }

    // --- EVENT HANDLERS ---
    routeSelect.on('change', function() {
        const selectedCode = $(this).val();
        opSelect.prop('disabled', !!selectedCode).val('');
        opSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');

        lookupDetails('Route', selectedCode);
        lookupRate('Route', selectedCode);
    });

    opSelect.on('change', function() {
        const selectedCode = $(this).val();
        routeSelect.prop('disabled', !!selectedCode).val('');
        routeSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');

        lookupDetails('Operation', selectedCode);
        lookupRate('Operation', selectedCode);
    });
    
    distanceInput.on('input', function() {
        calculateAmount();
    });
    
    acStatusSelect.on('change', function() {
        const activeCode = routeSelect.val() || opSelect.val();
        const codeType = routeSelect.val() ? 'Route' : (opSelect.val() ? 'Operation' : '');
        
        if (activeCode && codeType) {
            lookupRate(codeType, activeCode);
        } else {
            calculateAmount();
        }
    });


    // Initial calculation check
    calculateAmount(); 

    // --- EMPLOYEE/REASON GROUP LOGIC ---
    var groupContainer = $('#reason-group-container');
    
    function updateGroupIndices() {
        groupContainer.find('.reason-group').each(function(index) {
            const groupDiv = $(this);
            groupDiv.find('h4').text(`Reason Group ${index + 1}`);
            // Update the name attribute for the whole group index
            groupDiv.find('.reason-select').attr('name', `reason_group[${index}]`);
            groupDiv.find('.emp-id-input').attr('name', `emp_id_group[${index}][]`);
            groupDiv.find('.remove-group-btn').prop('disabled', index === 0);
            
            // Update placeholder text for employee inputs
            groupDiv.find('.employee-input').each(function(emp_index) {
                $(this).find('.emp-id-input').attr('placeholder', `Employee ID ${emp_index + 1} (e.g., SL001)`);
            });
        });
    }

    function updateEmployeeRemoveButtons(groupDiv) {
        const inputs = groupDiv.find('.employee-input');
        inputs.find('.remove-employee-btn').prop('disabled', inputs.length === 1);
    }

    updateGroupIndices();
    updateEmployeeRemoveButtons(groupContainer.find('.reason-group').first());
    
    $('#add-reason-group-btn').on('click', function() {
        // Clone the first group structure
        const newGroup = groupContainer.find('.reason-group').first().clone(true, true);
        
        // Clear all input values
        newGroup.find('select').val('');
        newGroup.find('.emp-id-input').val('');
        
        // Remove extra employee fields, keeping only the first one
        newGroup.find('.employee-input:not(:first)').remove();
        
        groupContainer.append(newGroup);
        updateGroupIndices();
        updateEmployeeRemoveButtons(newGroup);
    });

    groupContainer.on('click', '.remove-group-btn', function() {
        if (groupContainer.find('.reason-group').length > 1) {
            $(this).closest('.reason-group').remove();
            updateGroupIndices();
        }
    });
    
    groupContainer.on('click', '.add-employee-btn-group', function() {
        const currentGroup = $(this).closest('.reason-group');
        const container = currentGroup.find('.employee-list-container');
        
        // Clone the structure of the last employee input in that group
        const lastEmployeeInput = container.find('.employee-input').last();
        const newEmployeeInput = lastEmployeeInput.clone(true, true);
        
        newEmployeeInput.find('.emp-id-input').val('').prop('required', true);
        container.append(newEmployeeInput);
        
        // We only need to update the indices/placeholders for this group
        updateGroupIndices();
        updateEmployeeRemoveButtons(currentGroup);
    });
    
    groupContainer.on('click', '.remove-employee-btn', function() {
        const currentGroup = $(this).closest('.reason-group');
        const container = currentGroup.find('.employee-list-container');

        if (container.find('.employee-input').length > 1) {
            $(this).closest('.employee-input').remove();
            
            // We only need to update the indices/placeholders for this group
            updateGroupIndices();
            updateEmployeeRemoveButtons(currentGroup);
        }
    });
});