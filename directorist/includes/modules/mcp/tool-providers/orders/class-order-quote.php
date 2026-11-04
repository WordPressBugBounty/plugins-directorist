<?php
/**
 * Order Quote for orders.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Tool_Providers\Orders;

use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\MCP\Registration\Tool_Registry;
use Directorist\MCP\Runtime\Access_Control;
use Directorist\MCP\Support\Validation;

defined( 'ABSPATH' ) || exit;

/** Order Quote. */
class Order_Quote {
    /**
     * Quote.
     *
     * @param mixed $customer Customer.
     * @param string $type Type.
     * @param array $item Item.
     * @param \stdClass|null $order Order.
     */
    public static function quote( $customer, string $type, array $item, ?\stdClass $order = null ): array {
        Validation::check( directorist_is_monetization_enabled(), __( 'New purchases are unavailable while monetization is disabled.', 'directorist' ), 'dependency_unavailable' );
        Validation::check( (bool) get_userdata( Validation::id( $customer ) ), __( 'Customer not found.', 'directorist' ) );
        if ( 'featured_listing' !== $type ) {
            $adapters = Tool_Registry::instance()->fields( 'order_items', $order );
            Validation::check( isset( $adapters[ $type ] ), __( 'A supported order-item adapter is required.', 'directorist' ), 'unsupported_operation' );
            $definition = $adapters[ $type ];
            Validation::unwrap( rest_validate_value_from_schema( $item ?: (object) [], $definition['schema'] ) );
            $quote = Validation::unwrap( call_user_func( $definition['validate'], $item, $customer, $order ) );
            Validation::check( is_array( $quote ) && isset( $quote['dto'] ) && $quote['dto'] instanceof OrderDTO && isset( $quote['preview'], $quote['state'] ), __( 'Invalid native item quote.', 'directorist' ), 'unsupported_operation' );
            $dto = $quote['dto'];
            Validation::check( ! $dto->is_initialized( 'id' ) && $dto->get_user_id() === $customer && in_array( $dto->get_status(), [ 'pending', 'unpaid' ], true ) && ! $dto->is_initialized( 'expires_at' ), __( 'The item quote attempted a restricted identity or settled-state change.', 'directorist' ), 'unsupported_operation' );
            if ( $dto->is_initialized( 'listing_id' ) && $dto->get_listing_id() ) {
                $listing = Access_Control::listing( $dto->get_listing_id(), 'edit' );
                Validation::check( (int) $listing->post_author === $customer && ( ! $order || (int) $order->listing_id === $listing->ID ), __( 'The item listing must belong to the purchaser and cannot be transferred.', 'directorist' ) );
            }
            Money::native( Money::stored( directorist_order_payable( $dto ), $dto->get_currency() ), $dto->get_currency() );
            return $quote;
        }
        Validation::check( Order_Policy::featured_ready(), __( 'Featured-listing purchases are unavailable.', 'directorist' ), 'dependency_unavailable' );
        Validation::allowed( $item, [ 'listing_id' ] );
        $listing = Access_Control::listing( Validation::id( $item['listing_id'] ?? null ), 'edit' );
        Validation::check( (int) $listing->post_author === $customer && 'trash' !== $listing->post_status, __( 'The listing must belong to this customer and be eligible for purchase.', 'directorist' ) );
        Validation::check( ! directorist_order_repository()->listing_has_active_featured_entitlement( $listing->ID ), __( 'This listing already has an active featured entitlement.', 'directorist' ), 'dependency_blocked' );
        $request = new \WP_REST_Request( 'POST' );
        $request->set_param( 'listing_id', $listing->ID );
        do_action( 'directorist_checkout_validation', $type, $request );
        $amount = Money::stored( get_directorist_option( 'featured_listing_price' ), directorist_currency() );
        Validation::check( Money::minor( $amount, directorist_currency() ) > 0, __( 'Use the native free-listing workflow for a zero-priced item.', 'directorist' ), 'unsupported_operation' );
        $dto = ( new OrderDTO() )->set_user_id( $customer )->set_listing_id( $listing->ID )->set_currency( directorist_currency() )->set_status( 'pending' );
        do_action( 'directorist_checkout_create_order', $dto, $type, $request );
        Validation::check( ! $dto->is_initialized( 'id' ) && $dto->get_user_id() === $customer && $dto->get_listing_id() === $listing->ID && 'pending' === $dto->get_status(), __( 'The item adapter attempted an unsupported purchase change.', 'directorist' ), 'unsupported_operation' );
        if ( $order ) {
            $dto->set_coupon_code( $order->coupon_code )->set_coupon_discount( $order->coupon_discount )->set_coupon_discount_type( $order->coupon_discount_type )->set_tax_rate( (float) $order->tax_rate )->set_tax_type( (string) $order->tax_type );
        }
        $native_total = Money::stored( directorist_order_payable( $dto ), $dto->get_currency() );
        Money::native( $native_total, $dto->get_currency() );
        return [ 'dto' => $dto, 'preview' => [ 'item_type' => $type, 'customer_id' => $customer, 'listing_id' => $listing->ID, 'amount' => $native_total, 'currency' => $dto->get_currency(), 'status' => 'pending', 'effects' => 'Unpaid order only; no charge or entitlement grant.' ], 'state' => [ $native_total, $listing->to_array(), get_post_meta( $listing->ID ), get_directorist_option( 'featured_listing_time' ) ] ];
    }
}
