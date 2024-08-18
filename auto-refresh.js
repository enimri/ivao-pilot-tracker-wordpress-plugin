jQuery(document).ready(function($) {
    function refreshData() {
        $.ajax({
            url: ivao_pilot_tracker_ajax.url,
            type: 'POST',
            data: {
                action: 'ivao_pilot_tracker_refresh'
            },
            success: function(data) {
                $('.ivao-pilot-tracker').html(data);
            },
            error: function() {
                console.error('Failed to refresh data');
            }
        });
    }

    // Initial load
    refreshData();

    // Set interval for refreshing data every 3 seconds
    setInterval(refreshData, 3000);
});
