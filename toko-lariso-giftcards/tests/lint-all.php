<?php
/**
 * Runs PHP syntax checks for all plugin PHP files.
 *
 * Usage:
 * php tests/lint-all.php
 *
 * @package TokoLarisoGiftcards
 */

$root = dirname( __DIR__ );
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

$failed = false;

foreach ( $iterator as $file ) {
	if ( ! $file instanceof SplFileInfo || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$path = $file->getPathname();
	$cmd  = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path );
	echo $cmd . PHP_EOL;
	passthru( $cmd, $exit_code );

	if ( 0 !== $exit_code ) {
		$failed = true;
	}
}

exit( $failed ? 1 : 0 );
