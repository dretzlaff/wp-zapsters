<?php

/**
 * Plugin Name: Zapsters
 *
 * Description: Relays DeroZap notifications to one or more endpoints.
 * Author: <a href="mailto:dretzlaff@gmail.com">Dan Retzlaff</a>
 * Plugin URI: https://github.com/dretzlaff/wp-zapsters 
 * Version: 0.12
 */

define('ZAPSTERS_NAMESPACE', 'zapsters/v1');
define('ZAPSTERS_DATA_ROUTE', 'zapdata');
define('ZAPSTERS_MAIL_ROUTE', 'mail');
define('ZAPSTERS_DB_VERSION', '0.8');

# DeroZap box reports epoch seconds in this timezone. 
define('ZAPSTERS_TIMEZONE', 'America/Denver');

/**************************************************
 * Database and options setup and teardown.
 *
 * https://developer.wordpress.org/reference/classes/wpdb/
 * https://developer.wordpress.org/plugins/settings/options-api/
 */

function zapsters_activate() {
  add_option('zapsters_options');
  add_option('zapsters_db_version');
}
register_activation_hook( __FILE__, 'zapsters_activate' );

function zapsters_table() {
  global $wpdb;
  return $wpdb->prefix . 'zapsters_zapdata';
}

function zapsters_dbsetup() {
  $current_version = get_option('zapsters_db_version');
  if ($current_version == ZAPSTERS_DB_VERSION) {
    return;
  }

  global $wpdb;
  $table_name = zapsters_table();
  $charset_collate = $wpdb->get_charset_collate();

  # Note that request_time must be populated in local time at the time of insertion,
  # i.e. current_time('mysql'). The ROCV database timezone seems to be US/Eastern,
  # and MySQL's timezone table isn't populated so using DEFAULT CURRENT_TIMESTAMP is
  # complicated. This approach removes any database setup or timezone dependency. A
  # more robust but slightly more complicated solution would be inserting GMT and
  # adapting to wp_timezone() during display.

  $sql = "CREATE TABLE $table_name (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    request_time timestamp NOT NULL,
    request_body mediumtext,
    response_code smallint,
    response_body mediumtext,
    besteffort_response_code smallint,
    besteffort_response_body mediumtext,
    PRIMARY KEY  (id)
  ) $charset_collate;";

  require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
  dbDelta( $sql );

  update_option('zapsters_db_version', ZAPSTERS_DB_VERSION);
}
add_action( 'plugins_loaded', 'zapsters_dbsetup' );

function zapsters_uninstall() {
  global $wpdb;
  $wpdb->query("DROP TABLE IF EXISTS " . zapsters_table());
  delete_option('zapsters_options');
  delete_option('zapsters_db_version');
}
register_uninstall_hook( __FILE__, 'zapsters_uninstall' );

/**************************************************
 * HTML rendering for Tools -> Zapsters admin page.
 *
 * https://developer.wordpress.org/themes/basics/including-css-javascript/
 * https://developer.wordpress.org/plugins/settings/settings-api/
 */

function zapsters_enqueue_style($hook) {
  if (strpos($hook, 'zapsters')) {
    wp_enqueue_style( 'zapsters_style', plugin_dir_url( __FILE__ ) . 'zapsters.css' );
  }
}
add_action( 'admin_enqueue_scripts', 'zapsters_enqueue_style' );

function zapsters_settings_init() {
  register_setting( 'zapsters', 'zapsters_options' );
  add_settings_section(
    'zapsters_section_relay',
    __( 'Zap Data Relay Endpoints', 'zapsters' ),
    'zapsters_section_options_cb',
    'zapsters'
  );
  zapsters_add_text_setting('zapsters_field_relay_primary', __('Primary'));
  zapsters_add_text_setting('zapsters_field_relay_besteffort', __('Best Effort'));
  zapsters_add_text_setting('zapsters_field_require_station', __('Require Station ID'));
  add_settings_field(
    'zapsters_field_mail_relay', 
    __('Email Subscription Relay'),
    'zapsters_field_drop_cb',
    'zapsters',
    'zapsters_section_relay',
    array(
      'label_for' => 'zapsters_field_mail_relay',
      'class' => 'wporg_row'
    )
  );
}
add_action( 'admin_init', 'zapsters_settings_init' );

function zapsters_add_text_setting( $id, $title ) {
  add_settings_field(
    $id,
    $title,
    'zapsters_field_text_cb',
    'zapsters',
    'zapsters_section_relay',
    array(
      'label_for' => $id,
      'class' => 'wporg_row',
    )
  );
}

function zapsters_field_drop_cb( $args ) {
  $id = $args['label_for'];
  $esc_id = esc_attr( $id );
  $value = get_option( 'zapsters_options' )[$id] ?? "none";
  ?>
  <select id="<?= $esc_id ?>" name="zapsters_options[<?= $esc_id ?>]">
    <option value="none"<?php if ($value == 'none') echo " selected" ?>>None</option>
    <option value="primary"<?php if ($value == 'primary') echo " selected" ?>>Primary</option>
    <option value="besteffort"<?php if ($value == 'besteffort') echo " selected" ?>>Best Effort</option>
  </select>
  <?php
}

