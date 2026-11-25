<?php
/**
 * WordPress functions and classes for the standalone suite, recorded in $GLOBALS['wp_test'].
 *
 * Mirrors of core where the plugin depends on the detail:
 *   - add_query_arg() re-encodes existing query values and adds new ones unencoded (a stub that encodes both
 *     hid a redirect loop in another fleet plugin);
 *   - sanitize_text_field() returns '' for arrays and objects and collapses whitespace;
 *   - WP_REST_Request::get_json_params() is null for a body that is not JSON;
 *   - current_time( 'mysql' ) is site time, two hours off GMT here, so a writer that mixes the two is visible.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

const ARRAY_A           = 'ARRAY_A';
const OBJECT            = 'OBJECT';
const HOUR_IN_SECONDS   = 3600;
const MINUTE_IN_SECONDS = 60;

/** Fresh recorder state. */
function wm_test_state(): array {
	return [
		'options'           => [],
		'transients'        => [],
		'actions'           => [],
		'shortcodes'        => [],
		'blocks'            => [],
		'routes'            => [],
		'scheduled'         => [],
		'unscheduled'       => [],
		'cleared'           => [],
		'http_queue'        => [],
		'http_calls'        => [],
		'styles'            => [],
		'scripts'           => [],
		'registered'        => [],
		'can'               => true,
		'user_id'           => 1,
		'translations'      => [],
		'redirects'         => [],
		'nonces_checked'    => 0,
		'menus'             => [],
		'widgets'           => [],
		'is_admin'            => true,
		'orders'              => [],
		'cli_items'           => null,
		'script_translations' => [],
		'registered_settings' => [],
		'block_bindings'      => [],
		'gb_tags'             => [],
		'deactivated'         => [],
		'died'                => [],
		'is_multisite'           => false,
		'network_active_plugins' => [],
		'sites'                  => [ 1 ],
		'blog_id'                => 1,
		'blog_stack'             => [],
		'blog_options'           => [],
		'blog_option_autoloads'  => [],
		'switch_log'             => [],
		'option_autoloads'       => [],
	];
}
$GLOBALS['wp_test'] = wm_test_state();

final class Test_Redirect extends Exception {}
final class Test_Die extends Exception {}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code;
	}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_data(): mixed {
		return $this->data;
	}
}

class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
}

class WP_REST_Request {
	private array $params  = [];
	private array $headers = [];
	private string $body   = '';

	public function __construct( public string $method = '', public string $route = '' ) {}

	public function set_param( string $key, mixed $value ): void {
		$this->params[ $key ] = $value;
	}

	public function get_param( string $key ): mixed {
		$json = $this->get_json_params();
		return is_array( $json ) && array_key_exists( $key, $json ) ? $json[ $key ] : ( $this->params[ $key ] ?? null );
	}

	public function set_body( string $body ): void {
		$this->body = $body;
	}

	public function get_body(): string {
		return $this->body;
	}

	public function set_header( string $key, string $value ): void {
		$this->headers[ strtolower( $key ) ] = $value;
	}

	public function get_header( string $key ): ?string {
		return $this->headers[ strtolower( $key ) ] ?? null;
	}

	public function get_json_params(): ?array {
		if ( ! str_contains( (string) ( $this->headers['content-type'] ?? '' ), 'json' ) ) {
			return null;
		}
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}

class WP_REST_Response {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
	public function get_data(): mixed {
		return $this->data;
	}
	public function get_status(): int {
		return $this->status;
	}
}

// --- i18n and escaping -----------------------------------------------------------------------------------------

function __( $text, $domain = 'default' ) {
	return $GLOBALS['wp_test']['translations'][ $text ] ?? $text;
}
function _e( $text, $domain = 'default' ): void {
	echo __( $text, $domain );
}
function _n( $single, $plural, $number, $domain = 'default' ) {
	return __( 1 === (int) $number ? $single : $plural, $domain );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = 'default' ) {
	return esc_html( __( $text, $domain ) );
}
function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( __( $text, $domain ) );
}
function esc_html_e( $text, $domain = 'default' ): void {
	echo esc_html__( $text, $domain );
}
function esc_attr_e( $text, $domain = 'default' ): void {
	echo esc_attr__( $text, $domain );
}
function esc_url( $url ) {
	return esc_attr( esc_url_raw( $url ) );
}
function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	return preg_match( '#^https?://#i', $url ) ? $url : '';
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function sanitize_text_field( $str ) {
	if ( is_object( $str ) || is_array( $str ) ) {
		return '';
	}
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
}
function sanitize_key( $key ) {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals, ',', '.' );
}

