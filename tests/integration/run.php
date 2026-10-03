<?php
/**
 * Run: docker compose -f docker/docker-compose.yml run --rm wpcli wp eval-file wp-content/plugins/dn-burst-funnel-stats/tests/integration/run.php
 */

require __DIR__ . '/harness.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $dn_bfs_test_file ) {
	require $dn_bfs_test_file;
}

exit( dn_bfs_it_report() );
