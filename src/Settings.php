<?php
/**
 * Settings → Licenses.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Every site-specific value lives here, with a default that works on a fresh install.
 */
final class Settings {

	public const OPTION = 'beltoft_licenses_settings';
	public const PAGE   = 'beltoft-licenses';

	/**
	 * Field definitions: section, type, default and label, in page order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			// Keys.
			'key_prefix'               => array(
				'section' => 'keys',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'Key prefix', 'beltoft-licenses' ),
				'desc'    => __( 'Optional. Letters and digits, put in front of every new key with a dash, for example ACME gives ACME-1A2B-….', 'beltoft-licenses' ),
			),
			'key_template'             => array(
				'section' => 'keys',
				'type'    => 'text',
				'default' => '{X4}-{X4}-{X4}-{X4}',
				'label'   => __( 'Key format', 'beltoft-licenses' ),
				'desc'    => __( '{Xn} is n random uppercase hexadecimal characters. Dashes and letters are kept as written.', 'beltoft-licenses' ),
			),
			// API.
			'aliases'                  => array(
				'section' => 'api',
				'type'    => 'textarea',
				'default' => '',
				'label'   => __( 'Extra API paths', 'beltoft-licenses' ),
				'desc'    => __( 'One per line. Use {route} for validate, activate, deactivate, download and download-file, or map one path with "=> route". Add "auth" at the end of a line to require the header below on that path. Example: /store/licenses/{route} auth', 'beltoft-licenses' ),
			),
			'auth_header'              => array(
				'section' => 'api',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'Required header name', 'beltoft-licenses' ),
				'desc'    => __( 'Only used on paths marked "auth". Leave empty to require nothing.', 'beltoft-licenses' ),
			),
			'auth_values'              => array(
				'section' => 'api',
				'type'    => 'textarea',
				'default' => '',
				'label'   => __( 'Accepted header values', 'beltoft-licenses' ),
				'desc'    => __( 'One per line.', 'beltoft-licenses' ),
			),
			'response_profile'         => array(
				'section' => 'api',
				'type'    => 'select',
				'default' => 'standard',
				'options' => array(
					'standard' => __( 'Standard', 'beltoft-licenses' ),
					'medusa'   => __( 'Medusa compatible', 'beltoft-licenses' ),
				),
				'label'   => __( 'Response format', 'beltoft-licenses' ),
				'desc'    => __( 'Medusa compatible answers exactly as a Medusa license backend does, for plugins already built against one.', 'beltoft-licenses' ),
			),
			'updates_mode'             => array(
				'section' => 'api',
				'type'    => 'select',
				'default' => 'active',
				'options' => array(
					'active'  => __( 'Whenever the license is active', 'beltoft-licenses' ),
					'version' => __( 'Only when a newer version than the one sent exists', 'beltoft-licenses' ),
				),
				'label'   => __( 'Report updates available', 'beltoft-licenses' ),
			),
			'rate_limit_requests'      => array(
				'section' => 'api',
				'type'    => 'number',
				'default' => 10,
				'min'     => 0,
				'label'   => __( 'Rate limit: requests', 'beltoft-licenses' ),
				'desc'    => __( 'Per IP address and path. 0 turns the limit off.', 'beltoft-licenses' ),
			),
			'rate_limit_window'        => array(
				'section' => 'api',
				'type'    => 'number',
				'default' => 60,
				'min'     => 1,
				'label'   => __( 'Rate limit: window in seconds', 'beltoft-licenses' ),
			),
			'token_ttl'                => array(
				'section' => 'api',
				'type'    => 'number',
				'default' => 300,
				'min'     => 30,
				'label'   => __( 'Download link lifetime in seconds', 'beltoft-licenses' ),
			),
			// Subscriptions.
			'grace_days'               => array(
				'section' => 'subscriptions',
				'type'    => 'number',
				'default' => 3,
				'min'     => 0,
				'label'   => __( 'Grace period after a failed payment (days)', 'beltoft-licenses' ),
				'desc'    => __( 'The license keeps working this long while the subscription is on hold.', 'beltoft-licenses' ),
			),
			'revoke_on_refund'         => array(
				'section' => 'subscriptions',
				'type'    => 'checkbox',
				'default' => 1,
				'label'   => __( 'Revoke licenses when their order is fully refunded', 'beltoft-licenses' ),
			),
			'expiry_hourly'            => array(
				'section' => 'subscriptions',
				'type'    => 'checkbox',
				'default' => 0,
				'label'   => __( 'Check expiry every hour', 'beltoft-licenses' ),
				'desc'    => __( 'Licenses past their end date expire within the hour instead of at the daily run. Useful when other plugins act on expiry.', 'beltoft-licenses' ),
			),
			// Downloads.
			'storage_dir'              => array(
				'section' => 'downloads',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'Release storage directory', 'beltoft-licenses' ),
				'desc'    => __( 'Absolute path, preferably outside the web root. Empty uses a protected folder in uploads.', 'beltoft-licenses' ),
			),
			'delivery'                 => array(
				'section' => 'downloads',
				'type'    => 'select',
				'default' => 'php',
				'options' => array(
					'php'        => __( 'PHP streams the file', 'beltoft-licenses' ),
					'x-accel'    => __( 'nginx X-Accel-Redirect', 'beltoft-licenses' ),
					'x-sendfile' => __( 'Apache X-Sendfile', 'beltoft-licenses' ),
				),
				'label'   => __( 'File delivery', 'beltoft-licenses' ),
			),
			'x_accel_prefix'           => array(
				'section' => 'downloads',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'X-Accel internal location', 'beltoft-licenses' ),
				'desc'    => __( 'The nginx "internal" location that maps to the storage directory, for example /protected-releases/.', 'beltoft-licenses' ),
			),
			'download_requires_domain' => array(
				'section' => 'downloads',
				'type'    => 'checkbox',
				'default' => 0,
				'label'   => __( 'My Account downloads need at least one activated site', 'beltoft-licenses' ),
			),
			'release_max_mb'           => array(
				'section' => 'downloads',
				'type'    => 'number',
				'default' => 50,
				'min'     => 1,
				'label'   => __( 'Largest release file (MB)', 'beltoft-licenses' ),
			),
			// GitHub.
			'github_repos'             => array(
				'section' => 'github',
				'type'    => 'textarea',
				'default' => '',
				'label'   => __( 'Repositories', 'beltoft-licenses' ),
				'desc'    => __( 'One per line: owner/repository => software slug. A published GitHub release of that repository becomes a release of that software.', 'beltoft-licenses' ),
			),
			'github_secret'            => array(
				'section' => 'github',
				'type'    => 'password',
				'default' => '',
				'label'   => __( 'Webhook secret', 'beltoft-licenses' ),
				'desc'    => __( 'The secret set on the GitHub webhook (content type application/json, event "Releases"). Leave empty to keep the saved one.', 'beltoft-licenses' ),
			),
			'github_token'             => array(
				'section' => 'github',
				'type'    => 'password',
				'default' => '',
				'label'   => __( 'Access token', 'beltoft-licenses' ),
				'desc'    => __( 'Only for private repositories: a token that can read their contents. Leave empty to keep the saved one.', 'beltoft-licenses' ),
			),
			'github_auto_publish'      => array(
				'section' => 'github',
				'type'    => 'checkbox',
				'default' => 1,
				'label'   => __( 'Make a GitHub release the current version straight away', 'beltoft-licenses' ),
				'desc'    => __( 'Off: the release is stored, and becomes current when you choose it under Products → Releases.', 'beltoft-licenses' ),
			),
			'github_prereleases'       => array(
				'section' => 'github',
				'type'    => 'checkbox',
				'default' => 0,
				'label'   => __( 'Also take pre-releases', 'beltoft-licenses' ),
			),
			// My Account.
			'account_endpoint'         => array(
				'section' => 'account',
				'type'    => 'text',
				'default' => 'licenses',
				'label'   => __( 'My Account endpoint', 'beltoft-licenses' ),
			),
			'account_label'            => array(
				'section' => 'account',
				'type'    => 'text',
				'default' => __( 'Licenses', 'beltoft-licenses' ),
				'label'   => __( 'My Account menu label', 'beltoft-licenses' ),
			),
			// Advanced.
			'domain_strip_www'         => array(
				'section' => 'advanced',
				'type'    => 'checkbox',
				'default' => 1,
				'label'   => __( 'Treat www.example.com and example.com as the same site', 'beltoft-licenses' ),
			),
			'domain_strip_slash'       => array(
				'section' => 'advanced',
				'type'    => 'checkbox',
				'default' => 1,
				'label'   => __( 'Ignore one trailing slash in site addresses', 'beltoft-licenses' ),
			),
			'delete_data'              => array(
				'section' => 'advanced',
				'type'    => 'checkbox',
				'default' => 0,
				'label'   => __( 'Delete all licenses, releases and settings when the plugin is deleted', 'beltoft-licenses' ),
			),
		);
	}

	/**
	 * Section titles, in page order.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'keys'          => __( 'License keys', 'beltoft-licenses' ),
			'api'           => __( 'License API', 'beltoft-licenses' ),
			'subscriptions' => __( 'Subscriptions', 'beltoft-licenses' ),
			'downloads'     => __( 'Downloads', 'beltoft-licenses' ),
			'github'        => __( 'GitHub releases', 'beltoft-licenses' ),
			'account'       => __( 'My Account', 'beltoft-licenses' ),
			'advanced'      => __( 'Advanced', 'beltoft-licenses' ),
		);
	}

	/**
	 * One setting, falling back to its default.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$stored = get_option( self::OPTION, array() );
		$fields = self::fields();

		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}

		return $fields[ $key ]['default'] ?? null;
	}

	/**
	 * Non-empty lines of a textarea setting.
	 *
	 * @param string $key Field key.
	 * @return string[]
	 */
	public static function lines( string $key ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) self::get( $key ) ) ) ) );
	}

	/**
	 * Hooks the page into the admin.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BELTOFT_LICENSES_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * Settings API registration.
	 */
	public static function register_setting(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		foreach ( self::sections() as $id => $title ) {
			add_settings_section( 'beltoft_licenses_' . $id, $title, '__return_false', self::PAGE );
		}

		foreach ( self::fields() as $key => $field ) {
			add_settings_field(
				'beltoft_licenses_' . $key,
				$field['label'],
				array( __CLASS__, 'render_field' ),
				self::PAGE,
				'beltoft_licenses_' . $field['section'],
				array(
					'key'       => $key,
					'label_for' => 'beltoft_licenses_' . $key,
				)
			);
		}
	}

	/**
	 * Settings → Licenses.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Licenses', 'beltoft-licenses' ),
			__( 'Licenses', 'beltoft-licenses' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_link( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'beltoft-licenses' ) )
		);

		return $links;
	}

	/**
	 * The page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Licenses', 'beltoft-licenses' ) . '</h1>';
		printf(
			'<p>%s <code>%s</code></p>',
			esc_html__( 'License API base:', 'beltoft-licenses' ),
			esc_html( rest_url( Api\Router::NAMESPACE . '/' ) )
		);
		echo '<form action="options.php" method="post">';
		settings_fields( self::PAGE );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * One field.
	 *
	 * @param array{key:string} $args Field key.
	 */
	public static function render_field( array $args ): void {
		$key   = $args['key'];
		$field = self::fields()[ $key ];
		$id    = 'beltoft_licenses_' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = self::get( $key );

		switch ( $field['type'] ) {
			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s" rows="4" class="large-text code">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
				break;
			case 'checkbox':
				printf( '<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s>', esc_attr( $name ), esc_attr( $id ), checked( (int) $value, 1, false ) );
				break;
			case 'number':
				printf( '<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" class="small-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), esc_attr( (string) ( $field['min'] ?? 0 ) ) );
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $option => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'password':
				// A saved secret is never printed back into the page.
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( '' !== (string) $value ? __( 'Saved', 'beltoft-licenses' ) : '' )
				);
				break;
			default:
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
		}

		if ( 'github_repos' === $key ) {
			printf( '<p class="description">%s <code>%s</code></p>', esc_html__( 'Webhook URL:', 'beltoft-licenses' ), esc_html( rest_url( Api\Router::NAMESPACE . '/github' ) ) );
		}

		if ( ! empty( $field['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['desc'] ) );
		}
	}

	/**
	 * Sanitises the whole option. Unknown keys are dropped.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		foreach ( self::fields() as $key => $field ) {
			$raw = $input[ $key ] ?? $field['default'];

			switch ( $field['type'] ) {
				case 'checkbox':
					$clean[ $key ] = empty( $raw ) ? 0 : 1;
					break;
				case 'number':
					$clean[ $key ] = max( (int) ( $field['min'] ?? 0 ), absint( $raw ) );
					break;
				case 'select':
					$clean[ $key ] = array_key_exists( (string) $raw, $field['options'] ) ? (string) $raw : $field['default'];
					break;
				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( (string) $raw );
					break;
				case 'password':
					// Empty means "keep the saved one": the field is never pre-filled.
					$raw           = trim( (string) $raw );
					$clean[ $key ] = '' === $raw ? (string) self::get( $key ) : sanitize_text_field( $raw );
					break;
				default:
					$clean[ $key ] = sanitize_text_field( (string) $raw );
			}
		}

		$clean['key_prefix']       = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $clean['key_prefix'] ) );
		$clean['account_endpoint'] = sanitize_title( $clean['account_endpoint'] ) ? sanitize_title( $clean['account_endpoint'] ) : 'licenses';
		$clean['auth_header']      = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', $clean['auth_header'] ) );

		if ( '' === trim( $clean['key_template'] ) || false === strpos( $clean['key_template'], '{X' ) ) {
			$clean['key_template'] = self::fields()['key_template']['default'];
		}

		return $clean;
	}

}
