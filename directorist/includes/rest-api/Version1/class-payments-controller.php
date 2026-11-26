<?php

namespace Directorist\Rest_Api\Controllers\Version1;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

use Directorist\DTO\Payment\DTO as PaymentDTO;
use Directorist\Enums\Payment\Status as PaymentStatus;
use Directorist\Repositories\PaymentRepository;

class Payments_Controller extends Abstract_Controller {
    protected $rest_base = 'admin/payments';

    public function register_routes() {
        register_rest_route(
            $this->namespace,
            "{$this->rest_base}",
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'index' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'order_id'     => [
                            'description'       => __( 'The order ID.', 'directorist' ),
                            'type'              => 'integer',
                            'required'          => true,
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'store' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => $this->store_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            "{$this->rest_base}/(?P<id>[\d]+)",
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'show' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'id'     => [
                            'description'       => __( 'The order ID.', 'directorist' ),
                            'type'              => 'integer',
                            'required'          => true,
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'update' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'id' => [ 'type' => 'integer', 'required' => true ],
                        'status' => [ 'type' => 'string', 'enum' => [ 'paid' ], 'required' => true ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'id'     => [
                            'description'       => __( 'The order ID.', 'directorist' ),
                            'type'              => 'integer',
                            'required'          => true,
                        ],
                    ],
                ],
            ]
        );
    }

    public function index( WP_REST_Request $request ) {
        $repository = new PaymentRepository();

        return rest_ensure_response( 
            [
                "payments" => $repository->get( (int) $request->get_param( "order_id" ) )
            ]
         );
    }

    public function store( WP_REST_Request $request ) {
        try {
            $payment = \Directorist\Order\ManualPaymentService::create( (int) $request['order_id'], $request->get_params() );
            return new \WP_REST_Response( [ 'message' => __( 'Payment was created successfully', 'directorist' ), 'data' => [ 'id' => $payment->id ] ], 201 );
        } catch ( \Throwable $error ) {
            return $this->payment_error( $error );
        }
    }

    public function show( WP_REST_Request $request ) {
        $repository = new PaymentRepository();
        $payment    = $repository->get_by_id( $request->get_param( "id" ) );

        if ( ! $payment ) {
            return new WP_Error( 'rest_not_found', __( 'The payment was not found', 'directorist' ) );
        }

        return rest_ensure_response(
            [
                "data" => $payment
            ]
        );
    }

    public function update( WP_REST_Request $request ) {
        try {
            foreach ( [ 'amount', 'currency', 'transaction_id', 'method' ] as $immutable ) {
                if ( $request->has_param( $immutable ) ) {
                    throw new \InvalidArgumentException( __( 'Existing transaction details cannot be edited.', 'directorist' ), 400 );
                }
            }
            $payment = ( new PaymentRepository() )->get_by_id( (int) $request['id'] );
            if ( ! $payment || ( $request->has_param( 'order_id' ) && (int) $request['order_id'] !== (int) $payment->order_id ) ) {
                throw new \InvalidArgumentException( __( 'Payment not found.', 'directorist' ), 404 );
            }
            \Directorist\Order\ManualPaymentService::mark_paid( (int) $payment->order_id, (int) $payment->id );
            return rest_ensure_response( [ 'message' => __( 'Payment was updated successfully', 'directorist' ) ] );
        } catch ( \Throwable $error ) {
            return $this->payment_error( $error );
        }
    }

    public function delete( WP_REST_Request $request ) {
        try {
            $payment = ( new PaymentRepository() )->get_by_id( (int) $request['id'] );
            if ( ! $payment || ( $request->has_param( 'order_id' ) && (int) $request['order_id'] !== (int) $payment->order_id ) ) {
                throw new \InvalidArgumentException( __( 'Payment not found.', 'directorist' ), 404 );
            }
            \Directorist\Order\ManualPaymentService::delete( (int) $payment->order_id, (int) $payment->id );
            return rest_ensure_response( [ 'message' => __( 'Payment was deleted successfully', 'directorist' ), 'deleted' => true ] );
        } catch ( \Throwable $error ) {
            return $this->payment_error( $error );
        }
    }

    private function payment_error( \Throwable $error ) {
        $status = in_array( $error->getCode(), [ 400, 403, 404, 405, 409, 503 ], true ) ? $error->getCode() : 500;
        return new WP_Error( 'rest_invalid_payment', $error->getMessage(), [ 'status' => $status ] );
    }

    protected function store_args(): array {
        return [
            'amount' => [ 'default' => 0 ],
            'order_id' => [
                'description'       => __( 'The order ID.', 'directorist' ),
                'type'              => 'integer',
                'required'          => true,
            ],
            'status' => [
                'description'       => __( 'The status of the payment.', 'directorist' ),
                'type'              => 'string',
                'required'          => true,
                'enum'              => PaymentStatus::all(),
            ],
            'transaction_id' => [
                'description'       => __( 'The transaction ID.', 'directorist' ),
                'type'              => 'string',
                'required'          => true,
                'maxlength'         => 100,
            ],
            'method' => [
                'description'       => __( 'The method of the payment.', 'directorist' ),
                'type'              => 'string',
                'required'          => true,
                'maxlength'         => 30,
            ],
        ];
    }
}
