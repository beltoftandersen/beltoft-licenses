<?php
/**
 * WooCommerce → Licenses.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Admin;

use BeltoftLicenses\Account\View;
use BeltoftLicenses\Events;
use BeltoftLicenses\Key;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * The license list, one license's screen (status, expiry, sites, history), manual issue and
 * CSV export. The work is done by small static methods; the admin-post handlers only check
 * the capability and nonce, call one, and redirect back.
 */
final class Screen {

	public const PAGE            = 'beltoft-licenses-list';
	public const CAPABILITY      = 'manage_woocommerce';
	public const PER_PAGE_OPTION = 'beltoft_licenses_per_page';

	/**
	 * Bulk action => status it sets.
	 */
	private const BULK = array(
		'revoke'     => Licenses::STATUS_REVOKED,
		'suspend'    => Licenses::STATUS_SUSPENDED,
		'reactivate' => Licenses::STATUS_ACTIVE,
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( __CLASS__, 'save_per_page' ), 10, 3 );
		add_action( 'admin_post_beltoft_licenses_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_beltoft_licenses_deactivate', array( __CLASS__, 'handle_deactivate' ) );
		add_action( 'admin_post_beltoft_licenses_issue', array( __CLASS__, 'handle_issue' ) );
		add_action( 'admin_post_beltoft_licenses_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * WooCommerce → Licenses.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Licenses', 'beltoft-licenses' ),
			__( 'Licenses', 'beltoft-licenses' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
		}
	}

	/**
	 * Before the page is drawn: the per-page screen option, and bulk actions from the list.
	 */
	public static function load(): void {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => self::PER_PAGE_OPTION,
			)
		);

		$table  = new ListTable();
		$action = $table->current_action();

		if ( $action && isset( self::BULK[ $action ] ) ) {
			check_admin_referer( 'bulk-licenses' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each ID goes through absint.
			$ids = isset( $_REQUEST['licenses'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['licenses'] ) ) : array();

			self::back(
				array_merge(
					self::query_args(),
					array(
						'notice' => 'bulk',
						'count'  => self::bulk( $action, $ids ),
					)
				)
			);
		}

		// A search or filter submitted with the list form carries the bulk nonce; drop it from the URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks for the parameter, to clean the URL.
		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2', 'filter_action' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
			exit;
		}
	}

	/**
	 * Keeps the per-page screen option.
	 *
	 * @param mixed  $keep   Default.
	 * @param string $option Option.
	 * @param mixed  $value  Value.
	 * @return mixed
	 */
	public static function save_per_page( $keep, $option, $value ) {
		return self::PER_PAGE_OPTION === $option ? max( 1, min( 200, (int) $value ) ) : $keep;
	}

	/**
	 * The list filters from the request.
	 *
	 * @return array{status: string, product_slug: string, search: string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return array(
			'status'       => in_array( $status, Licenses::STATUSES, true ) ? $status : '',
			'product_slug' => isset( $_GET['product_slug'] ) ? sanitize_title( wp_unslash( $_GET['product_slug'] ) ) : '',
			'search'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable
	}

	/**
	 * The current filters as URL parameters.
	 *
	 * @return array<string, string>
	 */
	private static function query_args(): array {
		$filters = self::filters();

		return array(
			'status'       => $filters['status'],
			'product_slug' => $filters['product_slug'],
			's'            => $filters['search'],
		);
	}

	/**
	 * The page URL with parameters; empty ones are left out.
	 *
	 * @param array<string, mixed> $args Parameters, e.g. status, license, view, notice.
	 */
	public static function url( array $args = array() ): string {
		$args = array_filter(
			array_merge( array( 'page' => self::PAGE ), $args ),
			static fn( $value ) => null !== $value && '' !== $value
		);

		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * CSV download of every license matching the current filters.
	 */
	public static function export_url(): string {
		return wp_nonce_url(
			add_query_arg(
				array_map( 'rawurlencode', array_filter( array_merge( array( 'action' => 'beltoft_licenses_export' ), self::query_args() ) ) ),
				admin_url( 'admin-post.php' )
			),
			'beltoft_licenses_export'
		);
	}

	/**
	 * The page: the list, one license, or the issue form.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$id   = isset( $_GET['license'] ) ? absint( $_GET['license'] ) : 0;
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap">';

		if ( 'new' === $view ) {
			self::render_issue();
		} elseif ( $id ) {
			self::render_edit( $id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * The result of the last action.
	 */
	private static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$count  = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		$error  = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		$messages = array(
			/* translators: %d: number of licenses. */
			'bulk'        => sprintf( _n( '%d license changed.', '%d licenses changed.', $count, 'beltoft-licenses' ), $count ),
			'saved'       => __( 'License saved.', 'beltoft-licenses' ),
			'deactivated' => __( 'Site deactivated.', 'beltoft-licenses' ),
			'issued'      => __( 'License issued.', 'beltoft-licenses' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * The list.
	 */
	private static function render_list(): void {
		$table = new ListTable();
		$table->prepare_items();

		printf(
			'<h1 class="wp-heading-inline">%1$s</h1> <a href="%2$s" class="page-title-action">%3$s</a><hr class="wp-header-end">',
			esc_html__( 'Licenses', 'beltoft-licenses' ),
			esc_url( self::url( array( 'view' => 'new' ) ) ),
			esc_html__( 'Issue license', 'beltoft-licenses' )
		);
		self::render_notice();
		$table->views();

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="status" value="' . esc_attr( self::filters()['status'] ) . '">';
		$table->search_box( __( 'Search key, email or site', 'beltoft-licenses' ), 'beltoft-licenses' );
		$table->display();
		echo '</form>';
	}

	/**
	 * One license.
	 *
	 * @param int $id License ID.
	 */
	private static function render_edit( int $id ): void {
		$license = Licenses::find( $id );

		if ( ! $license ) {
			echo '<h1>' . esc_html__( 'Licenses', 'beltoft-licenses' ) . '</h1><p>' . esc_html__( 'That license does not exist.', 'beltoft-licenses' ) . '</p>';
			return;
		}

		$status = Licenses::effective_status( $license );

		/* translators: %s: license key. */
		echo '<h1>' . esc_html( sprintf( __( 'License %s', 'beltoft-licenses' ), $license['license_key'] ) ) . '</h1>';
		printf( '<p><a href="%1$s">&larr; %2$s</a></p>', esc_url( self::url() ), esc_html__( 'All licenses', 'beltoft-licenses' ) );
		self::render_notice();

		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th>%1$s</th><td>%2$s <code>%3$s</code></td></tr>', esc_html__( 'Product', 'beltoft-licenses' ), esc_html( View::product_name( $license ) ), esc_html( (string) $license['product_slug'] ) );
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Customer', 'beltoft-licenses' ), wp_kses_post( ListTable::customer( $license ) ) );
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'From', 'beltoft-licenses' ), wp_kses_post( ListTable::source( $license ) ) );
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Valid', 'beltoft-licenses' ), esc_html( View::expiry_label( $license, $status ) ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Settings', 'beltoft-licenses' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'beltoft_licenses_save_' . $id );
		printf( '<input type="hidden" name="action" value="beltoft_licenses_save"><input type="hidden" name="license" value="%d">', (int) $id );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th><label for="bl-status">' . esc_html__( 'Status', 'beltoft-licenses' ) . '</label></th><td><select id="bl-status" name="status">';
		foreach ( Licenses::STATUSES as $option ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $license['status'], $option, false ), esc_html( View::status_label( $option ) ) );
		}
		echo '</select>';
		if ( $status !== $license['status'] ) {
			echo '<p class="description">' . esc_html__( 'Its expiry date has passed, so it counts as expired.', 'beltoft-licenses' ) . '</p>';
		}
		echo '</td></tr>';

		printf(
			'<tr><th><label for="bl-expires">%1$s</label></th><td><input id="bl-expires" name="expires" type="date" value="%2$s"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Expires', 'beltoft-licenses' ),
			esc_attr( self::date_value( $license['expires_at'] ) ),
			esc_html__( 'At the end of this day, in the site\'s time zone. Leave empty for no expiry date.', 'beltoft-licenses' )
		);
		printf(
			'<tr><th><label for="bl-max">%1$s</label></th><td><input id="bl-max" name="max_activations" type="number" min="0" step="1" class="small-text" value="%2$d"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Site limit', 'beltoft-licenses' ),
			(int) $license['max_activations'],
			esc_html__( '0 means unlimited.', 'beltoft-licenses' )
		);
		echo '</table>';

		if ( $license['subscription_id'] ) {
			echo '<p class="description">' . esc_html__( 'This license follows its subscription. A renewal, suspension or cancellation will set the status and expiry again.', 'beltoft-licenses' ) . '</p>';
		}

		submit_button( __( 'Save license', 'beltoft-licenses' ) );
		echo '</form>';

		self::render_sites( $license );

		/**
		 * Fires on a license's admin screen, after its sites, for panels of plugins built on top.
		 *
		 * @param array<string, mixed> $license License.
		 */
		do_action( 'beltoft_licenses_admin_license', $license );

		self::render_history( $id );
	}

	/**
	 * Live sites, each with a deactivate button.
	 *
	 * @param array<string, mixed> $license License.
	 */
	private static function render_sites( array $license ): void {
		$sites = Licenses::live_activations( (int) $license['id'], 'site' );

		echo '<h2>' . esc_html( View::sites_label( count( $sites ), (int) $license['max_activations'] ) ) . '</h2>';

		if ( ! $sites ) {
			echo '<p>' . esc_html__( 'Not active on any site.', 'beltoft-licenses' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__( 'Site', 'beltoft-licenses' ) . '</th><th>' . esc_html__( 'Activated', 'beltoft-licenses' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $sites as $site ) {
			$domain = (string) $site['domain'];

			printf( '<tr><td><code>%1$s</code></td><td>%2$s</td><td>', esc_html( $domain ), esc_html( get_date_from_gmt( (string) $site['activated_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) );
			/* translators: %s: site address. */
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( sprintf( __( 'Deactivate the license on %s?', 'beltoft-licenses' ), $domain ) ) ) . ')">';
			wp_nonce_field( 'beltoft_licenses_deactivate_' . (int) $license['id'] );
			printf(
				'<input type="hidden" name="action" value="beltoft_licenses_deactivate"><input type="hidden" name="license" value="%1$d"><input type="hidden" name="domain" value="%2$s"><button class="button button-small button-link-delete">%3$s</button></form></td></tr>',
				(int) $license['id'],
				esc_attr( $domain ),
				esc_html__( 'Deactivate', 'beltoft-licenses' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The license's history.
	 *
	 * @param int $id License ID.
	 */
	private static function render_history( int $id ): void {
		$events = Events::for_license( $id );

		echo '<h2>' . esc_html__( 'History', 'beltoft-licenses' ) . '</h2>';

		if ( ! $events ) {
			echo '<p>' . esc_html__( 'Nothing recorded yet.', 'beltoft-licenses' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__( 'When', 'beltoft-licenses' ) . '</th><th>' . esc_html__( 'What', 'beltoft-licenses' ) . '</th><th>' . esc_html__( 'By', 'beltoft-licenses' ) . '</th></tr></thead><tbody>';

		foreach ( $events as $event ) {
			$user = $event['user_id'] ? get_userdata( (int) $event['user_id'] ) : false;

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( get_date_from_gmt( (string) $event['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
				esc_html( (string) $event['message'] ),
				esc_html( $user ? $user->display_name : __( 'System', 'beltoft-licenses' ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The manual issue form. Email and order can be filled in from the link.
	 */
	private static function render_issue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only prefill values.
		$email    = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		// phpcs:enable

		echo '<h1>' . esc_html__( 'Issue license', 'beltoft-licenses' ) . '</h1>';
		printf( '<p><a href="%1$s">&larr; %2$s</a></p>', esc_url( self::url() ), esc_html__( 'All licenses', 'beltoft-licenses' ) );
		self::render_notice();

		$all = Software::all();

		if ( ! $all ) {
			echo '<p>' . esc_html__( 'No product has a software slug yet. Open a product, go to its License tab, tick "Licensed product" and enter the plugin or theme folder name.', 'beltoft-licenses' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'beltoft_licenses_issue' );
		printf( '<input type="hidden" name="action" value="beltoft_licenses_issue"><input type="hidden" name="order_id" value="%d">', (int) $order_id );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th><label for="bl-product">' . esc_html__( 'Product', 'beltoft-licenses' ) . '</label></th><td><select id="bl-product" name="product_slug" required>';
		foreach ( $all as $slug => $name ) {
			printf( '<option value="%1$s">%2$s (%1$s)</option>', esc_attr( $slug ), esc_html( $name ) );
		}
		echo '</select></td></tr>';

		printf(
			'<tr><th><label for="bl-email">%1$s</label></th><td><input id="bl-email" name="email" type="email" class="regular-text" required value="%2$s"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Customer email', 'beltoft-licenses' ),
			esc_attr( $email ),
			esc_html__( 'When a user has this email, the license shows in their account.', 'beltoft-licenses' )
		);
		printf(
			'<tr><th><label for="bl-max">%1$s</label></th><td><input id="bl-max" name="max_activations" type="number" min="0" step="1" class="small-text" value="1"><p class="description">%2$s</p></td></tr>',
			esc_html__( 'Site limit', 'beltoft-licenses' ),
			esc_html__( '0 means unlimited.', 'beltoft-licenses' )
		);
		printf(
			'<tr><th><label for="bl-expires">%1$s</label></th><td><input id="bl-expires" name="expires" type="date"><p class="description">%2$s</p></td></tr>',
			esc_html__( 'Expires', 'beltoft-licenses' ),
			esc_html__( 'Optional. Leave empty for a license that does not expire.', 'beltoft-licenses' )
		);

		if ( $order_id ) {
			/* translators: %d: order ID. */
			printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Order', 'beltoft-licenses' ), esc_html( sprintf( __( 'Linked to order #%d', 'beltoft-licenses' ), $order_id ) ) );
		}

		echo '</table>';
		submit_button( __( 'Issue license', 'beltoft-licenses' ) );
		echo '</form>';
	}

	/**
	 * Applies a bulk action.
	 *
	 * @param string   $action revoke, suspend or reactivate.
	 * @param int[]    $ids    License IDs.
	 * @return int How many licenses changed.
	 */
	public static function bulk( string $action, array $ids ): int {
		if ( ! isset( self::BULK[ $action ] ) ) {
			return 0;
		}

		$changed = 0;

		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$license = Licenses::find( $id );

			if ( $license && self::BULK[ $action ] !== $license['status'] && Licenses::update( $id, array( 'status' => self::BULK[ $action ] ) ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Saves status, expiry and site limit from the license screen.
	 *
	 * The expiry is only changed when the date differs from the one shown, so saving the
	 * screen never moves an exact expiry time to the end of its day.
	 *
	 * @param int                  $id    License ID.
	 * @param array<string, mixed> $input status, expires (Y-m-d or ''), max_activations.
	 * @return true|\WP_Error
	 */
	public static function save( int $id, array $input ) {
		$license = Licenses::find( $id );

		if ( ! $license ) {
			return new \WP_Error( 'beltoft_licenses_not_found', __( 'That license does not exist.', 'beltoft-licenses' ) );
		}

		$data = array();

		if ( isset( $input['status'] ) && in_array( $input['status'], Licenses::STATUSES, true ) && $input['status'] !== $license['status'] ) {
			$data['status'] = $input['status'];
		}

		$expires = trim( (string) ( $input['expires'] ?? '' ) );
		if ( self::date_value( $license['expires_at'] ) !== $expires ) {
			$time = '' === $expires ? null : self::end_of_day( $expires );

			if ( '' !== $expires && null === $time ) {
				return new \WP_Error( 'beltoft_licenses_date', __( 'The expiry date is not a valid date.', 'beltoft-licenses' ) );
			}

			$data['expires_at'] = $time;
		}

		$max = isset( $input['max_activations'] ) ? max( 0, (int) $input['max_activations'] ) : $license['max_activations'];
		if ( $max !== $license['max_activations'] ) {
			$data['max_activations'] = $max;
		}

		if ( $data && ! Licenses::update( $id, $data ) ) {
			return new \WP_Error( 'beltoft_licenses_save', __( 'The license could not be saved.', 'beltoft-licenses' ) );
		}

		if ( isset( $data['max_activations'] ) ) {
			Events::add(
				$id,
				'limit',
				/* translators: 1: previous site limit, 2: new site limit; 0 means unlimited. */
				sprintf( __( 'Site limit %1$d → %2$d.', 'beltoft-licenses' ), $license['max_activations'], $max )
			);
		}

		return true;
	}

	/**
	 * Deactivates one site of a license.
	 *
	 * @param int    $id     License ID.
	 * @param string $domain Site.
	 */
	public static function deactivate_site( int $id, string $domain ): bool {
		$license = Licenses::find( $id );

		return $license && Licenses::deactivate( $license, Key::domain( $domain ) )['success'];
	}

	/**
	 * Issues a license by hand.
	 *
	 * @param array<string, mixed> $input product_slug, email, max_activations, expires (Y-m-d or ''), order_id.
	 * @return array<string, mixed>|\WP_Error The license.
	 */
	public static function issue( array $input ) {
		$slug  = sanitize_title( (string) ( $input['product_slug'] ?? '' ) );
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );

		if ( ! isset( Software::all()[ $slug ] ) ) {
			return new \WP_Error( 'beltoft_licenses_product', __( 'Choose a licensed product.', 'beltoft-licenses' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'beltoft_licenses_email', __( 'Enter the customer\'s email address.', 'beltoft-licenses' ) );
		}

		$expires = trim( (string) ( $input['expires'] ?? '' ) );
		$time    = '' === $expires ? null : self::end_of_day( $expires );

		if ( '' !== $expires && null === $time ) {
			return new \WP_Error( 'beltoft_licenses_date', __( 'The expiry date is not a valid date.', 'beltoft-licenses' ) );
		}

		$order_id = absint( $input['order_id'] ?? 0 );
		if ( $order_id && ! wc_get_order( $order_id ) ) {
			$order_id = 0;
		}

		$user    = get_user_by( 'email', $email );
		$license = Licenses::create(
			array(
				'product_slug'    => $slug,
				'product_id'      => Software::product_id( $slug ),
				'email'           => $email,
				'user_id'         => $user ? $user->ID : null,
				'max_activations' => max( 0, (int) ( $input['max_activations'] ?? 1 ) ),
				'expires_at'      => $time,
				'order_id'        => $order_id ? $order_id : null,
				'source'          => 'manual',
			)
		);

		return $license ? $license : new \WP_Error( 'beltoft_licenses_issue', __( 'The license could not be created.', 'beltoft-licenses' ) );
	}

	/**
	 * Every license matching the filters, as CSV.
	 *
	 * @param array<string, string> $filters status, product_slug, search.
	 */
	public static function csv( array $filters ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream for fputcsv, not a file.
		$out = fopen( 'php://temp', 'r+' );

		fputcsv( $out, array( 'license_key', 'status', 'product_slug', 'product', 'email', 'user_id', 'sites', 'max_activations', 'expires_at', 'order_id', 'subscription_id', 'source', 'created_at' ), ',', '"', '' );

		$page = 1;
		do {
			list( $licenses, $total ) = Licenses::query(
				array_merge(
					$filters,
					array(
						'per_page' => 200,
						'page'     => $page,
					)
				)
			);

			foreach ( $licenses as $license ) {
				$sites = array_map( static fn( $a ) => (string) $a['domain'], Licenses::live_activations( (int) $license['id'], 'site' ) );
				$row   = array(
					$license['license_key'],
					Licenses::effective_status( $license ),
					$license['product_slug'],
					View::product_name( $license ),
					$license['email'],
					$license['user_id'],
					implode( ' ', $sites ),
					$license['max_activations'],
					$license['expires_at'],
					$license['order_id'],
					$license['subscription_id'],
					$license['source'],
					$license['created_at'],
				);

				fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $row ), ',', '"', '' );
			}

			++$page;
		} while ( ( $page - 1 ) * 200 < $total );

		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
		fclose( $out );

		return $csv;
	}

	/**
	 * A cell a spreadsheet will not run as a formula.
	 *
	 * @param mixed $value Value.
	 */
	private static function csv_cell( $value ): string {
		$value = (string) $value;

		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * admin-post: save a license.
	 */
	public static function handle_save(): void {
		self::require_capability();

		$id = isset( $_POST['license'] ) ? absint( $_POST['license'] ) : 0;
		check_admin_referer( 'beltoft_licenses_save_' . $id );

		$result = self::save(
			$id,
			array(
				'status'          => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
				'expires'         => isset( $_POST['expires'] ) ? sanitize_text_field( wp_unslash( $_POST['expires'] ) ) : '',
				'max_activations' => isset( $_POST['max_activations'] ) ? absint( $_POST['max_activations'] ) : null,
			)
		);

		self::back(
			is_wp_error( $result )
				? array(
					'license' => $id,
					'error'   => $result->get_error_message(),
				)
				: array(
					'license' => $id,
					'notice'  => 'saved',
				)
		);
	}

	/**
	 * admin-post: deactivate a site.
	 */
	public static function handle_deactivate(): void {
		self::require_capability();

		$id = isset( $_POST['license'] ) ? absint( $_POST['license'] ) : 0;
		check_admin_referer( 'beltoft_licenses_deactivate_' . $id );

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

		self::back(
			self::deactivate_site( $id, $domain )
				? array(
					'license' => $id,
					'notice'  => 'deactivated',
				)
				: array(
					'license' => $id,
					'error'   => __( 'That site was not active on this license.', 'beltoft-licenses' ),
				)
		);
	}

	/**
	 * admin-post: issue a license.
	 */
	public static function handle_issue(): void {
		self::require_capability();
		check_admin_referer( 'beltoft_licenses_issue' );

		$result = self::issue(
			array(
				'product_slug'    => isset( $_POST['product_slug'] ) ? sanitize_title( wp_unslash( $_POST['product_slug'] ) ) : '',
				'email'           => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'max_activations' => isset( $_POST['max_activations'] ) ? absint( $_POST['max_activations'] ) : 1,
				'expires'         => isset( $_POST['expires'] ) ? sanitize_text_field( wp_unslash( $_POST['expires'] ) ) : '',
				'order_id'        => isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::back(
				array(
					'view'  => 'new',
					'error' => $result->get_error_message(),
				)
			);
		}

		self::back(
			array(
				'license' => (int) $result['id'],
				'notice'  => 'issued',
			)
		);
	}

	/**
	 * admin-post: CSV export.
	 */
	public static function handle_export(): void {
		self::require_capability();
		check_admin_referer( 'beltoft_licenses_export' );

		$csv = self::csv( self::filters() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="licenses-' . gmdate( 'Y-m-d' ) . '.csv"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV download, not HTML; cells are guarded against formulas.
		echo $csv;
		exit;
	}

	/**
	 * Stops anyone without the capability.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage licenses.', 'beltoft-licenses' ), 403 );
		}
	}

	/**
	 * The expiry as a date in the site's time zone, as a date input shows it, or ''.
	 *
	 * @param string|null $expires_at MySQL datetime in UTC.
	 */
	private static function date_value( ?string $expires_at ): string {
		return null === $expires_at ? '' : wp_date( 'Y-m-d', (int) strtotime( $expires_at . ' UTC' ) );
	}

	/**
	 * The last second of a Y-m-d day in the site's time zone, as a timestamp, or null.
	 *
	 * @param string $date Y-m-d.
	 */
	private static function end_of_day( string $date ): ?int {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		if ( ! $day || $day->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		return $day->setTime( 23, 59, 59 )->getTimestamp();
	}

	/**
	 * Redirects to the page and ends the request.
	 *
	 * @param array<string, mixed> $args URL parameters.
	 */
	private static function back( array $args ): never {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
