<?php
namespace Directorist\Rest_Api\Controllers\Version2;

defined( 'ABSPATH' ) || exit;

use Directorist\Order\ManualPaymentService;
use Directorist\Repositories\OrderActivityRepository;

class Order_Transactions_Controller extends \Directorist\Rest_Api\Controllers\Version1\Abstract_Controller {
    protected $namespace = 'directorist/v2';

    public function register_routes() {
        $base = '/admin/orders/(?P<id>[\d]+)';
        foreach ( [
            '/transactions/new'                             => [ 'GET', 'defaults' ],
            '/transactions'                                 => [ 'POST', 'create' ],
            '/transactions/(?P<payment_id>[\d]+)/mark-paid' => [ 'POST', 'mark_paid' ],
            '/transactions/(?P<payment_id>[\d]+)'           => [ 'DELETE', 'delete' ],
            '/activities'                                   => [ 'GET', 'activities' ],
        ] as $path => $handler ) {
            register_rest_route(
                $this->namespace, $base . $path, [
                    'methods'             => $handler[0],
                    'callback'            => [ $this, $handler[1] ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'id'       => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
                    ],
                ]
            );
        }
    }

    private function respond( callable $callback ) {
        try {
            return rest_ensure_response( $callback() );
        } catch ( \Throwable $error ) {
            $status = in_array( $error->getCode(), [ 400, 403, 404, 405, 409, 503 ], true ) ? $error->getCode() : 500;
            return new \WP_Error( 'directorist_transaction_error', $error->getMessage(), [ 'status' => $status ] );
        }
    }

    public function defaults( \WP_REST_Request $request ) {
        return $this->respond(
            static function () use ( $request ) {
                return ManualPaymentService::defaults( (int) $request['id'] );
            }
        );
    }

    public function create( \WP_REST_Request $request ) {
        return $this->respond(
            static function () use ( $request ) {
                $payment = ManualPaymentService::create( (int) $request['id'], $request->get_params() );
                return new \WP_REST_Response( [ 'payment' => $payment ], 201 );
            }
        );
    }

    public function mark_paid( \WP_REST_Request $request ) {
        return $this->respond(
            static function () use ( $request ) {
                return [ 'payment' => ManualPaymentService::mark_paid( (int) $request['id'], (int) $request['payment_id'] ) ];
            }
        );
    }

    public function delete( \WP_REST_Request $request ) {
        return $this->respond(
            static function () use ( $request ) {
                ManualPaymentService::delete( (int) $request['id'], (int) $request['payment_id'] );
                return [ 'deleted' => true ];
            }
        );
    }

    public function activities( \WP_REST_Request $request ) {
        return $this->respond(
            static function () use ( $request ) {
                if ( ! directorist_order_repository()->get_by_id( (int) $request['id'] ) ) {
                    return new \WP_Error( 'rest_not_found', __( 'Order not found.', 'directorist' ), [ 'status' => 404 ] );
                }
                $result              = ( new OrderActivityRepository() )->get( (int) $request['id'], (int) $request['page'], (int) $request['per_page'] );
                $state               = \Directorist\Order\MigrationState::get( \Directorist\Order\ActivityMigration::STATE );
                $data_state          = \Directorist\Order\MigrationState::get( \Directorist\Order\ActivityDataMigration::STATE );
                $result['migration'] = [
                    'pending'           => \Directorist\Order\ActivityDataMigration::pending() || ( $state['cursor'] ?? 0 ) < ( $state['high_water'] ?? 0 ),
                    'orphaned_payments' => (int) ( $state['orphans'] ?? 0 ),
                    'error'             => get_option( \Directorist\Order\ActivityMigration::ERROR, '' ) ?: ( $data_state['error'] ?? '' ) ?: ( $state['error'] ?? '' ),
                ];
                return rest_ensure_response( $result );
            }
        );
    }
}
