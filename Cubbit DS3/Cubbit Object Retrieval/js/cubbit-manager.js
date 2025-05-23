/**
 * Cubbit Directory Manager
 * JavaScript for handling directory listing, permissions, and file operations
 */
jQuery(document).ready(function($) {
    // Global variables
    let currentPath = '';
    let selectedItems = [];
    
    // Initialize the browser
    initBrowser();
    
    /**
     * Initialize the browser interface
     */
    function initBrowser() {
        // Initial directory load
        loadDirectory('');
        
        // Set up event handlers
        $('#refresh_btn').on('click', function() {
            loadDirectory(currentPath);
        });
        
        $('#create_folder_btn').on('click', function() {
            showCreateFolderDialog();
        });
        
        $('#upload_file_btn').on('click', function() {
            showUploadDialog();
        });
        
        $('#create_folder_submit').on('click', function() {
            createFolder();
        });
        
        $('#create_folder_cancel').on('click', function() {
            hideCreateFolderDialog();
        });
        
        $('#upload_files_submit').on('click', function() {
            uploadFiles();
        });
        
        $('#upload_files_cancel').on('click', function() {
            hideUploadDialog();
        });
        
        $('#apply_bulk_permissions').on('click', function() {
            applyBulkPermissions();
        });
        
        // Close dialogs when clicking outside
        $('.cubbit-dialog').on('click', function(e) {
            if ($(e.target).hasClass('cubbit-dialog')) {
                $(this).hide();
            }
        });
        
        // Press Enter to submit in dialogs
        $('#new_folder_name').on('keypress', function(e) {
            if (e.which === 13) {
                createFolder();
            }
        });
    }
    
    /**
     * Load directory contents
     */
    function loadDirectory(prefix) {
        currentPath = prefix;
        
        $('#cubbit_directory_contents').html('<p><span class="spinner is-active"></span> Loading contents...</p>');
        
        $.ajax({
            url: cubbitData.ajaxUrl,
            method: 'GET',
            data: {
                action: 'cubbit_list_directory',
                _wpnonce: cubbitData.directoryNonce,
                prefix: prefix
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderBreadcrumb(prefix);
                    renderContents(response.data);
                    updateSelectedItemsList();
                } else {
                    $('#cubbit_directory_contents').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $('#cubbit_directory_contents').html('<div class="notice notice-error"><p>Error loading directory contents. Please try again.</p></div>');
                console.error('AJAX Error:', error);
            }
        });
    }
    
    /**
     * Render breadcrumb navigation
     */
    function renderBreadcrumb(path) {
        let html = '<span class="breadcrumb-home" data-path=""><span class="dashicons dashicons-admin-home"></span> Home</span>';
        
        if (path) {
            let parts = path.split('/');
            let currentPath = '';
            
            // Remove empty parts
            parts = parts.filter(part => part !== '');
            
            parts.forEach(function(part, index) {
                currentPath += part + '/';
                html += ' / <span class="breadcrumb-item" data-path="' + currentPath + '">' + part + '</span>';
            });
        }
        
        $('#cubbit_breadcrumb').html(html);
        
        // Attach click events
        $('.breadcrumb-home, .breadcrumb-item').on('click', function() {
            loadDirectory($(this).data('path'));
        });
    }
    
    /**
     * Render directory contents
     */
    function renderContents(data) {
    let html = '<div class="toolbar">' +
               '<button id="select_all_btn" class="button">Select All</button> ' +
               '<button id="deselect_all_btn" class="button">Deselect All</button>' +
               '</div>';
    
    html += '<table class="wp-list-table widefat fixed striped">' +
            '<thead><tr>' +
            '<th class="check-column"><input type="checkbox" id="select_all_checkbox"></th>' +
            '<th>Name</th>' +
            '<th>Type</th>' +
            '<th>Size</th>' +
            '<th>Permissions</th>' +
            '<th>Actions</th>' +
            '</tr></thead><tbody>';
    
    // Render directories
    if (data.contents.directories && data.contents.directories.length > 0) {
        data.contents.directories.forEach(function(dir) {
            const displayName = dir.name.split('/').pop();
            
            html += '<tr class="directory-item">' +
                    '<td><input type="checkbox" class="item-checkbox" data-path="' + dir.path + '" data-type="directory"></td>' +
                    '<td><span class="dashicons dashicons-portfolio"></span> ' + displayName + '</td>' +
                    '<td>Directory</td>' +
                    '<td>-</td>' +
                    '<td>' + formatPermission(dir.permissions) + '</td>' +
                    '<td>' +
                    '<button class="button explore-btn" data-path="' + dir.path + '/">Explore</button> ' +
                    '<button class="button change-permission-btn" data-path="' + dir.path + '" data-permission="' + dir.permissions + '">Permissions</button> ' +
                    '</td>' +
                    '</tr>';
        });
    }
    
    // Render files
    if (data.contents.files && data.contents.files.length > 0) {
        data.contents.files.forEach(function(file) {
            html += '<tr class="file-item">' +
                    '<td><input type="checkbox" class="item-checkbox" data-path="' + file.path + '" data-type="file"></td>' +
                    '<td><span class="dashicons dashicons-media-default"></span> ' + file.name + '</td>' +
                    '<td>File</td>' +
                    '<td>' + formatBytes(file.size) + '</td>' +
                    '<td>' + formatPermission(file.permissions) + '</td>' +
                    '<td>' +
                    '<a href="' + file.url + '" class="button" target="_blank">View</a> ' +
                    '<button class="button change-permission-btn" data-path="' + file.path + '" data-permission="' + file.permissions + '">Permissions</button> ' +
                    '<button class="button delete-file-btn" data-path="' + file.path + '">Delete</button>' +
                    '</td>' +
                    '</tr>';
        });
    }
    
    if ((!data.contents.directories || data.contents.directories.length === 0) && 
        (!data.contents.files || data.contents.files.length === 0)) {
        html += '<tr><td colspan="6">No items found in this directory.</td></tr>';
    }
    
    html += '</tbody></table>';
    
    $('#cubbit_directory_contents').html(html);
    
    // Reset selection
    selectedItems = [];
    
    // Attach event handlers
    $('#select_all_btn, #select_all_checkbox').on('click', function() {
        $('.item-checkbox').prop('checked', true);
        updateSelectedItems();
    });
    
    $('#deselect_all_btn').on('click', function() {
        $('.item-checkbox').prop('checked', false);
        updateSelectedItems();
    });
    
    $('.item-checkbox').on('change', function() {
        updateSelectedItems();
    });
    
    $('.explore-btn').on('click', function() {
        loadDirectory($(this).data('path'));
    });
    
    $('.change-permission-btn').on('click', function() {
        const path = $(this).data('path');
        const currentPermission = $(this).data('permission');
        showPermissionDialog(path, currentPermission);
    });
    
    $('.delete-file-btn').on('click', function() {
        const path = $(this).data('path');
        if (confirm('Are you sure you want to delete this file?')) {
            deleteFile(path);
        }
    });
    
    // Check if any directories are selected and toggle recursive option
    updateSelectedItems();
}

// Update the updateSelectedItems function to handle recursive option visibility
function updateSelectedItems() {
    selectedItems = [];
    let hasDirectories = false;
    
    $('.item-checkbox:checked').each(function() {
        selectedItems.push($(this).data('path'));
        if ($(this).data('type') === 'directory' || $(this).data('path').endsWith('/')) {
            hasDirectories = true;
        }
    });
    
    // Update the selected items list
    updateSelectedItemsList();
    
    // Show/hide recursive option
    if (hasDirectories) {
        $('.recursive-option').show();
    } else {
        $('.recursive-option').hide();
        $('#apply_recursive').prop('checked', false);
    }
    
    // Enable/disable bulk actions
    if (selectedItems.length > 0) {
        $('#apply_bulk_permissions').prop('disabled', false);
    } else {
        $('#apply_bulk_permissions').prop('disabled', true);
    }
}
   
    
 
    
    /**
     * Show dialog for creating a new folder
     */
    function showCreateFolderDialog() {
        $('#new_folder_name').val('');
        $('#new_folder_permission').val('private');
        $('#create_folder_dialog').show();
        $('#new_folder_name').focus();
    }
    
    /**
     * Hide create folder dialog
     */
    function hideCreateFolderDialog() {
        $('#create_folder_dialog').hide();
    }
    
    /**
     * Show upload dialog
     */
    function showUploadDialog() {
        $('#file_upload').val('');
        $('#upload_permission').val('private');
        $('#upload_progress').empty();
        $('#upload_files_dialog').show();
    }
    
    /**
     * Hide upload dialog
     */
    function hideUploadDialog() {
        $('#upload_files_dialog').hide();
    }
    
    /**
     * Show permission change dialog (single item)
     */
   function showPermissionDialog(path, currentPermission) {
    // We'll use the bulk permissions dialog for simplicity
    selectedItems = [path];
    updateSelectedItemsList();
    $('#bulk_permission_level').val(currentPermission);
    $('#apply_bulk_permissions').prop('disabled', false);
    
    // Show or hide recursive option based on whether this is a directory
    if (path.endsWith('/') || $('.directory-item input[data-path="' + path + '"]').length > 0) {
        $('.recursive-option').show();
    } else {
        $('.recursive-option').hide();
    }
    
    // Scroll to the permissions panel
    $('html, body').animate({
        scrollTop: $('.cubbit-permissions-panel').offset().top - 50
    }, 500);
}

    
    /**
     * Create a new folder
     */
    function createFolder() {
        const folderName = $('#new_folder_name').val().trim();
        const permission = $('#new_folder_permission').val();
        
        if (!folderName) {
            alert('Please enter a folder name');
            return;
        }
        
        $.ajax({
            url: cubbitData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cubbit_create_folder',
                _wpnonce: cubbitData.directoryNonce,
                folder_name: folderName,
                permission: permission,
                prefix: currentPath
            },
            dataType: 'json',
            beforeSend: function() {
                $('#create_folder_submit').prop('disabled', true).text('Creating...');
            },
            success: function(response) {
                if (response.success) {
                    hideCreateFolderDialog();
                    loadDirectory(currentPath);
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to create folder. Please try again.');
            },
            complete: function() {
                $('#create_folder_submit').prop('disabled', false).text('Create');
            }
        });
    }
    
    /**
     * Upload files to current directory
     */
    function uploadFiles() {
        const files = $('#file_upload')[0].files;
        const permission = $('#upload_permission').val();
        
        if (files.length === 0) {
            alert('Please select files to upload');
            return;
        }
        
        // Create FormData object
        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }
        
        formData.append('action', 'cubbit_upload_files');
        formData.append('_wpnonce', cubbitData.directoryNonce);
        formData.append('prefix', currentPath);
        formData.append('permission', permission);
        
        $.ajax({
            url: cubbitData.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                $('#upload_files_submit').prop('disabled', true).text('Uploading...');
                $('#upload_progress').html('<div class="progress-bar"><div class="progress-inner"></div></div><div class="progress-text">0%</div>');
            },
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        $('.progress-inner').css('width', percent + '%');
                        $('.progress-text').text(percent + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    hideUploadDialog();
                    loadDirectory(currentPath);
                } else {
                    $('#upload_progress').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $('#upload_progress').html('<div class="notice notice-error"><p>Failed to upload files. Please try again.</p></div>');
            },
            complete: function() {
                $('#upload_files_submit').prop('disabled', false).text('Upload');
            }
        });
    }
    
    /**
     * Delete a file
     */
    function deleteFile(filePath) {
        $.ajax({
            url: cubbitData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cubbit_delete_file',
                _wpnonce: cubbitData.directoryNonce,
                file_path: filePath
            },
            dataType: 'json',
            beforeSend: function() {
                $('button[data-path="' + filePath + '"]').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    loadDirectory(currentPath);
                } else {
                    alert('Error: ' + response.data);
                    $('button[data-path="' + filePath + '"]').prop('disabled', false);
                }
            },
            error: function() {
                alert('Failed to delete file. Please try again.');
                $('button[data-path="' + filePath + '"]').prop('disabled', false);
            }
        });
    }
    
    /**
     * Apply bulk permissions to selected items
     */
// Update the applyBulkPermissions function to include recursive parameter
function applyBulkPermissions() {
    if (selectedItems.length === 0) {
        alert('Please select at least one item');
        return;
    }
    
    const permissionLevel = $('#bulk_permission_level').val();
    const applyRecursively = $('#apply_recursive').is(':checked') ? 'true' : 'false';
    
    $.ajax({
        url: cubbitData.ajaxUrl,
        method: 'POST',
        data: {
            action: 'cubbit_update_permissions',
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
}
    
    /**
     * Format file size in human-readable format
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    /**
     * Format permission level for display
     */
    function formatPermission(permission) {
        switch (permission) {
            case 'public-read':
                return '<span class="permission-public">Public</span>';
            case 'authenticated-read':
                return '<span class="permission-authenticated">Authenticated</span>';
            case 'private':
            default:
                return '<span class="permission-private">Private</span>';
        }
    }
});
