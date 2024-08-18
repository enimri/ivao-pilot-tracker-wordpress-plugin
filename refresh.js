jQuery(document).ready(function($) {
    function refreshTables() {
        $.ajax({
            url: ivaoPT.ajax_url,
            method: 'GET',
            data: {
                action: 'fetch_ivao_pilot_data'
            },
            success: function(response) {
                $('#departures-table').html(response.departures);
                $('#arrivals-table').html(response.arrivals);
            }
        });
    }

    refreshTables(); // Initial load
    setInterval(refreshTables, 3000); // Refresh every 3 seconds
});
