<?php
/*
Plugin Name: IVAO Pilot Tracker
Description: Displays pilot departures and arrivals for selected airports with estimated ETD and EET. Includes backend management for adding, editing, and removing airports.
Version: 1.5
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

// Function to estimate EET based on distance and average speed
function estimate_eet($from, $to) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';

    // Get coordinates for both departure and arrival airports
    $from_coords = $wpdb->get_row($wpdb->prepare("SELECT latitude, longitude FROM $table_name WHERE icao_code = %s", $from), ARRAY_A);
    $to_coords = $wpdb->get_row($wpdb->prepare("SELECT latitude, longitude FROM $table_name WHERE icao_code = %s", $to), ARRAY_A);

    if (!$from_coords || !$to_coords) {
        return 'N/A';
    }

    $distance = calculate_distance($from_coords['latitude'], $from_coords['longitude'], $to_coords['latitude'], $to_coords['longitude']);
    $average_speed = 450; // in knots
    $eet_hours = $distance / $average_speed; // Estimated Elapsed Time in hours

    return gmdate('H:i', $eet_hours * 3600) . ' UTC'; // Return EET in HH:MM format
}

// Function to estimate ETD based on current time and EET
function estimate_etd($eet) {
    if ($eet === 'N/A') {
        return 'N/A';
    }

    $eet_seconds = strtotime($eet) - strtotime('TODAY');
    $current_time = time();
    $etd_time = $current_time - $eet_seconds; // Subtract EET (in seconds) from current time

    return gmdate('H:i T', $etd_time); // Return ETD in HH:MM format
}

// Function to fetch IVAO data and calculate EET/ETD
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
        $eet = $pilot['flightPlan']['eet'] ?? null; // Fetch EET directly from API if available
        $eet_formatted = $eet ? gmdate('H:i', $eet) . ' UTC' : 'N/A'; // Format EET if available
        $etd = estimate_etd($eet_formatted);

        if (in_array($departureId, $icao_codes)) {
            $result['departures'][] = [
                'callsign' => $pilot['callsign'],
                'from' => $departureId,
                'etd' => $etd,  // Display ETD for departures
                'to' => $arrivalId,
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }

        if (in_array($arrivalId, $icao_codes)) {
            $result['arrivals'][] = [
                'callsign' => $pilot['callsign'],
                'to' => $arrivalId,
                'eet' => $eet_formatted,  // Display EET for arrivals
                'from' => $departureId,
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
    echo '<tr><th>CALLSIGN</th><th>FROM</th><th>ETD</th><th>TO</th><th>LAST TRACK</th></tr>';
    if (!empty($data['departures'])) {
        foreach ($data['departures'] as $departure) {
            echo '<tr>';
            echo '<td>' . esc_html($departure['callsign']) . '</td>';
            echo '<td>' . esc_html($departure['from']) . '</td>';
            echo '<td>' . esc_html($departure['etd']) . '</td>';
            echo '<td>' . esc_html($departure['to']) . '</td>';
            echo '<td>' . esc_html($departure['last_track']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No departures</td></tr>';
    }
    echo '</table></div>';

    // Arrivals section
    echo '<h3>Arrivals</h3>';
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>CALLSIGN</th><th>TO</th><th>EET</th><th>FROM</th><th>LAST TRACK</th></tr>';
    if (!empty($data['arrivals'])) {
        foreach ($data['arrivals'] as $arrival) {
            echo '<tr>';
            echo '<td>' . esc_html($arrival['callsign']) . '</td>';
            echo '<td>' . esc_html($arrival['to']) . '</td>';
            echo '<td>' . esc_html($arrival['eet']) . '</td>';
            echo '<td>' . esc_html($arrival['from']) . '</td>';
            echo '<td>' . esc_html($arrival['last_track']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No arrivals</td></tr>';
    }
    echo '</table></div>';

    echo '</div>';

    // Custom CSS for Scrollable Table on Mobile
    echo '<style>
        .ivao-pilot-tracker table {
            width: 100%;
            border-collapse: collapse;
        }

        .ivao-pilot-tracker th, .ivao-pilot-tracker td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .ivao-pilot-tracker th {
            background-color: #f2f2f2;
        }

        /* Scrollable Table for Mobile */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Mobile Adjustments */
        @media screen and (max-width: 600px) {
            .ivao-pilot-tracker th, .ivao-pilot-tracker td {
                font-size: 14px;
            }
        }
    </style>';

    return ob_get_clean(); // Return the content
}

