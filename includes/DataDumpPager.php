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
use MediaWiki\Pager\IndexPager;
use MediaWiki\Pager\TablePager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\DataDump\Jobs\DataDumpGenerateJob;
use PermissionsError;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;

class DataDumpPager extends TablePager {

	/** @inheritDoc */
	public $mDefaultDirection = IndexPager::DIR_ASCENDING;

	public function __construct(
		IConnectionProvider $connectionProvider,
		IContextSource $context,
		private readonly Config $config,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LinkRenderer $linkRenderer,
		private readonly PermissionManager $permissionManager
	) {
		$this->mDb = $connectionProvider->getPrimaryDatabase();
		parent::__construct( $context, $linkRenderer );
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
	public function formatValue( $field, $value ): string {
		$row = $this->getCurrentRow();
		// Silence phan (this code used to return an empty string when $value is null,
		// but that conflicts with dumps_delete since that column is not really a part
		// of the database).
		$value ??= '';

		switch ( $field ) {
			case 'dumps_timestamp':
				$formatted = $this->escape( $this->getLanguage()->userTimeAndDate(
					$value, $this->getUser()
				) );
				break;
			case 'dumps_type':
				$formatted = $this->escape( $value );
				break;
			case 'dumps_filename':
				$formatted = $this->getDownloadUrl( $row );
				break;
			case 'dumps_status':
				$formatted = match ( $value ) {
					'queued' => $this->msg( 'datadump-table-column-queued' )->escaped(),
					'in-progress' => $this->msg( 'datadump-table-column-in-progress' )->escaped(),
					'completed' => $this->msg( 'datadump-table-column-completed' )->escaped(),
					'failed' => $this->msg( 'datadump-table-column-failed' )->escaped(),
					default => '',
				};
				break;
			case 'dumps_size':
				$formatted = $this->escape(
					$this->getLanguage()->formatSize( (int)$value )
				);
				break;
			case 'dumps_delete':
				$formatted = '';

				$dataDumpConfig = $this->config->get( ConfigNames::DataDump );
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
					// Do not show a delete button if the dump is not completed, failed,
					// or queued or in progress for over 48 hours.
					if (
						$row->dumps_status === 'completed' ||
						$row->dumps_status === 'failed' ||
						(
							(
								$row->dumps_status === 'queued' ||
								$row->dumps_status === 'in-progress'
							) &&
							( strtotime( $row->dumps_timestamp ) <= time() - 48 * 3600 )
						)
					) {
						$formatted = Html::rawElement(
							'form',
							[
								'action' => $pageTitle->getLinkURL( $query ),
								'method' => 'POST',
							],
							$element . $token
						);
					}
				}
				break;
			default:
				$formatted = $this->escape( "Unable to format $field" );
		}

		return $formatted;
	}

	/**
	 * Safely HTML-escapes $value
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES );
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
		$dataDumpDisableGenerate = $this->config->get( ConfigNames::DisableGenerate );
		if ( $dataDumpDisableGenerate ) {
			$out = $this->getOutput();
			$out->addHTML(
				Html::errorBox( $this->msg( 'datadump-generated-disabled' )->escaped() )
			);

			$out->addHTML(
				Html::element( 'br' ) .
				Linker::specialLink( 'DataDump', 'datadump-refresh' )
			);

			return;
		}

		$dataDumpConfig = $this->config->get( ConfigNames::DataDump );

		$opts = [];
		$user = $this->getContext()->getUser();
		foreach ( $dataDumpConfig as $name => $_ ) {
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

		foreach ( $dataDumpConfig as $name => $_ ) {
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

	public function onGenerate( array $params ): void {
		$out = $this->getOutput();

		if ( !$this->getContext()->getCsrfTokenSet()->matchTokenField( 'wpEditToken' ) ) {
			$out->addWikiMsg( 'sessionfailure' );
			return;
		}

		$dataDumpConfig = $this->config->get( ConfigNames::DataDump );

		$args = [];
		foreach ( $dataDumpConfig as $name => $_ ) {
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
				$args[$name]['generate']['arguments'][$arg] = $val . '=' .
					( $htmlform['value'] ?? '' ) . $params[ $htmlform['name'] ];
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

				$this->mDb->newInsertQueryBuilder()
					->insertInto( 'data_dump' )
					->row( [
						'dumps_status' => 'queued',
						'dumps_filename' => $fileName,
						'dumps_timestamp' => $this->mDb->timestamp(),
						'dumps_type' => $type,
					] )
					->caller( __METHOD__ )
					->execute();

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
		$config = $this->config->get( ConfigNames::DataDump );

		if ( $config[$type]['limit'] ?? null ) {
			$typeCount = $this->getDatabase()->newSelectQueryBuilder()
				->select( '*' )
				->from( 'data_dump' )
				->where( [ 'dumps_type' => $type ] )
				->caller( __METHOD__ )
				->fetchRowCount();

			$limit = $config[$type]['limit'];
			if ( $typeCount < (int)$limit ) {
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
