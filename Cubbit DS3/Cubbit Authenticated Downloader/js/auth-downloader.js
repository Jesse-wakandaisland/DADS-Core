/**
 * Cubbit Authenticated Downloader
 * JavaScript for handling authenticated file downloads
 */
jQuery(document).ready(function($) {
    // Check if the original plugin's JS is loaded
    if (typeof cubbitData === 'undefined') {
        console.error('Cubbit Directory Manager script not loaded');
        return;
    }
    
    // Add download button to toolbar
    $('.cubbit-toolbar').append(
        '<button id="auth_download_btn" class="button auth-download-button" disabled>' +
        '<span class="dashicons dashicons-download"></span> Secure Download</button>'
    );
    
    // Create download modal
    $('body').append(
        '<div id="auth_download_modal" class="auth-download-modal" style="display: none;">' +
        '  <div class="auth-download-modal-content">' +
        '    <div class="auth-download-modal-header">' +
        '      <h3>Authenticated Download</h3>' +
        '      <span class="auth-download-modal-close">&times;</span>' +
        '    </div>' +
        '    <div id="auth_download_content">' +
        '      <p>Processing <span id="file_count">0</span> item(s) for download...</p>' +
        '      <div class="auth-download-note">' +
        '        <p><strong>Note:</strong> This process downloads files using your stored Cubbit credentials, allowing access to private files.</p>' +
        '      </div>' +
        '      <div class="auth-download-progress">' +
        '        <div class="auth-download-progress-bar">' +
        '          <div class="auth-download-progress-inner"></div>' +
        '        </div>' +
        '        <div class="auth-download-progress-text">Initializing...</div>' +
        '      </div>' +
        '      <div id="auth_download_link_container"></div>' +
        '      <div id="auth_download_errors_container"></div>' +
        '    </div>' +
        '  </div>' +
        '</div>'
    );
    
    // Listen for checkbox changes to update download button state
    $(document).on('change', '.item-checkbox', function() {
        updateDownloadButton();
    });
    
    // Function to update download button state
    function updateDownloadButton() {
        if (typeof selectedItems !== 'undefined' && selectedItems.length > 0) {
            $('#auth_download_btn').prop('disabled', false);
        } else {
            $('#auth_download_btn').prop('disabled', true);
        }
    }
    
    // Handle download button click
    $('#auth_download_btn').on('click', function() {
        if (typeof selectedItems === 'undefined' || selectedItems.length === 0) {
            alert('Please select at least one item to download');
            return;
        }
        
        // Show modal
        $('#auth_download_modal').show();
        $('#file_count').text(selectedItems.length);
        $('#auth_download_link_container').empty();
        $('#auth_download_errors_container').empty();
        $('.auth-download-progress-inner').css('width', '5%');
        $('.auth-download-progress-text').text('Starting download process...');
        
        // Start download process
        startAuthDownload();
    });
    
    // Close modal when clicking X or outside the modal
    $('.auth-download-modal-close').on('click', function() {
        $('#auth_download_modal').hide();
    });
    
    $('#auth_download_modal').on('click', function(e) {
        if ($(e.target).hasClass('auth-download-modal')) {
            $('#auth_download_modal').hide();
        }
    });
    
    // Function to start authenticated download
    function startAuthDownload() {
        $.ajax({
            url: cubbitAuthData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cubbit_auth_download',
                nonce: cubbitAuthData.nonce,
                items: selectedItems
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var downloadId = response.data.download_id;
                    var totalItems = response.data.total_items;
                    
                    // Start polling for progress
                    $('.auth-download-progress-text').text('Starting download process...');
                    pollDownloadProgress(downloadId);
                } else {
                    $('.auth-download-progress-inner').css('width', '100%');
                    $('.auth-download-progress-text').text('Download failed');
                    
                    // Show error message
                    $('#auth_download_link_container').html(
                        '<div class="notice notice-error"><p>Failed to start download: ' + 
                        (response.data ? response.data : 'Unknown error') + '</p></div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('.auth-download-progress-inner').css('width', '100%');
                $('.auth-download-progress-text').text('Download failed');
                
                // Show error message
                $('#auth_download_link_container').html(
                    '<div class="notice notice-error"><p>An error occurred while starting the download.</p></div>'
                );
                
                console.error('AJAX Error:', xhr.responseText);
            }
        });
    }
    
    // Function to poll download progress
    function pollDownloadProgress(downloadId) {
        $.ajax({
            url: cubbitAuthData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cubbit_check_zip_status',
                nonce: cubbitAuthData.nonce,
                download_id: downloadId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var status = response.data.status;
                    var progress = response.data.progress || 0;
                    
                    // Update progress bar
                    $('.auth-download-progress-inner').css('width', progress + '%');
                    
                    // Update progress text based on status
                    switch (status) {
                        case 'initializing':
                            $('.auth-download-progress-text').text('Initializing download...');
                            break;
                        case 'downloading':
                            var processed = response.data.processed_items || 0;
                            var total = response.data.total_items || 1;
                            $('.auth-download-progress-text').text(
                                'Downloading files: ' + processed + ' of ' + total + ' (' + Math.round(progress) + '%)'
                            );
                            break;
                        case 'creating_zip':
                            $('.auth-download-progress-text').text('Creating ZIP archive...');
                            break;
                        case 'completed':
                            $('.auth-download-progress-inner').css('width', '100%');
                            $('.auth-download-progress-text').text('Download ready!');
                            
                            // Show download link
                            $('#auth_download_link_container').html(
                                '<a href="' + response.data.download_url + '" class="button button-primary auth-download-link">Download ZIP</a>'
                            );
                            
                            // Show any errors
                            if (response.data.errors && response.data.errors.length > 0) {
                                showDownloadErrors(response.data.errors);
                            }
                            
                            // Add success message
                            $('#auth_download_link_container').append(
                                '<div class="notice notice-success"><p>Successfully downloaded ' + 
                                response.data.successful_items + ' files. ' +
                                (response.data.failed_items > 0 ? response.data.failed_items + ' files failed.' : '') +
                                '</p></div>'
                            );
                            
                            // Auto-download after a short delay
                            setTimeout(function() {
                                window.location.href = response.data.download_url;
                            }, 1000);
                            
                            return; // Stop polling
                        case 'failed':
                            $('.auth-download-progress-inner').css('width', '100%');
                            $('.auth-download-progress-text').text('Download failed');
                            
                            // Show error message
                            $('#auth_download_link_container').html(
                                '<div class="notice notice-error"><p>Download failed: ' + 
                                (response.data.message || 'Unknown error') + '</p></div>'
                            );
                            
                            // Show detailed errors
                            if (response.data.errors && response.data.errors.length > 0) {
                                showDownloadErrors(response.data.errors);
                            }
                            
                            return; // Stop polling
                    }
                    
                    // Continue polling if not completed or failed
                    setTimeout(function() {
                        pollDownloadProgress(downloadId);
                    }, 2000); // Poll every 2 seconds
                } else {
                    $('.auth-download-progress-inner').css('width', '100%');
                    $('.auth-download-progress-text').text('Download failed');
                    
                    // Show error message
                    $('#auth_download_link_container').html(
                        '<div class="notice notice-error"><p>Failed to check download status: ' + 
                        (response.data ? response.data : 'Unknown error') + '</p></div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('.auth-download-progress-inner').css('width', '100%');
                $('.auth-download-progress-text').text('Download failed');
                
                // Show error message
                $('#auth_download_link_container').html(
                    '<div class="notice notice-error"><p>An error occurred while checking download status.</p></div>'
                );
                
                console.error('AJAX Error:', xhr.responseText);
            }
        });
    }
    
    // Function to display download errors
    function showDownloadErrors(errors) {
        if (!errors || errors.length === 0) {
            return;
        }
        
        let errorHtml = '<div class="auth-download-errors">' +
                      '<h4>Some files could not be downloaded:</h4>' +
                      '<ul>';
        
        errors.forEach(function(error) {
            errorHtml += '<li>' + error + '</li>';
        });
        
        errorHtml += '</ul></div>';
        
        $('#auth_download_errors_container').html(errorHtml);
    }
    
    // Initialize button state
    updateDownloadButton();
});
