<?php

namespace Miraheze\DataDump;

use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\MediaWikiServices;
use MediaWiki\Pager\TablePager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Title\Title;
use Miraheze\DataDump\Jobs\DataDumpGenerateJob;
use PermissionsError;

class DataDumpPager extends TablePager {

	/** @var Config */
	private $config;

	/** @var Title */
	private $pageTitle;

	/** @var PermissionManager */
	private $permissionManager;

	public function __construct( IContextSource $context, $pageTitle ) {
		$this->setContext( $context );

		$this->mDb = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		if ( !$this->getRequest()->getVal( 'sort', null ) ) {
			$this->mDefaultDirection = true;
		}

		parent::__construct( $context );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'DataDump' );
		$this->pageTitle = $pageTitle;
		$this->permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
	}

	public function getFieldNames() {
		static $headers = null;

		$headers = [
			'dumps_timestamp' => 'datadump-table-header-date',
			'dumps_filename'  => 'datadump-table-header-name',
			'dumps_type'      => 'datadump-table-header-type',
			'dumps_size'      => 'datadump-table-header-size',
			'dumps_status'    => 'datadump-table-header-status',
			'dumps_delete'    => 'datadump-table-header-delete',
		];

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->parse();
		}

		return $headers;
	}

	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'dumps_timestamp':
				$time = $row->dumps_timestamp ?? '';
				$formatted = htmlspecialchars(
					$this->getLanguage()->userTimeAndDate( $time, $this->getUser() )
				);
				break;
			case 'dumps_type':
				$formatted = htmlspecialchars( $row->dumps_type );
				break;
			case 'dumps_filename':
				$formatted = $this->getDownloadUrl( $row );
				break;
			case 'dumps_status':
				if ( $row->dumps_status === 'queued' ) {
					$formatted = $this->msg( 'datadump-table-column-queued' )->escaped();
				} elseif ( $row->dumps_status === 'in-progress' ) {
					$formatted = $this->msg( 'datadump-table-column-in-progress' )->escaped();
				} elseif ( $row->dumps_status === 'completed' ) {
					$formatted = $this->msg( 'datadump-table-column-completed' )->escaped();
				} elseif ( $row->dumps_status === 'failed' ) {
					$formatted = $this->msg( 'datadump-table-column-failed' )->escaped();
				} else {
					$formatted = '';
				}
				break;
			case 'dumps_size':
				$formatted = htmlspecialchars(
					$this->getLanguage()->formatSize( $row->dumps_size ?? 0 ) );
				break;
			case 'dumps_delete':
				$formatted = '';

				$dataDumpConfig = $this->config->get( 'DataDump' );
				$perm = $dataDumpConfig[$row->dumps_type]['permissions']['delete'] ?? 'delete-dump';
				if ( $this->permissionManager->userHasRight( $this->getUser(), $perm ) ) {
					$query = [
						'action' => 'delete',
						'type' => $row->dumps_type,
						'dump' => $row->dumps_filename
					];
					$link = $this->pageTitle->getLinkURL( $query );
					$element = Html::element(
						'input',
						[
							'type' => 'submit',
							'title' => $this->pageTitle,
							'value' => $this->msg( 'datadump-delete-button' )->parse()
						]
					);
					$token = Html::element(
						'input',
						[
							'type' => 'hidden',
							'name' => 'token',
							'value' => $this->getContext()->getCsrfTokenSet()->getToken()
						]
					);
					// Do not show a delete button if the dump is not completed or failed.
					if ( $row->dumps_status === 'completed' || $row->dumps_status === 'failed' ) {
						$formatted = Html::openElement(
							'form',
							[
								'action' => $link,
								'method' => 'POST'
							]
						) . $element . $token . Html::closeElement( 'form' );
					}
				}
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		return $formatted;
	}

	public function getQueryInfo() {
		return [
			'tables' => [ 'data_dump' ],
			'fields' => [ 'dumps_status', 'dumps_filename', 'dumps_size', 'dumps_timestamp', 'dumps_type' ],
			'conds' => [],
			'joins_conds' => [],
		];
	}

	public function getDefaultSort() {
		return 'dumps_timestamp';
	}

	public function isFieldSortable( $name ) {
		if ( $name === 'dumps_delete' || $name === 'dumps_status' ) {
			return false;
		} else {
			return true;
		}
	}

	public function getForm() {
		$dataDumpDisableGenerate = $this->config->get( 'DataDumpDisableGenerate' );
		if ( $dataDumpDisableGenerate ) {
			$out = $this->getOutput();
			$out->addHTML(
				Html::errorBox( $this->msg( 'datadump-generated-disabled' )->escaped() )
			);

			$out->addHTML(
				'<br />' . Linker::specialLink( 'DataDump', 'datadump-refresh' )
			);

			return true;
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
				'default' => $this->msg( 'datadump-desc' )->parse(),
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

	public function onGenerate( array $params ) {
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
							$this->msg( 'datadump-type-invalid' )->parse()
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
				$dbName = $this->config->get( 'DBname' );
				$fileName = $dbName . '_' . $type . '_' .
					bin2hex( random_bytes( 10 ) ) .
						$dataDumpConfig[$type]['file_ending'];
				$this->mDb->insert(
					'data_dump',
					[
						'dumps_status' => 'queued',
						'dumps_filename' => $fileName,
						'dumps_timestamp' => $this->mDb->timestamp(),
						'dumps_type' => $type
					],
					__METHOD__
				);

				$jobParams = [
					'fileName' => $fileName,
					'type' => $type,
					'arguments' => $args[$type]['generate']['arguments'] ?? []
				];

				$job = new DataDumpGenerateJob(
					Title::newFromText( 'Special:DataDump' ), $jobParams );
				MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

				$logEntry = new ManualLogEntry( 'datadump', 'generate' );
				$logEntry->setPerformer( $user );
				$logEntry->setTarget( $this->pageTitle );
				$logEntry->setComment( 'Generated dump' );
				$logEntry->setParameters( [ '4::filename' => $fileName ] );
				$logEntry->publish( $logEntry->insert() );

				$out->addHTML(
					Html::successBox(
						Html::element(
							'p',
							[],
							$this->msg( 'datadump-generated-success' )->parse()
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
						$this->msg( 'datadump-type-invalid' )->parse()
					),
					'mw-notify-error'
				)
			);
		}

		return true;
	}

	private function getGenerateLimit( string $type ) {
		$config = $this->config->get( 'DataDump' );

		if ( isset( $config[$type]['limit'] ) && $config[$type]['limit'] ) {
			$res = $this->mDb->select(
				'data_dump',
				'*',
				[
					'dumps_type' => $type
				]
			);

			$limit = $config[$type]['limit'];

			if ( (int)$res->numRows() < (int)$limit ) {
				return true;
			} else {
				$this->getOutput()->addHTML(
					Html::warningBox(
						Html::element(
							'p',
							[],
							$this->msg( 'datadump-generated-error', $limit )->parse()
						),
						'mw-notify-error'
					)
				);

				return false;
			}
		}

		return true;
	}

	private function getDownloadUrl( object $row ) {
		// Do not create a link if the file has not been created.
		if ( $row->dumps_status !== 'completed' ) {
			return $row->dumps_filename;
		}

		// If wgDataDumpDownloadUrl is configured, use that
		// rather than using the internal streamer.
		if ( $this->config->get( 'DataDumpDownloadUrl' ) ) {
			$url = preg_replace(
				'/\$\{filename\}/im',
				$row->dumps_filename,
				$this->config->get( 'DataDumpDownloadUrl' )
			);
			return Linker::makeExternalLink( $url, $row->dumps_filename );
		}

		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		$query = [
			'action' => 'download',
			'dump' => $row->dumps_filename
		];

		return $linkRenderer->makeLink( $this->pageTitle, $row->dumps_filename, [], $query );
	}
}
