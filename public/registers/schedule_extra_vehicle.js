$(document).ready(function() {
    var inModal = $('#inModal');
    var employeeModal = $('#employeeModal');
    var cancelModal = $('#cancelModal'); // New modal selector
    var ajaxHandlerUrl = 'schedule_extra_vehicle.php'; 

    // Helper function to close the NEW custom modal
    function closeCancelModal() {
        // 1. Trigger the native JS event to run the Tailwind transition (scale-100 -> scale-95)
        // This event is listened to by the inline script in your PHP file.
        cancelModal.get(0).dispatchEvent(new CustomEvent('modal:close')); 

        setTimeout(function() {
            // 2. After transition, remove display classes
            cancelModal.removeClass('modal-active').addClass('modal-inactive');
            // 3. Reset button and message
            $('#cancel-yes-btn').prop('disabled', false).text('ඔව්, මකන්න (Yes, Delete)');
            $('#cancel-message').empty().addClass('hidden');
        }, 300); // Wait for transition duration
    }
    
    // Helper function to open the NEW custom modal
    function openCancelModal(tripId) {
        $('#cancel-trip-id-holder').val(tripId);
        cancelModal.removeClass('modal-inactive').addClass('modal-active');
        // 1. Trigger the native JS event to run the Tailwind transition (scale-95 -> scale-100)
        cancelModal.get(0).dispatchEvent(new CustomEvent('modal:open')); 
    }
    
    // --- 1. Report/Out Button Click Handler (Same Script) ---
    $('.action-btn').on('click', function() {
        var button = $(this);
        if (button.prop('disabled')) return; 

        var tripId = button.data('trip-id');
        var action = button.data('action');
        
        button.prop('disabled', true).addClass('bg-gray-400').removeClass('btn-yellow bg-blue-600 hover:bg-yellow-600 hover:bg-blue-700');
        button.find('.btn-text').text('Wait...');

        $.ajax({
            url: ajaxHandlerUrl,
            type: 'POST',
            data: { action: action, trip_id: tripId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var timeDisplay = button.find('.time-display');
                    timeDisplay.text(response.time);
                    button.find('.btn-text').text(''); 

                    var row = $('#row-' + tripId);
                    
                    if (action === 'report') {
                        var outButton = row.find('.action-btn[data-action="out"]');
                        outButton.prop('disabled', false).removeClass('bg-gray-300 text-gray-600 cursor-not-allowed').addClass('bg-blue-600 hover:bg-blue-700 text-white');
                    } else if (action === 'out') {
                        var inButton = row.find('.action-in-btn[data-action="in"]');
                        inButton.prop('disabled', false).removeClass('bg-gray-300 text-gray-600 cursor-not-allowed').addClass('btn-green hover:bg-green-700 text-white');
                    }
                    
                    button.removeClass('bg-gray-400').addClass('bg-gray-300 text-gray-600 cursor-not-allowed');

                } else {
                    alert('Error recording ' + action + ' time: ' + response.message);
                    button.prop('disabled', false).removeClass('bg-gray-400');
                    if (action === 'report') {
                        button.addClass('btn-yellow hover:bg-yellow-600 text-white');
                        button.find('.btn-text').text('Report');
                    } else if (action === 'out') {
                        button.addClass('bg-blue-600 hover:bg-blue-700 text-white');
                        button.find('.btn-text').text('Out');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error:", status, error, xhr.responseText);
                
                if (status === 'parsererror') {
                     alert('Error: Data corruption occurred. HTML was sent before the JSON response. Check your PHP includes.');
                } else {
                     alert('A network error occurred: ' + status + '. Check console for details.');
                }
                
                button.prop('disabled', false).removeClass('bg-gray-400');
                if (action === 'report') {
                    button.addClass('btn-yellow hover:bg-yellow-600 text-white');
                    button.find('.btn-text').text('Report');
                } else if (action === 'out') {
                    button.addClass('bg-blue-600 hover:bg-blue-700 text-white');
                    button.find('.btn-text').text('Out');
                }
            }
        });
    });

    // --- 2. Open 'View Employees' Modal Handler (Existing) ---
    $('.action-view-btn').on('click', function() {
        var button = $(this);
        var tripId = button.data('trip-id');
        var employeeListDiv = $('#employee-list-content');
        
        employeeListDiv.html('<p class="text-blue-500 text-center font-medium">Loading employee data...</p>');
        employeeModal.removeClass('modal-inactive').addClass('modal-active');

        $.ajax({
            url: ajaxHandlerUrl,
            type: 'POST',
            data: { action: 'view_employees', trip_id: tripId },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.employees.length > 0) {
                    var html = '<ul class="divide-y divide-gray-100">';
                    $.each(response.employees, function(i, emp) {
                        html += '<li class="py-2 flex justify-between items-center"><span class="font-semibold text-gray-700">' + emp.calling_name + '</span><span class="text-sm text-gray-500">(' + emp.emp_id + ')</span></li>';
                    });
                    html += '</ul>';
                    employeeListDiv.html(html);
                } else if (response.success && response.employees.length === 0) {
                    employeeListDiv.html('<p class="text-orange-500 text-center font-medium">No employees registered for this trip.</p>');
                } else {
                    employeeListDiv.html('<p class="text-red-500 text-center font-medium">Error: ' + response.message + '</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error("View Employees Error:", status, error, xhr.responseText);
                employeeListDiv.html('<p class="text-red-500 text-center font-medium">A network or server error occurred.</p>');
            }
        });
    });

    // --- 3. Open 'In' Modal Handler (Existing) ---
    $('.action-in-btn').on('click', function() {
        var button = $(this);
        if (button.prop('disabled')) return; 

        var tripId = button.data('trip-id');
        var row = $('#row-' + tripId);
        var routeCode = row.data('route');
        var initialAcStatus = row.data('ac-status');
        
        $('#modal-trip-id').val(tripId);
        $('#modal-route-code').val(routeCode);
        $('#distance').val('');
        $('#in-message').html('');
        $('#in-submit-btn').prop('disabled', false).text('Confirm Arrival');

        if (initialAcStatus == 1 || initialAcStatus == 0) {
            $(`input[name="ac_status"][value="${initialAcStatus}"]`).prop('checked', true);
        } else {
            $('input[name="ac_status"]').prop('checked', false);
        }

        inModal.removeClass('modal-inactive').addClass('modal-active');
    });

    // --- 4. Close Modal functionality (Updated for new Cancel modal) ---
    $('.modal-close, .modal-overlay, .modal-close-in').on('click', function() {
        inModal.removeClass('modal-active').addClass('modal-inactive');
    });

    $('.modal-close-emp, #employeeModal .modal-overlay').on('click', function() {
        employeeModal.removeClass('modal-active').addClass('modal-inactive');
    });
    
    // Handle closing the NEW custom cancel modal
    $('#cancel-no-btn, #cancelModal .modal-overlay').on('click', function() {
        closeCancelModal();
    });


    // --- 5. 'In' Form Submission (AJAX) (Existing) ---
    $('#in-form').on('submit', function(e) {
        e.preventDefault();

        var submitBtn = $('#in-submit-btn');
        var messageDiv = $('#in-message');
        
        var tripId = $('#modal-trip-id').val();
        var routeCode = $('#modal-route-code').val();
        var distance = $('#distance').val();
        var acStatus = $('input[name="ac_status"]:checked').val();
        
        submitBtn.prop('disabled', true).text('Processing...');
        messageDiv.html('<span class="text-blue-600">Calculating and finalising trip...</span>');

        $.ajax({
            url: 'process_trip_in.php',
            type: 'POST',
            data: { 
                trip_id: tripId, 
                route_code: routeCode, 
                distance: distance, 
                ac_status: acStatus
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    messageDiv.html('<span class="text-green-600 font-bold">Trip completed successfully! Amount: LKR ' + response.amount + '</span>');
                    $('#row-' + tripId).fadeOut(500, function() {
                        $(this).remove();
                    });
                    
                    setTimeout(function() {
                        inModal.removeClass('modal-active').addClass('modal-inactive');
                    }, 2000);

                } else {
                    messageDiv.html('<span class="text-red-600 font-bold">Error: ' + response.message + '</span>');
                    submitBtn.prop('disabled', false).text('Try Again');
                }
            },
            error: function(xhr, status, error) {
                console.error("IN Submission Error:", status, error, xhr.responseText);
                messageDiv.html('<span class="text-red-600 font-bold">Network Error or Script Issue. Check process_trip_in.php.</span>');
                submitBtn.prop('disabled', false).text('Try Again');
            }
        });
    });

    // --- 6. 🆕 NEW: Cancel Trip Handler (Delete) ---

    // A. Trigger Modal (from table button)
    $('.action-cancel-btn').on('click', function() {
        var tripId = $(this).data('trip-id');
        openCancelModal(tripId);
    });

    // B. Execute Deletion (from modal's 'Yes' button)
    $('#cancel-yes-btn').on('click', function() {
        var button = $(this);
        var tripId = $('#cancel-trip-id-holder').val();
        var messageDiv = $('#cancel-message');
        var row = $('#row-' + tripId);
        
        button.prop('disabled', true).text('data deleting...');
        messageDiv.html('<span class="text-blue-600">trip is canceling...</span>').removeClass('hidden');

        $.ajax({
            url: ajaxHandlerUrl,
            type: 'POST',
            data: { action: 'cancel_trip', trip_id: tripId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    messageDiv.html('<span class="text-green-600 font-bold">✅ successfully delete trip.</span>');
                    
                    // Fade out table row
                    row.fadeOut(1000, function() {
                        $(this).remove();
                    });
                    
                    // Close modal after a delay
                    setTimeout(function() {
                        closeCancelModal();
                    }, 1500);

                } else {
                    messageDiv.html('<span class="text-red-600 font-bold">❌ දත්ත මකා දැමීම අසාර්ථකයි: ' + response.message + '</span>');
                    button.prop('disabled', false).text('Try again'); 
                }
            },
            error: function(xhr, status, error) {
                console.error("Cancel Trip Error:", status, error, xhr.responseText);
                messageDiv.html('<span class="text-red-600 font-bold">⚠️ internal error.</span>');
                button.prop('disabled', false).text('Try again');
            }
        });
    });
});