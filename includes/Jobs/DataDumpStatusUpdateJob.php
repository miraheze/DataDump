<?php

namespace Miraheze\DataDump\Jobs;

use Job;
use Miraheze\DataDump\Services\DataDumpStatusManager;

class DataDumpStatusUpdateJob extends Job {

	public const JOB_NAME = 'DataDumpStatusUpdateJob';

	private string $status;
	private string $fileName;
	private string $comment;
	private int $fileSize;

	public function __construct(
		array $params,
		private readonly DataDumpStatusManager $statusManager,
	) {
		parent::__construct( self::JOB_NAME, $params );
		
		$this->status = $params['status'];
		$this->fileName = $params['fileName'];
		$this->comment = $params['comment'];
		$this->fileSize = $params['fileSize'];
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$this->statusManager->setStatus(
			status: $this->status,
			fileName: $this->fileName,
			fname: __METHOD__,
			comment: $this->comment,
			fileSize: $this->fileSize
		);
		return true;
	}

}
