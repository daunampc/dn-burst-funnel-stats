<?php
/**
 * Minimal GitHub release updater.
 *
 * Publish a GitHub release with a tag like v1.0.1. Attach the plugin ZIP to the release,
 * or the updater will use GitHub's generated source ZIP as a fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DN_Burst_Funnel_Stats_GitHub_Updater {
	private string $plugin_basename;
	private string $current_version;
	private string $github_repo;
	private string $plugin_name;
	private string $slug;
	private string $cache_key;

	public function __construct( string $plugin_basename, string $current_version, string $github_repo, string $plugin_name ) {
		$this->plugin_basename = $plugin_basename;
		$this->current_version = $current_version;
		$this->github_repo     = trim( $github_repo, '/' );
		$this->plugin_name     = $plugin_name;
		$this->slug            = dirname( $plugin_basename );
		$this->cache_key       = 'dn_burst_funnel_stats_github_release_' . md5( $this->github_repo );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'fix_install_folder' ), 10, 3 );
	}

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) || empty( $this->github_repo ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->current_version, '<=' ) ) {
			return $transient;
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'id'          => $this->plugin_basename,
			'slug'        => $this->slug,
			'plugin'      => $this->plugin_basename,
			'new_version' => $release['version'],
			'url'         => $release['html_url'],
			'package'     => $release['package'],
			'tested'      => $release['tested'] ?? '',
		);

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( empty( $release ) ) {
			return $result;
		}

		return (object) array(
			'name'          => $this->plugin_name,
			'slug'          => $this->slug,
			'version'       => $release['version'] ?? $this->current_version,
			'author'        => '<a href="https://toshstack.dev">toshstack.dev</a>',
			'homepage'      => $release['html_url'] ?? 'https://github.com/' . $this->github_repo,
			'download_link' => $release['package'] ?? '',
			'sections'      => array(
				'description' => 'Funnel dashboard for WooCommerce using Burst Statistics page visit data.',
				'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( wpautop( $release['body'] ) ) : 'See GitHub releases for changelog.',
			),
		);
	}

	public function fix_install_folder( $response, $hook_extra, $result ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $response;
		}

		global $wp_filesystem;

		$proper_destination = WP_PLUGIN_DIR . '/' . $this->slug;
		if ( isset( $result['destination'] ) && $result['destination'] !== $proper_destination ) {
			$wp_filesystem->move( $result['destination'], $proper_destination, true );
			$result['destination'] = $proper_destination;
		}

		return $result;
	}

	private function get_latest_release(): array {
		$cached = get_site_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = sprintf( 'https://api.github.com/repos/%s/releases/latest', rawurlencode( $this->github_repo ) );
		$url = str_replace( '%2F', '/', $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'DN-Burst-Funnel-Stats-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( $this->cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_site_transient( $this->cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$package = $this->get_release_asset_zip( $data );
		if ( empty( $package ) && ! empty( $data['zipball_url'] ) ) {
			$package = $data['zipball_url'];
		}

		$release = array(
			'version'  => ltrim( (string) $data['tag_name'], 'vV' ),
			'package'  => $package,
			'html_url' => $data['html_url'] ?? 'https://github.com/' . $this->github_repo,
			'body'     => $data['body'] ?? '',
		);

		set_site_transient( $this->cache_key, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	private function get_release_asset_zip( array $data ): string {
		if ( empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return '';
		}

		foreach ( $data['assets'] as $asset ) {
			if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
				continue;
			}

			if ( '.zip' === substr( strtolower( $asset['name'] ), -4 ) ) {
				return (string) $asset['browser_download_url'];
			}
		}

		return '';
	}
}
