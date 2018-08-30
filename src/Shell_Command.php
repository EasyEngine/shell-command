<?php

use EE\Utils;
use EE\Model\Site;
use function EE\Site\Utils\auto_site_name;

/**
 * Brings up a shell to run wp-cli, composer etc.
 *
 * ## EXAMPLES
 *
 *     # Open shell of example.com
 *     $ ee shell example.com
 *
 * @package ee-cli
 */
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
		$args      = auto_site_name( $args, 'shell', '' );
		$site_name = EE\Utils\remove_trailing_slash( $args[0] );

		$site = Site::find( $site_name );

		if ( ! $site || ! $site->site_enabled ) {
			EE::error( "Site $site_name does not exist or is not enabled." );
		}

		chdir( $site->site_fs_path );
		$this->check_shell_available( 'php', $site );
		$this->run( "docker-compose exec --user='www-data' php bash" );
		EE\Utils\delem_log( 'ee shell end' );
	}

	/**
	 * Run the command to open shell.
	 *
	 * @param string $cmd             Command to be executed to open shell.
	 * @param null|array $descriptors File descriptors for proc.
	 */
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

	/**
	 * Function to check if container supporting shell is present in docker-compose.yml or not.
	 *
	 * @param string $shell_container Container to be checked.
	 * @param Object $site            Contains relevant site info.
	 */
	private function check_shell_available( $shell_container, $site ) {

		$launch   = EE::launch( 'docker-compose config --services' );
		$services = explode( PHP_EOL, trim( $launch->stdout ) );
		if ( ! in_array( $shell_container, $services, true ) ) {
			EE::debug( 'Site type: ' . $site->site_type );
			EE::debug( 'Site command: ' . $site->app_sub_type );
			EE::error( sprintf( '%s site does not have support to launch %s shell.', $shell_container, $site->site_url ) );
		}
	}
}
