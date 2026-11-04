<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
// Core's init-ee.php may already define it (a redefinition warns and is an error in PHP 9).
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

class FeatureContext implements Context
{
	/**
	 * Whether this run built ee.phar/ee-old.phar, so the cleanup only removes its own files.
	 *
	 * @var bool
	 */
	private static $phar_built = false;

	public $command;
	public $commands = [];
	public $webroot_path;
	public $ee_path;

	/**
	 * Every site this suite creates starts with this prefix; cleanup only ever touches these.
	 */
	const SITE_PREFIX = 'bh-shell-command-';

	/**
	 * Working directory for `I run` (set by `I am in the directory`), null means the core root.
	 *
	 * @var string|null
	 */
	public $cwd = null;

	/**
	 * Containers stopped by a step, restarted after the scenario even when it fails.
	 *
	 * @var array
	 */
	public $stopped_containers = [];

	/**
	 * Containers created by a step, removed after the scenario even when it fails.
	 *
	 * @var array
	 */
	public $created_containers = [];

	/**
	 * Snapshots of files a scenario changes, as [ path => null (absent) | [ content, mode ] ].
	 *
	 * @var array
	 */
	public $file_snapshots = [];

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			self::$phar_built = true;
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
		self::$phar_built = true;
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * Anchored (upstream's unanchored `/I run '(.*)'|"(.*)"/` also matched every step containing a double-quoted string).
	 *
	 * @When /^I run (?:'|")(.*)(?:'|")$/
	 */
	public function iRun($command)
	{
		if ( null !== $this->cwd ) {
			// `cd` in the same shell keeps $PWD logical (like a user's terminal), so symlinked site dirs resolve by name.
			$ee      = realpath( EE_ROOT ) . '/bin/ee';
			$command = 'cd ' . escapeshellarg( $this->cwd ) . ' && ' . preg_replace( '#(^|[\s;&|(\'"])bin/ee\b#', '${1}' . $ee, $command );
		}
		$this->commands[] = EE::launch($command, false, true);
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /(STDOUT|STDERR) should return exactly/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) of command :index should return exactly/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) should return something like/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /(STDOUT|STDERR) of command :index should return something like/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		$out = shell_exec("sudo bin/ee site list");
		if (strpos($out, $site) !== false) {
			throw new Exception("$site db entry not been removed!");
		}

	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		$containers = $table->getHash();
		$site_name = implode(explode('.', $site));

		foreach ($containers as $container) {

			$sevice = $container['container'];
			$container_name = $site_name . '_' . $sevice . '_1';

			exec("docker inspect -f '{{.State.Running}}' $container_name > /dev/null 2>&1", $exec_out, $return);
			if (!$return) {
				throw new Exception("$container_name has not been removed!");
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		$errors = [];
		// Containers go first: compose may still need a file that is restored below.
		foreach ( array_unique( $this->created_containers ) as $container ) {
			$out = [];
			exec( 'docker rm -f ' . escapeshellarg( $container ) . ' 2>&1', $out, $ret );
			if ( 0 !== $ret ) {
				$errors[] = "Could not remove container $container: " . implode( "\n", $out );
			}
		}
		foreach ( $this->file_snapshots as $path => $snapshot ) {
			clearstatcache();
			if ( null === $snapshot ) {
				if ( file_exists( $path ) && ! unlink( $path ) ) {
					$errors[] = "Could not remove $path.";
				}
			} elseif ( false === file_put_contents( $path, $snapshot[0] ) || ! chmod( $path, $snapshot[1] ) ) {
				$errors[] = "Could not restore $path.";
			}
		}
		foreach ( array_unique( $this->stopped_containers ) as $container ) {
			$out = [];
			exec( 'docker start ' . escapeshellarg( $container ) . ' 2>&1', $out, $ret );
			if ( 0 !== $ret ) {
				$errors[] = "Could not restart container $container: " . implode( "\n", $out );
			}
		}
		$this->created_containers = [];
		$this->file_snapshots     = [];
		$this->stopped_containers = [];
		$this->commands           = [];
		$this->cwd                = null;
		chdir($this->ee_path);

		if ( $errors ) {
			throw new Exception( "Scenario cleanup failed, restore by hand:\n" . implode( "\n", $errors ) );
		}
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$labels = $pyStringNode->getStrings();
		$label_string = implode(' -f label=',$labels);

		$result = EE::launch( "docker ps -qf label=$label_string | wc -l", false, true );
		$running_containers = (int) trim($result->stdout);

		if ( (int) $expected_running_containers !== $running_containers ) {
			throw new Exception("Expected $expected_running_containers running containers. Found: $running_containers");
		}
	}

	/**
	 * Deletes only this suite's sites (upstream deleted a fixed list; never "all sites").
	 *
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		$failed = [];
		foreach ( self::listSites() as $site => $status ) {
			if ( 0 === strpos( $site, self::SITE_PREFIX ) ) {
				exec( 'sudo bin/ee site delete ' . escapeshellarg( $site ) . ' --yes 2>&1', $out, $ret );
				if ( 0 !== $ret ) {
					$failed[] = $site;
				}
			}
		}

		if ( self::$phar_built ) {
			foreach ( [ 'ee.phar', 'ee-old.phar' ] as $phar ) {
				if ( file_exists( $phar ) ) {
					unlink( $phar );
				}
			}
			self::$phar_built = false;
		}
		if ( $failed ) {
			throw new Exception( 'Could not delete: ' . implode( ', ', $failed ) );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shared generic steps (added by the shell-command suite).
	 * Step arguments use single quotes; assertions work on the LAST command run.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Returns the sites known to EE as [ site => status ].
	 *
	 * @return array
	 */
	public static function listSites()
	{
		$result = EE::launch( 'sudo bin/ee site list --format=json', false, true );
		if ( 0 !== $result->return_code && false !== strpos( $result->stderr, 'No sites found!' ) ) {
			return [];
		}
		$sites = json_decode( trim( $result->stdout ), true );
		// A broken list must not look like "no sites" (vacuous "should not exist", silent cleanup).
		if ( 0 !== $result->return_code || ! is_array( $sites ) ) {
			throw new Exception( "Could not list sites.\n" . self::describeRun( $result ) );
		}

		$list = [];
		foreach ( $sites as $row ) {
			if ( isset( $row['site'] ) ) {
				$list[ $row['site'] ] = isset( $row['status'] ) ? $row['status'] : '';
			}
		}

		return $list;
	}

	/**
	 * @return \EE\ProcessRun
	 */
	private function lastCommand()
	{
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	/**
	 * Strips ANSI escapes and carriage returns (pty output) so comparisons are stable.
	 */
	private static function normalizeOutput( $output )
	{
		$output = preg_replace( '/\e\[[0-9;?]*[A-Za-z]/', '', $output );

		return str_replace( [ "\r\n", "\r" ], [ "\n", '' ], $output );
	}

	private static function describeRun( $run )
	{
		return sprintf(
			"Command: %s\nReturn code: %s\n--- STDOUT ---\n%s\n--- STDERR ---\n%s",
			$run->command,
			$run->return_code,
			$run->stdout,
			$run->stderr
		);
	}

	private function streamOf( $stream )
	{
		$run = $this->lastCommand();

		return self::normalizeOutput( 'STDOUT' === $stream ? $run->stdout : $run->stderr );
	}

	/**
	 * Runs a command and fails the step if it exits non-zero.
	 */
	private function mustRun( $command )
	{
		$run = EE::launch( $command, false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Setup command failed.\n" . self::describeRun( $run ) );
		}

		return $run;
	}

	/**
	 * @Given /^the (html|php|wp) site '([^']+)' exists$/
	 */
	public function theSiteExists( $type, $site )
	{
		$this->theSiteExistsWithFlags( $type, $site, '' );
	}

	/**
	 * Creates the site once per feature (idempotent) and makes sure it is enabled.
	 *
	 * @Given /^the (html|php|wp) site '([^']+)' exists with flags '([^']*)'$/
	 */
	public function theSiteExistsWithFlags( $type, $site, $flags )
	{
		$sites = self::listSites();
		if ( ! isset( $sites[ $site ] ) ) {
			$this->mustRun( sprintf( 'sudo bin/ee site create %s --type=%s %s', escapeshellarg( $site ), $type, $flags ) );
		} elseif ( 'enabled' !== $sites[ $site ] ) {
			$this->mustRun( 'sudo bin/ee site enable ' . escapeshellarg( $site ) );
		}
	}

	/**
	 * @Given the site :site is disabled
	 */
	public function theSiteIsDisabled( $site )
	{
		$sites = self::listSites();
		if ( ! isset( $sites[ $site ] ) ) {
			throw new Exception( "Site $site does not exist." );
		}
		if ( 'disabled' !== $sites[ $site ] ) {
			$this->mustRun( 'sudo bin/ee site disable ' . escapeshellarg( $site ) );
		}
	}

	/**
	 * @Given the site :site is enabled
	 */
	public function theSiteIsEnabled( $site )
	{
		$sites = self::listSites();
		if ( ! isset( $sites[ $site ] ) ) {
			throw new Exception( "Site $site does not exist." );
		}
		if ( 'enabled' !== $sites[ $site ] ) {
			$this->mustRun( 'sudo bin/ee site enable ' . escapeshellarg( $site ) );
		}
	}

	/**
	 * @Then the site :site should exist
	 */
	public function theSiteShouldExist( $site )
	{
		if ( ! array_key_exists( $site, self::listSites() ) ) {
			throw new Exception( "Site $site is not in `ee site list`." );
		}
	}

	/**
	 * @Then the site :site should not exist
	 */
	public function theSiteShouldNotExist( $site )
	{
		if ( array_key_exists( $site, self::listSites() ) ) {
			throw new Exception( "Site $site is in `ee site list`." );
		}
	}

	/**
	 * @Then the site :site should be :status
	 */
	public function theSiteShouldBe( $site, $status )
	{
		$sites = self::listSites();
		if ( ! isset( $sites[ $site ] ) || $status !== $sites[ $site ] ) {
			throw new Exception( "Expected site $site to be $status, `ee site list` has: " . var_export( $sites, true ) );
		}
	}

	/**
	 * Following `I run` steps execute from this directory (`bin/ee` is made absolute).
	 *
	 * @Given I am in the directory :dir
	 */
	public function iAmInTheDirectory( $dir )
	{
		if ( ! is_dir( $dir ) ) {
			throw new Exception( "Directory $dir does not exist." );
		}
		$this->cwd = $dir;
	}

	/**
	 * @Then the return code should be :code
	 */
	public function theReturnCodeShouldBe( $code )
	{
		$run = $this->lastCommand();
		if ( (int) $code !== $run->return_code ) {
			throw new Exception( "Expected return code $code.\n" . self::describeRun( $run ) );
		}
	}

	/**
	 * @Then the return code should not be :code
	 */
	public function theReturnCodeShouldNotBe( $code )
	{
		$run = $this->lastCommand();
		if ( (int) $code === $run->return_code ) {
			throw new Exception( "Expected a return code other than $code.\n" . self::describeRun( $run ) );
		}
	}

	/**
	 * Exact match after trimming surrounding whitespace.
	 *
	 * @Then /^(STDOUT|STDERR) should be:$/
	 */
	public function streamShouldBe( $stream, PyStringNode $expected )
	{
		if ( trim( $expected->getRaw() ) !== trim( $this->streamOf( $stream ) ) ) {
			throw new Exception( "Expected $stream to be:\n" . $expected->getRaw() . "\n" . self::describeRun( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 */
	public function streamShouldBeEmpty( $stream )
	{
		if ( '' !== trim( $this->streamOf( $stream ) ) ) {
			throw new Exception( "Expected $stream to be empty.\n" . self::describeRun( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should contain:$/
	 */
	public function streamShouldContain( $stream, PyStringNode $expected )
	{
		if ( false === strpos( $this->streamOf( $stream ), $expected->getRaw() ) ) {
			throw new Exception( "Expected $stream to contain:\n" . $expected->getRaw() . "\n" . self::describeRun( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should not contain:$/
	 */
	public function streamShouldNotContain( $stream, PyStringNode $expected )
	{
		if ( false !== strpos( $this->streamOf( $stream ), $expected->getRaw() ) ) {
			throw new Exception( "Expected $stream not to contain:\n" . $expected->getRaw() . "\n" . self::describeRun( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should match '(.+)'$/
	 */
	public function streamShouldMatch( $stream, $regex )
	{
		if ( ! preg_match( $regex, $this->streamOf( $stream ) ) ) {
			throw new Exception( "Expected $stream to match $regex.\n" . self::describeRun( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then the outputs of the last two commands should be identical
	 */
	public function theOutputsOfTheLastTwoCommandsShouldBeIdentical()
	{
		if ( count( $this->commands ) < 2 ) {
			throw new Exception( 'Fewer than two commands have been run.' );
		}
		list( $a, $b ) = array_slice( $this->commands, -2 );
		if ( $a->return_code !== $b->return_code || $a->stdout !== $b->stdout ) {
			throw new Exception( "Outputs differ.\n" . self::describeRun( $a ) . "\n\n" . self::describeRun( $b ) );
		}
	}

	/**
	 * Snapshots the file (or its absence) now; @AfterScenario puts it back, even when a step fails.
	 *
	 * @Given the file :path is restored after the scenario
	 */
	public function theFileIsRestoredAfterTheScenario( $path )
	{
		if ( array_key_exists( $path, $this->file_snapshots ) ) {
			return;
		}
		clearstatcache();
		if ( ! file_exists( $path ) ) {
			$this->file_snapshots[ $path ] = null;
			return;
		}
		$content = file_get_contents( $path );
		if ( false === $content ) {
			throw new Exception( "Could not read $path." );
		}
		$this->file_snapshots[ $path ] = [ $content, fileperms( $path ) & 07777 ];
	}

	/**
	 * @Given the file :path is removed
	 */
	public function theFileIsRemoved( $path )
	{
		if ( file_exists( $path ) && ! unlink( $path ) ) {
			throw new Exception( "Could not remove $path." );
		}
	}

	/**
	 * @Given the file :path contains:
	 */
	public function theFileContains( $path, PyStringNode $content )
	{
		if ( ! is_dir( dirname( $path ) ) ) {
			throw new Exception( 'Directory ' . dirname( $path ) . ' does not exist.' );
		}
		if ( false === file_put_contents( $path, $content->getRaw() . "\n" ) ) {
			throw new Exception( "Could not write $path." );
		}
	}

	/**
	 * @Given the line :line is appended to the file :path
	 */
	public function theLineIsAppendedToTheFile( $line, $path )
	{
		if ( ! file_exists( $path ) ) {
			throw new Exception( "$path does not exist." );
		}
		file_put_contents( $path, "\n" . $line . "\n", FILE_APPEND );
	}

	/**
	 * @Then the file :path should exist
	 */
	public function theFileShouldExist( $path )
	{
		clearstatcache();
		if ( ! file_exists( $path ) ) {
			throw new Exception( "$path does not exist." );
		}
	}

	/**
	 * @Then the file :path should not exist
	 */
	public function theFileShouldNotExist( $path )
	{
		clearstatcache();
		if ( file_exists( $path ) ) {
			throw new Exception( "$path exists." );
		}
	}

	/**
	 * @Then the file :path should contain:
	 */
	public function theFileShouldContain( $path, PyStringNode $expected )
	{
		$this->theFileShouldExist( $path );
		$content = file_get_contents( $path );
		if ( false === strpos( $content, $expected->getRaw() ) ) {
			throw new Exception( "Expected $path to contain:\n" . $expected->getRaw() . "\nActual content:\n" . $content );
		}
	}

	/**
	 * @Then the file :path should not contain:
	 */
	public function theFileShouldNotContain( $path, PyStringNode $expected )
	{
		$this->theFileShouldExist( $path );
		$content = file_get_contents( $path );
		if ( false !== strpos( $content, $expected->getRaw() ) ) {
			throw new Exception( "Expected $path not to contain:\n" . $expected->getRaw() . "\nActual content:\n" . $content );
		}
	}

	/**
	 * @Then the file :path should have permissions :mode
	 */
	public function theFileShouldHavePermissions( $path, $mode )
	{
		$this->theFileShouldExist( $path );
		$actual = substr( sprintf( '%o', fileperms( $path ) ), -4 );
		if ( ltrim( $actual, '0' ) !== ltrim( $mode, '0' ) ) {
			throw new Exception( "Expected $path to have mode $mode, it has $actual." );
		}
	}

	private static function containerRunning( $name )
	{
		$run = EE::launch( "docker inspect -f '{{.State.Running}}' " . escapeshellarg( $name ), false, true );

		return 0 === $run->return_code && 'true' === trim( $run->stdout );
	}

	private function stopContainer( $name )
	{
		// Registered first, so a stop that fails halfway is still undone.
		$this->stopped_containers[] = $name;
		$this->mustRun( 'docker stop ' . escapeshellarg( $name ) );
	}

	/**
	 * Container name of a compose service of a site (independent of the compose naming scheme).
	 */
	private function siteServiceContainer( $site, $service )
	{
		$run = $this->mustRun( sprintf( 'cd %s && docker-compose ps -aq %s', escapeshellarg( EE_SITE_ROOT . '/' . $site ), escapeshellarg( $service ) ) );
		$id  = trim( $run->stdout );
		if ( '' === $id ) {
			throw new Exception( "Site $site has no container for service $service.\n" . self::describeRun( $run ) );
		}

		return $id;
	}

	/**
	 * Stopped containers are restarted after the scenario.
	 *
	 * @Given the docker container :name is stopped
	 */
	public function theDockerContainerIsStopped( $name )
	{
		$this->stopContainer( $name );
	}

	/**
	 * @Given the docker container :name is started
	 */
	public function theDockerContainerIsStarted( $name )
	{
		$this->mustRun( 'docker start ' . escapeshellarg( $name ) );
	}

	/**
	 * @Given the service :service of site :site is stopped
	 */
	public function theServiceOfSiteIsStopped( $service, $site )
	{
		$this->stopContainer( $this->siteServiceContainer( $site, $service ) );
	}

	/**
	 * Creates and starts a compose service of a site (including docker-compose-custom.yml, like ee);
	 * its container is removed after the scenario.
	 *
	 * @Given the service :service of site :site is up
	 */
	public function theServiceOfSiteIsUp( $service, $site )
	{
		$dir     = EE_SITE_ROOT . '/' . $site;
		$compose = 'docker-compose -f docker-compose.yml' . ( file_exists( "$dir/docker-compose-custom.yml" ) ? ' -f docker-compose-custom.yml' : '' );
		$cd      = 'cd ' . escapeshellarg( $dir ) . ' && ';
		$up      = EE::launch( $cd . $compose . ' up -d ' . escapeshellarg( $service ), false, true );
		// Register whatever was created, even when `up` failed after creating the container.
		$ps = EE::launch( $cd . $compose . ' ps -aq ' . escapeshellarg( $service ), false, true );
		foreach ( array_filter( explode( "\n", trim( $ps->stdout ) ) ) as $id ) {
			$this->created_containers[] = $id;
		}
		if ( 0 !== $up->return_code ) {
			throw new Exception( "Could not start $service.\n" . self::describeRun( $up ) );
		}
	}

	/**
	 * @Given the service :service of site :site is started
	 */
	public function theServiceOfSiteIsStarted( $service, $site )
	{
		$this->mustRun( 'docker start ' . escapeshellarg( $this->siteServiceContainer( $site, $service ) ) );
	}

	/**
	 * @Then the docker container :name should be running
	 */
	public function theDockerContainerShouldBeRunning( $name )
	{
		if ( ! self::containerRunning( $name ) ) {
			throw new Exception( "Container $name is not running." );
		}
	}

	/**
	 * @Then I wait until the docker container :name is running up to :seconds seconds
	 */
	public function iWaitUntilTheDockerContainerIsRunning( $name, $seconds )
	{
		$deadline = time() + (int) $seconds;
		do {
			if ( self::containerRunning( $name ) ) {
				return;
			}
			sleep( 1 );
		} while ( time() < $deadline );

		throw new Exception( "Container $name was not running after $seconds seconds." );
	}

	/**
	 * @Then I wait until the service :service of site :site is running up to :seconds seconds
	 */
	public function iWaitUntilTheServiceOfSiteIsRunning( $service, $site, $seconds )
	{
		$this->iWaitUntilTheDockerContainerIsRunning( $this->siteServiceContainer( $site, $service ), $seconds );
	}
}
