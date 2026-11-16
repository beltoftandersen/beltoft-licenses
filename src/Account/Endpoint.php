<?php
/**
 * My Account → Licenses.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Account;

use BeltoftLicenses\Api\Download;
use BeltoftLicenses\Key;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Settings;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * The customer's licenses: key, sites, expiry, "Free this site", and a download of the
 * current version. The endpoint's slug and menu label are settings.
 *
 * Plugins built on top of this one add their own panel to each license through the
 * beltoft_licenses_account_license action, rather than a page of their own.
 */
final class Endpoint {

	/**
	 * User meta: the email a customer set themselves and has not verified.
	 */
	const UNVERIFIED_META = 'beltoft_licenses_unverified_email';

	public const OPTION_FLUSH = 'beltoft_licenses_flush_rewrites';

	/**
	 * Option holding a hash of the extra tabs' slugs, so rewrite rules are flushed when they change.
	 */
	public const OPTION_GROUPS = 'beltoft_licenses_account_groups';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		$slug = self::slug();

		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_filter( 'woocommerce_endpoint_' . $slug . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . $slug . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'init', array( __CLASS__, 'register_groups' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_action( 'woocommerce_save_account_details_errors', array( __CLASS__, 'mark_account_save' ) );
		add_action( 'profile_update', array( __CLASS__, 'flag_changed_email' ), 10, 2 );

		// The list of a customer's licenses is read once per request; any write that can change it forgets it.
		foreach ( array( 'beltoft_licenses_license_created', 'beltoft_licenses_license_updated', 'beltoft_licenses_activated', 'beltoft_licenses_deactivated', 'profile_update', 'deleted_user' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget_user_licenses' ) );
		}
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget_user_licenses_on_meta' ), 10, 3 );
		}
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_saved' ), 10, 2 );
	}

	/**
	 * The endpoint slug.
	 */
	public static function slug(): string {
		$slug = sanitize_title( (string) Settings::get( 'account_endpoint' ) );

		return '' !== $slug ? $slug : 'licenses';
	}

	/**
	 * Registers the endpoint with WooCommerce, which adds its rewrite rule.
	 *
	 * @param array<string, string> $vars Query vars.
	 * @return array<string, string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[ self::slug() ] = self::slug();

		foreach ( array_keys( self::groups() ) as $slug ) {
			$vars[ $slug ] = $slug;
		}

		return $vars;
	}

	/**
	 * Menu item, before "Log out".
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function menu( array $items ): array {
		$label  = (string) Settings::get( 'account_label' );
		$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();
		unset( $items['customer-logout'] );

		$groups = self::groups();
		$show   = true;

		if ( $groups ) {
			$tabs  = self::split( self::licenses_for_user( get_current_user_id() ) );
			$other = false;

			foreach ( $groups as $slug => $group ) {
				if ( $tabs[ $slug ] ) {
					$items[ $slug ] = $group['label'];
					$other          = true;
				}
			}

			// A tab with nothing in it is hidden; the licenses tab stays when it is the only one.
			$show = ! $other || (bool) $tabs[''];
		}

		if ( $show ) {
			$items[ self::slug() ] = '' !== $label ? $label : __( 'Licenses', 'beltoft-licenses' );
		}

		return $items + $logout;
	}

	/**
	 * Extra My Account tabs that other plugins add, each holding the licenses it claims. A group
	 * is array( slug, label, match (callable taking a license row), empty (text for an empty
	 * tab, optional) ). A license goes to the first group that matches it; the rest stay on the
	 * licenses tab.
	 *
	 * @return array<string, array{slug: string, label: string, match: callable, empty: string}>
	 */
	public static function groups(): array {
		$groups = array();

		/**
		 * Filters the extra My Account tabs.
		 *
		 * @param array<int, array<string, mixed>> $groups Groups: slug, label, match, empty.
		 */
		foreach ( (array) apply_filters( 'beltoft_licenses_account_groups', array() ) as $group ) {
			$slug = is_array( $group ) ? sanitize_title( (string) ( $group['slug'] ?? '' ) ) : '';

			if ( '' === $slug || self::slug() === $slug || isset( $groups[ $slug ] ) || ! is_callable( $group['match'] ?? null ) ) {
				continue;
			}

			$label           = (string) ( $group['label'] ?? '' );
			$groups[ $slug ] = array(
				'slug'  => $slug,
				'label' => '' !== $label ? $label : $slug,
				'match' => $group['match'],
				'empty' => (string) ( $group['empty'] ?? '' ),
			);
		}

		return $groups;
	}

	/**
	 * Every My Account endpoint of this plugin: the licenses tab and the extra tabs.
	 *
	 * @return array<int, string>
	 */
	public static function slugs(): array {
		return array_merge( array( self::slug() ), array_keys( self::groups() ) );
	}

	/**
	 * The tab a license lives on: a group's slug, or '' for the licenses tab.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private static function home_of( array $license ): string {
		foreach ( self::groups() as $slug => $group ) {
			if ( call_user_func( $group['match'], $license ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Licenses sorted by tab: '' is the licenses tab, the other keys are the groups' slugs.
	 *
	 * @param array<int, array<string, mixed>> $licenses License rows.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function split( array $licenses ): array {
		$tabs = array( '' => array() ) + array_fill_keys( array_keys( self::groups() ), array() );

		foreach ( $licenses as $license ) {
			// A child licence (made by a service for one of its parts, such as the mailbox of a hosting account) belongs to its parent
			// and is shown with it, never as a licence of its own.
			if ( 'child' === (string) ( $license['source'] ?? '' ) ) {
				continue;
			}

			$tabs[ self::home_of( $license ) ][] = $license;
		}

		return $tabs;
	}

	/**
	 * Address of the tab a license lives on.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function url_for_license( array $license ): string {
		$home = self::home_of( $license );

		return wc_get_account_endpoint_url( '' !== $home ? $home : self::slug() );
	}

	/**
	 * Output and title for each extra tab.
	 */
	public static function register_groups(): void {
		foreach ( self::groups() as $slug => $group ) {
			add_action(
				'woocommerce_account_' . $slug . '_endpoint',
				static function () use ( $slug ): void {
					self::output( $slug );
				}
			);
			add_filter(
				'woocommerce_endpoint_' . $slug . '_title',
				static function () use ( $group ): string {
					return $group['label'];
				}
			);
		}
	}

	public static function title(): string {
		$label = (string) Settings::get( 'account_label' );

		return '' !== $label ? $label : __( 'Licenses', 'beltoft-licenses' );
	}

	/**
	 * Rewrite rules are flushed once after activation or a slug change, not on every request.
	 */
	public static function maybe_flush(): void {
		$hash = md5( implode( ',', array_keys( self::groups() ) ) );

		if ( get_option( self::OPTION_FLUSH ) || get_option( self::OPTION_GROUPS ) !== $hash ) {
			delete_option( self::OPTION_FLUSH );
			update_option( self::OPTION_GROUPS, $hash, false );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Schedules a flush when the endpoint slug changes.
	 *
	 * @param mixed $old Previous settings.
	 * @param mixed $new New settings.
	 */
	public static function settings_saved( $old, $new ): void {
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();

		if ( ( $old['account_endpoint'] ?? '' ) !== ( $new['account_endpoint'] ?? '' ) ) {
			update_option( self::OPTION_FLUSH, 1 );
		}
	}

	/**
	 * The licenses that belong to a user: linked to the account, or bought as a guest with
	 * the account's email address and not linked to another account.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function licenses_for_user( int $user_id ): array {
		$user = get_userdata( $user_id );

		// The email check is part of the key: its answer can change inside a request (a confirmation flow, a filter).
		$key = $user_id . ( $user && self::email_verified( $user ) ? ':v' : ':u' );

		if ( ! isset( self::$user_licenses[ $key ] ) ) {
			self::$user_licenses[ $key ] = self::read_licenses_for_user( $user_id );
		}

		return self::$user_licenses[ $key ];
	}

	/**
	 * The result of licenses_for_user for each user, for this request.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static $user_licenses = array();

	/**
	 * Forgets the remembered lists. Hooked to every write that can change one.
	 */
	public static function forget_user_licenses(): void {
		self::$user_licenses = array();
	}

	/**
	 * Forgets the remembered lists when the unverified-email marker of a user changes.
	 *
	 * @param int|string[] $meta_id Meta ID (or IDs).
	 * @param int          $user_id User ID.
	 * @param string       $key     Meta key.
	 */
	public static function forget_user_licenses_on_meta( $meta_id, $user_id, $key ): void {
		if ( self::UNVERIFIED_META === $key ) {
			self::forget_user_licenses();
		}
	}

	/**
	 * Reads the licenses of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_licenses_for_user( int $user_id ): array {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		$licenses = array();

		foreach ( Licenses::where( 'user_id', $user_id ) as $license ) {
			$licenses[ $license['id'] ] = $license;
		}

		if ( self::email_verified( $user ) ) {
			foreach ( Licenses::where( 'email', (string) $user->user_email ) as $license ) {
				if ( null === $license['user_id'] ) {
					$licenses[ $license['id'] ] = $license;
				}
			}
		}

		ksort( $licenses );

		return apply_filters( 'beltoft_licenses_account_licenses', array_values( $licenses ), $user_id );
	}

	/**
	 * Set while the customer saves My Account details, so a changed email can be told from an
	 * email that staff or a confirmation flow changed.
	 *
	 * @var bool
	 */
	private static $account_save = false;

	/**
	 * Marks that the current request is the customer saving their own account details.
	 */
	public static function mark_account_save(): void {
		self::$account_save = true;
	}

	/**
	 * A customer who changes their own email in My Account has not proved the new address, so
	 * licenses held by that address alone are not shown to them until the address is verified.
	 *
	 * @param int      $user_id  User ID.
	 * @param \WP_User $old_user User before the update.
	 */
	public static function flag_changed_email( $user_id, $old_user = null ): void {
		if ( ! self::$account_save || ! $old_user instanceof \WP_User ) {
			return;
		}

		$user = get_userdata( (int) $user_id );

		if ( $user && strtolower( (string) $user->user_email ) !== strtolower( (string) $old_user->user_email ) ) {
			update_user_meta( (int) $user_id, self::UNVERIFIED_META, strtolower( (string) $user->user_email ) );
		}
	}

	/**
	 * Is the user's current email address one they have not proved? Licenses held only by an
	 * email address are shown to the account only while it is.
	 *
	 * @param \WP_User $user User.
	 */
	public static function email_verified( \WP_User $user ): bool {
		$unverified = (string) get_user_meta( $user->ID, self::UNVERIFIED_META, true );
		$verified   = '' === $unverified || strtolower( (string) $user->user_email ) !== $unverified;

		/**
		 * Filters whether the account's email address counts as verified for matching licenses
		 * that are held by email only. A confirmation flow answers true (and may delete the
		 * user meta beltoft_licenses_unverified_email).
		 *
		 * @param bool     $verified Verified.
		 * @param \WP_User $user     User.
		 */
		return (bool) apply_filters( 'beltoft_licenses_email_verified', $verified, $user );
	}

	/**
	 * Does this license belong to the user?
	 *
	 * @param array<string, mixed> $license License.
	 * @param int                  $user_id User ID.
	 */
	public static function owns( array $license, int $user_id ): bool {
		foreach ( self::licenses_for_user( $user_id ) as $own ) {
			if ( (int) $own['id'] === (int) $license['id'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The page content.
	 */
	public static function render(): void {
		self::output( '' );
	}

	/**
	 * One tab's list: '' for the licenses tab, or an extra tab's slug.
	 *
	 * @param string $tab Tab.
	 */
	private static function output( string $tab ): void {
		$groups = self::groups();
		$tabs   = self::split( self::licenses_for_user( get_current_user_id() ) );
		$views  = array_map( array( View::class, 'license' ), $tabs[ $tab ] ?? array() );

		// Feedback from "Free this site" and downloads. Themes that print WooCommerce notices
		// earlier on the page have cleared them by now, so nothing shows twice; themes that
		// don't (some page builders) would otherwise lose the message.
		if ( function_exists( 'wc_print_notices' ) ) {
			wc_print_notices();
		}

		self::enqueue();

		wc_get_template(
			'myaccount/licenses.php',
			array(
				'licenses'    => $views,
				'account_url' => wc_get_account_endpoint_url( '' !== $tab ? $tab : self::slug() ),
				'empty'       => '' !== $tab ? (string) ( $groups[ $tab ]['empty'] ?? '' ) : '',
			),
			'beltoft-licenses/',
			BELTOFT_LICENSES_PATH . 'templates/'
		);
	}

	/**
	 * The layout stylesheet, only on this page. Themes can turn it off with the
	 * beltoft_licenses_account_css filter.
	 */
	public static function styles(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! array_filter( array_map( 'is_wc_endpoint_url', self::slugs() ) ) || ! apply_filters( 'beltoft_licenses_account_css', true ) ) {
			return;
		}

		wp_enqueue_style( 'beltoft-licenses-account', plugins_url( 'assets/account.css', BELTOFT_LICENSES_FILE ), array(), BELTOFT_LICENSES_VERSION );
	}

	/**
	 * A small script for the copy buttons, only on this page.
	 */
	private static function enqueue(): void {
		wp_register_script( 'beltoft-licenses-account', '', array(), BELTOFT_LICENSES_VERSION, true );
		wp_enqueue_script( 'beltoft-licenses-account' );
		wp_add_inline_script(
			'beltoft-licenses-account',
			'document.addEventListener("click",function(e){var b=e.target.closest("[data-beltoft-copy]");if(!b||!navigator.clipboard){return;}navigator.clipboard.writeText(b.getAttribute("data-beltoft-copy")).then(function(){var t=b.textContent;b.textContent=' . wp_json_encode( __( 'Copied', 'beltoft-licenses' ) ) . ';setTimeout(function(){b.textContent=t;},1500);});});'
		);
	}

	/**
	 * The download link for one license, with a nonce.
	 *
	 * @param int $license_id License ID.
	 */
	public static function download_url( int $license_id ): string {
		return wp_nonce_url(
			add_query_arg( 'beltoft_licenses_download', $license_id, wc_get_account_endpoint_url( self::slug() ) ),
			'beltoft_licenses_download_' . $license_id
		);
	}

	/**
	 * Handles "Free this site" (POST) and downloads (GET) for logged-in customers.
	 */
	public static function handle(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- each branch verifies its own nonce first.
		if ( isset( $_POST['beltoft_licenses_free_site'], $_POST['license_id'], $_POST['domain'] ) ) {
			$license_id = absint( $_POST['license_id'] );
			$domain     = Key::domain( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) );
			check_admin_referer( 'beltoft_licenses_free_site_' . $license_id );
			self::free_site( $license_id, $domain, get_current_user_id() );
			wp_safe_redirect( wc_get_account_endpoint_url( self::slug() ) );
			exit;
		}

		if ( isset( $_GET['beltoft_licenses_download'] ) ) {
			$license_id = absint( $_GET['beltoft_licenses_download'] );
			check_admin_referer( 'beltoft_licenses_download_' . $license_id );
			self::download( $license_id, get_current_user_id() );
		}
		// phpcs:enable
	}

	/**
	 * Frees a site of one of the user's licenses.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised site.
	 * @param int    $user_id    User ID.
	 */
	public static function free_site( int $license_id, string $domain, int $user_id ): bool {
		$license = Licenses::find( $license_id );

		if ( ! $license || ! self::owns( $license, $user_id ) ) {
			wc_add_notice( __( 'That license is not yours.', 'beltoft-licenses' ), 'error' );
			return false;
		}

		$result = Licenses::deactivate( $license, $domain );

		if ( $result['success'] ) {
			/* translators: %s: site address. */
			wc_add_notice( sprintf( __( '%s was removed from the license. You can now use it on another site.', 'beltoft-licenses' ), $domain ) );
			return true;
		}

		wc_add_notice( __( 'That site was not active on this license.', 'beltoft-licenses' ), 'error' );

		return false;
	}

	/**
	 * Sends the current version of one of the user's licensed products, or a notice why not.
	 *
	 * @param int $license_id License ID.
	 * @param int $user_id    User ID.
	 */
	public static function download( int $license_id, int $user_id ): void {
		$license = Licenses::find( $license_id );
		$view    = $license && self::owns( $license, $user_id ) ? View::license( $license ) : null;

		if ( ! $view || ! $view['can_download'] ) {
			wc_add_notice( __( 'This download is not available. The license must be active, and the product must have a published version.', 'beltoft-licenses' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::slug() ) );
			exit;
		}

		$release  = Software::release( (string) $license['product_slug'], (string) $view['version'] );
		$path     = Software::path( (string) $release['file'] );
		$filename = $license['product_slug'] . '-' . $view['version'] . '.zip';

		if ( apply_filters( 'beltoft_licenses_deliver_file', false, $path, $filename, $license ) ) {
			return;
		}

		Download::send( $path, $filename );
	}
}
