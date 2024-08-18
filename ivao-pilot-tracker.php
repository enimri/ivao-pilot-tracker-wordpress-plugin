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

    $result = ['departures' => '', 'arrivals' => ''];

    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';
    $icao_codes = $wpdb->get_col("SELECT icao_code FROM $table_name");

    ob_start(); // Start output buffering for departures
    echo '<tr><th>CALLSIGN</th><th>FROM</th><th>TO</th><th>ETD</th><th>EET</th><th>ETA</th><th>LAST TRACK</th></tr>';
    foreach ($data['clients']['pilots'] as $pilot) {
        $departureId = $pilot['flightPlan']['departureId'] ?? '';
        $arrivalId = $pilot['flightPlan']['arrivalId'] ?? '';
        $departureTime = $pilot['flightPlan']['departureTime'] ?? null;
        $arrivalTime = $pilot['flightPlan']['arrivalTime'] ?? null;
        $arrivalDistance = $pilot['lastTrack']['arrivalDistance'] ?? 0;
        $groundSpeed = $pilot['lastTrack']['groundSpeed'] ?? 0;
        $lastTrackTimestamp = $pilot['lastTrack']['timestamp'] ?? null;

        $eet = isset($pilot['flightPlan']['eet']) ? gmdate('H:i', $pilot['flightPlan']['eet']) . ' UTC' : 'N/A';
        $etd = calculate_etd($departureTime);
        $eta = calculate_eta($arrivalDistance, $groundSpeed, $lastTrackTimestamp);

        if (in_array($departureId, $icao_codes)) {
            echo '<tr>';
            echo '<td>' . esc_html($pilot['callsign']) . '</td>';
            echo '<td>' . esc_html($departureId) . '</td>';
            echo '<td>' . esc_html($arrivalId) . '</td>';
            echo '<td>' . esc_html($etd) . '</td>';
            echo '<td>' . esc_html($eet) . '</td>';
            echo '<td>' . esc_html($eta) . '</td>';
            echo '<td>' . esc_html($pilot['lastTrack']['state'] ?? 'Unknown') . '</td>';
            echo '</tr>';
        }
    }
    $result['departures'] = ob_get_clean(); // End buffering for departures

    ob_start(); // Start output buffering for arrivals
    echo '<tr><th>CALLSIGN</th><th>TO</th><th>FROM</th><th>ETD</th><th>EET</th><th>ETA</th><th>LAST TRACK</th></tr>';
    foreach ($data['clients']['pilots'] as $pilot) {
        $departureId = $pilot['flightPlan']['departureId'] ?? '';
        $arrivalId = $pilot['flightPlan']['arrivalId'] ?? '';
        $departureTime = $pilot['flightPlan']['departureTime'] ?? null;
        $arrivalTime = $pilot['flightPlan']['arrivalTime'] ?? null;
        $arrivalDistance = $pilot['lastTrack']['arrivalDistance'] ?? 0;
        $groundSpeed = $pilot['lastTrack']['groundSpeed'] ?? 0;
        $lastTrackTimestamp = $pilot['lastTrack']['timestamp'] ?? null;

        $eet = isset($pilot['flightPlan']['eet']) ? gmdate('H:i', $pilot['flightPlan']['eet']) . ' UTC' : 'N/A';
        $etd = calculate_etd($departureTime);
        $eta = calculate_eta($arrivalDistance, $groundSpeed, $lastTrackTimestamp);

        if (in_array($arrivalId, $icao_codes)) {
            echo '<tr>';
            echo '<td>' . esc_html($pilot['callsign']) . '</td>';
            echo '<td>' . esc_html($arrivalId) . '</td>';
            echo '<td>' . esc_html($departureId) . '</td>';
            echo '<td>' . esc_html($etd) . '</td>';
            echo '<td>' . esc_html($eet) . '</td>';
            echo '<td>' . esc_html($eta) . '</td>';
            echo '<td>' . esc_html($pilot['lastTrack']['state'] ?? 'Unknown') . '</td>';
            echo '</tr>';
        }
    }
    $result['arrivals'] = ob_get_clean(); // End buffering for arrivals

    return $result;
}

// AJAX handler to fetch IVAO data
function ivao_pilot_tracker_ajax_handler() {
    $data = fetch_ivao_data();
    wp_send_json($data);
}
add_action('wp_ajax_fetch_ivao_pilot_data', 'ivao_pilot_tracker_ajax_handler');
add_action('wp_ajax_nopriv_fetch_ivao_pilot_data', 'ivao_pilot_tracker_ajax_handler');

// Shortcode function to render the plugin output
function render_ivao_pilot_tracker() {
    ob_start(); // Start output buffering

    echo '<h2>PILOTS</h2>';
    echo '<div class="ivao-pilot-tracker">';

    // Departures section
    echo '<h3>Departures</h3>';
    echo '<div class="table-responsive"><table id="departures-table">';
    echo '</table></div>';

    // Arrivals section
    echo '<h3>Arrivals</h3>';
    echo '<div class="table-responsive"><table id="arrivals-table">';
    echo '</table></div>';

    echo '</div>';

    return ob_get_clean(); // Return the buffered content
}
add_shortcode('ivao_pilot_tracker', 'render_ivao_pilot_tracker');

// Enqueue the styles.css file
function ivao_pilot_tracker_enqueue_styles() {
    wp_enqueue_style('ivao-pilot-tracker-styles', plugins_url('styles.css', __FILE__));
}
add_action('wp_enqueue_scripts', 'ivao_pilot_tracker_enqueue_styles');

// Enqueue the refresh.js file
function ivao_pilot_tracker_enqueue_scripts() {
    wp_enqueue_script('ivao-pilot-tracker-js', plugins_url('refresh.js', __FILE__), ['jquery'], null, true);
    wp_localize_script('ivao-pilot-tracker-js', 'ivaoPT', [
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
}
add_action('wp_enqueue_scripts', 'ivao_pilot_tracker_enqueue_scripts');
