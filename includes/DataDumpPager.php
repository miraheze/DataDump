<?php

namespace Miraheze\DataDump;

use JobSpecification;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MainConfigNames;
use MediaWiki\Pager\TablePager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\Jobs\DataDumpGenerateJob;
use PermissionsError;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;

class DataDumpPager extends TablePager {

	private Config $config;
	private JobQueueGroupFactory $jobQueueGroupFactory;
	private LinkRenderer $linkRenderer;
	private PermissionManager $permissionManager;

	public function __construct(
		Config $config,
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		JobQueueGroupFactory $jobQueueGroupFactory,
		LinkRenderer $linkRenderer,
		PermissionManager $permissionManager
	) {
		parent::__construct( $context, $linkRenderer );

		$this->mDb = $connectionProvider->getPrimaryDatabase();

		$this->config = $config;
		$this->jobQueueGroupFactory  = $jobQueueGroupFactory;
		$this->linkRenderer = $linkRenderer;
		$this->permissionManager = $permissionManager;
	}

	/** @inheritDoc */
	public function getFieldNames(): array {
		return [
			'dumps_timestamp' => $this->msg( 'datadump-table-header-date' )->text(),
			'dumps_filename' => $this->msg( 'datadump-table-header-name' )->text(),
			'dumps_type' => $this->msg( 'datadump-table-header-type' )->text(),
			'dumps_size' => $this->msg( 'datadump-table-header-size' )->text(),
			'dumps_status' => $this->msg( 'datadump-table-header-status' )->text(),
			'dumps_delete' => $this->msg( 'datadump-table-header-delete' )->text(),
		];
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		$row = $this->getCurrentRow();

		switch ( $name ) {
			case 'dumps_timestamp':
				$formatted = $this->escape( $this->getLanguage()->userTimeAndDate(
					$row->dumps_timestamp ?? '', $this->getUser()
				) );
				break;
			case 'dumps_type':
				$formatted = $this->escape( $row->dumps_type );
				break;
			case 'dumps_filename':
				$formatted = $this->getDownloadUrl( $row );
				break;
			case 'dumps_status':
				$formatted = match ( $row->dumps_status ) {
					'queued' => $this->msg( 'datadump-table-column-queued' )->escaped(),
					'in-progress' => $this->msg( 'datadump-table-column-in-progress' )->escaped(),
					'completed' => $this->msg( 'datadump-table-column-completed' )->escaped(),
					'failed' => $this->msg( 'datadump-table-column-failed' )->escaped(),
					default => '',
				};
				break;
			case 'dumps_size':
				$formatted = $this->escape(
					$this->getLanguage()->formatSize( $row->dumps_size ?? 0 )
				);
				break;
			case 'dumps_delete':
				$formatted = '';

				$dataDumpConfig = $this->config->get( 'DataDump' );
				$perm = $dataDumpConfig[$row->dumps_type]['permissions']['delete'] ?? 'delete-dump';
				if ( $this->permissionManager->userHasRight( $this->getUser(), $perm ) ) {
					$query = [
						'action' => 'delete',
						'type' => $row->dumps_type,
						'dump' => $row->dumps_filename,
					];
					$pageTitle = SpecialPage::getTitleFor( 'DataDump' );
					$element = Html::element(
						'input',
						[
							'type' => 'submit',
							'title' => $pageTitle->getText(),
							'value' => $this->msg( 'datadump-delete-button' )->text(),
						]
					);
					$token = Html::element(
						'input',
						[
							'type' => 'hidden',
							'name' => 'token',
							'value' => $this->getContext()->getCsrfTokenSet()->getToken(),
						]
					);
					// Do not show a delete button if the dump is not completed or failed.
					if ( $row->dumps_status === 'completed' || $row->dumps_status === 'failed' ) {
						$formatted = Html::openElement(
							'form',
							[
								'action' => $pageTitle->getLinkURL( $query ),
								'method' => 'POST',
							]
						) . $element . $token . Html::closeElement( 'form' );
					}
				}
				break;
			default:
				$formatted = $this->escape( "Unable to format {$name}" );
		}

		return $formatted;
	}

	/**
	 * Safely HTML-escapes $value
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
		return [
			'tables' => [
				'data_dump',
			],
			'fields' => [
				'dumps_status',
				'dumps_filename',
				'dumps_size',
				'dumps_timestamp',
				'dumps_type',
			],
			'conds' => [],
			'joins_conds' => [],
		];
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'dumps_timestamp';
	}

	/** @inheritDoc */
	public function isFieldSortable( $name ): bool {
		return $name !== 'dumps_delete' && $name !== 'dumps_status';
	}

