<?php
/**
 * A stand-in with WC_Subscription's methods, so the suite does not need WooCommerce Subscriptions.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

class FakeSubscription {
	public $id;
	public $items     = array();
	public $end       = 0;
	public $notes     = array();
	public $parent_id = null;
	public $status    = 'active';
	public $meta      = array();
	public $customer  = 7;
	public $email     = 'sub@example.org';
	public function __construct( int $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_items() { return $this->items; }
	public function get_customer_id() { return $this->customer; }
	public function get_billing_email() { return $this->email; }
	public function get_parent_id() { return null === $this->parent_id ? 1000 + $this->id : $this->parent_id; }
	public function get_time( $type ) { return 'end' === $type ? $this->end : 0; }
	public function get_status() { return $this->status; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_status( $status, $note = '' ) { $this->status = $status; $this->notes[] = $note; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
}
