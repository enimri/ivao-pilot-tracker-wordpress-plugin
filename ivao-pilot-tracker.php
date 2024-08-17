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
        $eet = $pilot['flightPlan']['eet'] ?? 0;
        $departureTime = $pilot['flightPlan']['departureTime'] ?? null;

        if ($departureTime !== null) {
            $etd = format_etd($departureTime);
            $etd_seconds = (float)$departureTime;
        } else {
            $etd = 'N/A';
            $etd_seconds = 0;
        }

        $eta = $eet ? calculate_eta($etd_seconds, $eet) : 'N/A';

        if (in_array($departureId, $icao_codes)) {
            $result['departures'][] = [
                'callsign' => $pilot['callsign'],
                'from' => $departureId,
                'etd' => $etd,
                'to' => $arrivalId,
                'eta' => $eta,
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }

        if (in_array($arrivalId, $icao_codes)) {
            $result['arrivals'][] = [
                'callsign' => $pilot['callsign'],
                'to' => $arrivalId,
                'eet' => $eet ? format_eet($eet) : 'N/A',
                'from' => $departureId,
                'eta' => $eta,
                'last_track' => $pilot['lastTrack']['state'] ?? 'Unknown'
            ];
        }
    }

    return $result;
}

// Function to render the shortcode output
function render_ivao_pilot_tracker() {
    $data = fetch_ivao_data();

    ob_start();

    echo '<h2>PILOTS</h2>';
    echo '<div class="ivao-pilot-tracker">';

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
            background-color: #f2f2f2;
        }

        .ivao-pilot-tracker .table-responsive {
            overflow-x: auto;
        }
    </style>';

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('ivao_airport_tracker', 'render_ivao_pilot_tracker');

// Add menu item for plugin settings
function ivao_pilot_tracker_admin_menu() {
    add_menu_page(
        'IVAO Pilot Tracker',
        'IVAO Pilot Tracker',
        'manage_options',
        'ivao-pilot-tracker',
        'ivao_pilot_tracker_settings_page',
        'dashicons-admin-generic',
        20
    );
}
add_action('admin_menu', 'ivao_pilot_tracker_admin_menu');

// Display the settings page content
function ivao_pilot_tracker_settings_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ivao_airports';

    // Handle form submissions for adding/editing airports
    if (isset($_POST['action']) && check_admin_referer('ivao_pilot_tracker_action', 'ivao_pilot_tracker_nonce')) {
        if ($_POST['action'] == 'add') {
            $icao_code = sanitize_text_field($_POST['icao_code']);
            $latitude = floatval($_POST['latitude']);
            $longitude = floatval($_POST['longitude']);
            $wpdb->insert($table_name, ['icao_code' => $icao_code, 'latitude' => $latitude, 'longitude' => $longitude]);
        } elseif ($_POST['action'] == 'edit') {
            $id = intval($_POST['id']);
            $icao_code = sanitize_text_field($_POST['icao_code']);
            $latitude = floatval($_POST['latitude']);
            $longitude = floatval($_POST['longitude']);
            $wpdb->update($table_name, ['icao_code' => $icao_code, 'latitude' => $latitude, 'longitude' => $longitude], ['id' => $id]);
        } elseif ($_POST['action'] == 'delete') {
            $id = intval($_POST['id']);
            $wpdb->delete($table_name, ['id' => $id]);
        }
    }

    // Fetch all airports
    $airports = $wpdb->get_results("SELECT * FROM $table_name");

    ?>
    <div class="wrap">
        <h1>IVAO Pilot Tracker - Manage Airports</h1>

        <!-- Add/Edit Airport Form -->
        <form method="post" action="">
            <?php wp_nonce_field('ivao_pilot_tracker_action', 'ivao_pilot_tracker_nonce'); ?>
            <h2>Add/Edit Airport</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="icao_code">ICAO Code</label></th>
                    <td><input type="text" id="icao_code" name="icao_code" value="" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="latitude">Latitude</label></th>
                    <td><input type="number" id="latitude" name="latitude" step="0.000001" value="" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="longitude">Longitude</label></th>
                    <td><input type="number" id="longitude" name="longitude" step="0.000001" value="" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"></th>
                    <td>
                        <input type="hidden" name="action" value="add" />
                        <input type="submit" class="button-primary" value="Add Airport" />
                    </td>
                </tr>
            </table>
        </form>

        <!-- Edit Airport Form -->
        <form method="post" action="">
            <?php wp_nonce_field('ivao_pilot_tracker_action', 'ivao_pilot_tracker_nonce'); ?>
            <h2>Edit Airport</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="airport_id">Airport ID</label></th>
                    <td>
                        <select id="airport_id" name="id" required>
                            <option value="">Select an airport</option>
                            <?php foreach ($airports as $airport): ?>
                                <option value="<?php echo esc_attr($airport->id); ?>"><?php echo esc_html($airport->icao_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="edit_icao_code">ICAO Code</label></th>
                    <td><input type="text" id="edit_icao_code" name="icao_code" value="" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="edit_latitude">Latitude</label></th>
                    <td><input type="number" id="edit_latitude" name="latitude" step="0.000001" value="" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="edit_longitude">Longitude</label></th>
                    <td><input type="number" id="edit_longitude" name="longitude" step="0.000001" value="" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"></th>
                    <td>
                        <input type="hidden" name="action" value="edit" />
                        <input type="submit" class="button-primary" value="Update Airport" />
                    </td>
                </tr>
            </table>
        </form>

        <!-- Delete Airport -->
        <form method="post" action="">
            <?php wp_nonce_field('ivao_pilot_tracker_action', 'ivao_pilot_tracker_nonce'); ?>
            <h2>Remove Airport</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="remove_airport_id">Airport ID</label></th>
                    <td>
                        <select id="remove_airport_id" name="id" required>
                            <option value="">Select an airport</option>
                            <?php foreach ($airports as $airport): ?>
                                <option value="<?php echo esc_attr($airport->id); ?>"><?php echo esc_html($airport->icao_code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"></th>
                    <td>
                        <input type="hidden" name="action" value="delete" />
                        <input type="submit" class="button-primary" value="Remove Airport" />
                    </td>
                </tr>
            </table>
        </form>

        <!-- Display list of airports -->
        <h2>Airport List</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ICAO Code</th>
                    <th>Latitude</th>
                    <th>Longitude</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($airports as $airport): ?>
                    <tr>
                        <td><?php echo esc_html($airport->id); ?></td>
                        <td><?php echo esc_html($airport->icao_code); ?></td>
                        <td><?php echo esc_html($airport->latitude); ?></td>
                        <td><?php echo esc_html($airport->longitude); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
