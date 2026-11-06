<?php
/**
 * System health dashboard.
 *
 * @package BraintumSearch
 */

namespace BraintumSearch\Admin;

use BraintumSearch\Backend\BackendRegistry;
use BraintumSearch\Contracts\IndexStatus;
use BraintumSearch\Contracts\JobQueue;
use BraintumSearch\Indexing\FullSync;
use BraintumSearch\Indexing\Rebuild;
use BraintumSearch\Indexing\Reconciler;
use BraintumSearch\Indexing\ReindexNotice;
use BraintumSearch\Rest\RateLimiter;
use BraintumSearch\Support\Config;
use BraintumSearch\Support\Container;

/**
 * Tells an administrator whether search is working.
 *
 * Every number links to the thing that explains it, and every state that could
 * be quietly wrong is named rather than implied. Three in particular:
 *
 * - A queue driver that cannot run reports itself, because a stalled queue and
 *   an empty one look identical from the outside.
 * - Search being switched off is shown as a state, not an absence, so an
 *   administrator who has indexed everything and wonders why nothing changed
 *   has somewhere to look.
 * - Rate limiting that cannot work without a persistent object cache says so,
 *   rather than implying protection that is not there.
 *
 * @since 0.4.0
 */
final class DashboardScreen {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'braintum-search-dashboard';

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Add the menu entry.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			ConnectionScreen::SLUG,
			__( 'Dashboard', 'braintum-search-for-solr' ),
			__( 'Dashboard', 'braintum-search-for-solr' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config = $this->container->get( 'config' );

		$backends = $this->container->get( 'backends' );

		$queue = $this->container->get( 'indexing.queue' );

		$sync       = $this->container->get( 'indexing.full_sync' );
		$reconciler = $this->container->get( 'indexing.reconciler' );
		$limiter    = $this->container->get( 'rest.rate_limiter' );

		$documents = null;
		$index     = $backends->index();

		if ( null !== $index ) {
			$status = $index->status();
			$value  = $status->is_ok() ? $status->value() : null;

			$documents = $value instanceof IndexStatus ? $value->document_count() : null;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Braintum Search', 'braintum-search-for-solr' ); ?></h1>

			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<?php
					$this->row(
						__( 'Search', 'braintum-search-for-solr' ),
						$config->get_bool( 'search.enabled' )
							? __( 'Active. WordPress search is answered by Braintum Search.', 'braintum-search-for-solr' )
							: __( 'Off. WordPress is answering searches itself. Turn this on once indexing has finished.', 'braintum-search-for-solr' ),
						$config->get_bool( 'search.enabled' ) ? 'good' : 'idle'
					);

					$this->row(
						__( 'Instant search', 'braintum-search-for-solr' ),
						$config->get_bool( 'search.instant_enabled' )
							? __( 'Active. Suggestions appear as visitors type.', 'braintum-search-for-solr' )
							: __( 'Off.', 'braintum-search-for-solr' ),
						$config->get_bool( 'search.instant_enabled' ) ? 'good' : 'idle'
					);

					$this->row(
						__( 'Backend', 'braintum-search-for-solr' ),
						null === $index
							? __( 'Not reachable. Check the connection settings.', 'braintum-search-for-solr' )
							: sprintf(
								/* translators: %s: backend identifier. */
								__( 'Connected via %s', 'braintum-search-for-solr' ),
								$backends->selected_id()
							),
						null === $index ? 'bad' : 'good'
					);

					if ( $backends->is_migrating() ) {
						// Named rather than implied. A site indexing into one
						// backend and searching another is a deliberate,
						// temporary state, and one nobody should be able to
						// forget they left it in.
						$this->row(
							__( 'Migration', 'braintum-search-for-solr' ),
							sprintf(
								/* translators: 1: backend being filled, 2: backend answering searches. */
								__( 'In progress. Indexing into %1$s, searches still answered by %2$s. Finish with "wp braintum-search backends finish" once it is full.', 'braintum-search-for-solr' ),
								$backends->selected_id(),
								$backends->search_id()
							),
							'warn'
						);
					}

					$this->row(
						__( 'Documents', 'braintum-search-for-solr' ),
						null === $documents
							? __( 'Unknown', 'braintum-search-for-solr' )
							: number_format_i18n( $documents ),
						null === $documents ? 'idle' : 'good'
					);

					$this->row(
						__( 'Schema', 'braintum-search-for-solr' ),
						sprintf(
							/* translators: %d: schema contract version. */
							__( 'Contract version %d required', 'braintum-search-for-solr' ),
							BRAINTUM_SEARCH_SCHEMA_CONTRACT_VERSION
						),
						'idle'
					);

					$this->render_queue_row( $queue );
					$this->render_sync_row( $sync );
					$this->render_rebuild_row();
					$this->render_reindex_row();
					$this->render_drift_row( $reconciler );
					$this->render_limiter_row( $limiter );
					?>
				</tbody>
			</table>

			<p style="margin-top:1.5em">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ConnectionScreen::SLUG ) ); ?>">
					<?php esc_html_e( 'Connection settings', 'braintum-search-for-solr' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ContentScreen::SLUG ) ); ?>">
					<?php esc_html_e( 'What is indexed', 'braintum-search-for-solr' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SearchScreen::SLUG ) ); ?>">
					<?php esc_html_e( 'Relevance', 'braintum-search-for-solr' ); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ToolsScreen::SLUG ) ); ?>">
					<?php esc_html_e( 'Schema, reindex and history', 'braintum-search-for-solr' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Indexing', 'braintum-search-for-solr' ); ?></h2>
			<p>
				<?php esc_html_e( 'Indexing can be started from the Tools screen, or run from the command line, where there is no web-server timeout to fight:', 'braintum-search-for-solr' ); ?>
			</p>
			<pre class="code" style="padding:1em;background:#f6f7f7;max-width:820px"><code>wp braintum-search index
wp braintum-search index progress
wp braintum-search index resume
wp braintum-search rebuild start
wp braintum-search history</code></pre>
		</div>
		<?php
	}

	/**
	 * Render the queue row.
	 *
	 * @param mixed $queue Queue service.
	 * @return void
	 */
	private function render_queue_row( $queue ): void {
		if ( ! $queue instanceof JobQueue ) {
			$this->row( __( 'Queue', 'braintum-search-for-solr' ), __( 'Unavailable', 'braintum-search-for-solr' ), 'bad' );

			return;
		}

		$driver = ( new \ReflectionClass( $queue ) )->getShortName();
		$is_as  = false !== strpos( $driver, 'ActionScheduler' );

		if ( ! $queue->is_available() ) {
			// Named explicitly. A queue that cannot run looks exactly like an
			// empty one, and an administrator would otherwise wait for jobs
			// that will never start.
			$this->row(
				__( 'Queue', 'braintum-search-for-solr' ),
				__( 'Not running. Indexing jobs will not be processed. If WP-Cron is disabled on this site, run indexing from the command line instead.', 'braintum-search-for-solr' ),
				'bad'
			);

			return;
		}

		$this->row(
			__( 'Queue', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: 1: number of pending jobs, 2: extra note. */
				__( '%1$s pending. %2$s', 'braintum-search-for-solr' ),
				number_format_i18n( $queue->pending_count() ),
				$is_as
					? __( 'Using Action Scheduler.', 'braintum-search-for-solr' )
					: __( 'Using WP-Cron, which has weaker guarantees: jobs can run twice and only run when the site is visited. Indexing is safe to repeat, so this costs time rather than correctness.', 'braintum-search-for-solr' )
			),
			$is_as ? 'good' : 'warn'
		);
	}

	/**
	 * Render the synchronisation row.
	 *
	 * @param mixed $sync Full sync service.
	 * @return void
	 */
	private function render_sync_row( $sync ): void {
		if ( ! $sync instanceof FullSync ) {
			return;
		}

		$run = $sync->state();

		if ( null === $run ) {
			$this->row(
				__( 'Last full index', 'braintum-search-for-solr' ),
				__( 'Never run. Nothing is in the index yet.', 'braintum-search-for-solr' ),
				'warn'
			);

			return;
		}

		$this->row(
			__( 'Last full index', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: 1: state, 2: processed count, 3: failure count, 4: human time difference. */
				__( '%1$s — %2$s processed, %3$s failed, %4$s ago', 'braintum-search-for-solr' ),
				esc_html( (string) $run['state'] ),
				number_format_i18n( (int) $run['processed'] ),
				number_format_i18n( (int) $run['failed'] ),
				human_time_diff( (int) $run['updated_at'] )
			),
			'complete' === $run['state'] ? 'good' : ( 'running' === $run['state'] ? 'idle' : 'warn' )
		);
	}

	/**
	 * Render the rebuild row, when one is under way or has just switched.
	 *
	 * Absent the rest of the time. A dashboard row that says "no rebuild in
	 * progress" is a row that is wrong to read on every visit but one.
	 *
	 * @return void
	 */
	private function render_rebuild_row(): void {
		$rebuild = $this->container->get( 'indexing.rebuild' );

		if ( ! $rebuild instanceof Rebuild ) {
			return;
		}

		$state = $rebuild->state();

		if ( null === $state ) {
			return;
		}

		$labels = array(
			'building' => __( 'A replacement index is being built. Searches are still answered by the current one.', 'braintum-search-for-solr' ),
			'ready'    => __( 'A replacement index is built and checked, and is waiting for you to switch to it.', 'braintum-search-for-solr' ),
			'switched' => __( 'Searches are answered by the rebuilt index. The previous one is still on your server.', 'braintum-search-for-solr' ),
		);

		$this->row(
			__( 'Rebuild', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: 1: state description, 2: index name. */
				__( '%1$s (%2$s)', 'braintum-search-for-solr' ),
				$labels[ (string) $state['state'] ] ?? (string) $state['state'],
				(string) $state['target']
			),
			'ready' === $state['state'] ? 'warn' : 'idle'
		);
	}

	/**
	 * Render the outstanding-reindex row.
	 *
	 * @return void
	 */
	private function render_reindex_row(): void {
		$notice = $this->container->get( 'indexing.reindex_notice' );

		if ( ! $notice instanceof ReindexNotice || ! $notice->is_required() ) {
			return;
		}

		$this->row(
			__( 'Outstanding', 'braintum-search-for-solr' ),
			sprintf(
				/* translators: 1: reason a reindex is needed, 2: human time difference. */
				__( 'Content in the index does not reflect a change made %2$s ago: %1$s. A settings change never rewrites documents that are already indexed.', 'braintum-search-for-solr' ),
				$notice->reason(),
				human_time_diff( $notice->since() )
			),
			'warn'
		);
	}

	/**
	 * Render the drift row.
	 *
	 * @param mixed $reconciler Reconciler service.
	 * @return void
	 */
	private function render_drift_row( $reconciler ): void {
		if ( ! $reconciler instanceof Reconciler ) {
			return;
		}

		$report = $reconciler->last_report();

		if ( null === $report ) {
			return;
		}

		$this->row(
			__( 'Index accuracy', 'braintum-search-for-solr' ),
			empty( $report['drifted'] )
				? sprintf(
					/* translators: 1: indexed count, 2: expected count. */
					__( 'Matches WordPress: %1$s indexed of %2$s expected.', 'braintum-search-for-solr' ),
					number_format_i18n( (int) $report['actual'] ),
					number_format_i18n( (int) $report['expected'] )
				)
				: sprintf(
					/* translators: 1: indexed count, 2: expected count. */
					__( 'Out of step: %1$s indexed, %2$s expected. Run a full index to repair it.', 'braintum-search-for-solr' ),
					number_format_i18n( (int) $report['actual'] ),
					number_format_i18n( (int) $report['expected'] )
				),
			empty( $report['drifted'] ) ? 'good' : 'warn'
		);
	}

	/**
	 * Render the rate limiter row.
	 *
	 * @param mixed $limiter Rate limiter.
	 * @return void
	 */
	private function render_limiter_row( $limiter ): void {
		if ( ! $limiter instanceof RateLimiter || $limiter->is_effective() ) {
			return;
		}

		// Only shown when it cannot work. Claiming protection that is absent
		// would be worse than saying nothing.
		$this->row(
			__( 'Search API limits', 'braintum-search-for-solr' ),
			__( 'Not enforced. Rate limiting needs a persistent object cache. Limit requests at your CDN or web server instead.', 'braintum-search-for-solr' ),
			'warn'
		);
	}

	/**
	 * Render one row.
	 *
	 * @param string $label  Row label.
	 * @param string $detail Row detail.
	 * @param string $state  One of good, warn, bad or idle.
	 * @return void
	 */
	private function row( string $label, string $detail, string $state ): void {
		$colours = array(
			'good' => '#008a20',
			'warn' => '#bd8600',
			'bad'  => '#d63638',
			'idle' => '#646970',
		);

		printf(
			'<tr><td style="width:2em;color:%s">&#9679;</td><td style="width:14em"><strong>%s</strong></td><td>%s</td></tr>',
			esc_attr( $colours[ $state ] ?? '#646970' ),
			esc_html( $label ),
			esc_html( $detail )
		);
	}
}
