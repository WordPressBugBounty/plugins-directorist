<?php
namespace Directorist\DBModels;

defined( 'ABSPATH' ) || exit;

class OrderActivity extends \Directorist\Utils\Database\Eloquent\Model {
    public static function get_table_name(): string {
        return 'directorist_order_activities';
    }

    public function order(): \Directorist\Utils\Database\Eloquent\Relations\BelongsToOne {
        return $this->belongs_to_one( Order::class, 'id', 'order_id' );
    }

    public function resolver(): \Directorist\Utils\Database\Resolver {
        return new \Directorist\Utils\Database\Resolver();
    }
}
