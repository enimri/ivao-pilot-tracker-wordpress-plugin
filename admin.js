jQuery(document).ready(function($) {
    // Load airports when the admin page is loaded
    loadAirports();

    // Handle form submission for adding airports
    $('#ivao-pilot-tracker-form').on('submit', function(event) {
        event.preventDefault();

        var data = {
            action: 'ivao_pilot_tracker_add_airport',
            icao_code: $('#icao-code').val(),
            latitude: $('#latitude').val(),
            longitude: $('#longitude').val()
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                loadAirports();
                $('#ivao-pilot-tracker-form')[0].reset();
            }
        });
    });

    // Handle deleting airports
    $(document).on('click', '.delete-airport', function() {
        var id = $(this).data('id');

        var data = {
            action: 'ivao_pilot_tracker_delete_airport',
            id: id
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                loadAirports();
            }
        });
    });

    // Function to load airports from the database and display them in the table
    function loadAirports() {
        var data = {
            action: 'ivao_pilot_tracker_load_airports'
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                var airports = response.data;
                var rows = '';

                $.each(airports, function(index, airport) {
                    rows += '<tr>';
                    rows += '<td>' + airport.icao_code + '</td>';
                    rows += '<td>' + airport.latitude + '</td>';
                    rows += '<td>' + airport.longitude + '</td>';
                    rows += '<td><button class="button delete-airport" data-id="' + airport.id + '">Delete</button></td>';
                    rows += '</tr>';
                });

                $('#ivao-pilot-tracker-table tbody').html(rows);
            }
        });
    }
});
