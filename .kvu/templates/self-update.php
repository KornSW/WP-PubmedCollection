<?php
/**
 * KornSW Self Update Loader
 *
 * Dieses Template wird beim Release pro Plugin mit einem persistenten,
 * kollisionsfreien Funktions-/Klassenpräfix personalisiert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function {{KSWUPD_PREFIX}}_bootstrap( $plugin_file ) {
	static $instances = [];

	$plugin_file = wp_normalize_path( $plugin_file );
	$plugin_key  = plugin_basename( $plugin_file );

	if ( isset( $instances[ $plugin_key ] ) ) {
		return $instances[ $plugin_key ];
	}

	$instance = new {{KSWUPD_CLASS_PREFIX}}SelfUpdate( $plugin_file );
	$instance->register_hooks();

	$instances[ $plugin_key ] = $instance;

	return $instance;
}

class {{KSWUPD_CLASS_PREFIX}}SelfUpdate {

		private string $plugin_file;
		private string $plugin_basename;
		private ?array $plugin_headers = null;

		public function __construct( string $plugin_file ) {
			$this->plugin_file     = wp_normalize_path( $plugin_file );
			$this->plugin_basename = plugin_basename( $this->plugin_file );
		}

		public function register_hooks(): void {
			if (
				is_admin()
				&& defined( '{{KSWUPD_CLASS_PREFIX}}_SELF_UPDATE_DIAGNOSTICS' )
				&& {{KSWUPD_CLASS_PREFIX}}_SELF_UPDATE_DIAGNOSTICS
			) {
				add_action( 'admin_menu', [ $this, 'register_diagnostics_page' ] );
			}

			$headers    = $this->get_plugin_headers();
			$update_uri = trim( (string) ( $headers['UpdateURI'] ?? '' ) );

			if ( $update_uri === '' ) {
				return;
			}

			$host = wp_parse_url( $update_uri, PHP_URL_HOST );

			if ( empty( $host ) ) {
				return;
			}

			add_filter( 'update_plugins_' . $host, [ $this, 'filter_update_response' ], 10, 4 );
			add_filter( 'plugins_api', [ $this, 'filter_plugin_information' ], 20, 3 );
			add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update_transient' ] );
			add_filter( 'plugin_row_meta', [ $this, 'filter_plugin_row_meta' ], 10, 2 );
			add_action( 'upgrader_process_complete', [ $this, 'schedule_post_update_refresh' ], 100, 2 );

			if ( is_admin() ) {
				add_action( 'admin_init', [ $this, 'maybe_refresh_wordpress_update_state' ], 20 );
				add_action( 'admin_notices', [ $this, 'render_update_refresh_notice' ] );
			}
		}

		private function get_plugin_headers(): array {
			if ( null !== $this->plugin_headers ) {
				return $this->plugin_headers;
			}

			$this->plugin_headers = get_file_data(
				$this->plugin_file,
				[
					'Name'        => 'Plugin Name',
					'Version'     => 'Version',
					'Description' => 'Description',
					'PluginURI'   => 'Plugin URI',
					'Author'      => 'Author',
					'AuthorURI'   => 'Author URI',
					'RequiresWP'  => 'Requires at least',
					'RequiresPHP' => 'Requires PHP',
					'UpdateURI'   => 'Update URI',
				],
				'plugin'
			);

			return $this->plugin_headers;
		}

		private function get_metadata_url(): string {
			$headers    = $this->get_plugin_headers();
			$update_uri = trim( (string) ( $headers['UpdateURI'] ?? '' ) );

			if ( $update_uri === '' ) {
				return '';
			}

			return $update_uri;
		}

		private function get_slug(): string {
			$slug = dirname( $this->plugin_basename );

			if ( $slug === '.' || $slug === '' ) {
				$slug = basename( $this->plugin_basename, '.php' );
			}

			return $slug;
		}

		private function get_remote_metadata() {
			$url = $this->get_metadata_url();

			if ( $url === '' ) {
				return false;
			}

			$response = wp_remote_get(
				$url,
				[
					'timeout' => 15,
					'headers' => [
						'Accept' => 'application/json',
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				return false;
			}

			$body = wp_remote_retrieve_body( $response );

			if ( ! is_string( $body ) || $body === '' ) {
				return false;
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || empty( $data['version'] ) ) {
				return false;
			}

			return $data;
		}

		public function filter_update_response( $update, $plugin_data, $plugin_file, $locales ) {
			if ( $plugin_file !== $this->plugin_basename ) {
				return $update;
			}

			$remote  = $this->get_remote_metadata();
			$headers = $this->get_plugin_headers();

			if ( ! $remote ) {
				return false;
			}

			$new_version = (string) $remote['version'];

			return [
				'id'           => (string) ( $headers['UpdateURI'] ?? '' ),
				'slug'         => $this->get_slug(),
				'plugin'       => $this->plugin_basename,
				'version'      => $new_version,
				'url'          => ! empty( $remote['homepage'] ) ? (string) $remote['homepage'] : '',
				'package'      => ! empty( $remote['download_url'] ) ? (string) $remote['download_url'] : '',
				'tested'       => ! empty( $remote['tested'] ) ? (string) $remote['tested'] : '',
				'requires_php' => ! empty( $remote['requires_php'] ) ? (string) $remote['requires_php'] : '',
				'requires'     => ! empty( $remote['requires'] ) ? (string) $remote['requires'] : '',
			];
		}

		public function inject_update_transient( $transient ) {
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = [];
			}

			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}

			$remote  = $this->get_remote_metadata();
			$headers = $this->get_plugin_headers();

			if ( ! $remote ) {
				return $transient;
			}

			$current_version = ! empty( $headers['Version'] ) ? (string) $headers['Version'] : '0.0.0';
			$new_version     = ! empty( $remote['version'] ) ? (string) $remote['version'] : $current_version;

			$item = (object) [
				'id'           => ! empty( $headers['UpdateURI'] ) ? (string) $headers['UpdateURI'] : '',
				'slug'         => $this->get_slug(),
				'plugin'       => $this->plugin_basename,
				'new_version'  => $new_version,
				'url'          => ! empty( $remote['homepage'] ) ? (string) $remote['homepage'] : '',
				'package'      => ! empty( $remote['download_url'] ) ? (string) $remote['download_url'] : '',
				'tested'       => ! empty( $remote['tested'] ) ? (string) $remote['tested'] : '',
				'requires'     => ! empty( $remote['requires'] ) ? (string) $remote['requires'] : '',
				'requires_php' => ! empty( $remote['requires_php'] ) ? (string) $remote['requires_php'] : '',
			];

			if ( version_compare( $new_version, $current_version, '>' ) ) {
				$transient->response[ $this->plugin_basename ] = $item;
				unset( $transient->no_update[ $this->plugin_basename ] );
			} else {
				$transient->no_update[ $this->plugin_basename ] = $item;
				unset( $transient->response[ $this->plugin_basename ] );
			}

			return $transient;
		}

		public function filter_plugin_information( $result, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}

			if ( empty( $args->slug ) || $args->slug !== $this->get_slug() ) {
				return $result;
			}

			$remote  = $this->get_remote_metadata();
			$headers = $this->get_plugin_headers();

			if ( ! $remote ) {
				return $result;
			}

			$info = new stdClass();

			$info->name          = ! empty( $remote['name'] ) ? (string) $remote['name'] : (string) ( $headers['Name'] ?? '' );
			$info->slug          = $this->get_slug();
			$info->version       = ! empty( $remote['version'] ) ? (string) $remote['version'] : (string) ( $headers['Version'] ?? '' );
			$info->author        = ! empty( $remote['author'] ) ? (string) $remote['author'] : (string) ( $headers['Author'] ?? '' );
			$info->homepage      = ! empty( $remote['homepage'] ) ? (string) $remote['homepage'] : (string) ( $headers['PluginURI'] ?? '' );
			$info->requires      = ! empty( $remote['requires'] ) ? (string) $remote['requires'] : (string) ( $headers['RequiresWP'] ?? '' );
			$info->requires_php  = ! empty( $remote['requires_php'] ) ? (string) $remote['requires_php'] : (string) ( $headers['RequiresPHP'] ?? '' );
			$info->tested        = ! empty( $remote['tested'] ) ? (string) $remote['tested'] : '';
			$info->download_link = ! empty( $remote['download_url'] ) ? (string) $remote['download_url'] : '';

			$description = '';

			if ( ! empty( $remote['sections']['description'] ) ) {
				$description = (string) $remote['sections']['description'];
			} elseif ( ! empty( $headers['Description'] ) ) {
				$description = (string) $headers['Description'];
			}

			$changelog = '';

			if ( ! empty( $remote['sections']['changelog'] ) ) {
				$changelog = (string) $remote['sections']['changelog'];
			}

			$info->sections = [
				'description' => $description,
				'changelog'   => $changelog,
			];

			return $info;
		}

		public function schedule_post_update_refresh( $upgrader, $hook_extra ): void {
			if (
				! is_array( $hook_extra )
				|| 'update' !== (string) ( $hook_extra['action'] ?? '' )
				|| 'plugin' !== (string) ( $hook_extra['type'] ?? '' )
			) {
				return;
			}

			$updated_plugins = [];

			if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$updated_plugins = array_map( 'strval', $hook_extra['plugins'] );
			} elseif ( isset( $hook_extra['plugin'] ) ) {
				$updated_plugins[] = (string) $hook_extra['plugin'];
			}

			if ( ! in_array( $this->plugin_basename, $updated_plugins, true ) ) {
				return;
			}

			/*
			 * Do not rebuild the update transient from inside the upgrader callback itself.
			 * WordPress may still perform bookkeeping after upgrader_process_complete.
			 * Running at shutdown guarantees that the freshly installed plugin files are
			 * already in place and that our refresh is the final update-state operation
			 * of this request.
			 */
			add_action( 'shutdown', [ $this, 'refresh_wordpress_update_state_after_self_update' ], PHP_INT_MAX );
		}

		public function refresh_wordpress_update_state_after_self_update(): void {
			$this->refresh_wordpress_update_state();
		}

		public function render_update_refresh_notice(): void {
			if (
				! current_user_can( 'update_plugins' )
				|| ! isset( $_GET['{{KSWUPD_PREFIX}}_update_state_refreshed'] )
				|| '1' !== (string) $_GET['{{KSWUPD_PREFIX}}_update_state_refreshed']
			) {
				return;
			}

			echo '<div class="notice notice-success is-dismissible"><p>Der WordPress-Update-Status wurde neu aufgebaut.</p></div>';
		}

		public function maybe_refresh_wordpress_update_state(): void {
			if ( ! current_user_can( 'update_plugins' ) ) {
				return;
			}

			if ( isset( $_GET['{{KSWUPD_PREFIX}}_refresh_update_state'] ) ) {
				$requested_plugin = sanitize_text_field(
					wp_unslash( (string) $_GET['{{KSWUPD_PREFIX}}_refresh_update_state'] )
				);

				if ( $requested_plugin === $this->plugin_basename ) {
					check_admin_referer( '{{KSWUPD_PREFIX}}_refresh_update_state' );
					$this->refresh_wordpress_update_state();

					wp_safe_redirect(
						add_query_arg(
							'{{KSWUPD_PREFIX}}_update_state_refreshed',
							'1',
							admin_url( 'plugins.php' )
						)
					);
					exit;
				}
			}

			$transient     = get_site_transient( 'update_plugins' );
			$local_version = trim( (string) ( $this->get_plugin_headers()['Version'] ?? '' ) );
			$needs_refresh = false;

			if ( ! is_object( $transient ) ) {
				$needs_refresh = true;
			}

			$in_response = is_object( $transient )
				&& isset( $transient->response[ $this->plugin_basename ] );

			$in_no_update = is_object( $transient )
				&& isset( $transient->no_update[ $this->plugin_basename ] );

			if ( ! $in_response && ! $in_no_update ) {
				$needs_refresh = true;
			}

			$checked_version = '';
			if (
				is_object( $transient )
				&& isset( $transient->checked )
				&& is_array( $transient->checked )
				&& isset( $transient->checked[ $this->plugin_basename ] )
			) {
				$checked_version = trim( (string) $transient->checked[ $this->plugin_basename ] );
			}

			if ( $checked_version === '' || $checked_version !== $local_version ) {
				$needs_refresh = true;
			}

			if ( $in_response ) {
				$remote_version = trim(
					(string) ( $transient->response[ $this->plugin_basename ]->new_version ?? '' )
				);

				if (
					$remote_version === ''
					|| version_compare( $remote_version, $local_version, '<=' )
				) {
					$needs_refresh = true;
				}
			}

			if ( $in_no_update ) {
				$remote_version = trim(
					(string) ( $transient->no_update[ $this->plugin_basename ]->new_version ?? '' )
				);

				if (
					$remote_version !== ''
					&& version_compare( $remote_version, $local_version, '>' )
				) {
					$needs_refresh = true;
				}
			}

			if ( $needs_refresh ) {
				$this->refresh_wordpress_update_state();
			}
		}

		private function refresh_wordpress_update_state(): void {
			static $refresh_in_progress = false;

			if ( $refresh_in_progress ) {
				return;
			}

			$refresh_in_progress = true;

			delete_site_transient( 'update_plugins' );

			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache( true );
			}

			if ( function_exists( 'wp_update_plugins' ) ) {
				wp_update_plugins();
			}

			$refresh_in_progress = false;
		}

		public function filter_plugin_row_meta( $links, $plugin_file ) {
			if ( $plugin_file !== $this->plugin_basename ) {
				return $links;
			}

			$headers    = $this->get_plugin_headers();
			$plugin_uri = trim( (string) ( $headers['PluginURI'] ?? '' ) );

			if ( $plugin_uri === '' ) {
				return $links;
			}

			$host = wp_parse_url( $plugin_uri, PHP_URL_HOST );

			if ( strtolower( (string) $host ) !== 'github.com' ) {
				return $links;
			}

			$links[] = '<a href="' . esc_url( $plugin_uri ) . '" target="_blank" rel="noopener noreferrer">GitHub Repository</a>';

			if ( current_user_can( 'update_plugins' ) ) {
				$refresh_url = wp_nonce_url(
					add_query_arg(
						'{{KSWUPD_PREFIX}}_refresh_update_state',
						rawurlencode( $this->plugin_basename ),
						admin_url( 'plugins.php' )
					),
					'{{KSWUPD_PREFIX}}_refresh_update_state'
				);

				$links[] = '<a href="' . esc_url( $refresh_url ) . '">Update-Status neu prüfen</a>';
			}

			return $links;
		}

		public function register_diagnostics_page(): void {
			add_management_page(
				'Self-Update Diagnose (KornSW)',
				'Self-Update Diagnose (KornSW)',
				'manage_options',
				'{{KSWUPD_PREFIX}}-self-update-diagnostics',
				[ $this, 'render_diagnostics_page' ]
			);
		}

		private function diagnostic_http_request( bool $bypass_cache = false ): array {
			$url = $this->get_metadata_url();

			if ( $url === '' ) {
				return [
					'url'        => '',
					'wp_error'   => 'Keine Update URI vorhanden.',
					'http_code'  => 0,
					'headers'    => [],
					'body_length'=> 0,
					'json_error' => '',
					'version'    => '',
					'download'   => '',
				];
			}

			$request_url = $url;
			$headers     = [
				'Accept' => 'application/json',
			];

			if ( $bypass_cache ) {
				$request_url = add_query_arg(
					'ksw_diag',
					rawurlencode( (string) microtime( true ) ),
					$url
				);
				$headers['Cache-Control'] = 'no-cache';
				$headers['Pragma']        = 'no-cache';
			}

			$response = wp_remote_get(
				$request_url,
				[
					'timeout' => 15,
					'headers' => $headers,
				]
			);

			if ( is_wp_error( $response ) ) {
				return [
					'url'         => $request_url,
					'wp_error'    => $response->get_error_code() . ': ' . $response->get_error_message(),
					'http_code'   => 0,
					'headers'     => [],
					'body_length' => 0,
					'json_error'  => '',
					'version'     => '',
					'download'    => '',
				];
			}

			$body         = wp_remote_retrieve_body( $response );
			$decoded      = json_decode( $body, true );
			$json_error   = json_last_error_msg();
			$resp_headers = wp_remote_retrieve_headers( $response );

			$interesting_headers = [];
			foreach ( [ 'etag', 'last-modified', 'cache-control', 'age', 'via', 'x-cache' ] as $header_name ) {
				$value = '';

				if ( is_object( $resp_headers ) && isset( $resp_headers[ $header_name ] ) ) {
					$value = (string) $resp_headers[ $header_name ];
				} elseif ( is_array( $resp_headers ) && isset( $resp_headers[ $header_name ] ) ) {
					$value = (string) $resp_headers[ $header_name ];
				}

				$interesting_headers[ $header_name ] = $value;
			}

			return [
				'url'         => $request_url,
				'wp_error'    => '',
				'http_code'   => (int) wp_remote_retrieve_response_code( $response ),
				'headers'     => $interesting_headers,
				'body_length' => is_string( $body ) ? strlen( $body ) : 0,
				'json_error'  => $json_error,
				'version'     => is_array( $decoded ) && ! empty( $decoded['version'] ) ? (string) $decoded['version'] : '',
				'download'    => is_array( $decoded ) && ! empty( $decoded['download_url'] ) ? (string) $decoded['download_url'] : '',
			];
		}

		private function diagnostic_transient_value( $container, string $property, string $plugin_key ) {
			if ( ! is_object( $container ) || ! isset( $container->{$property} ) || ! is_array( $container->{$property} ) ) {
				return null;
			}

			return $container->{$property}[ $plugin_key ] ?? null;
		}

		private function diagnostic_dump( $value ): string {
			if ( null === $value ) {
				return '(nicht vorhanden)';
			}

			if ( is_scalar( $value ) ) {
				return (string) $value;
			}

			return print_r( $value, true );
		}

		public function render_diagnostics_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Du hast keine Berechtigung für diese Seite.' ) );
			}

			$forced = false;

			if ( isset( $_POST['ksw_force_update_check'] ) ) {
				check_admin_referer( '{{KSWUPD_PREFIX}}_force_update_check' );

				delete_site_transient( 'update_plugins' );

				if ( function_exists( 'wp_clean_plugins_cache' ) ) {
					wp_clean_plugins_cache( true );
				}

				wp_update_plugins();

				$forced = true;
			}

			$headers       = $this->get_plugin_headers();
			$metadata_url  = $this->get_metadata_url();
			$metadata_host = wp_parse_url( $metadata_url, PHP_URL_HOST );
			$host_hook     = ! empty( $metadata_host ) ? 'update_plugins_' . $metadata_host : '';
			$transient     = get_site_transient( 'update_plugins' );

			$checked   = $this->diagnostic_transient_value( $transient, 'checked', $this->plugin_basename );
			$response  = $this->diagnostic_transient_value( $transient, 'response', $this->plugin_basename );
			$no_update = $this->diagnostic_transient_value( $transient, 'no_update', $this->plugin_basename );

			$normal_http = $this->diagnostic_http_request( false );
			$bypass_http = $this->diagnostic_http_request( true );

			$runtime_rows = [
				'Plugin-Datei'              => $this->plugin_file,
				'plugin_basename'           => $this->plugin_basename,
				'Plugin-Slug'               => $this->get_slug(),
				'Lokale Version'            => (string) ( $headers['Version'] ?? '' ),
				'Plugin URI'                => (string) ( $headers['PluginURI'] ?? '' ),
				'Update URI'                => $metadata_url,
				'Bootstrap-Funktion'        => '{{KSWUPD_PREFIX}}_bootstrap',
				'Updater-Klasse'            => '{{KSWUPD_CLASS_PREFIX}}SelfUpdate',
				'Host-Filter'               => $host_hook,
				'Host-Filter registriert'   => $host_hook !== '' && false !== has_filter( $host_hook ) ? 'JA' : 'NEIN',
				'Transient-Filter registriert' => false !== has_filter( 'pre_set_site_transient_update_plugins' ) ? 'JA' : 'NEIN',
				'plugins_api registriert'   => false !== has_filter( 'plugins_api' ) ? 'JA' : 'NEIN',
				'plugin_row_meta registriert' => false !== has_filter( 'plugin_row_meta' ) ? 'JA' : 'NEIN',
			];

			?>
			<div class="wrap">
				<h1>Self-Update Diagnose (KornSW)</h1>

				<?php if ( $forced ) : ?>
					<div class="notice notice-success"><p>WordPress-Update-Check wurde erzwungen.</p></div>
				<?php endif; ?>

				<p>Diese Seite verändert die Update-Logik nicht. Sie zeigt den tatsächlich geladenen Runtime-Zustand, zwei direkte HTTP-Abrufe der Update-Metadaten und den aktuellen WordPress-Update-Transient.</p>

				<form method="post" style="margin: 18px 0;">
					<?php wp_nonce_field( '{{KSWUPD_PREFIX}}_force_update_check' ); ?>
					<input type="hidden" name="ksw_force_update_check" value="1">
					<?php submit_button( 'Update-Check jetzt erzwingen', 'primary', 'submit', false ); ?>
				</form>

				<h2>Runtime und Hooks</h2>
				<table class="widefat striped" style="max-width: 1200px;">
					<tbody>
					<?php foreach ( $runtime_rows as $label => $value ) : ?>
						<tr>
							<th style="width: 260px;"><?php echo esc_html( $label ); ?></th>
							<td><code><?php echo esc_html( $value ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2>HTTP-Test: normal</h2>
				<?php $this->render_diagnostic_http_table( $normal_http ); ?>

				<h2>HTTP-Test: Cache-Bypass</h2>
				<p>Zusätzliches Query-Argument sowie <code>Cache-Control: no-cache</code> und <code>Pragma: no-cache</code>.</p>
				<?php $this->render_diagnostic_http_table( $bypass_http ); ?>

				<h2>WordPress update_plugins Transient</h2>
				<table class="widefat striped" style="max-width: 1200px;">
					<tbody>
						<tr>
							<th style="width: 260px;">checked[plugin]</th>
							<td><pre style="white-space: pre-wrap;"><?php echo esc_html( $this->diagnostic_dump( $checked ) ); ?></pre></td>
						</tr>
						<tr>
							<th>response[plugin]</th>
							<td><pre style="white-space: pre-wrap;"><?php echo esc_html( $this->diagnostic_dump( $response ) ); ?></pre></td>
						</tr>
						<tr>
							<th>no_update[plugin]</th>
							<td><pre style="white-space: pre-wrap;"><?php echo esc_html( $this->diagnostic_dump( $no_update ) ); ?></pre></td>
						</tr>
					</tbody>
				</table>

				<h2>Gesamter Transient</h2>
				<details>
					<summary>update_plugins vollständig anzeigen</summary>
					<pre style="white-space: pre-wrap; max-width: 1200px;"><?php echo esc_html( $this->diagnostic_dump( $transient ) ); ?></pre>
				</details>
			</div>
			<?php
		}

		private function render_diagnostic_http_table( array $result ): void {
			$rows = [
				'URL'          => (string) ( $result['url'] ?? '' ),
				'HTTP-Status'  => (string) ( $result['http_code'] ?? '' ),
				'WP_Error'     => (string) ( $result['wp_error'] ?? '' ),
				'Body-Länge'   => (string) ( $result['body_length'] ?? '' ),
				'JSON-Status'  => (string) ( $result['json_error'] ?? '' ),
				'Version'      => (string) ( $result['version'] ?? '' ),
				'Download-URL' => (string) ( $result['download'] ?? '' ),
			];

			$headers = isset( $result['headers'] ) && is_array( $result['headers'] ) ? $result['headers'] : [];
			foreach ( $headers as $name => $value ) {
				$rows[ 'Header: ' . $name ] = (string) $value;
			}
			?>
			<table class="widefat striped" style="max-width: 1200px;">
				<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th style="width: 260px;"><?php echo esc_html( $label ); ?></th>
						<td><code><?php echo esc_html( $value ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

	}