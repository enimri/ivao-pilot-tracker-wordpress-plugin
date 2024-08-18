# IVAO Pilot Tracker

**Version:** 1.20  
**Author:** Eyad Nimri

## Description

IVAO Pilot Tracker is a WordPress plugin that displays real-time pilot departures and arrivals for selected airports. It shows estimated ETD (Estimated Time of Departure), EET (Estimated Elapsed Time), and ETA (Estimated Time of Arrival). The plugin also includes a backend management interface for adding, editing, and removing airports.

## Features

- **Real-time Data:** Displays pilot departures and arrivals with estimated times.
- **Backend Management:** Easily add, edit, or remove airports through the WordPress admin panel.
- **Automatic Refresh:** Data is refreshed every 3 seconds to ensure you have the most up-to-date information.

## Installation

1. **Download and Install:**
   - Download the plugin ZIP file from your source.
   - Go to your WordPress admin dashboard.
   - Navigate to **Plugins** > **Add New** > **Upload Plugin**.
   - Choose the ZIP file and click **Install Now**.
   - Activate the plugin from the **Plugins** menu.

2. **Add the Shortcode:**
   - Create a new page or edit an existing page.
   - Insert the shortcode `[ivao_pilot_tracker]` into the content area.
   - Publish or update the page.

## Configuration

1. **Database Setup:**
   - The plugin creates a custom database table to store airport information upon activation.

2. **Manage Airports:**
   - Go to **IVAO Pilot Tracker** in the WordPress admin menu.
   - Use the settings page to add new airports or delete existing ones.

## Frontend Display

- **Departures Table:** Lists all pilots currently departing from the airports you have added, including callsign, departure and arrival airports, ETD, EET, ETA, and last track information.
- **Arrivals Table:** Lists all pilots currently arriving at the airports you have added, with the same information as above.

## Customization

- **Styles:** You can customize the appearance of the tables by modifying the `styles.css` file located in the plugin directory.
- **JavaScript Refresh:** The data refresh interval is set to 3 seconds in `refresh.js`. You can adjust this as needed.

## Troubleshooting

- **Design Issues:** Ensure that the `styles.css` file is correctly enqueued and not overridden by other styles.
- **JavaScript Errors:** Check browser developer tools for any JavaScript errors and ensure the AJAX request URL is correct.

## Changelog

**1.20**
- Added AJAX handler to refresh data every 3 seconds.
- Improved the design to maintain consistency with the original layout.

