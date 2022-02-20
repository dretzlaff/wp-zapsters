<?php

/**
 * Plugin Name: Zapsters
 *
 * Description: Relays DeroZap notifications to one or more endpoints.
 * Author: <a href="mailto:dretzlaff@gmail.com">Dan Retzlaff</a>
 * Version: 0.3
 */

define('ZAPSTERS_NAMESPACE', 'zapsters/v1');
define('ZAPSTERS_ZAPDATA', 'zapdata');
define('ZAPSTERS_RAWDATA', 'rawdata');
define('ZAPSTERS_DB_VERSION', '0.5');

function zapsters_activate() {
  add_option('zapsters_options');
  add_option('zapsters_db_version');
}
register_activation_hook( __FILE__, 'zapsters_activate' );

function zapsters_zapdata_table_name() {
  global $wpdb;
  return $wpdb->prefix . 'zapsters_zapdata';
}

function zapsters_dbsetup() {
  $current_version = get_option('zapsters_db_version');
  if ($current_verison == ZAPSTERS_DB_VERSION) {
    return;
  }

  global $wpdb;
  $table_name = zapsters_zapdata_table_name();
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table_name (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    time timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
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
  $wpdb->query("DROP TABLE IF EXISTS " . zapsters_zapdata_table_name());
  delete_option('zapsters_options');
}
register_uninstall_hook( __FILE__, 'zapsters_uninstall' );

function zapsters_settings_init() {
  register_setting( 'zapsters', 'zapsters_options' );
  add_settings_section(
    'zapsters_section_relay',
    __( 'Zap Data Relay Endpoints', 'zapsters' ),
    'zapsters_section_options_cb',
    'zapsters'
  );
  add_settings_field(
    'zapsters_field_relay_primary', __( 'Primary' ),
    'zapsters_field_relay_cb',
    'zapsters',
    'zapsters_section_relay',
    array(
      'label_for' => 'zapsters_field_relay_primary',
      'class' => 'wporg_row',
    )
  );
  add_settings_field(
    'zapsters_field_relay_besteffort', __( 'Best Effort' ),
    'zapsters_field_relay_cb',
    'zapsters',
    'zapsters_section_relay',
    array(
      'label_for' => 'zapsters_field_relay_besteffort',
      'class' => 'wporg_row',
    )
  );
}
add_action( 'admin_init', 'zapsters_settings_init' );

function zapsters_field_relay_cb( $args) {
  $options = get_option( 'zapsters_options' );
  $relay = $options[ $args['label_for'] ] ?? "";
  ?>
  <textarea rows="2" cols="70"
    id="<?php echo esc_attr( $args['label_for'] ); ?>"
    name="zapsters_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
  ><?php echo esc_html( $relay );?></textarea>
  <?php
}

function zapsters_endpoint($route) {
  return site_url() . '/' . rest_get_url_prefix() . '/' . ZAPSTERS_NAMESPACE . '/' . $route;
}

function zapsters_section_options_cb( $args ) {
  ?><p id="<?php echo esc_attr( $args['id'] ); ?>">
    This plugin has a URL for receiving DeroZap POST notifications at
    <a href="<?php echo zapsters_endpoint(ZAPSTERS_ZAPDATA); ?>"><?php echo zapsters_endpoint(ZAPSTERS_ZAPDATA) ?></a>.
    It relays these notifications to the endpoint(s) configured here. The primary
    endpoint's response will be returned to the DeroZap box (so errors can be retried),
    and the best effort endpoint's response will simply be logged.

    <p>The <a href="https://www.active4.me/">Active4.me</a> URL is:
    https://www.active4.me/api/dero/v1
  <?php
}

function zapsters_format_range($array) {
  if (count($array) == 0) return "";
  if (count($array) == 1) return $array[0];
  return min($array) . " - " . max($array);
}

function zapsters_enqueue_style($hook) {
  if (strpos($hook, 'zapsters')) {
    wp_enqueue_style( 'zapsters_style', plugin_dir_url( __FILE__ ) . 'zapsters.css' );
  }
}
add_action( 'admin_enqueue_scripts', 'zapsters_enqueue_style' );

function zapsters_page_html() {
  if (!current_user_can('manage_options')) {
    return;
  }
  if ( isset( $_GET['settings-updated'] ) ) {
    add_settings_error( 
      'zapsters_messages',
      'zapster_message',
      __( 'Settings Saved', 'zapsters' ),
      'updated' );
  }
  settings_errors( 'zapsters_messages' );
  ?>
  <div class="wrap">
      <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
      <h2>Recent Zap Data (<a href="<?php echo zapsters_endpoint(ZAPSTERS_RAWDATA); ?>?max_count=10&from_id=-1">raw</a>)</h2>
      <table id="zapdata">
        <tr>
          <th>ID</th>
          <th>Request Time</th>
          <th>Event Times</th>
          <th>Battery Voltages</th>
          <th>Status Events</th>
          <th>Zaps</th>
        </tr>
        <?php
          global $wpdb;
          $sql = 
            "SELECT * FROM " . zapsters_zapdata_table_name() . " ORDER BY id DESC LIMIT 10";
          foreach ($wpdb->get_results($sql) as $row) {
            $parsed = array();
            $voltages = array();
            $dateTimes = array();
            parse_str($row->request_body, $parsed);
            $statusEventCount = intval( $parsed['statusEventCount'] ?? "0" );
            $bikeEventCount = intval( $parsed['bikeEventCount'] ?? "0" );
            for ($i = 0; $i < $statusEventCount; $i++) {
              $dateTimes[] = date("h:i:s", intval( $parsed['DateTime' . $i] ));
              $voltages[] = floatval( $parsed['BatteryVoltage' . $i] );
            }
            for ($i = 0; $i < $bikeEventCount; $i++) {
              $dateTimes[] = date("h:i:s", intval( $parsed['BikeDateTime' . $i] ));
            }
            ?>
            <tr>
              <td><?php echo $row->id; ?></td>
              <td><?php echo $row->time; ?></td>
              <td><?php echo zapsters_format_range($dateTimes); ?></td>
              <td><?php echo zapsters_format_range($voltages); ?></td>
              <td><?php echo $statusEventCount; ?></td>
              <td><?php echo $bikeEventCount; ?></td>
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
function zapsters_page() {
  add_submenu_page(
    'tools.php',
    'Zapsters',
    'Zapsters',
    'manage_options',
    'zapsters',
    'zapsters_page_html'
  );
  add_allowed_options( array( 'zapsters' => array() ) );
}
add_action('admin_menu', 'zapsters_page');

function zapsters_zapdata_request( WP_REST_Request $request ) {
  global $wpdb;
  $dbdata = array('request_body' => $request->get_body());

  # We add an extra "norelay" param to relayed requests to avoid recursion. 
  if ($request->has_param('norelay')) {
    http_response_code(200);
    echo "ignoring request with norelay param\n";
    $wpdb->insert(zapsters_zapdata_table_name(), $dbdata);
    exit();
  }

  $zapsters_options = get_option( 'zapsters_options' );
  $post_args = array('body' => $request->get_body() . "&norelay");

  $primary_url = $zapsters_options[ 'zapsters_field_relay_primary' ];
  if (strlen($primary_url) > 0) {
    $response = wp_remote_post($primary_url, $post_args);
    if (is_wp_error($response)) {
      http_response_code(500);
      $dbdata['response_body'] = 'WP_Error: ' . $response->get_error_message();
    } else {
      $response_code = $response['response']['code'];
      http_response_code($response_code);
      echo $response['body'];
      $dbdata['response_code'] = $response_code;
      $dbdata['response_body'] = $response['body'];
    }
  }

  $besteffort_url = $zapsters_options[ 'zapsters_field_relay_besteffort' ];
  if (strlen($besteffort_url) > 0) {
    $besteffort_response = wp_remote_post($besteffort_url, $post_args);
    if (is_wp_error($besteffort_response)) {
      $dbdata['besteffort_response_body'] = 'WP_Error: ' . $response->get_error_message();
    } else {
      $dbdata['besteffort_response_code'] = $besteffort_response['response']['code'];
      $dbdata['besteffort_response_body'] = $besteffort_response['body'];
    }
  }

  $wpdb->insert(zapsters_zapdata_table_name(), $dbdata);
  exit();
}
function zapsters_rawdata_request( WP_REST_Request $request ) {
  $sql = "SELECT * FROM " . zapsters_zapdata_table_name();
  $fromId = $request->get_param('from_id');
  if ($fromId > 0) $sql .= " WHERE id < " . intval($fromId);
  $sql .= " ORDER BY id DESC";
  $maxCount = $request->get_param('max_count');
  if ($maxCount > 0) $sql .= " LIMIT " . intval($maxCount);

  global $wpdb;
  foreach ($wpdb->get_results($sql) as $row) {
    print json_encode($row, JSON_PRETTY_PRINT);
  }
  exit();
}
add_action( 'rest_api_init', function () {
  register_rest_route( ZAPSTERS_NAMESPACE, ZAPSTERS_ZAPDATA, array(
    'methods' => array('POST'),
    'callback' => 'zapsters_zapdata_request',
  ) );
  register_rest_route( ZAPSTERS_NAMESPACE, ZAPSTERS_RAWDATA, array(
    'methods' => array('GET'),
    'callback' => 'zapsters_rawdata_request',
  ) );
} );


add_filter( 'rest_url_prefix', 'zapsters_api_prefix' );
function zapsters_api_prefix() {
  return "api";
}

?>
