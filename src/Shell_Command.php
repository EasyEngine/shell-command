<?php

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
	 *
	 * [--user=<user>]
	 * : Set the user to exec into shell.
	 *
	 * [--service=<service>]
	 * : Set the service whose shell you want.
	 * ---
	 * default: php
	 * ---
	 *
	 * [--command=<command>]
	 * : Command to non-interactively run in the shell.
	 *
	 * [--skip-tty]
	 * : Skips tty allocation.
	 *
	 *  ## EXAMPLES
	 *
	 *     # Open shell for site
	 *     $ ee shell example.com
	 *
	 *     # Open shell with root user
	 *     $ ee shell example.com --user=root
	 *
	 *     # Open shell for some other service
	 *     $ ee shell example.com --service=nginx
	 *
	 *     # Run command non-interactively
	 *     $ ee shell example.com --service=nginx --command='nginx -t && nginx -s reload'
	 *
	 */
	public function __invoke( $args, $assoc_args ) {

		EE\Utils\delem_log( 'ee shell start' );
		$args      = auto_site_name( $args, 'shell', '' );
		$site_name = EE\Utils\remove_trailing_slash( $args[0] );

		$site = Site::find( $site_name );

		if ( ! $site || ! $site->site_enabled ) {
			EE::error( "Site $site_name does not exist or is not enabled." );
		}

		chdir( $site->site_fs_path );

		$service = \EE\Utils\get_flag_value( $assoc_args, 'service' );
		$this->check_shell_available( $service, $site );

		$user        = \EE\Utils\get_flag_value( $assoc_args, 'user' );
		$user_string = '';
		if ( $user ) {
			$user_string = $this->check_user_available( $user, $service ) ? "--user='$user'" : '';
		}

		$shell   = ( 'mailhog' === $service ) ? 'sh' : 'bash';
		$command = \EE\Utils\get_flag_value( $assoc_args, 'command' );

		$tty = '';
		if ( \EE\Utils\get_flag_value( $assoc_args, 'skip-tty' ) ) {
			$tty = '-T';
		}

		if ( $command ) {
			EE::exec( "docker-compose exec $tty $user_string $service $shell -c \"$command\"", true, true );
		} else {
			$this->run( "docker-compose exec $user_string $service $shell" );
		}
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
	 * @param EE\Model\Site $site     Contains relevant site info.
	 *
	 * @throws \EE\ExitException
	 */
	private function check_shell_available( $shell_container, $site ) {

		$launch   = EE::launch( 'docker-compose config --services' );
		$services = explode( PHP_EOL, trim( $launch->stdout ) );
		if ( in_array( $shell_container, $services, true ) ) {
			return;
		}
		EE::debug( 'Site type: ' . $site->site_type );
		EE::debug( 'Site command: ' . $site->app_sub_type );
		EE::error( sprintf( '%s site does not have support to launch %s shell.', $site->site_url, $shell_container ) );
	}

	/**
	 * Function to check if a user is present or not in the given container.
	 *
	 * @param string $user            User to be checked in the shell.
	 * @param string $shell_container Container in which the user is to be checked.
	 *
	 * @return bool Success.
	 */
	private function check_user_available( $user, $shell_container ) {
		$check_command = sprintf( "docker-compose exec --user='%s' %s bash -c 'exit'", $user, $shell_container );

		if ( EE::exec( $check_command ) ) {
			return true;
		}
		EE::warning( "$user is not available in $shell_container, falling back to default user." );

		return false;
	}
}
