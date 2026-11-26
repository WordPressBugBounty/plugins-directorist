<?php

namespace Directorist\Rest_Api\Controllers\Version1;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

use Exception;
use Directorist\Checkout\Currency;
use Directorist\Checkout\Quote;
use Directorist\Contracts\PaymentInterface;
use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\Enums\Order\Status as OrderStatus;

class Checkout_Controller extends Abstract_Controller {
    protected $rest_base = 'checkout';

    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_checkout_data' ],
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'checkout' ],
                    'permission_callback' => [ $this, 'auth_permissions_check' ],
                    'args'                => [
                        'checkout_type'     => [
                            'description'       => __( 'The type of checkout to be performed.', 'directorist' ),
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                            'enum'              => directorist_get_checkout_types(),
                            'required'          => true,
                        ],
                        'payment_gateway'     => [
                            'description'       => __( 'The payment gateway to be used for the checkout.', 'directorist' ),
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                            'required'          => false,
                        ],
                    ],
                ],
            ]
        );
        
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/retry-payment',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'retry_payment' ],
                    'permission_callback' => [ $this, 'auth_permissions_check' ],
                    'args'                => [
                        'order_id'     => [
                            'description'       => __( 'The order id to be retried.', 'directorist' ),
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                            'required'          => true,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Get the checkout provider and page URL.
     *
     * @param WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_checkout_data( WP_REST_Request $request ) {
        $checkout_page_id = (int) get_directorist_option( 'checkout_page', 0 );
        $data             = [
            'type' => 'directorist',
            'url'  => $checkout_page_id ? get_permalink( $checkout_page_id ) : '',
        ];

        /**
         * Filters the checkout provider data returned by the REST API.
         *
         * Extensions can replace the provider type and URL when they own the
         * checkout flow.
         *
         * @param array           $data    Checkout provider data.
         * @param WP_REST_Request $request Request object.
         */
        $data = apply_filters( 'directorist_rest_checkout_data', $data, $request );

        return rest_ensure_response( $data );
    }

    public function checkout( WP_REST_Request $request ) {
        try {
            $checkout_type = $request->get_param( 'checkout_type' );

            do_action( 'directorist_checkout_validation', $checkout_type, $request );

            $gateway = (string) $request->get_param( 'payment_gateway' );
            [ $subtotal, $total ] = Quote::totals( $checkout_type, $request );
            $review = Quote::choices( $checkout_type, $request, $subtotal, $total );
            $quote = $review['choices'][$gateway] ?? null;
            $token = $request->get_param( 'checkout_quote_token' );

            if ( ( $review['review_required'] && ( $review['show_gateways'] || '' !== $gateway ) ) || $token ) {
                if ( ! $quote ) {
                    return new WP_Error( 'checkout_currency_unavailable', __( 'This payment method is unavailable for the configured currency or exchange rate. Select another method or contact the site administrator.', 'directorist' ), [ 'status' => 422 ] );
                }

                if ( ! is_string( $token ) || ! hash_equals( Quote::token( $quote, $checkout_type, $request ), $token ) ) {
                    return new WP_Error( 'checkout_quote_changed', __( 'The checkout amount needs to be reviewed. Reload checkout and confirm the amount before paying.', 'directorist' ), [ 'status' => 409 ] );
                }
            }

            $dto = ( new OrderDTO )
                ->set_user_id( get_current_user_id() )
                ->set_currency( Currency::matches_display() ? strtoupper( trim( directorist_get_currency() ) ) : atbdp_get_payment_currency() )
                ->set_status( OrderStatus::PENDING );

            $request->set_attributes( array_merge( $request->get_attributes(), [ 'directorist_persists_checkout_order' => true ] ) );
            do_action( 'directorist_checkout_create_order', $dto, $checkout_type, $request );

            $repository = directorist_order_repository();

            if ( $dto->is_initialized( 'id' ) ) {
                $order = $repository->get_by_id( $dto->get_id() );

                if ( ! $order ) {
                    return new WP_Error( 'rest_not_found', __( 'Order not found.', 'directorist' ) );
                }

                $dto = $repository->to_dto( $order );
            }

            $previous_quote = $dto->get_checkout_quote();
            if ( $token && $quote && $quote['native'] ) {
                if ( $dto->is_initialized( 'coupon_code' ) && $dto->get_coupon_code() && empty( $quote['coupon'] ) && ! isset( Quote::decode( $previous_quote )['payable'] ) && 'payment' !== $checkout_type ) {
                    return new WP_Error( 'checkout_coupon_quote_unavailable', __( 'The coupon could not be included in the checkout amount. Review the discount before paying.', 'directorist' ), [ 'status' => 409 ] );
                }

                Quote::apply( $dto, $quote );
            }

            $processor_instance = null;
            $order_payable      = directorist_order_payable( $dto );
            $process_payment    = apply_filters( 'directorist_checkout_process_payment', $order_payable > 0, $dto, $request );
            
            if ( $process_payment ) {
                $payment_gateway = $request->get_param( 'payment_gateway' );

                if ( ! $payment_gateway ) {
                    return new WP_Error( 'rest_empty_value', __( 'Payment gateway is required.', 'directorist' ) );
                }

                $payment_processors = directorist_get_payment_processors();

                if ( ! isset( $payment_processors[ $payment_gateway ] ) || ! isset( $review['choices'][$payment_gateway] ) ) {
                    return new WP_Error( 'rest_invalid_value', __( 'Invalid payment gateway.', 'directorist' ) );
                }

                /**
                 * @var PaymentInterface
                 */
                $processor_instance = directorist_make( $payment_processors[ $payment_gateway ], __( 'Invalid payment gateway.', 'directorist' ) );

                if ( ! $processor_instance instanceof PaymentInterface ) {
                    return new WP_Error( 'rest_invalid_value', __( 'Invalid payment gateway.', 'directorist' ) );
                }

                do_action( 'directorist_checkout_validate_payment_processor', $processor_instance, $dto, $checkout_type, $request );
            }

            $repository = directorist_order_repository();

            if ( ! $dto->is_initialized( 'id' ) ) {
                if ( ! $repository->create( $dto ) ) {
                    return new WP_Error( 'checkout_order_failed', __( 'The order could not be saved.', 'directorist' ), [ 'status' => 500 ] );
                }
                do_action( 'directorist_checkout_order_saved', $dto, $checkout_type, $request );
                $dto = $repository->to_dto( $repository->get_by_id( $dto->get_id() ) );
                if ( ! $dto ) {
                    return new WP_Error( 'checkout_order_failed', __( 'The saved order could not be loaded.', 'directorist' ), [ 'status' => 500 ] );
                }
            } elseif ( $token && $quote && $quote['native'] ) {
                if ( ! $repository->accept_checkout_quote( $dto, $previous_quote ) ) {
                    return new WP_Error( 'checkout_quote_changed', __( 'The order has changed. Reload checkout to review it.', 'directorist' ), [ 'status' => 409 ] );
                }
            } else {
                $repository->silent_update( $dto );
            }

            if ( $process_payment ) {
                do_action( 'directorist_before_redirect_checkout', $dto, $checkout_type, $request );

                $redirect_url = $this->pay_order( $processor_instance, $dto, $request->get_params() );

                /**
                 * Fires after a checkout payment has been initiated successfully.
                 *
                 * @param OrderDTO        $dto           The created order.
                 * @param string          $checkout_type The checkout type.
                 * @param WP_REST_Request $request       The checkout request.
                 */
                do_action( 'directorist_checkout_payment_initiated', $dto, $checkout_type, $request );

                return rest_ensure_response(
                    [
                        "redirect_url" => $redirect_url ?? $this->get_redirect_url( $dto )
                    ]
                );
            }

            do_action( 'directorist_before_redirect_checkout', $dto, $checkout_type, $request );

            // Update the order status to paid if the payable amount is zero or less
            if ( $order_payable <= 0 ) {
                $dto->set_id( $dto->get_id() )->set_status( OrderStatus::PAID );
                $repository->update( $dto );
            }

            return rest_ensure_response(
                [
                    "redirect_url" => $this->get_redirect_url( $dto )
                ]
            );
        } catch ( Exception $e ) {
            if ( in_array( $e->getCode(), [ 400, 403 ], true ) ) {
                return new WP_Error( 'rest_error', $e->getMessage(), [ 'status' => $e->getCode() ] );
            }

            return new WP_Error( 'rest_error', $e->getMessage() );
        }
    }

    private function pay_order( PaymentInterface $processor, OrderDTO $order, array $params = [] ): ?string {
        $quote = Quote::stored( $order );
        if ( empty( $quote['payable'] ) ) {
            return $processor->pay( $order, $params );
        }

        return Quote::with_payment_lock( 'checkout:' . $order->get_id(), static function () use ( $processor, $order, $params ) {
            $current = directorist_order_repository()->get_by_id( $order->get_id() );
            if ( ! $current || ! in_array( $current->status, [ OrderStatus::PENDING, OrderStatus::FAILED ], true ) ) {
                throw new Exception( __( 'This order no longer needs payment.', 'directorist' ) );
            }

            return $processor->pay( $order, $params );
        } );
    }

    protected function get_redirect_url( OrderDTO $dto ): string {
        return apply_filters( 'atbdp_payment_receipt_page_link', \ATBDP_Permalink::get_payment_receipt_page_link( $dto->get_id() ), $dto->get_id() );
    }

    public function retry_payment( WP_REST_Request $request ) {
        $order_id         = $request->get_param( 'order_id' );
        $order_repository = directorist_order_repository();
        $order            = $order_repository->get_by_id( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'rest_not_found', __( 'Order not found.', 'directorist' ) );
        }

        $order = $order_repository->to_dto( $order );

        if ( $order->get_user_id() !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You do not have permission to pay for this order.', 'directorist' ), [ 'status' => 403 ] );
        }

        if ( \Directorist\FeaturedListingCheckout::CHECKOUT_TYPE === $order->get_ref_type()
            && ( ! directorist_user_can_checkout_listing( $order->get_listing_id() ) || ! directorist_user_can_checkout_listing( $order->get_listing_id(), $order->get_user_id() ) )
        ) {
            return new WP_Error( 'rest_forbidden', __( 'You do not have permission to pay for this listing.', 'directorist' ), [ 'status' => 403 ] );
        }

        if ( ! in_array( $order->get_status(), [ OrderStatus::PENDING, OrderStatus::FAILED ], true ) ) {
            return new WP_Error( 'rest_invalid_value', __( 'Order is not pending or failed.', 'directorist' ) );
        }

        $quote = Quote::stored( $order );
        if ( empty( $quote['gateway'] ) ) {
            return rest_ensure_response(
                [ 'redirect_url' => directorist_get_checkout_page_url( 'payment', [ 'order_id' => $order->get_id() ] ) ]
            );
        }

        $gateway = $quote['gateway'];
        $payment_processors = directorist_get_payment_processors();

        if ( ! is_callable( [ $payment_processors[$gateway] ?? '', 'checkout_currency_precision' ] ) ) {
            return new WP_Error( 'rest_invalid_value', __( 'Invalid payment gateway.', 'directorist' ) );
        }

        /**
         * @var PaymentInterface $processor_instance
         */
        $processor_instance = directorist_make( $payment_processors[$gateway], __( 'Invalid payment gateway.', 'directorist' ) );

        if ( $processor_instance instanceof PaymentInterface ) {
            try {
                $redirect_url = $this->pay_order( $processor_instance, $order );
            } catch ( Exception $error ) {
                return new WP_Error( 'checkout_retry_failed', $error->getMessage(), [ 'status' => 409 ] );
            }
        }

        return rest_ensure_response(
            [
                "redirect_url" => $redirect_url ?? $this->get_redirect_url( $order )
            ]
        );
    }
}
