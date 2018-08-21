<?php

/**
 * Executes wp-cli command on a site.
 *
 * ## EXAMPLES
 *
 *     # Create simple WordPress site
 *     $ ee wp test.local plugin list
 *
 * @package ee-cli
 */

use EE\Utils;

class Shell_Command extends EE_Command {

	/**
	 * Brings up a shell to run wp-cli, composer etc.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to run shell on.
	 */
	public function __invoke( $args ) {
		EE\Utils\delem_log( 'ee shell start' );
		$args      = EE\SiteUtils\auto_site_name( $args, 'shell', '' );
		$site_name = EE\Utils\remove_trailing_slash( $args[0] );

		$site = Site::find( $site_name );

		if ( ! $site ) {
			EE::error( "Site $site_name does not exist." );
		}

		chdir( $site->site_fs_path );
		$this->run( "docker-compose exec --user='www-data' php bash" );
		EE\Utils\delem_log( 'ee shell end' );
	}

	private function run( $cmd, $descriptors = null ) {
		EE\Utils\check_proc_available( 'ee_shell' );
		if ( ! $descriptors ) {
			$descriptors = array( STDIN, STDOUT, STDERR );
		}

		$final_cmd = EE\Utils\force_env_on_nix_systems( $cmd );
		$proc      = EE\Utils\proc_open_compat( $final_cmd, $descriptors, $pipes );
		if ( ! $proc ) {
			exit( 1 );
		}
		$r = proc_close( $proc );
		if ( $r ) {
			exit( $r );
		}
	}

}
