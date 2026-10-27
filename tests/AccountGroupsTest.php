<?php
/**
 * My Account tabs that other plugins add.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Account\Endpoint;

class AccountGroupsTest extends TestCase {

	private int $user;

	public function set_up(): void {
		parent::set_up();
		$this->user = self::factory()->user->create( array( 'user_email' => 'me@example.org', 'role' => 'customer' ) );
		WC()->initialize_session();
		wp_set_current_user( $this->user );
	}

	/** A "domains" tab holding licenses whose product slug starts with domain-. */
	private function domains_tab( array $extra = array() ): void {
		add_filter(
			'beltoft_licenses_account_groups',
			static function ( $groups ) use ( $extra ) {
				$groups[] = $extra + array( 'slug' => 'domains', 'label' => 'Domains', 'empty' => 'You have no domains yet.', 'match' => static fn( $l ) => str_starts_with( (string) $l['product_slug'], 'domain-' ) );
				return $groups;
			}
		);
	}

	private function menu(): array {
		return Endpoint::menu( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
	}

	public function test_licenses_are_sorted_into_tabs_and_linked_there(): void {
		$this->domains_tab();
		$plugin = $this->license( array( 'user_id' => $this->user, 'product_slug' => 'my-plugin' ) );
		$domain = $this->license( array( 'user_id' => $this->user, 'product_slug' => 'domain-test' ) );

		$tabs = Endpoint::split( array( $plugin, $domain ) );

		$this->assertSame( array( $plugin['id'] ), array_column( $tabs[''], 'id' ) );
		$this->assertSame( array( $domain['id'] ), array_column( $tabs['domains'], 'id' ) );
		$this->assertSame( wc_get_account_endpoint_url( 'domains' ), Endpoint::url_for_license( $domain ) );
		$this->assertSame( wc_get_account_endpoint_url( Endpoint::slug() ), Endpoint::url_for_license( $plugin ) );
		$this->assertArrayHasKey( 'domains', Endpoint::query_vars( array() ) );
	}

	public function test_menu_shows_only_tabs_that_have_licenses(): void {
		$this->domains_tab();

		$this->assertArrayHasKey( Endpoint::slug(), $this->menu(), 'nothing at all: the licenses tab stays' );
		$this->assertArrayNotHasKey( 'domains', $this->menu() );

		$this->license( array( 'user_id' => $this->user, 'product_slug' => 'domain-test' ) );
		$menu = $this->menu();
		$this->assertArrayHasKey( 'domains', $menu );
		$this->assertArrayNotHasKey( Endpoint::slug(), $menu, 'only domains: no empty licenses tab' );
		$this->assertSame( 'customer-logout', array_key_last( $menu ) );

		$this->license( array( 'user_id' => $this->user, 'product_slug' => 'my-plugin' ) );
		$menu = $this->menu();
		$this->assertSame( array( 'dashboard', 'domains', Endpoint::slug(), 'customer-logout' ), array_keys( $menu ) );
	}

	public function test_each_tab_lists_only_its_own_licenses(): void {
		$this->domains_tab();
		$plugin = $this->license( array( 'user_id' => $this->user, 'product_slug' => 'my-plugin' ) );
		$domain = $this->license( array( 'user_id' => $this->user, 'product_slug' => 'domain-test' ) );
		Endpoint::register_groups();

		ob_start();
		Endpoint::render();
		$licenses_tab = (string) ob_get_clean();
		ob_start();
		do_action( 'woocommerce_account_domains_endpoint' );
		$domains_tab = (string) ob_get_clean();

		$this->assertStringContainsString( $plugin['license_key'], $licenses_tab );
		$this->assertStringNotContainsString( $domain['license_key'], $licenses_tab, 'a license is shown on one tab only' );
		$this->assertStringContainsString( $domain['license_key'], $domains_tab );
		$this->assertStringNotContainsString( $plugin['license_key'], $domains_tab );
		$this->assertSame( 'Domains', apply_filters( 'woocommerce_endpoint_domains_title', '' ) );
	}

	public function test_an_empty_tab_uses_its_own_text(): void {
		$this->domains_tab();
		Endpoint::register_groups();

		ob_start();
		do_action( 'woocommerce_account_domains_endpoint' );
		$this->assertStringContainsString( 'You have no domains yet.', (string) ob_get_clean() );
	}

	public function test_invalid_groups_are_ignored(): void {
		add_filter(
			'beltoft_licenses_account_groups',
			static function () {
				return array(
					array( 'slug' => 'no-match', 'label' => 'X' ),
					array( 'slug' => Endpoint::slug(), 'label' => 'Clash', 'match' => '__return_true' ),
					array( 'slug' => 'ok', 'label' => 'Fine', 'match' => '__return_false' ),
					array( 'slug' => 'ok', 'label' => 'Duplicate', 'match' => '__return_true' ),
					'not an array',
				);
			}
		);

		$this->assertSame( array( 'ok' ), array_keys( Endpoint::groups() ) );
		$this->assertSame( 'Fine', Endpoint::groups()['ok']['label'] );
	}
}