	public function getForm(): void {
		$dataDumpDisableGenerate = $this->config->get( 'DataDumpDisableGenerate' );
		if ( $dataDumpDisableGenerate ) {
			$out = $this->getOutput();
			$out->addHTML(
				Html::errorBox( $this->msg( 'datadump-generated-disabled' )->escaped() )
			);

			$out->addHTML(
				Html::closeElement( 'br' ) .
				Linker::specialLink( 'DataDump', 'datadump-refresh' )
			);

			return;
		}

		$dataDumpConfig = $this->config->get( 'DataDump' );

		$opts = [];

		$user = $this->getContext()->getUser();

		foreach ( $dataDumpConfig as $name => $value ) {
			$perm = $dataDumpConfig[$name]['permissions']['generate'] ?? 'generate-dump';
			if ( $this->permissionManager->userHasRight( $user, $perm ) ) {
				$opts[$name] = $name;
			}
		}

		if ( !$opts ) {
			return;
		}

		$formDescriptor = [
			'intro' => [
				'type' => 'info',
				'default' => $this->msg( 'datadump-desc' )->text(),
			],
			'generatedumptype' => [
				'type' => 'select',
				'label-message' => 'datadump-label-generate',
				'options' => $opts,
				'name' => 'generatedumptype',
				'required' => true,
			],
		];

		foreach ( $dataDumpConfig as $name => $value ) {
			$type = $dataDumpConfig[$name];

			if ( !( $type['htmlform'] ?? false ) ) {
				continue;
			}

			$htmlform = $type['htmlform'];

			$formDescriptor[ $htmlform['name'] ] = $htmlform;
		}

		$htmlFormGenerate = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'searchForms' );
		$htmlFormGenerate->setMethod( 'post' )
			->setFormIdentifier( 'generateDumpForm' )
			->setSubmitCallback( [ $this, 'onGenerate' ] )
			->setWrapperLegendMsg( 'datadump-action' )
			->prepareForm()
			->show();
	}

	protected function onGenerate( array $params ): void {
		$out = $this->getOutput();

		if ( !$this->getContext()->getCsrfTokenSet()->matchTokenField( 'wpEditToken' ) ) {
			$out->addWikiMsg( 'sessionfailure' );
			return;
		}

		$dataDumpConfig = $this->config->get( 'DataDump' );

		$args = [];
		foreach ( $dataDumpConfig as $name => $value ) {
			$type = $dataDumpConfig[$name];

			if ( !( $type['htmlform'] ?? false ) ) {
				continue;
			}

			$htmlform = $type['htmlform'];

			if ( ( $htmlform['noArgsValue'] ?? '' ) === $params[ $htmlform['name'] ] ) {
				continue;
			}

			$arguments = $type['generate']['arguments'] ?? [];

			foreach ( $arguments as $arg => $val ) {
				$args[$name]['generate']['arguments'][$arg] = $val . '=' . ( $htmlform['value'] ?? '' ) . $params[ $htmlform['name'] ];
			}
		}

		$type = $params['generatedumptype'];
		if ( $type ) {
			if ( !isset( $dataDumpConfig[$type] ) ) {
				$out->addHTML(
					Html::warningBox(
						Html::element(
							'p',
							[],
							$this->msg( 'datadump-type-invalid' )->text()
						),
						'mw-notify-error'
					)
				);
				return;
			}

			$user = $this->getContext()->getUser();
			$perm = $dataDumpConfig[$type]['permissions']['generate'];
			if ( !$this->permissionManager->userHasRight( $user, $perm ) ) {
				throw new PermissionsError( $perm );
			}

			if ( $this->getGenerateLimit( $type ) ) {
				$dbName = $this->config->get( MainConfigNames::DBname );
				$fileName = $dbName . '_' . $type . '_' .
					bin2hex( random_bytes( 10 ) ) .
						$dataDumpConfig[$type]['file_ending'];

				$this->mDb->insert(
					'data_dump',
					[
						'dumps_status' => 'queued',
						'dumps_filename' => $fileName,
						'dumps_timestamp' => $this->mDb->timestamp(),
						'dumps_type' => $type,
					],
					__METHOD__
				);

				$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
				$jobQueueGroup->push(
					new JobSpecification(
						DataDumpGenerateJob::JOB_NAME,
						[
							'arguments' => $args[$type]['generate']['arguments'] ?? [],
							'fileName' => $fileName,
							'type' => $type,
						]
					)
				);

				$logEntry = new ManualLogEntry( 'datadump', 'generate' );
				$logEntry->setPerformer( $user );
				$logEntry->setTarget( SpecialPage::getTitleValueFor( 'DataDump' ) );
				$logEntry->setComment( 'Generated dump' );
				$logEntry->setParameters( [ '4::filename' => $fileName ] );
				$logEntry->publish( $logEntry->insert() );

				$out->addHTML(
					Html::successBox(
						Html::element(
							'p',
							[],
							$this->msg( 'datadump-generated-success' )->text()
						),
						'mw-notify-success'
					)
				);

			}
		} else {
			$out->addHTML(
				Html::warningBox(
					Html::element(
						'p',
						[],
						$this->msg( 'datadump-type-invalid' )->text()
					),
					'mw-notify-error'
				)
			);
		}
	}

	private function getGenerateLimit( string $type ): bool {
		$config = $this->config->get( 'DataDump' );

		if ( isset( $config[$type]['limit'] ) && $config[$type]['limit'] ) {
			$res = $this->getDatabase()->select(
				'data_dump',
				'*',
				[
					'dumps_type' => $type,
				],
				__METHOD__
			);

			$limit = $config[$type]['limit'];

			if ( $res->numRows() < (int)$limit ) {
				return true;
			} else {
				$this->getOutput()->addHTML(
					Html::warningBox(
						Html::element(
							'p',
							[],
							$this->msg( 'datadump-generated-error', $limit )->text()
						),
						'mw-notify-error'
					)
				);

				return false;
			}
		}

		return true;
	}

	private function getDownloadUrl( stdClass $row ): string {
		// Do not create a link if the file has not been created.
		if ( $row->dumps_status !== 'completed' ) {
			return $row->dumps_filename;
		}

		$query = [
			'action' => 'download',
			'dump' => $row->dumps_filename,
		];

		return $this->linkRenderer->makeLink(
			SpecialPage::getTitleValueFor( 'DataDump' ),
			$row->dumps_filename, [], $query
		);
	}
}