// --- options, transients, time ----------------------------------------------------------------------------------

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wp_test']['options'] ) ? $GLOBALS['wp_test']['options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wp_test']['options'][ $name ]          = $value;
	$GLOBALS['wp_test']['option_autoloads'][ $name ] = $autoload;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['wp_test']['options'][ $name ] );
	return true;
}
function delete_site_option( $name ) {
	return delete_option( $name );
}
function get_transient( $name ) {
	return $GLOBALS['wp_test']['transients'][ $name ] ?? false;
}
function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['wp_test']['transients'][ $name ] = $value;
	return true;
}
function delete_transient( $name ) {
	unset( $GLOBALS['wp_test']['transients'][ $name ] );
	return true;
}
function current_time( $type, $gmt = false ) {
	return gmdate( 'Y-m-d H:i:s', time() + ( $gmt ? 0 : 7200 ) );
}
function wp_salt( $scheme = 'auth' ) {
	return 'salt-' . $scheme;
}

// --- hooks and registrations -------------------------------------------------------------------------------------

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_test']['actions'][ $hook ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}
function do_action( $hook, ...$args ): void {
	foreach ( $GLOBALS['wp_test']['actions'][ $hook ] ?? [] as $callback ) {
		$callback( ...$args );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['wp_test']['actions'][ $hook ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function add_shortcode( $tag, $callback ): void {
	$GLOBALS['wp_test']['shortcodes'][ $tag ] = $callback;
}
function register_block_type( $name, $args = [] ) {
	if ( is_string( $name ) && is_file( rtrim( $name, '/' ) . '/block.json' ) ) {
		$meta = json_decode( (string) file_get_contents( rtrim( $name, '/' ) . '/block.json' ), true );
		if ( is_array( $meta ) && isset( $meta['name'] ) && is_string( $meta['name'] ) ) {
			$GLOBALS['wp_test']['blocks'][ $meta['name'] ] = array_merge( $meta, $args );
			return true;
		}
	}
	$GLOBALS['wp_test']['blocks'][ $name ] = $args;
	return true;
}
function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
	$GLOBALS['wp_test']['script_translations'][ $handle ] = [ 'domain' => $domain, 'path' => $path ];
	return true;
}
function register_setting( $option_group, $option_name, $args = [] ): void {
	$GLOBALS['wp_test']['registered_settings'][ $option_name ] = [ 'group' => $option_group, 'args' => $args ];
}
function register_block_bindings_source( $source_name, $source_properties ) {
	$GLOBALS['wp_test']['block_bindings'][ $source_name ] = $source_properties;
	return true;
}
class GenerateBlocks_Register_Dynamic_Tag {
	public function __construct( array $args ) {
		$GLOBALS['wp_test']['gb_tags'][ (string) ( $args['tag'] ?? '' ) ] = $args;
	}
}
function register_rest_route( $namespace, $route, $args = [] ) {
	$GLOBALS['wp_test']['routes'][ '/' . $namespace . $route ] = $args;
	return true;
}
function rest_validate_request_arg( $value, $request, $param ) {
	return true;
}
function rest_sanitize_request_arg( $value, $request, $param ) {
	return $value;
}
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$new = $args[0];
		$url = (string) ( $args[1] ?? '' );
	} else {
		$new = [ $args[0] => $args[1] ];
		$url = (string) ( $args[2] ?? '' );
	}
	[ $base, $query ] = array_pad( explode( '?', $url, 2 ), 2, '' );
	parse_str( $query, $existing );
	$existing = array_map( static fn( $v ) => is_string( $v ) ? urlencode( $v ) : $v, $existing );
	$merged   = array_merge( $existing, $new );
	$pairs    = [];
	foreach ( $merged as $key => $value ) {
		if ( false !== $value && null !== $value ) {
			$pairs[] = $key . '=' . $value;
		}
	}
	return $base . ( $pairs ? '?' . implode( '&', $pairs ) : '' );
}
function current_user_can( $capability ) {
	return (bool) $GLOBALS['wp_test']['can'];
}
function get_current_user_id() {
	return (int) $GLOBALS['wp_test']['user_id'];
}
function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
	++$GLOBALS['wp_test']['nonces_checked'];
	return 1;
}
function wp_nonce_field( $action = -1, $name = '_wpnonce' ): void {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce" />';
}
function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['wp_test']['redirects'][] = $location;
	throw new Test_Redirect( (string) $location );
}
function is_admin() {
	return (bool) $GLOBALS['wp_test']['is_admin'];
}
function add_menu_page( ...$args ) {
	$GLOBALS['wp_test']['menus'][] = $args;
	return 'toplevel_page_' . $args[3];
}
function wp_add_dashboard_widget( $id, $title, $callback ): void {
	$GLOBALS['wp_test']['widgets'][ $id ] = [ $title, $callback ];
}
function wp_register_style( $handle, ...$args ) {
	$GLOBALS['wp_test']['registered'][ 'style:' . $handle ] = $args;
	return true;
}
function wp_register_script( $handle, ...$args ) {
	$GLOBALS['wp_test']['registered'][ 'script:' . $handle ] = $args;
	return true;
}
function wp_enqueue_style( $handle, ...$args ): void {
	$GLOBALS['wp_test']['styles'][] = $handle;
}
function wp_enqueue_script( $handle, ...$args ): void {
	$GLOBALS['wp_test']['scripts'][] = $handle;
}
function wp_style_is( $handle, $list = 'enqueued' ) {
	return 'registered' === $list ? isset( $GLOBALS['wp_test']['registered'][ 'style:' . $handle ] ) : in_array( $handle, $GLOBALS['wp_test']['styles'], true );
}
function plugin_basename( $file ) {
	return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
}
function load_plugin_textdomain( ...$args ) {
	return true;
}
function plugin_dir_path( $file ) {
	return rtrim( dirname( (string) $file ), '/' ) . '/';
}
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( (string) $file ) ) . '/';
}
function register_activation_hook( $file, $callback ): void {
	$GLOBALS['wp_test']['activation'] = $callback;
}
function register_deactivation_hook( $file, $callback ): void {
	$GLOBALS['wp_test']['deactivation'] = $callback;
}
function wp_schedule_single_event( $timestamp, $hook, $args = [] ) {
	$GLOBALS['wp_test']['scheduled'][] = [ $hook, $args ];
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = [] ) {
	$GLOBALS['wp_test']['cleared'][] = $hook;
	return 0;
}
function wp_unschedule_hook( $hook ) {
	$GLOBALS['wp_test']['unscheduled'][] = $hook;
	return 0;
}
function wp_delete_file( $file ): void {
	@unlink( (string) $file );
}
function wp_tempnam( $filename = '', $dir = '' ) {
	unset( $filename, $dir );
	return tempnam( sys_get_temp_dir(), 'wm-peppol-' );
}
function deactivate_plugins( $plugins ): void {
	foreach ( (array) $plugins as $plugin ) {
		$GLOBALS['wp_test']['deactivated'][] = (string) $plugin;
	}
}
function wp_die( $message = '', $title = '', $args = [] ): void {
	$GLOBALS['wp_test']['died'][] = [ 'message' => (string) $message, 'title' => (string) $title, 'args' => $args ];
	throw new Test_Die( (string) $message );
}
function is_multisite(): bool {
	return (bool) $GLOBALS['wp_test']['is_multisite'];
}
function is_plugin_active_for_network( $plugin ): bool {
	return in_array( (string) $plugin, $GLOBALS['wp_test']['network_active_plugins'], true );
}
function get_sites( $args = [] ): array {
	return $GLOBALS['wp_test']['sites'];
}
function switch_to_blog( $new_blog_id, $deprecated = null ): bool {
	$current_id = (int) $GLOBALS['wp_test']['blog_id'];
	$GLOBALS['wp_test']['blog_options'][ $current_id ]          = $GLOBALS['wp_test']['options'];
	$GLOBALS['wp_test']['blog_option_autoloads'][ $current_id ] = $GLOBALS['wp_test']['option_autoloads'];
	$GLOBALS['wp_test']['blog_stack'][]                         = [
		'blog_id' => $current_id,
		'prefix'  => $GLOBALS['wpdb']->prefix,
	];
	$target_id = (int) $new_blog_id;
	$GLOBALS['wp_test']['blog_id']          = $target_id;
	$GLOBALS['wpdb']->prefix                = 1 === $target_id ? 'wp_' : 'wp_' . $target_id . '_';
	$GLOBALS['wp_test']['options']          = $GLOBALS['wp_test']['blog_options'][ $target_id ] ?? [];
	$GLOBALS['wp_test']['option_autoloads'] = $GLOBALS['wp_test']['blog_option_autoloads'][ $target_id ] ?? [];
	$GLOBALS['wp_test']['switch_log'][]     = [ 'switch', $target_id ];
	return true;
}
function restore_current_blog(): bool {
	$current_id = (int) $GLOBALS['wp_test']['blog_id'];
	$GLOBALS['wp_test']['blog_options'][ $current_id ]          = $GLOBALS['wp_test']['options'];
	$GLOBALS['wp_test']['blog_option_autoloads'][ $current_id ] = $GLOBALS['wp_test']['option_autoloads'];
	$prev = array_pop( $GLOBALS['wp_test']['blog_stack'] );
	if ( is_array( $prev ) ) {
		$prev_id = (int) $prev['blog_id'];
		$GLOBALS['wp_test']['blog_id']          = $prev_id;
		$GLOBALS['wpdb']->prefix                = (string) $prev['prefix'];
		$GLOBALS['wp_test']['options']          = $GLOBALS['wp_test']['blog_options'][ $prev_id ] ?? [];
		$GLOBALS['wp_test']['option_autoloads'] = $GLOBALS['wp_test']['blog_option_autoloads'][ $prev_id ] ?? [];
	}
	$GLOBALS['wp_test']['switch_log'][] = [ 'restore' ];
	return true;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function dbDelta( $queries = '', $execute = true ) {
	$GLOBALS['wpdb']->create_from_mysql( (string) $queries );
	return [];
}

// --- HTTP --------------------------------------------------------------------------------------------------------

function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' );
}
function trailingslashit( $string ) {
	return untrailingslashit( $string ) . '/';
}
function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['wp_test']['http_calls'][] = [ 'url' => $url, 'args' => $args, 'method' => 'GET' ];
	return array_shift( $GLOBALS['wp_test']['http_queue'] ) ?? new WP_Error( 'http_request_failed', 'no response queued' );
}
function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['wp_test']['http_calls'][] = [ 'url' => $url, 'args' => $args ];
	return array_shift( $GLOBALS['wp_test']['http_queue'] ) ?? new WP_Error( 'http_request_failed', 'no response queued' );
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : '';
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wm_test_http( int $code, string $body = '' ): array {
	return [ 'response' => [ 'code' => $code ], 'body' => $body ];
}
