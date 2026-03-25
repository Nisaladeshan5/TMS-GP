$(document).ready(function() {
    
    // --- ELEMENT DECLARATIONS ---
    const routeSelect = $('#route_code');
    const subRouteSelect = $('#sub_route_code'); // අලුතින් එකතු කළා
    const opSelect = $('#op_code');
    const supplierSelect = $('#supplier_code');
    const vehicleInput = $('#vehicle_no');
    const statusSpan = $('#supplier_loading_status');
    const hiddenSupplierInput = $('#hidden_supplier_code');

    // --- HELPER FUNCTIONS ---

    function clearDetails() {
        supplierSelect.val('').removeClass('bg-gray-200'); 
        hiddenSupplierInput.val('');
        vehicleInput.val(''); 
        statusSpan.addClass('hidden').removeClass('text-green-500 text-red-500');
    }

    // 1. Lookup Supplier/Vehicle Details based on Selected Code
    function lookupDetails(codeType, tripCode) {
        if (!tripCode) {
            clearDetails();
            return;
        }
        
        statusSpan.removeClass('hidden text-green-500').addClass('text-red-500').text('Searching...');
        supplierSelect.addClass('bg-gray-200'); 

        $.ajax({
            url: 'fetch_details.php', 
            method: 'GET',
            data: { code_type: codeType, trip_code: tripCode },
            dataType: 'json',
            success: function(response) {
                statusSpan.addClass('hidden');
                
                if (response.success) {
                    // Supplier set කිරීම (Dropdown & Hidden input)
                    supplierSelect.val(response.supplier_code);
                    hiddenSupplierInput.val(response.supplier_code);
                    
                    // Vehicle No set කිරීම
                    vehicleInput.val(response.vehicle_no || ''); 
                    
                    if (response.supplier_code) {
                        statusSpan.removeClass('text-red-500').addClass('text-green-500').text('Found!').removeClass('hidden');
                    }
                } else {
                    statusSpan.removeClass('text-green-500').addClass('text-red-500').text('Not found').removeClass('hidden');
                    supplierSelect.val('').removeClass('bg-gray-200'); 
                    hiddenSupplierInput.val('');
                }
            },
            error: function() {
                statusSpan.removeClass('hidden').addClass('text-red-500').text('Error checking code.');
                supplierSelect.removeClass('bg-gray-200'); 
            }
        });
    }

    // --- EVENT HANDLERS ---
    
    // Route Selection Change
    routeSelect.on('change', function() {
        const selectedCode = $(this).val();
        
        // අනිත් ඒවා Disable කර හිස් කරයි
        opSelect.prop('disabled', !!selectedCode).val('');
        subRouteSelect.prop('disabled', !!selectedCode).val(''); // අලුතින් එකතු කළා
        
        opSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');
        subRouteSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff'); // අලුතින් එකතු කළා
        
        // Route එකට අදාළ දත්ත සොයයි
        lookupDetails('Route', selectedCode);
    });

    // Sub Route Selection Change (NEW)
    subRouteSelect.on('change', function() {
        const selectedCode = $(this).val();
        
        // අනිත් ඒවා Disable කර හිස් කරයි
        opSelect.prop('disabled', !!selectedCode).val('');
        routeSelect.prop('disabled', !!selectedCode).val('');
        
        opSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');
        routeSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');
        
        // Sub Route එකට අදාළ දත්ත සොයයි (Sub_Route යන්න fetch_details.php එකට යවයි)
        lookupDetails('Sub_Route', selectedCode);
    });

    // Operation Code Selection Change
    opSelect.on('change', function() {
        const selectedCode = $(this).val();
        
        // අනිත් ඒවා Disable කර හිස් කරයි
        routeSelect.prop('disabled', !!selectedCode).val('');
        subRouteSelect.prop('disabled', !!selectedCode).val(''); // අලුතින් එකතු කළා
        
        routeSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff');
        subRouteSelect.css('background-color', selectedCode ? '#e0e0e0' : '#ffffff'); // අලුතින් එකතු කළා
        
        // Operation එකට අදාළ දත්ත සොයයි
        lookupDetails('Operation', selectedCode);
    });

    // ======================================================
    // EMPLOYEE & REASON GROUP LOGIC 
    // ======================================================
    
    $('#add-reason-group-btn').click(function () {
        var $template = $('.reason-group').first();
        var $newGroup = $template.clone();

        $newGroup.find('input').val(''); 
        $newGroup.find('select').val(''); 
        $newGroup.find('.remove-group-btn').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');

        var $empContainer = $newGroup.find('.employee-list-container');
        var $firstEmpInput = $empContainer.find('.employee-input').first();
        
        $empContainer.empty();
        $firstEmpInput.find('input').val(''); 
        $firstEmpInput.find('.remove-employee-btn').prop('disabled', true).addClass('opacity-50'); 
        $empContainer.append($firstEmpInput);

        $('#reason-group-container').append($newGroup);
        updateGroupTitles();
    });

    $(document).on('click', '.remove-group-btn', function () {
        if ($('.reason-group').length > 1) {
            $(this).closest('.reason-group').remove();
            updateGroupTitles(); 
        }
    });

    $(document).on('click', '.add-employee-btn-group', function () {
        var $container = $(this).closest('.reason-group').find('.employee-list-container');
        var $templateInput = $container.find('.employee-input').first().clone();
        $templateInput.find('input').val('');
        $templateInput.find('.remove-employee-btn').prop('disabled', false).removeClass('opacity-50');
        $container.append($templateInput);
        updateEmployeeRemoveState($container);
    });

    $(document).on('click', '.remove-employee-btn', function () {
        var $container = $(this).closest('.employee-list-container');
        if ($container.find('.employee-input').length > 1) {
            $(this).closest('.employee-input').remove();
            updateEmployeeRemoveState($container);
        } else {
            $(this).closest('.employee-input').find('input').val('');
        }
    });

    function updateGroupTitles() {
        $('.reason-group').each(function (index) {
            $(this).find('h4').text('Group ' + (index + 1));
            $(this).find('input[name^="emp_id_group"]').attr('name', 'emp_id_group[' + index + '][]');
            if (index === 0) {
                $(this).find('.remove-group-btn').prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
            } else {
                $(this).find('.remove-group-btn').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
            }
        });
    }

    function updateEmployeeRemoveState($container) {
        var inputs = $container.find('.employee-input');
        if (inputs.length === 1) {
            inputs.find('.remove-employee-btn').prop('disabled', true).addClass('opacity-50');
        } else {
            inputs.find('.remove-employee-btn').prop('disabled', false).removeClass('opacity-50');
        }
    }

    updateGroupTitles();
});

// PHP inline onchange එක සඳහා පවතින function එක
function toggleCodeSelection(type) {
    if (type === 'route') {
        $('#op_code').val(""); 
        $('#sub_route_code').val(""); 
    } else if (type === 'sub_route') {
        $('#op_code').val(""); 
        $('#route_code').val(""); 
    } else {
        $('#route_code').val("");
        $('#sub_route_code').val("");
    }
}