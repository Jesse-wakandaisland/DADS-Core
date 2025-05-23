jQuery(document).ready(function($) {
    // Add download button to toolbar
    if ($('.cubbit-toolbar').length && !$('#direct_download_btn').length) {
        console.log("Adding download button...");
        
        // Add the button
        $('.cubbit-toolbar').append(
            '<button id="direct_download_btn" class="button" style="margin-left: 10px; background-color: #0073aa; color: white;">' +
            '<span class="dashicons dashicons-download"></span> Download Selected</button>'
        );
        
        // Handle click event
        $('#direct_download_btn').on('click', function() {
            // Get selected items directly from checkboxes
            var selectedItems = [];
            $('.item-checkbox:checked').each(function() {
                var path = $(this).data('path');
                if (path) {
                    selectedItems.push(path);
                }
            });
            
            if (selectedItems.length === 0) {
                alert('Please select at least one file to download');
                return;
            }
            
            // Get bucket name
            var bucketName = prompt('Enter your Cubbit bucket name:');
            if (!bucketName) return;
            
            // Now process each selected file for download
            selectedItems.forEach(function(filepath, index) {
                if (!filepath.endsWith('/')) { // Skip folders
                    // Create download URL
                    var fileUrl = 'https://s3.cubbit.eu/' + bucketName + '/' + filepath;
                    
                    // Add slight delay to avoid browser blocking multiple downloads
                    setTimeout(function() {
                        window.open(fileUrl, '_blank');
                    }, index * 300);
                }
            });
        });
        
        // Enable button when items are selected
        $(document).on('change', '.item-checkbox', function() {
            var anyChecked = $('.item-checkbox:checked').length > 0;
            $('#direct_download_btn').prop('disabled', !anyChecked);
        });
        
        // Initial check for selected items
        var initialChecked = $('.item-checkbox:checked').length > 0;
        $('#direct_download_btn').prop('disabled', !initialChecked);
    }
});
