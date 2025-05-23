/**
 * Cubbit Directory Manager Extension
 * JavaScript for handling recursive permissions
 */
jQuery(document).ready(function($) {
    // Check if the original plugin's JS is loaded
    if (typeof cubbitData === 'undefined') {
        console.error('Cubbit Directory Manager script not loaded');
        return;
    }
    
    // Add event to check for directories and show/hide recursive option
    $(document).on('change', '.item-checkbox', function() {
        updateRecursiveOption();
    });
    
    // Override the original apply bulk permissions function
    if (typeof window.originalApplyBulkPermissions === 'undefined') {
        window.originalApplyBulkPermissions = window.applyBulkPermissions;
    }
    
    window.applyBulkPermissions = function() {
        if (selectedItems.length === 0) {
            alert('Please select at least one item');
            return;
        }
        
        const permissionLevel = $('#bulk_permission_level').val();
        const applyRecursively = $('#apply_recursive').is(':checked') ? 'true' : 'false';
        
        // Use the recursive endpoint if the recursive option is checked
        const action = applyRecursively === 'true' ? 
            'cubbit_update_permissions_recursive' : 
            'cubbit_update_permissions';
        
        $.ajax({
            url: cubbitData.ajaxUrl,
            method: 'POST',
            data: {
                action: action,
                _wpnonce: cubbitData.directoryNonce,
                items: selectedItems,
                permission_level: permissionLevel,
                recursive: applyRecursively
            },
            dataType: 'json',
            beforeSend: function() {
                $('#apply_bulk_permissions').prop('disabled', true).text('Applying...');
                $('#permission_update_status').html('<p><span class="spinner is-active"></span> Updating permissions...</p>');
            },
            success: function(response) {
                if (response.success) {
                    $('#permission_update_status').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    loadDirectory(currentPath);
                } else {
                    $('#permission_update_status').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $('#permission_update_status').html('<div class="notice notice-error"><p>Failed to update permissions. Please try again.</p></div>');
                console.error('AJAX Error:', xhr.responseText);
            },
            complete: function() {
                $('#apply_bulk_permissions').prop('disabled', false).text('Apply Permissions');
                setTimeout(function() {
                    $('#permission_update_status').empty();
                }, 5000);
            }
        });
    };
    
    // Function to show/hide the recursive option based on selection
    function updateRecursiveOption() {
        let hasDirectories = false;
        
        $('.item-checkbox:checked').each(function() {
            const path = $(this).data('path');
            const type = $(this).data('type');
            
            if (type === 'directory' || path.endsWith('/')) {
                hasDirectories = true;
                return false; // Break the loop once we find a directory
            }
        });
        
        if (hasDirectories) {
            $('.recursive-option').show();
        } else {
            $('.recursive-option').hide();
            $('#apply_recursive').prop('checked', false);
        }
    }
    
    // Add the recursive option to the permissions panel if it doesn't exist
    if ($('.recursive-option').length === 0) {
        const recursiveOptionHtml = '<div class="recursive-option" style="margin-top: 10px; display: none;">' +
            '<label>' +
            '<input type="checkbox" id="apply_recursive"> ' +
            'Apply recursively to folder contents' +
            '</label>' +
            '<p class="description">This will apply the permission to all files and subfolders.</p>' +
            '</div>';
            
        $('.bulk-permissions-control select').after(recursiveOptionHtml);
    }
});
