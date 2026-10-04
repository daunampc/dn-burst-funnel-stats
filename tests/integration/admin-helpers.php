<?php
/**
 * Helpers for admin-side integration tests.
 */

function dn_bfs_it_login_admin() {
	$admin_id = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	wp_set_current_user( $admin_id );

	return $admin_id;
}
