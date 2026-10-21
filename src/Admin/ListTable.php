<?php
/**
 * WooCommerce → Licenses list.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Admin;

use BeltoftLicenses\Account\View;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The license list: search by key, email or site, filter by status and product,
 * bulk revoke, suspend or reactivate.
 */
final class ListTable extends \WP_List_Table {

	/**
	 * Set up.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'license',
				'plural'   => 'licenses',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox">',
			'key'      => __( 'License key', 'beltoft-licenses' ),
			'product'  => __( 'Product', 'beltoft-licenses' ),
			'customer' => __( 'Customer', 'beltoft-licenses' ),
			'status'   => __( 'Status', 'beltoft-licenses' ),
			'sites'    => __( 'Sites', 'beltoft-licenses' ),
			'expires'  => __( 'Valid', 'beltoft-licenses' ),
			'source'   => __( 'From', 'beltoft-licenses' ),
			'created'  => __( 'Created', 'beltoft-licenses' ),
		);
	}

	/**
	 * Loads the current page of licenses.
	 */
	public function prepare_items(): void {
		$filters = Screen::filters();
		$per     = $this->get_items_per_page( 'beltoft_licenses_per_page', 20 );

		list( $items, $total ) = Licenses::query(
			array_merge(
				$filters,
				array(
					'per_page' => $per,
					'page'     => $this->get_pagenum(),
				)
			)
		);

		$this->items           = $items;
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/**
	 * Status links above the table.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = Screen::filters()['status'];
		$views   = array(
			'' => __( 'All', 'beltoft-licenses' ),
		);
		foreach ( Licenses::STATUSES as $status ) {
			$views[ $status ] = View::status_label( $status );
		}

		$links = array();
		foreach ( $views as $status => $label ) {
			$links[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( Screen::url( array( 'status' => $status ) ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}

		return $links;
	}

	/**
	 * Product filter.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$current = Screen::filters()['product_slug'];

		echo '<div class="alignleft actions"><label class="screen-reader-text" for="beltoft-licenses-product">' . esc_html__( 'Filter by product', 'beltoft-licenses' ) . '</label>';
		echo '<select name="product_slug" id="beltoft-licenses-product"><option value="">' . esc_html__( 'All products', 'beltoft-licenses' ) . '</option>';
		foreach ( Software::all() as $slug => $name ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $slug ), selected( $current, $slug, false ), esc_html( $name ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'beltoft-licenses' ), '', 'filter_action', false );
		printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( Screen::export_url() ), esc_html__( 'Export CSV', 'beltoft-licenses' ) );
		echo '</div>';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'revoke'     => __( 'Revoke', 'beltoft-licenses' ),
			'suspend'    => __( 'Suspend', 'beltoft-licenses' ),
			'reactivate' => __( 'Reactivate', 'beltoft-licenses' ),
		);
	}

	/**
	 * Row checkbox.
	 *
	 * @param array<string, mixed> $item License.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="licenses[]" value="%d">', (int) $item['id'] );
	}

	/**
	 * Key, linking to the edit screen.
	 *
	 * @param array<string, mixed> $item License.
	 */
	protected function column_key( array $item ): string {
		return sprintf( '<a href="%1$s"><code>%2$s</code></a>', esc_url( Screen::url( array( 'license' => (int) $item['id'] ) ) ), esc_html( (string) $item['license_key'] ) );
	}

	/**
	 * Other columns.
	 *
	 * @param array<string, mixed> $item        License.
	 * @param string               $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'product':
				return esc_html( View::product_name( $item ) );
			case 'customer':
				return self::customer( $item );
			case 'status':
				return esc_html( View::status_label( Licenses::effective_status( $item ) ) );
			case 'sites':
				return esc_html( View::usage_label( $item ) );
			case 'expires':
				return esc_html( View::expiry_label( $item, Licenses::effective_status( $item ) ) );
			case 'source':
				return self::source( $item );
			case 'created':
				return esc_html( get_date_from_gmt( (string) $item['created_at'], get_option( 'date_format' ) ) );
		}

		return '';
	}

	/**
	 * Customer email, linking to the user when there is one.
	 *
	 * @param array<string, mixed> $item License.
	 */
	public static function customer( array $item ): string {
		$email = esc_html( (string) $item['email'] );

		if ( $item['user_id'] && get_userdata( (int) $item['user_id'] ) ) {
			return sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_user_link( (int) $item['user_id'] ) ), $email );
		}

		return $email;
	}

	/**
	 * Where the license came from, linking to its order and subscription.
	 *
	 * @param array<string, mixed> $item License.
	 */
	public static function source( array $item ): string {
		$parts = array( esc_html( (string) $item['source'] ) );

		if ( $item['order_id'] ) {
			$order = wc_get_order( (int) $item['order_id'] );
			if ( $order ) {
				/* translators: %s: order number. */
				$parts[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html( sprintf( __( 'Order #%s', 'beltoft-licenses' ), $order->get_order_number() ) ) );
			}
		}

		if ( $item['subscription_id'] && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( (int) $item['subscription_id'] );
			if ( $subscription ) {
				/* translators: %s: subscription number. */
				$parts[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $subscription->get_edit_order_url() ), esc_html( sprintf( __( 'Subscription #%s', 'beltoft-licenses' ), $subscription->get_order_number() ) ) );
			}
		}

		return implode( '<br>', $parts );
	}

	/**
	 * When nothing matches.
	 */
	public function no_items(): void {
		esc_html_e( 'No licenses found.', 'beltoft-licenses' );
	}
}