add_shortcode('ivao_pilot_tracker', 'render_ivao_pilot_tracker');

// Enqueue admin scripts and styles
function ivao_pilot_tracker_admin_scripts($hook) {
    if ($hook != 'toplevel_page_ivao-pilot-tracker') {
        return;
    }
    wp_enqueue_script('ivao-pilot-tracker-admin', plugin_dir_url(__FILE__) . 'admin.js', array('jquery'), '1.0', true);
}
add_action('admin_enqueue_scripts', 'ivao_pilot_tracker_admin_scripts');

// Add admin menu
function ivao_pilot_tracker_admin_menu() {
    add_menu_page('IVAO Pilot Tracker', 'IVAO Pilot Tracker', 'manage_options', 'ivao-pilot-tracker', 'ivao_pilot_tracker_admin_page');
}
add_action('admin_menu', 'ivao_pilot_tracker_admin_menu');

// Admin page content
function ivao_pilot_tracker_admin_page() {
    ?>
    <div class="wrap">
        <h1>IVAO Pilot Tracker</h1>
        <form id="ivao-pilot-tracker-form">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">ICAO Code</th>
                    <td><input type="text" id="icao-code" name="icao_code" required></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Latitude</th>
                    <td><input type="text" id="latitude" name="latitude" required></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Longitude</th>
                    <td><input type="text" id="longitude" name="longitude" required></td>
                </tr>
            </table>
            <input type="submit" class="button button-primary" value="Add Airport">
        </form>

        <h2>Registered Airports</h2>
        <table id="ivao-pilot-tracker-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ICAO Code</th>
                    <th>Latitude</th>
                    <th>Longitude</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <!-- Airports will be loaded here by JavaScript -->
            </tbody>
        </table>
    </div>
    <?php
}

// Handle AJAX request to add an airport
function ivao_pilot_tracker_add_airport() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';

    $icao_code = sanitize_text_field($_POST['icao_code']);
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);

    $wpdb->insert(
        $table_name,
        array(
            'icao_code' => $icao_code,
            'latitude' => $latitude,
            'longitude' => $longitude
        )
    );

    wp_send_json_success();
}
add_action('wp_ajax_ivao_pilot_tracker_add_airport', 'ivao_pilot_tracker_add_airport');

// Handle AJAX request to delete an airport
function ivao_pilot_tracker_delete_airport() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';

    $id = intval($_POST['id']);

    $wpdb->delete($table_name, array('id' => $id));

    wp_send_json_success();
}
add_action('wp_ajax_ivao_pilot_tracker_delete_airport', 'ivao_pilot_tracker_delete_airport');

// Load airports for the admin table
function ivao_pilot_tracker_load_airports() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';

    $airports = $wpdb->get_results("SELECT * FROM $table_name");

    wp_send_json_success($airports);
}
add_action('wp_ajax_ivao_pilot_tracker_load_airports', 'ivao_pilot_tracker_load_airports');

// Utility function to calculate distance between two coordinates using the Haversine formula
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 3440; // Earth's radius in nautical miles

    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $lat_diff = $lat2 - $lat1;
    $lon_diff = $lon2 - $lon1;

    $a = sin($lat_diff / 2) * sin($lat_diff / 2) + cos($lat1) * cos($lat2) * sin($lon_diff / 2) * sin($lon_diff / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth_radius * $c;
}
