<?php

use MediaWiki\Shell\Shell;

/**
 * Used to generate dump
 *
 * @author Paladox
 */
class DataDumpGenerateJob extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( 'DataDumpGenerateJob', $title, $params );
	}

	public function run() {
		global $wgDataDump, $wgDBname, $IP;

		$dbw = wfGetDB( DB_MASTER );

		$fileName = $this->params['fileName'];
		$type = $this->params['type'];

		$options = [];
		foreach ( $wgDataDump[$type]['generate']['options'] as $option ) {
			$options[] = preg_replace( '/\$\{filename\}/i', $fileName, $option );
		}

		$wgDataDump[$type]['generate']['options'] = $options;

		if ( $wgDataDump[$type]['generate']['type'] === 'mwscript' ) {
			$result = Shell::makeScriptCommand(
				$wgDataDump[$type]['generate']['script'],
				array_merge(
					$wgDataDump[$type]['generate']['options'],
					[ '--wiki', $wgDBname ],
				);
			)->execute()->getExitCode();
		} else {
			$result = Shell::command(
				$wgDataDump[$type]['generate']['script'],
				$wgDataDump[$type]['generate']['options'],
			)->execute()->getExitCode();
		}

		if ( $result ) {
			$dbw->update(
				'data_dump',
				[
					'dumps_completed' => 1,
					'dumps_failed' => 0
				],
				[
					'dumps_filename' => $fileName
				],
				__METHOD__
			);
		} else {
			$dbw->update(
				'data_dump',
				[
					'dumps_completed' => 0,
					'dumps_failed' => 1
				],
				[
					'dumps_filename' => $fileName
				],
				__METHOD__
			);
		}

		return true;
	}
}
