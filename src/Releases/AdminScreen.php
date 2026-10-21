<?php
/**
 * Products → Releases.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Releases;

use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * Upload a version, see every version of a piece of software, choose which one is current
 * (to roll back), and delete old ones.
 */
final class AdminScreen {

	public const PAGE       = 'beltoft-licenses-releases';
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_beltoft_licenses_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_beltoft_licenses_release_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Products → Releases.
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Releases', 'beltoft-licenses' ),
			__( 'Releases', 'beltoft-licenses' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The page URL, optionally for one piece of software and with a result notice.
	 *
	 * @param string $slug   Software slug.
	 * @param string $notice Notice code.
	 * @param string $error  Error message.
	 */
	public static function url( string $slug = '', string $notice = '', string $error = '' ): string {
		return add_query_arg(
			array_filter(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'software'  => $slug,
					'notice'    => $notice,
					'error'     => '' !== $error ? rawurlencode( $error ) : '',
				)
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * The page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$all = Software::all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$slug   = isset( $_GET['software'] ) ? sanitize_title( wp_unslash( $_GET['software'] ) ) : (string) array_key_first( $all );
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$error  = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap"><h1>' . esc_html__( 'Releases', 'beltoft-licenses' ) . '</h1>';

		$messages = array(
			'published' => __( 'Release published.', 'beltoft-licenses' ),
			'current'   => __( 'Current version changed.', 'beltoft-licenses' ),
			'deleted'   => __( 'Release deleted.', 'beltoft-licenses' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}

		if ( ! $all ) {
			printf( '<p>%s</p></div>', esc_html__( 'No product has a software slug yet. Open a product, go to its License tab, tick "Licensed product" and enter the plugin or theme folder name.', 'beltoft-licenses' ) );
			return;
		}

		// Software picker.
		echo '<form method="get" style="margin:1em 0"><input type="hidden" name="post_type" value="product"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label for="beltoft-licenses-software">' . esc_html__( 'Software', 'beltoft-licenses' ) . '</label> <select id="beltoft-licenses-software" name="software" onchange="this.form.submit()">';
		foreach ( $all as $s => $name ) {
			printf( '<option value="%1$s" %2$s>%3$s (%1$s)</option>', esc_attr( $s ), selected( $slug, $s, false ), esc_html( $name ) );
		}
		echo '</select> <noscript><button class="button">' . esc_html__( 'Show', 'beltoft-licenses' ) . '</button></noscript></form>';

		self::render_upload( $slug );
		self::render_list( $slug );
		self::render_github_log();

		echo '</div>';
	}

	/**
	 * Upload form.
	 *
	 * @param string $slug Software slug.
	 */
	private static function render_upload( string $slug ): void {
		echo '<h2>' . esc_html__( 'Publish a version', 'beltoft-licenses' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'beltoft_licenses_upload' );
		echo '<input type="hidden" name="action" value="beltoft_licenses_upload"><input type="hidden" name="software" value="' . esc_attr( $slug ) . '">';
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th><label for="bl-version">%s</label></th><td><input id="bl-version" name="version" type="text" class="regular-text" required pattern="[vV]?[0-9A-Za-z][0-9A-Za-z.+\-]{0,31}" placeholder="1.2.3"></td></tr>', esc_html__( 'Version', 'beltoft-licenses' ) );
		printf(
			'<tr><th><label for="bl-zip">%1$s</label></th><td><input id="bl-zip" name="release_zip" type="file" accept=".zip,application/zip" required><p class="description">%2$s</p></td></tr>',
			esc_html__( 'Zip file', 'beltoft-licenses' ),
			/* translators: %s: software slug. */
			esc_html( sprintf( __( 'One folder named "%s" with the plugin or theme inside it.', 'beltoft-licenses' ), $slug ) )
		);
		printf( '<tr><th><label for="bl-changelog">%s</label></th><td><textarea id="bl-changelog" name="changelog" rows="5" class="large-text"></textarea></td></tr>', esc_html__( 'Changelog', 'beltoft-licenses' ) );
		printf( '<tr><th>%1$s</th><td><label><input type="checkbox" name="make_current" value="1" checked> %2$s</label></td></tr>', esc_html__( 'Current version', 'beltoft-licenses' ), esc_html__( 'Serve this version to licensed sites straight away', 'beltoft-licenses' ) );
		echo '</table>';
		submit_button( __( 'Publish', 'beltoft-licenses' ) );
		echo '</form>';
	}

	/**
	 * Releases of one piece of software.
	 *
	 * @param string $slug Software slug.
	 */
	private static function render_list( string $slug ): void {
		$releases = Software::releases( $slug );
		$current  = (string) Software::current_version( $slug );

		echo '<h2>' . esc_html__( 'Versions', 'beltoft-licenses' ) . '</h2>';

		if ( ! $releases ) {
			echo '<p>' . esc_html__( 'No releases yet.', 'beltoft-licenses' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Version', 'beltoft-licenses' ), __( 'Published', 'beltoft-licenses' ), __( 'Size', 'beltoft-licenses' ), __( 'From', 'beltoft-licenses' ), __( 'Changelog', 'beltoft-licenses' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $releases as $release ) {
			$version    = (string) $release['version'];
			$is_current = $version === $current;

			echo '<tr><td><strong>' . esc_html( $version ) . '</strong>' . ( $is_current ? ' <span class="dashicons dashicons-yes" title="' . esc_attr__( 'Current', 'beltoft-licenses' ) . '"></span> ' . esc_html__( 'current', 'beltoft-licenses' ) : '' ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( (string) $release['published_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td>' . esc_html( size_format( (int) $release['filesize'] ) ) . '</td>';
			echo '<td>' . esc_html( self::source_label( (string) $release['source'] ) ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( (string) $release['changelog'], 20 ) ) . '</td><td>';

			if ( ! $is_current ) {
				self::action_button( $slug, $version, 'current', __( 'Make current', 'beltoft-licenses' ), '' );
			}
			self::action_button(
				$slug,
				$version,
				'delete',
				__( 'Delete', 'beltoft-licenses' ),
				/* translators: %s: version. */
				sprintf( __( 'Delete version %s and its file?', 'beltoft-licenses' ), $version )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * A small form posting one release action.
	 *
	 * @param string $slug    Software slug.
	 * @param string $version Version.
	 * @param string $op      current or delete.
	 * @param string $label   Button label.
	 * @param string $confirm Confirmation question, or ''.
	 */
	private static function action_button( string $slug, string $version, string $op, string $label, string $confirm ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		wp_nonce_field( 'beltoft_licenses_release_' . $op . '_' . $slug . '_' . $version );
		printf(
			'<input type="hidden" name="action" value="beltoft_licenses_release_action"><input type="hidden" name="op" value="%1$s"><input type="hidden" name="software" value="%2$s"><input type="hidden" name="version" value="%3$s"><button class="button button-small%4$s">%5$s</button>',
			esc_attr( $op ),
			esc_attr( $slug ),
			esc_attr( $version ),
			'delete' === $op ? ' button-link-delete' : '',
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Where a release came from, in words.
	 *
	 * @param string $source upload, github or import.
	 */
	private static function source_label( string $source ): string {
		$labels = array(
			'github' => 'GitHub',
			'import' => __( 'Import', 'beltoft-licenses' ),
		);

		return $labels[ $source ] ?? __( 'Upload', 'beltoft-licenses' );
	}

	/**
	 * The most recent GitHub webhook results.
	 */
	private static function render_github_log(): void {
		$log = GitHub::log();

		if ( ! $log ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Recent GitHub releases', 'beltoft-licenses' ) . '</h2><ul>';
		foreach ( $log as $entry ) {
			printf( '<li><code>%1$s</code> %2$s</li>', esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ), 'Y-m-d H:i' ) ), esc_html( (string) $entry['message'] ) );
		}
		echo '</ul>';
	}

	/**
	 * admin-post: upload.
	 */
	public static function handle_upload(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to publish releases.', 'beltoft-licenses' ), 403 );
		}

		check_admin_referer( 'beltoft_licenses_upload' );

		$slug    = isset( $_POST['software'] ) ? sanitize_title( wp_unslash( $_POST['software'] ) ) : '';
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$log     = isset( $_POST['changelog'] ) ? sanitize_textarea_field( wp_unslash( $_POST['changelog'] ) ) : '';
		$current = ! empty( $_POST['make_current'] );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the array is validated by wp_handle_upload().
		$file = isset( $_FILES['release_zip'] ) && is_array( $_FILES['release_zip'] ) ? $_FILES['release_zip'] : null;

		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::back( $slug, '', __( 'Choose a zip file to upload.', 'beltoft-licenses' ) );
		}

		$stored = self::receive_upload( $file );
		if ( is_wp_error( $stored ) ) {
			self::back( $slug, '', $stored->get_error_message() );
		}

		$result = Publisher::publish( $slug, $version, $stored, $log, 'upload', $current );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $stored );
			self::back( $slug, '', $result->get_error_message() );
		}

		self::back( $slug, 'published' );
	}

	/**
	 * Takes the uploaded file into the private storage's incoming folder, never into
	 * public uploads, even briefly.
	 *
	 * @param array<string, mixed> $file One $_FILES entry.
	 * @return string|\WP_Error Path.
	 */
	public static function receive_upload( array $file ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$incoming = Software::storage_dir() . '/incoming';
		wp_mkdir_p( $incoming );

		$to_incoming = static function ( array $dirs ) use ( $incoming ): array {
			$dirs['path']   = $incoming;
			$dirs['subdir'] = '';
			return $dirs;
		};

		add_filter( 'upload_dir', $to_incoming );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( 'zip' => 'application/zip' ),
			)
		);
		remove_filter( 'upload_dir', $to_incoming );

		if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return new \WP_Error( 'beltoft_licenses_upload', isset( $moved['error'] ) ? (string) $moved['error'] : __( 'The upload failed.', 'beltoft-licenses' ) );
		}

		return (string) $moved['file'];
	}

	/**
	 * admin-post: make current / delete.
	 */
	public static function handle_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change releases.', 'beltoft-licenses' ), 403 );
		}

		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$slug    = isset( $_POST['software'] ) ? sanitize_title( wp_unslash( $_POST['software'] ) ) : '';
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

		check_admin_referer( 'beltoft_licenses_release_' . $op . '_' . $slug . '_' . $version );

		if ( 'current' === $op ) {
			if ( Software::set_current( $slug, $version ) ) {
				self::back( $slug, 'current' );
			}
			self::back( $slug, '', __( 'That version could not be made current: its product or release is missing.', 'beltoft-licenses' ) );
		}

		if ( 'delete' === $op && Software::delete_release( $slug, $version ) ) {
			self::back( $slug, 'deleted' );
		}

		self::back( $slug, '', __( 'Nothing was changed.', 'beltoft-licenses' ) );
	}

	/**
	 * Redirects back to the page and ends the request.
	 *
	 * @param string $slug   Software slug.
	 * @param string $notice Notice code.
	 * @param string $error  Error message.
	 */
	private static function back( string $slug, string $notice = '', string $error = '' ): never {
		wp_safe_redirect( self::url( $slug, $notice, $error ) );
		exit;
	}
}
