<?php

/**
 * Plugin Name: Zapsters
 *
 * Description: Relays DeroZap notifications to one or more endpoints.
 * Author: <a href="mailto:dretzlaff@gmail.com">Dan Retzlaff</a>
 * Version: 0.1
 */

define('ZAPSTERS_NAMESPACE', 'zapsters/v1');
define('ZAPSTERS_ROUTE', 'zapdata');
define('ZAPSTERS_DB_VERSION', '0.4');

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
    client_ip tinytext,
    method tinytext,
    request_body mediumtext,
    request_headers mediumtext,
    response_code smallint,
    response_body mediumtext,
    response_headers mediumtext,
    besteffort_response_code smallint,
    besteffort_response_body mediumtext,
    besteffort_response_headers mediumtext,
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
  ?>
  <textarea rows="2" cols="70"
    id="<?php echo esc_attr( $args['label_for'] ); ?>"
    name="zapsters_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
  ><?php echo esc_html( $options[ $args['label_for'] ] );?></textarea>
  <?php
}

function zapsters_section_options_cb( $args ) {
  ?><p id="<?php echo esc_attr( $args['id'] ); ?>">
    This plugin has a URL for receiving DeroZap notifications at
    <?php echo site_url() . '/wp-json/' . ZAPSTERS_NAMESPACE . '/' . ZAPSTERS_ROUTE ?>.
    It relays these notifications to the endpoint(s) configured here. The primary
    endpoint's response will be returned to the DeroZap box (so errors can be retried),
    and the best effort endpoint's response will simply be logged.

    <p>The <a href="https://www.active4.me/">Active4.me</a> URL is:
    https://www.active4.me/api/dero/v1
  <?php
}

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
      <h2>Recent Zap Data</h2>
      <table border="1" cellspacing="0">
        <tr>
          <th>Time</th><th>Client IP</th><th>Method</th>
          <th>Request Body</th><th>Request Headers</th>
        </tr>
        <?php
          global $wpdb;
          $sql = 
            "SELECT * FROM " . zapsters_zapdata_table_name() . 
            " ORDER BY id DESC LIMIT 10";
          foreach ($wpdb->get_results($sql) as $row) {
            ?>
            <tr>
              <td><?php echo esc_html( $row->time ); ?></td>
              <td><?php echo esc_html( $row->client_ip ); ?></td>
              <td><?php echo esc_html( $row->method ); ?></td>
              <td><?php echo esc_html( $row->request_body ); ?></td>
              <td><?php echo esc_html( $row->request_headers ); ?></td>
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

function zapsters_get_client_ip() {
  if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
    return $_SERVER['HTTP_CLIENT_IP'];
  } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
    return $_SERVER['HTTP_X_FORWARDED_FOR'];
  } else {
    return $_SERVER['REMOTE_ADDR'];
  }
}

function zapsters_zapdata_request( WP_REST_Request $request ) {
  $body = $request->get_body();
  $request_headers = "";
  foreach ($request->get_headers() as $name => $values) {
    foreach ($values as $value) {
      $request_headers .= "$name=$value\n";
    }
  }

  // TODO: get from real response
  $response_code = 200;
  $response_body = "OK";
  $response_headers = array();
  $response_headers[] = "Content-Type: text/plain";

  $dbdata = array(
    'client_ip' => zapsters_get_client_ip(),
    'method' => $request->get_method(),
    'request_body' => $request->get_body(),
    'request_headers' => $request_headers,
    'response_code' => $response_code,
    'response_body' => $response_body,
    'response_headers' => join('\n', $response_headers),
  );

  global $wpdb;
  $wpdb ->insert(zapsters_zapdata_table_name(), $dbdata);

  http_response_code($response_code);
  foreach ($response_headers as $response_header) {
    header($response_header);
  }
  echo $response_body;
  exit();
}
add_action( 'rest_api_init', function () {
  register_rest_route( ZAPSTERS_NAMESPACE, ZAPSTERS_ROUTE, array(
    'methods' => array('GET', 'POST'),
    'callback' => 'zapsters_zapdata_request',
  ) );
} );

?>