function zapsters_field_text_cb( $args ) {
  $id = $args['label_for'];
  $esc_id = esc_attr( $id );
  $value = get_option( 'zapsters_options' )[$id] ?? "";
  ?>
  <textarea rows="2" cols="70"
    id="<?= $esc_id ?>"
    name="zapsters_options[<?= $esc_id ?>]"
  ><?= esc_html( $value ) ?></textarea>
  <?php
}

function zapsters_section_options_cb( $args ) {
  ?><p id="<?= esc_attr( $args['id'] ) ?>">
    This plugin has a URL for receiving DeroZap POST notifications at
    <a href="<?= zapsters_endpoint() ?>"><?= zapsters_endpoint() ?></a>.  
    It relays notifications to the endpoint(s) configured here. The primary endpoint's 
    response will be returned to the DeroZap box (so errors can be retried), and the best 
    effort endpoint's response will simply be recorded.

    <p>If a required station ID is configured, notifications with missing or mismatched
    IDs are ignored. This is a basic authentication measure.

    <p>The <a href="https://www.active4.me/">Active4.me</a> URL is
    <a href="https://www.active4.me/api/dero/v1">https://www.active4.me/api/dero/v1</a>.
  <?php
}

function zapsters_endpoint() {
  return site_url() . '/' . rest_get_url_prefix() . '/' . ZAPSTERS_NAMESPACE . '/' . ZAPSTERS_DATA_ROUTE;
}

function zapsters_zapdata_rows( $row_count = -1 ) {
  $sql = "SELECT * FROM " . zapsters_table() . " ORDER BY id DESC";
  if ($row_count > 0) $sql .= " LIMIT " . intval($row_count); # intval to sanitize
  global $wpdb;
  return $wpdb->get_results($sql);
}

function zapsters_page_html() {
  if (!current_user_can('manage_options')) {
    return;
  }
  if (isset( $_GET['settings-updated'] )) {
    add_settings_error( 
      'zapsters_messages',
      'zapster_message',
      __( 'Settings Saved', 'zapsters' ),
      'updated' );
  }
  settings_errors( 'zapsters_messages' );
  $raw_url = zapsters_endpoint(ZAPSTERS_DATA_ROUTE) . "?max_count=10";
  $raw_url = wp_nonce_url( $raw_url, 'wp_rest' );
  ?>
  <div class="wrap">
      <h1><?= esc_html( get_admin_page_title() ) ?></h1>
      <h2>Recent Zap Data (<a href="<?= $raw_url ?>">raw</a>)</h2>
      <table id="zapdata">
        <tr>
          <th>ID</th>
          <th>Request Time</th>
          <th>First Event</th>
          <th>Last Event</th>
          <th>Status Count</th>
          <th>Zap Count</th>
        </tr>
        <?php
          $zapsters_datetimezone = new DateTimeZone(ZAPSTERS_TIMEZONE);
          foreach (zapsters_zapdata_rows(10) as $row) {
            $parsed = array();
            parse_str($row->request_body, $parsed);
            $eventTimes = array();
            $statusEventCount = intval( $parsed['statusEventCount'] ?? "0" );
            $bikeEventCount = intval( $parsed['bikeEventCount'] ?? "0" );
            for ($i = 0; $i < $statusEventCount; $i++) {
              $eventTimes[] = wp_date("Y-m-d H:i:s", intval( $parsed['DateTime' . $i] ), $zapsters_datetimezone);
            }
            for ($i = 0; $i < $bikeEventCount; $i++) {
              $eventTimes[] = wp_date("Y-m-d H:i:s", intval( $parsed['BikeDateTime' . $i] ), $zapsters_datetimezone);
            }
            ?>
            <tr>
              <td><?= $row->id ?></td>
              <td><?= $row->request_time ?></td>
              <td><?php if (count($eventTimes) > 0) echo min($eventTimes) ?></td>
              <td><?php if (count($eventTimes) > 1) echo max($eventTimes) ?></td>
              <td><?= $statusEventCount ?></td>
              <td><?= $bikeEventCount ?></td>
            </tr>
            <?php
          }
        ?>
      </table>
      <form action="options.php" method="post">
        <?php
        settings_fields( 'zapsters' );
        do_settings_sections( 'zapsters' );
        submit_button( __( 'Save Settings', 'textdomain' ) );
        ?>
      </form>
  </div>
  <?php
}
function zapsters_add_page() {
  add_submenu_page(
    'tools.php',
    'Zapsters',
    'Zapsters',
    'manage_options',
    'zapsters',
    'zapsters_page_html'
  );
  # Whitelist this zapsters page so options.php doesn't reject our form data.
  add_allowed_options( array( 'zapsters' => array() ) );
}
add_action('admin_menu', 'zapsters_add_page');

/**************************************************
 * REST endpoint for recording and serving zap data.
 *
 * https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
 * https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
 */

