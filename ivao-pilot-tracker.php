<?php
/*
Plugin Name: IVAO Pilot Tracker
Description: Displays pilot departures and arrivals for selected airports with estimated ETD, EET, and ETA. Includes backend management for adding, editing, and removing airports.
Version: 1.8
Author: Eyad Nimri
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Function to create a custom database table for storing airports
function ivao_pilot_tracker_create_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        icao_code varchar(4) NOT NULL,
        latitude float(10,6) NOT NULL,
        longitude float(10,6) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'ivao_pilot_tracker_create_db');

// Function to calculate distance between two coordinates
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // Radius of the Earth in kilometers

    $dlat = deg2rad($lat2 - $lat1);
    $dlon = deg2rad($lon2 - $lon1);

    $a = sin($dlat / 2) * sin($dlat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dlon / 2) * sin($dlon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth_radius * $c; // Distance in kilometers
}

// Function to format EET in HH:MM
function format_eet($eet_seconds) {
    $eet_seconds = (float)$eet_seconds;
    if ($eet_seconds <= 0) {
        return 'N/A';
    }

    // Ensure EET does not exceed 23 hours and 59 minutes
    $eet_seconds = min($eet_seconds, 23 * 3600 + 59 * 60);

    $hours = floor($eet_seconds / 3600);
    $minutes = floor(($eet_seconds % 3600) / 60);
    return sprintf('%02d:%02d', $hours, $minutes);
}

// Function to format ETD in HH:MM UTC
function format_etd($departure_time) {
    $departure_time = (float)$departure_time;
    if ($departure_time === null) {
        return 'N/A';
    }

    $hours = floor($departure_time / 3600);
    $minutes = floor(($departure_time % 3600) / 60);
    return sprintf('%02d:%02d UTC', $hours, $minutes);
}

// Function to calculate ETA based on ETD and EET
function calculate_eta($etd_seconds, $eet_seconds) {
    $etd_seconds = (float)$etd_seconds;
    $eet_seconds = (float)$eet_seconds;

    // Calculate ETA as the sum of ETD and EET
    $eta_seconds = $etd_seconds + $eet_seconds;

    $hours = floor($eta_seconds / 3600);
    $minutes = floor(($eta_seconds % 3600) / 60);
    return sprintf('%02d:%02d UTC', $hours, $minutes);
}

// Function to fetch IVAO data and calculate EET/ETD/ETA
function fetch_ivao_data() {
    $response = wp_remote_get('https://api.ivao.aero/v2/tracker/whazzup');
    if (is_wp_error($response)) {
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    $result = [
        'departures' => [],
        'arrivals' => []
    ];

    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';
    $icao_codes = $wpdb->get_col("SELECT icao_code FROM $table_name");

    foreach ($data['clients']['pilots'] as $pilot) {
        $departureId = $pilot['flightPlan']['departureId'] ?? '';
        $arrivalId = $pilot['flightPlan']['arrivalId'] ?? '';
        $eet = $pilot['flightPlan']['eet'] ?? 0; // Fetch EET directly from API if available
        $departureTime = $pilot['flightPlan']['departureTime'] ?? null; // Fetch departureTime directly from API if available

        // Format departureTime
        if ($departureTime !== null) {
            $etd = format_etd($departureTime); // Format departureTime to HH:MM UTC
            $etd_seconds = (float)$departureTime;
        } else {
            $etd = 'N/A';
            $etd_seconds = 0;
        }

        // Calculate ETA if EET is available
        $eta = $eet ? calculate_eta($etd_seconds, $eet) : 'N/A';

        // Handle departure data
        if (in_array($departureId, $icao_codes)) {
            $result['departures'][] = [
                'callsign' => $pilot['callsign'],
                'from' => $departureId,
                'etd' => $etd,  // Use ETD with UTC
                'to' => $arrivalId,
                'eta' => $eta,  // Add ETA
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }

        // Handle arrival data
        if (in_array($arrivalId, $icao_codes)) {
            $result['arrivals'][] = [
                'callsign' => $pilot['callsign'],
                'to' => $arrivalId,
                'eet' => $eet ? format_eet($eet) : 'N/A',  // Format EET without UTC
                'from' => $departureId,
                'eta' => $eta,  // Add ETA
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }
    }

    return $result;
}

// Function to render the shortcode output
function render_ivao_pilot_tracker() {
    $data = fetch_ivao_data();

    ob_start(); // Start output buffering

    echo '<h2>PILOTS</h2>';
    echo '<div class="ivao-pilot-tracker">';

    // Departures section
    echo '<h3>Departures</h3>';
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>CALLSIGN</th><th>FROM</th><th>ETD</th><th>ETA</th><th>TO</th><th>LAST TRACK</th></tr>';
    if (!empty($data['departures'])) {
        foreach ($data['departures'] as $departure) {
            echo '<tr>';
            echo '<td>' . esc_html($departure['callsign']) . '</td>';
            echo '<td>' . esc_html($departure['from']) . '</td>';
            echo '<td>' . esc_html($departure['etd']) . '</td>';
            echo '<td>' . esc_html($departure['eta']) . '</td>';
            echo '<td>' . esc_html($departure['to']) . '</td>';
            echo '<td>' . esc_html($departure['last_track']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No departures</td></tr>';
    }
    echo '</table></div>';

    // Arrivals section
    echo '<h3>Arrivals</h3>';
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>CALLSIGN</th><th>TO</th><th>EET</th><th>ETA</th><th>FROM</th><th>LAST TRACK</th></tr>';
    if (!empty($data['arrivals'])) {
        foreach ($data['arrivals'] as $arrival) {
            echo '<tr>';
            echo '<td>' . esc_html($arrival['callsign']) . '</td>';
            echo '<td>' . esc_html($arrival['to']) . '</td>';
            echo '<td>' . esc_html($arrival['eet']) . '</td>';
            echo '<td>' . esc_html($arrival['eta']) . '</td>';
            echo '<td>' . esc_html($arrival['from']) . '</td>';
            echo '<td>' . esc_html($arrival['last_track']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">No arrivals</td></tr>';
    }
    echo '</table></div>';

    // Custom CSS for Scrollable Table on Mobile
    echo '<style>
        .ivao-pilot-tracker table {
            width: 100%;
            border-collapse: collapse;
        }

        .ivao-pilot-tracker th, .ivao-pilot-tracker td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .ivao-pilot-tracker th {
            background-color: #f4f4f4;
        }

        .table-responsive {
            overflow-x: auto;
        }
    </style>';

    echo '</div>';

    return ob_get_clean(); // Return the output buffer contents
}

// Register shortcode for displaying pilot tracker
function ivao_pilot_tracker_shortcode() {
    return render_ivao_pilot_tracker();
}
add_shortcode('ivao_pilot_tracker', 'ivao_pilot_tracker_shortcode');

// Function to add admin menu page
function ivao_pilot_tracker_admin_menu() {
    add_menu_page(
        'IVAO Airport Tracker',
        'IVAO Airport Tracker',
        'manage_options',
        'ivao-pilot-tracker',
        'ivao_pilot_tracker_admin_page',
        'dashicons-admin-generic'
    );
}
add_action('admin_menu', 'ivao_pilot_tracker_admin_menu');

// Admin page for managing airports
function ivao_pilot_tracker_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';

    // Handle form submission for adding/editing airports
    if (isset($_POST['submit'])) {
        $icao_code = sanitize_text_field($_POST['icao_code']);
        $latitude = floatval($_POST['latitude']);
        $longitude = floatval($_POST['longitude']);

        if (!empty($icao_code) && $latitude && $longitude) {
            $wpdb->replace(
                $table_name,
                [
                    'icao_code' => $icao_code,
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ],
                ['%s', '%f', '%f']
            );
            echo '<div class="updated"><p>Airport saved.</p></div>';
        }
    }

    // Handle form submission for deleting airports
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['icao_code'])) {
        $icao_code = sanitize_text_field($_GET['icao_code']);
        $wpdb->delete($table_name, ['icao_code' => $icao_code], ['%s']);
        echo '<div class="updated"><p>Airport deleted.</p></div>';
    }

    // Display the form and list of airports
    ?>
    <div class="wrap">
        <h1>Manage Airports</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="icao_code">ICAO Code</label></th>
                    <td><input name="icao_code" type="text" id="icao_code" value="" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="latitude">Latitude</label></th>
                    <td><input name="latitude" type="text" id="latitude" value="" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="longitude">Longitude</label></th>
                    <td><input name="longitude" type="text" id="longitude" value="" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button('Save Airport'); ?>
        </form>

        <h2>Airport List</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col">ICAO Code</th>
                    <th scope="col">Latitude</th>
                    <th scope="col">Longitude</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $airports = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
                foreach ($airports as $airport) {
                    echo '<tr>';
                    echo '<td>' . esc_html($airport['icao_code']) . '</td>';
                    echo '<td>' . esc_html($airport['latitude']) . '</td>';
                    echo '<td>' . esc_html($airport['longitude']) . '</td>';
                    echo '<td><a href="' . esc_url(add_query_arg(['action' => 'delete', 'icao_code' => $airport['icao_code']])) . '">Delete</a></td>';
                    echo '</tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}
