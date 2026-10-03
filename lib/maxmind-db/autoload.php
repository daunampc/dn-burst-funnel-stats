<?php
/**
 * Loads the vendored MaxMind DB Reader (Apache-2.0, see LICENSE in this folder).
 */

if ( ! class_exists( 'MaxMind\\Db\\Reader' ) ) {
	$dn_bfs_mmdb_dir = __DIR__ . '/src/MaxMind/Db/';

	require_once $dn_bfs_mmdb_dir . 'Reader/InvalidDatabaseException.php';
	require_once $dn_bfs_mmdb_dir . 'Reader/Util.php';
	require_once $dn_bfs_mmdb_dir . 'Reader/Decoder.php';
	require_once $dn_bfs_mmdb_dir . 'Reader/Metadata.php';
	require_once $dn_bfs_mmdb_dir . 'Reader.php';
}
