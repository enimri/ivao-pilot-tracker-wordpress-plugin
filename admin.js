jQuery(document).ready(function($) {
    // Add new airport
    $('#add-airport-button').click(function(e) {
        e.preventDefault();
        
        var icaoCode = $('#icao-code').val();
        var latitude = $('#latitude').val();
        var longitude = $('#longitude').val();

        if (icaoCode === '' || latitude === '' || longitude === '') {
            alert('Please fill in all fields.');
            return;
        }

        var data = {
            action: 'ivao_add_airport',
            icao_code: icaoCode,
            latitude: latitude,
            longitude: longitude
        };

        $.post(ivaoAdmin.ajax_url, data, function(response) {
            if (response.success) {
                alert('Airport added successfully.');
                location.reload();
            } else {
                alert('Error adding airport.');
            }
        });
    });

    // Remove airport
    $('.remove-airport-button').click(function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to remove this airport?')) {
            return;
        }

        var airportId = $(this).data('id');

        var data = {
            action: 'ivao_remove_airport',
            id: airportId
        };

        $.post(ivaoAdmin.ajax_url, data, function(response) {
            if (response.success) {
                alert('Airport removed successfully.');
                location.reload();
            } else {
                alert('Error removing airport.');
            }
        });
    });

    // Edit airport
    $('#edit-airport-button').click(function(e) {
        e.preventDefault();

        var airportId = $('#airport-id').val();
        var icaoCode = $('#edit-icao-code').val();
        var latitude = $('#edit-latitude').val();
        var longitude = $('#edit-longitude').val();

        if (icaoCode === '' || latitude === '' || longitude === '') {
            alert('Please fill in all fields.');
            return;
        }

        var data = {
            action: 'ivao_edit_airport',
            id: airportId,
            icao_code: icaoCode,
            latitude: latitude,
            longitude: longitude
        };

        $.post(ivaoAdmin.ajax_url, data, function(response) {
            if (response.success) {
                alert('Airport updated successfully.');
                location.reload();
            } else {
                alert('Error updating airport.');
            }
        });
    });
});
