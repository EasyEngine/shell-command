<?php

if ( ! defined( 'SHELL_TEMPLATE_ROOT' ) ) {
	define( 'SHELL_TEMPLATE_ROOT', __DIR__ . '/templates' );
}

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'shell', 'Shell_Command' );
