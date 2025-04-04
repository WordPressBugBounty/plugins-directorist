<?php
/**
 * Users Account Rest Controller.
 *
 * @package Directorist\Rest_Api
 * @version  1.0.0
 */

namespace Directorist\Rest_Api\Controllers\Version1;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Server;

/**
 * Users account controller class.
 */
class Users_Account_Controller extends Abstract_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'users/account';

	/**
	 * Register the routes for terms.
	 */
	public function register_routes() {
		// Send Password Reset PIN
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/send-password-reset-pin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'send_password_reset_pin' ),
			'permission_callback' => array( $this, 'check_send_password_permission' ),
			'args'                => array(
				'email' => array(
					'required'    => true,
					'type'        => 'string',
					'format'      => 'email',
					'description' => __( 'User email address.', 'directorist' ),
				),
			)
		) );

		// Verify Password Reset PIN
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/verify-password-reset-pin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'verify_password_reset_pin' ),
			'permission_callback' => array( $this, 'check_verify_reset_pin_permission' ),
			'args'                => array(
				'email' => array(
					'required'    => true,
					'type'        => 'string',
					'format'      => 'email',
					'description' => __( 'User email address.', 'directorist' ),
				),
				'pin' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => __( 'Password rest pin.', 'directorist' ),
				),
			)
		) );

		// Rest user password.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/reset-user-password', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'reset_user_password' ),
			'permission_callback' => array( $this, 'check_reset_password_permission' ),
			'args'                => array(
				'email' => array(
					'required'    => true,
					'type'        => 'string',
					'format'      => 'email',
					'description' => __( 'User email address.', 'directorist' ),
				),
				'password' => array(
					'required'    => true,
					'type'        => 'string',
					'minLength'   => 6,
					'description' => __( 'User new password.', 'directorist' ),
				),
				'pin' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => __( 'Password rest pin.', 'directorist' ),
				),
			)
		) );

		// Change password.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/change-password', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'change_password' ),
			'permission_callback' => array( $this, 'check_change_password_permission' ),
			'args'                => array(
				'user_id' => array(
					'required'    => true,
					'type'        => 'integer',
					'description' => __( 'User id.', 'directorist' ),
				),
				'old_password' => array(
					'required'    => true,
					'type'        => 'string',
					'description' => __( 'User old password.', 'directorist' ),
				),
				'new_password' => array(
					'required'    => true,
					'type'        => 'string',
					'minLength'   => 6,
					'description' => __( 'User new password.', 'directorist' ),
				),
			)
		) );
	}

	protected function get_user_by_email( $email ) {
		$user = get_user_by( 'email', $email );

		if ( empty( $user ) ) {
			return new WP_Error( 'directorist_rest_user_invalid', __( 'Resource does not exist.', 'directorist' ), array( 'status' => 404 ) );
		}

		return $user;
	}

	public function check_send_password_permission( $request ) {
		$user = $this->get_user_by_email( $request['email'] );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return true;
	}

	public function check_verify_reset_pin_permission( $request ) {
		$user = $this->get_user_by_email( $request['email'] );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return true;
	}

	public function check_reset_password_permission( $request ) {
		$user = $this->get_user_by_email( $request['email'] );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return true;
	}

	public function check_change_password_permission( $request ) {
		$user = get_userdata( $request['user_id'] );

		if ( empty( $user ) ) {
			return new WP_Error( 'directorist_rest_user_invalid', __( 'Resource does not exist.', 'directorist' ), array( 'status' => 404 ) );
		}

		return current_user_can( 'edit_user', $user->ID );
	}

	public function send_password_reset_pin( $request ) {
		$user = $this->get_user_by_email( $request['email'] );

		ATBDP()->email->send_password_reset_pin_email( $user );

		$data = [
			'success' => true,
			'message' => __( 'Password reset code has been sent to your email.', 'directorist' ),
			'email'   => $request['email'],
		];

		$response = rest_ensure_response( $data );

		return $response;
	}

	public function verify_password_reset_pin( $request ) {
		$is_valid = $this->validate_reset_pin_code( $request );

		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		$data = [
			'success' => true,
			'message' => __( 'Password reset pin has been verified.', 'directorist' ),
		];

		$response = rest_ensure_response( $data );

		return $response;
	}

	public function reset_user_password( $request ) {
		$is_valid = $this->validate_reset_pin_code( $request );

		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		$user = $this->get_user_by_email( $request['email'] );

		wp_set_password( $request['password'], $user->ID );

		directorist_delete_password_reset_code_transient( $user );
		delete_user_meta( $user->ID, 'directorist_pasword_reset_key' );

		$data = [
			'success' => true,
			'message' => __( 'Password has been reset successfully.', 'directorist' ),
			'user_id' => $user->ID,
		];

		$response = rest_ensure_response( $data );

		return $response;
	}

	public function change_password( $request ) {
		$user = get_userdata( $request['user_id'] );

		if ( ! wp_check_password( $request['old_password'], $user->data->user_pass, $user->ID ) ) {
			return new WP_Error( 'directorist_rest_password_invalid', __( 'Invalid old password.', 'directorist' ), array( 'status' => 400 ) );
		}

		// Change Password
		wp_set_password( $request['new_password'], $user->ID );

		$data = [
			'success' => true,
			'message' => __( 'Password has been changed successfully.', 'directorist' ),
		];

		$response = rest_ensure_response( $data );

		return $response;
	}

	protected function validate_reset_pin_code( $request ) {
		if ( strlen( $request['pin'] ) < 4 ) {
			return new WP_Error( 'directorist_rest_password_reset_pin_invalid', __( 'Pin code must be 4 letters long.', 'directorist' ), array( 'status' => 400 ) );
		}

		$user   = $this->get_user_by_email( $request['email'] );
		return directorist_check_password_reset_pin_code( $user, $request['pin'] );
	}
}