<?php
/**
 * WordPress 6.5+ Block Bindings (`wm-peppol/status`) and GenerateBlocks 2.x Dynamic Tag (`{{wm_peppol_status}}`).
 *
 * Exposes measured gateway counts and dispatch configuration to block attributes and dynamic tags.
 * Never outputs conformance or legal readiness assertions.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Peppol\Core\InvoiceStore;
use WMCyber\Peppol\Core\Settings;

final class DynamicBindings {

	public const BINDING_SOURCE = 'wm-peppol/status';
	public const GB_TAG         = 'wm_peppol_status';

	public function init(): void {
		add_action( 'init', [ $this, 'register_block_bindings' ] );
		add_action( 'init', [ $this, 'register_generateblocks_tag' ], 20 );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ $this, 'filter_generateblocks_replacement' ], 10, 2 );
	}

	public function register_block_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'wm-peppol/status',
			[
				'label'              => __( 'Peppol-Gateway-Status', 'wm-peppol-as4-gateway' ),
				'get_value_callback' => [ $this, 'get_bound_value' ],
				'uses_context'       => [ 'postId', 'postType' ],
			]
		);
	}

	public function register_generateblocks_tag(): void {
		if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return;
		}

		new \GenerateBlocks_Register_Dynamic_Tag(
			[
				'title'    => __( 'Peppol-Gateway-Status', 'wm-peppol-as4-gateway' ),
				'tag'      => 'wm_peppol_status',
				'type'     => 'wm-peppol',
				'supports' => [],
				'return'   => [ $this, 'render_generateblocks_tag' ],
			]
		);
	}

	/**
	 * Resolve WP 6.5+ block binding value for `wm-peppol/status`.
	 *
	 * @param array<string, mixed> $source_args    Binding arguments (`key` optional: summary, invoices, transmissions, delivered, dispatch).
	 * @param mixed                $block_instance Block instance.
	 * @param string               $attribute_name Bound attribute name.
	 * @return string
	 */
	public function get_bound_value( array $source_args, $block_instance = null, string $attribute_name = '' ): string {
		unset( $block_instance, $attribute_name );
		$key = isset( $source_args['key'] ) && is_string( $source_args['key'] ) ? sanitize_key( $source_args['key'] ) : 'summary';

		return $this->measured_value( $key );
	}

	/**
	 * Callback for `GenerateBlocks_Register_Dynamic_Tag` (`{{wm_peppol_status}}`).
	 *
	 * @param array<string, mixed> $options Tag options (`key` optional).
	 * @return string
	 */
	public function render_generateblocks_tag( array $options = [] ): string {
		$key = isset( $options['key'] ) && is_string( $options['key'] ) ? sanitize_key( $options['key'] ) : 'summary';

		return esc_html( $this->measured_value( $key ) );
	}

	/**
	 * Fallback replacement filter for GenerateBlocks `{{wm_peppol_status}}` tokens.
	 *
	 * @param mixed $replacement Existing replacement.
	 * @param mixed $args        Tag context from GenerateBlocks.
	 * @return mixed
	 */
	public function filter_generateblocks_replacement( mixed $replacement, mixed $args = [] ): mixed {
		if ( ! is_array( $args ) ) {
			return $replacement;
		}
		$tag = isset( $args['tag'] ) && is_string( $args['tag'] ) ? $args['tag'] : '';
		if ( self::GB_TAG !== $tag ) {
			return $replacement;
		}
		$options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : [];

		return $this->render_generateblocks_tag( $options );
	}

	/**
	 * Measured status string without unverified conformity assertions.
	 *
	 * @param string $key Metric key.
	 * @return string
	 */
	private function measured_value( string $key ): string {
		$counts = InvoiceStore::counts();
		if ( ! $counts['tables'] ) {
			return __( 'Tabellen des Plugins fehlen', 'wm-peppol-as4-gateway' );
		}

		return match ( $key ) {
			'invoices'      => (string) $counts['invoices'],
			'transmissions' => (string) $counts['transmissions'],
			'delivered'     => (string) $counts['delivered'],
			'dispatch'      => Settings::dispatch_configured()
				? __( 'KoSIT-Validator und Phase4-Dienst konfiguriert', 'wm-peppol-as4-gateway' )
				: __( 'Versand nicht konfiguriert', 'wm-peppol-as4-gateway' ),
			default         => sprintf(
				/* translators: 1: stored invoices count, 2: delivered receipts count */
				__( '%1$d gespeicherte Rechnungen, %2$d Empfangsquittungen', 'wm-peppol-as4-gateway' ),
				$counts['invoices'],
				$counts['delivered']
			),
		};
	}
}
