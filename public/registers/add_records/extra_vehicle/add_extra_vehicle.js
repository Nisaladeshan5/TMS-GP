$(document).ready(function() {
    
    // --- ELEMENT DECLARATIONS ---
    const routeSelect = $('#route_code');
    const opSelect = $('#op_code');
    const supplierSelect = $('#supplier_code');
    const vehicleInput = $('#vehicle_no');
    const distanceInput = $('#distance');
    const statusSpan = $('#supplier_loading_status');
    const acStatusSelect = $('#ac_status'); 
    
    // Get the hidden supplier input
    const hiddenSupplierInput = $('#hidden_supplier_code');

    // --- HELPER FUNCTIONS ---

    function clearDetails() {
        supplierSelect.removeClass('bg-gray-200').val(''); 
        hiddenSupplierInput.val('');
        statusSpan.addClass('hidden').removeClass('text-green-500 text-red-500');
    }

    // 1. Lookup Supplier/Vehicle Details
    function lookupDetails(codeType, tripCode) {
        
        if (!tripCode) {
            clearDetails();
            return;
        }
        
        statusSpan.removeClass('hidden').removeClass('text-green-500').addClass('text-red-500').text('Searching...');
        supplierSelect.addClass('bg-gray-200'); 

        $.ajax({
            url: 'fetch_details.php', 
            method: 'GET',
            data: { code_type: codeType, trip_code: tripCode },
            dataType: 'json',
            success: function(response) {
                statusSpan.addClass('hidden');
                
                if (response.success) {
                    supplierSelect.val(response.supplier_code);
                    hiddenSupplierInput.val(response.supplier_code);
                    vehicleInput.val(response.vehicle_no || ''); 
                    
                    if (supplierSelect.val()) {
                        statusSpan.removeClass('text-red-500').addClass('text-green-500').text('Found!').removeClass('hidden');
                    }
                } else {
                    statusSpan.removeClass('text-green-500').addClass('text-red-500').text('Not found').removeClass('hidden');
                    supplierSelect.val('').removeClass('bg-gray-200'); 
                    hiddenSupplierInput.val('');
                }
            },
            error: function() {
                statusSpan.removeClass('hidden').addClass('text-red-500').text('Error.');
                supplierSelect.removeClass('bg-gray-200'); 
                hiddenSupplierInput.val('');
            }
        });
    }

    // --- EVENT HANDLERS ---
    routeSelect.on('change', function() {
        const selectedCode = $(this).val();
        opSelect.prop('disabled', !!selectedCode).val('');
        opSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');
        lookupDetails('Route', selectedCode);
    });

    opSelect.on('change', function() {
        const selectedCode = $(this).val();
        routeSelect.prop('disabled', !!selectedCode).val('');
        routeSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');
        lookupDetails('Operation', selectedCode);
    });
    

    // ======================================================
    //  EMPLOYEE & REASON GROUP LOGIC (UPDATED)
    // ======================================================
    
    // --- 1. ADD NEW REASON GROUP ---
    $('#add-reason-group-btn').click(function () {
        // Clone the first group
        var $template = $('.reason-group').first();
        var $newGroup = $template.clone();

        // Clear Inputs & Selects
        $newGroup.find('input').val(''); 
        $newGroup.find('select').val(''); 

        // Enable Remove Button
        $newGroup.find('.remove-group-btn').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');

        // Reset Employee List (Keep only one input)
        var $empContainer = $newGroup.find('.employee-list-container');
        var $firstEmpInput = $empContainer.find('.employee-input').first();
        
        // Remove all employee inputs except the first one logic
        $empContainer.empty();
        $firstEmpInput.find('input').val(''); // Clear value
        $firstEmpInput.find('.remove-employee-btn').prop('disabled', true).addClass('opacity-50'); // Disable remove for first item
        $empContainer.append($firstEmpInput);

        // Append to Container
        $('#reason-group-container').append($newGroup);
        
        // Update Titles & Indices
        updateGroupTitles();
    });

    // --- 2. REMOVE REASON GROUP ---
    $(document).on('click', '.remove-group-btn', function () {
        if ($('.reason-group').length > 1) {
            $(this).closest('.reason-group').remove();
            updateGroupTitles(); 
        } else {
            alert("At least one reason group is required.");
        }
    });

    // --- 3. ADD EMPLOYEE INPUT (Within a Group) ---
    $(document).on('click', '.add-employee-btn-group', function () {
        var $container = $(this).closest('.reason-group').find('.employee-list-container');
        var $templateInput = $container.find('.employee-input').first().clone();

        // Clear Value
        $templateInput.find('input').val('');
        
        // Enable Remove Button
        $templateInput.find('.remove-employee-btn').prop('disabled', false).removeClass('opacity-50');

        $container.append($templateInput);
        
        // Re-check remove buttons state
        updateEmployeeRemoveState($container);
    });

    // --- 4. REMOVE EMPLOYEE INPUT ---
    $(document).on('click', '.remove-employee-btn', function () {
        var $container = $(this).closest('.employee-list-container');
        
        if ($container.find('.employee-input').length > 1) {
            $(this).closest('.employee-input').remove();
            updateEmployeeRemoveState($container);
        } else {
            // If it's the last one, just clear it
            $(this).closest('.employee-input').find('input').val('');
        }
    });

    // --- HELPER: Update Group Titles & Indices ---
    function updateGroupTitles() {
        $('.reason-group').each(function (index) {
            // Update Title
            $(this).find('h4').text('Group ' + (index + 1));
            
            // Update PHP Array Index: emp_id_group[0][], emp_id_group[1][]...
            $(this).find('input[name^="emp_id_group"]').attr('name', 'emp_id_group[' + index + '][]');

            // Disable Remove Button for First Group
            if (index === 0) {
                $(this).find('.remove-group-btn').prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
            } else {
                $(this).find('.remove-group-btn').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
            }
        });
    }

    // --- HELPER: Update Employee Remove Buttons State ---
    function updateEmployeeRemoveState($container) {
        var inputs = $container.find('.employee-input');
        if (inputs.length === 1) {
            inputs.find('.remove-employee-btn').prop('disabled', true).addClass('opacity-50');
        } else {
            inputs.find('.remove-employee-btn').prop('disabled', false).removeClass('opacity-50');
        }
    }

    // Initialize on load
    updateGroupTitles();
});

// Route / Op Code Toggle Logic
function toggleCodeSelection(type) {
    if (type === 'route') {
        $('#op_code').val(""); 
    } else {
        $('#route_code').val("");
    }
}