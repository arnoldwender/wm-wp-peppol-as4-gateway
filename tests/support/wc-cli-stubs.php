<?php
/**
 * WooCommerce and WP-CLI fakes for the standalone suite.
 *
 * Order and item getters return what WooCommerce 10.7.0 returns: get_total() and get_quantity() are strings or
 * numbers as stored, get_taxes() is ['total' => [rate id => amount], 'subtotal' => [...]]
 * (includes/class-wc-order-item-product.php:177-199, 359-381), get_items( $type ) filters by item type
 * (abstracts/abstract-wc-order.php:941) and WC_Tax::get_rate_percent_value() reads the rate of a rate id as float
 * (includes/class-wc-tax.php:755-766).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace {

	final class Test_WC_Item {
		/** @param array<string, array<string, string>> $taxes */
		public function __construct( public string $name, public string $quantity, public string $total, public array $taxes = [ 'total' => [], 'subtotal' => [] ] ) {}
		public function get_name(): string {
			return $this->name;
		}
		public function get_quantity(): string {
			return $this->quantity;
		}
		public function get_total(): string {
			return $this->total;
		}
		public function get_taxes(): array {
			return $this->taxes;
		}
	}

	final class Test_WC_Order {
		public array $meta     = [];
		public array $notes    = [];
		public array $statuses = [];
		public int $saves      = 0;
		/** @var array<string, Test_WC_Item[]> */
		public array $items = [ 'line_item' => [], 'shipping' => [], 'fee' => [] ];

		/** @param array<string, string> $billing */
		public function __construct( public int $id, public array $billing = [], public string $total = '0.00', public string $currency = 'EUR' ) {}

		public function get_id(): int {
			return $this->id;
		}
		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}
		public function save(): int {
			++$this->saves;
			return $this->id;
		}
		public function add_order_note( $note ): int {
			$this->notes[] = (string) $note;
			return count( $this->notes );
		}
		public function update_status( $status, $note = '' ): bool {
			$this->statuses[] = (string) $status;
			return true;
		}
		public function get_items( $types = 'line_item' ): array {
			return $this->items[ $types ] ?? [];
		}
		public function get_total(): string {
			return $this->total;
		}
		public function get_currency(): string {
			return $this->currency;
		}
		public function get_billing_company(): string {
			return $this->billing['company'] ?? '';
		}
		public function get_billing_first_name(): string {
			return $this->billing['first_name'] ?? '';
		}
		public function get_billing_last_name(): string {
			return $this->billing['last_name'] ?? '';
		}
		public function get_billing_address_1(): string {
			return $this->billing['address_1'] ?? '';
		}
		public function get_billing_city(): string {
			return $this->billing['city'] ?? '';
		}
		public function get_billing_postcode(): string {
			return $this->billing['postcode'] ?? '';
		}
		public function get_billing_country(): string {
			return $this->billing['country'] ?? '';
		}
	}

	function wc_get_order( $order_id ) {
		return $GLOBALS['wp_test']['orders'][ (int) $order_id ] ?? false;
	}

	function wc_get_orders( array $args ): array {
		$found = [];
		foreach ( $GLOBALS['wp_test']['orders'] as $order ) {
			if ( isset( $args['meta_key'] ) && (string) $order->get_meta( $args['meta_key'] ) !== (string) ( $args['meta_value'] ?? '' ) ) {
				continue;
			}
			$found[] = $order;
		}
		return array_slice( $found, 0, (int) ( $args['limit'] ?? 10 ) );
	}

	final class WC_Tax {
		/** @var array<string, string> rate id => rate as stored */
		public static array $rates = [];
		public static function get_rate_percent_value( $key_or_rate ): float {
			return floatval( self::$rates[ (string) $key_or_rate ] ?? 0 );
		}
	}

	final class WC_Admin_Meta_Boxes {
		public static array $errors = [];
		public static function add_error( $text ): void {
			self::$errors[] = (string) $text;
		}
	}

	function woocommerce_wp_text_input( array $field ): void {
		echo '<input type="text" id="' . esc_attr( $field['id'] ) . '" value="' . esc_attr( (string) ( $field['value'] ?? '' ) ) . '" />';
	}

	class WP_CLI_Command {}

	final class Test_CLI_Exit extends Exception {}

	final class WP_CLI {
		public static array $out = [];
		public static function add_command( $name, $callable ): void {}
		public static function line( $message = '' ): void {
			self::$out[] = [ 'line', (string) $message ];
		}
		public static function success( $message ): void {
			self::$out[] = [ 'success', (string) $message ];
		}
		public static function warning( $message ): void {
			self::$out[] = [ 'warning', (string) $message ];
		}
		public static function error( $message ): void {
			self::$out[] = [ 'error', (string) $message ];
			throw new Test_CLI_Exit( (string) $message );
		}
	}
}

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ): void {
		$GLOBALS['wp_test']['cli_items'] = [ 'format' => $format, 'items' => $items, 'fields' => $fields ];
	}
}
