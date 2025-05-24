jQuery(document).ready(function($) {
    function updateContent() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'nara_get_data'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    data.forEach(function(endpoint) {
                        if (endpoint.data) {
                            $(naraSettings.find(function(e) { return e.url === endpoint.url; }).selector).html(endpoint.data);
                        } else {
                            console.error('Error fetching data from:', endpoint.url, endpoint.error);
                        }
                    });
                } else {
                    console.error('Error:', response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
            }
        });
    }

    // Initial load
    updateContent();

  
});
