<?php
/**
 * Read the current user's Directorist extension account state.
 */

namespace Directorist;

defined( 'ABSPATH' ) || exit;

final class Extension_Account {
    public static function has_session() {
        $user_id = get_current_user_id();

        if ( ! $user_id || 'access_key' === get_user_meta( $user_id, '_atbdp_subscription_connection_method', true ) ) {
            return false;
        }

        return ! empty( get_user_meta( $user_id, '_atbdp_has_subscriptions_sassion', true ) );
    }

    public static function get_purchased_extensions() {
        if ( ! self::has_session() ) {
            return [];
        }

        $purchases = get_user_meta( get_current_user_id(), '_plugins_available_in_subscriptions', true );
        $filtered  = apply_filters( 'directorist_purchased_extension_list', $purchases );

        return is_array( $filtered ) ? $filtered : $purchases;
    }
}