function zapsters_zapdata_request( WP_REST_Request $request ) {
  global $wpdb;
  $options = get_option('zapsters_options');

  # Show a raw data dump in JSON.
  if ($request->get_method() == "GET") {
    $maxCount = $request->get_param('max_count');
    foreach (zapsters_zapdata_rows($maxCount) as $row) {
      print json_encode($row, JSON_PRETTY_PRINT);
    }
    exit();
  }

  # If configured to require a specific StationId, ignore without recording requests without it.
  $required_station = $options['zapsters_field_require_station'] ?? "";
  if (!empty($required_station) && $required_station != $request->get_param('StationId')) {
    http_response_code(400);
    echo "incorrect station id";
    exit();
  }

  $dbdata = array(
    'request_time' => current_time('mysql'),
    'request_body' => $request->get_body(),
  );

  # Record but don't relay requests with "norelay" param to avoid recursion.
  if ($request->has_param('norelay')) {
    http_response_code(200);
    echo "ignoring request with norelay param\n";
    $wpdb->insert(zapsters_table(), $dbdata);
    exit();
  }

  $relay_body = $request->get_body() . "&norelay";

  $primary_url = $options[ 'zapsters_field_relay_primary' ] ?? "";
  if (!empty($primary_url)) {
    $response = zapsters_post($primary_url, $relay_body);
    $dbdata['response_code'] = $response['code'];
    $dbdata['response_body'] = $response['body'];
    http_response_code($response['code']);
    echo $response['body'];
  }

  $besteffort_url = $options[ 'zapsters_field_relay_besteffort' ] ?? "";
  if (!empty($besteffort_url)) {
    $besteffort_response = zapsters_post($besteffort_url, $relay_body);
    $dbdata['besteffort_response_code'] = $besteffort_response['code'];
    $dbdata['besteffort_response_body'] = $besteffort_response['body'];
  }

  $wpdb->insert(zapsters_table(), $dbdata);

  # Only keep 1 year of data to limit database size.
  # This ignores the NOW() vs request_time timezone inconsistency.
  $delete_sql = "DELETE FROM " . zapsters_table();
  $delete_sql .= " WHERE request_time < DATE_SUB(NOW(), INTERVAL 1 YEAR)";
  $wpdb->query($delete_sql);
  exit();
}

/**
 * wp_remote_post() loses its mind on the 302 redirect resulting in a 400 response.
 * Having cURL (used by wp_remote_post() internally) follow the redirect itself
 * works fine, so that's what we do.
 */
function zapsters_post( $url, $request_body ) {
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => $request_body,
    CURLOPT_FOLLOWLOCATION => 1,
    CURLOPT_RETURNTRANSFER => 1,
  ));
  $response_body = curl_exec($ch);
  if (curl_errno($ch)) {
    $response_body = curl_error($ch);
    $response_code = 500;
  } else {
    $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  }
  # A missing Apps Script permission returns 200 with a big error HTML page.
  # Let's make sure these kinds of thing don't return 200 back to the box.
  if ($response_code == 200 && strpos($response_body, 'error')) {
    $response_code = 500;
  }
  curl_close($ch);
  return array('body' => $response_body, 'code' => $response_code);
}

function zapsters_mail_request( WP_REST_Request $request ) {
  $options = get_option('zapsters_options');
  $relay = $options[ 'zapsters_field_mail_relay' ] ?? "none";
  if ($relay == "none") {
    echo "subscription relay not configured";
    exit();
  }
  $relay_url = $options[ 'zapsters_field_relay_' . $relay ] ?? "";
  if ($relay_url == "") {
    echo "no $relay endpoint configured";
    exit();
  }
  $cid = $request->get_param( 'cid' );
  if (!$cid) {
    echo "missing 'cid' parameter";
    exit();
  }

  $relay_body = "cid=" . $cid;
  if ($request->get_param('resub')) {
    $relay_body .= "&resub=1";
  }

  $response = zapsters_post( $relay_url, $relay_body );

  http_response_code($response['code']);
  header("Content-Type: text/html");
  echo $response['body'];
  exit();
}

add_action( 'rest_api_init', function () {
  register_rest_route( ZAPSTERS_NAMESPACE, ZAPSTERS_DATA_ROUTE, array(
    'methods' => array('POST', 'GET'),
    'callback' => 'zapsters_zapdata_request',
    'permission_callback' => function( $request ) {
      return current_user_can("manage_options") || $request->get_method() == "POST";
    },
  ) );
} );

add_action( 'rest_api_init', function () {
  register_rest_route( ZAPSTERS_NAMESPACE, ZAPSTERS_MAIL_ROUTE, array(
    'methods' => array('POST', 'GET'),
    'callback' => 'zapsters_mail_request',
  ) );
} );

# The DeroZap box doesn't accept URLs with dashes, so use "api" instead of "wp-json".
add_filter( 'rest_url_prefix', 'zapsters_api_prefix' );
function zapsters_api_prefix() {
  return "api";
}

?>
