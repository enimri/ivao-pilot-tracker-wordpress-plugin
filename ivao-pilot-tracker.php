<?php
/*
Plugin Name: IVAO Pilot Tracker
Description: Displays pilot departures and arrivals for selected airports with estimated ETD, EET, and ETA. Includes backend management for adding, editing, and removing airports.
Version: 1.20
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

// Function to calculate ETA and EET based on arrival distance, ground speed, departure time, and last track timestamp
function calculate_eta($arrivalDistance, $groundSpeed, $lastTrackTimestamp) {
    if ($groundSpeed > 0 && $arrivalDistance > 0 && $lastTrackTimestamp) {
        $arrivalDistanceInKm = $arrivalDistance * 1.852;
        $currentTime = time();
        $lastTrackTime = strtotime($lastTrackTimestamp);
        $etaSeconds = ($arrivalDistanceInKm / ($groundSpeed * 1.852)) * 3600;
        $etaTimestamp = $currentTime + $etaSeconds;
        $eta = gmdate('H:i', $etaTimestamp) . ' UTC';
    } else {
        $eta = 'N/A';
    }
    return $eta;
}

function calculate_etd($departureTime) {
    if ($departureTime) {
        return gmdate('H:i', $departureTime) . ' UTC';
    }
    return 'N/A';
}

function calculate_eet($departureTime, $arrivalTime) {
    if ($departureTime && $arrivalTime) {
        $eetSeconds = $arrivalTime - $departureTime;
        $hours = floor($eetSeconds / 3600);
        $minutes = floor(($eetSeconds % 3600) / 60);
        return sprintf('%02d:%02d', $hours, $minutes) . ' UTC';
    }
    return 'N/A';
}

// Function to fetch IVAO data
function fetch_ivao_data() {
    $response = wp_remote_get('https://api.ivao.aero/v2/tracker/whazzup');
    if (is_wp_error($response)) {
        return ['departures' => [], 'arrivals' => []];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    $result = ['departures' => [], 'arrivals' => []];

    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';
    $icao_codes = $wpdb->get_col("SELECT icao_code FROM $table_name");

    foreach ($data['clients']['pilots'] as $pilot) {
        $departureId = $pilot['flightPlan']['departureId'] ?? '';
        $arrivalId = $pilot['flightPlan']['arrivalId'] ?? '';
        $departureTime = $pilot['flightPlan']['departureTime'] ?? null;
        $arrivalTime = $pilot['flightPlan']['arrivalTime'] ?? null;
        $arrivalDistance = $pilot['lastTrack']['arrivalDistance'] ?? 0;
        $groundSpeed = $pilot['lastTrack']['groundSpeed'] ?? 0;
        $lastTrackTimestamp = $pilot['lastTrack']['timestamp'] ?? null;

        // Fetch EET directly from API response if available
        $eet = isset($pilot['flightPlan']['eet']) ? gmdate('H:i', $pilot['flightPlan']['eet']) . ' UTC' : 'N/A';

        $etd = calculate_etd($departureTime);
        $eta = calculate_eta($arrivalDistance, $groundSpeed, $lastTrackTimestamp);

        if (in_array($departureId, $icao_codes)) {
            $result['departures'][] = [
                'callsign' => $pilot['callsign'],
                'from' => $departureId,
                'to' => $arrivalId,
                'etd' => $etd,
                'eet' => $eet,
                'eta' => $eta,
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }

        if (in_array($arrivalId, $icao_codes)) {
            $result['arrivals'][] = [
                'callsign' => $pilot['callsign'],
                'to' => $arrivalId,
                'from' => $departureId,
                'etd' => $etd,
                'eet' => $eet,
                'eta' => $eta,
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }
    }

    return $result;
}

// Shortcode function to render the plugin output
function render_ivao_pilot_tracker() {
    $data = fetch_ivao_data();

    ob_start(); // Start output buffering

    echo '<h2></h2>';
    echo '<div class="ivao-pilot-tracker">';

    // Departures section
    echo '<h3>Departures</h3>';
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>CALLSIGN</th><th>FROM</th><th>TO</th><th>ETD</th><th>EET</th><th>ETA</th><th>LAST TRACK</th></tr>';
    if (!empty($data['departures'])) {
        foreach ($data['departures'] as $departure) {
            echo '<tr>';
            echo '<td>' . esc_html($departure['callsign']) . '</td>';
            echo '<td>' . esc_html($departure['from']) . '</td>';
            echo '<td>' . esc_html($departure['to']) . '</td>';
            echo '<td>' . esc_html($departure['etd']) . '</td>';
            echo '<td>' . esc_html($departure['eet']) . '</td>';
            echo '<td>' . esc_html($departure['eta']) . '</td>';
            echo '<td>' . esc_html($departure['last_track']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7">No departures</td></tr>';
    }
    echo '</table></div>';

    // Arrivals section
    echo '<h3>Arrivals</h3>';
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>CALLSIGN</th><th>TO</th><th>FROM</th><th>ETD</th><th>EET</th><th>ETA</th><th>LAST TRACK</th></tr>';
    if (!empty($data['arrivals'])) {
        foreach ($data['arrivals'] as $arrival) {
            echo '<tr>';
            echo '<td>' . esc_html($arrival['callsign']) . '</td>';
            echo '<td>' . esc_html($arrival['to']) . '</td>';
            echo '<td>' . esc_html($arrival['from']) . '</td>';
            echo '<td>' . esc_html($arrival['etd']) . '</td>';
            echo '<td>' . esc_html($arrival['eet']) . '</td>';
            echo '<td>' . esc_html($arrival['eta']) . '</td>';
            echo '<td>' . esc_html($arrival['last_track']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7">No arrivals</td></tr>';
    }
    echo '</table></div>';

    echo '</div>';

    return ob_get_clean(); // Return the buffered content
}
add_shortcode('ivao_airport_tracker', 'render_ivao_pilot_tracker');

// Enqueue the styles.css file
function ivao_pilot_tracker_enqueue_styles() {
    wp_enqueue_style('ivao-pilot-tracker-styles', plugins_url('styles.css', __FILE__));
}
add_action('wp_enqueue_scripts', 'ivao_pilot_tracker_enqueue_styles');

// Enqueue the auto-refresh script
function ivao_pilot_tracker_enqueue_scripts() {
    wp_enqueue_script('ivao-pilot-tracker-script', plugins_url('auto-refresh.js', __FILE__), array('jquery'), null, true);
    wp_localize_script('ivao-pilot-tracker-script', 'ivao_pilot_tracker_ajax', array('url' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'ivao_pilot_tracker_enqueue_scripts');

// Function to add a menu item for the plugin settings
function ivao_pilot_tracker_menu() {
    add_menu_page(
        'IVAO Pilot Tracker Settings',
        'IVAO Pilot Tracker',
        'manage_options',
        'ivao-pilot-tracker',
        'ivao_pilot_tracker_settings_page',
        'dashicons-admin-generic'
    );
}
add_action('admin_menu', 'ivao_pilot_tracker_menu');

// Function to display the settings page
function ivao_pilot_tracker_settings_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_POST['delete'])) {
            $id = intval($_POST['delete']);
            $wpdb->delete($table_name, ['id' => $id]);
        } else {
            $icao_code = sanitize_text_field($_POST['icao_code']);
            $latitude = floatval($_POST['latitude']);
            $longitude = floatval($_POST['longitude']);
            $wpdb->insert($table_name, [
                'icao_code' => $icao_code,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }
    }

    $airports = $wpdb->get_results("SELECT * FROM $table_name");

    echo '<div class="wrap">';
    echo '<h1>IVAO Pilot Tracker Settings</h1>';
    echo '<h2>Manage Airports</h2>';
    echo '<form method="POST">';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ICAO Code</th><th>Latitude</th><th>Longitude</th><th>Action</th></tr></thead>';
    echo '<tbody>';
    foreach ($airports as $airport) {
        echo '<tr>';
        echo '<td>' . esc_html($airport->icao_code) . '</td>';
        echo '<td>' . esc_html($airport->latitude) . '</td>';
        echo '<td>' . esc_html($airport->longitude) . '</td>';
        echo '<td>';
        echo '<button type="submit" name="delete" value="' . esc_attr($airport->id) . '" class="button button-secondary">Delete</button>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</form>';

    echo '<h2>Add New Airport</h2>';
    echo '<form method="POST">';
    echo '<table class="form-table">';
    echo '<tr><th scope="row"><label for="icao_code">ICAO Code</label></th>';
    echo '<td><input type="text" name="icao_code" id="icao_code" required></td></tr>';
    echo '<tr><th scope="row"><label for="latitude">Latitude</label></th>';
    echo '<td><input type="text" name="latitude" id="latitude" required></td></tr>';
    echo '<tr><th scope="row"><label for="longitude">Longitude</label></th>';
    echo '<td><input type="text" name="longitude" id="longitude" required></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" class="button button-primary" value="Add Airport"></p>';
    echo '</form>';
    echo '</div>';
}

// Handle AJAX request to refresh data
function ivao_pilot_tracker_ajax_handler() {
    echo render_ivao_pilot_tracker(); // Render the updated content
    wp_die(); // Required to terminate immediately and return a proper response
}
add_action('wp_ajax_ivao_pilot_tracker_refresh', 'ivao_pilot_tracker_ajax_handler');
add_action('wp_ajax_nopriv_ivao_pilot_tracker_refresh', 'ivao_pilot_tracker_ajax_handler');
