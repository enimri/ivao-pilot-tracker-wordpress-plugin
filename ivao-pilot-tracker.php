<?php
/*
Plugin Name: IVAO Pilot Tracker
Description: Displays pilot departures and arrivals for OJAI, OSDI, and ORBI airports with estimated ETD and EET. Now with a Scrollable Table on Mobile.
Version: 1.4
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Function to calculate distance between two points (Haversine formula)
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // in kilometers
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;

    $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlon / 2) * sin($dlon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    $distance = $earth_radius * $c; // in kilometers
    return $distance;
}

// Function to estimate EET based on distance and average speed
function estimate_eet($from, $to) {
    $coords = [
        'OJAI' => [31.7225, 35.9933],
        'OSDI' => [33.4114, 36.5108],
        'ORBI' => [33.2625, 44.2346],
        'OEJN' => [21.6796, 39.1565],
        // Add more airports as needed
    ];

    // Check if the coordinates exist for both departure and arrival airports
    if (!isset($coords[$from]) || !isset($coords[$to])) {
        return 'N/A';
    }

    $distance = calculate_distance($coords[$from][0], $coords[$from][1], $coords[$to][0], $coords[$to][1]);

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

    foreach ($data['clients']['pilots'] as $pilot) {
        $departureId = $pilot['flightPlan']['departureId'] ?? '';
        $arrivalId = $pilot['flightPlan']['arrivalId'] ?? '';
        $eet = $pilot['flightPlan']['eet'] ?? null; // Fetch EET directly from API if available
        $eet_formatted = $eet ? gmdate('H:i', $eet) . ' UTC' : 'N/A'; // Format EET if available
        $etd = estimate_etd($eet_formatted);

        if (in_array($departureId, ['OJAI', 'OSDI', 'ORBI'])) {
            $result['departures'][] = [
                'callsign' => $pilot['callsign'],
                'from' => $departureId,
                'etd' => $etd,  // Display ETD for departures
                'to' => $arrivalId,
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }

        if (in_array($arrivalId, ['OJAI', 'OSDI', 'ORBI'])) {
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
?>
