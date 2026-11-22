<?php
/**
 * Connection settings screen.
 *
 * @package BraintumSearch
 */

namespace BraintumSearch\Admin;

use BraintumSearch\Backend\BackendRegistry;
use BraintumSearch\Diagnostics\ConnectionDiagnostic;
use BraintumSearch\Diagnostics\DiagnosticReport;
use BraintumSearch\Integration\WooCommerce\WooCommerce;
use BraintumSearch\Multisite\SiteScope;
use BraintumSearch\Schema\AppliedSchema;
use BraintumSearch\Support\Config;
use BraintumSearch\Support\Credentials;

/**
 * Where an administrator points Braintum Search at their Solr server.
 *
 * Deliberately one screen. The plan's section 17 argues for six in total, not
 * twelve, because every extra top-level item lengthens the setup path for
 * someone who is not a Solr expert.
 *
 * Three behaviours worth noting, each fixing something the 0.1.0 prototype got
 * wrong:
 *
 * - It reads the same option it writes. The prototype's settings page wrote
 *   braintum_search_settings while its configuration read braintum_search_solr_endpoint,
 *   so saving the endpoint had no effect whatsoever.
 * - A value pinned by a constant in wp-config.php is shown as such, and its
 *   field is disabled rather than accepting input that would be discarded.
 * - The password is never rendered back into the form. The field shows whether
 *   one is set and offers to replace it.
 *
 * Narrowed in 0.8.0 to the connection itself. The schema it used to export moved
 * to Tools, beside the plan that applies it, and the filters it used to list
 * moved to Search, beside the relevance they belong with. Both were here for the
 * reason everything ends up on the first screen somebody builds.
 *
 * @since 0.1.0
 */
final class ConnectionScreen extends Screen {

	/**
	 * Menu and page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'braintum-search';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Connection diagnostic.
	 *
	 * @var ConnectionDiagnostic
	 */
	private $diagnostic;

	/**
	 * This site's place in a network, or null on a single site.
	 *
	 * @var SiteScope|null
	 */
	private $scope;

	/**
	 * Registered backends.
	 *
	 * @var BackendRegistry|null
	 */
	private $backends;

	/**
	 * Constructor.
	 *
	 * @param Config               $config      Configuration.
	 * @param Credentials          $credentials Credential store.
	 * @param ConnectionDiagnostic $diagnostic  Connection diagnostic.
	 * @param SiteScope|null       $scope       This site's place in a network.
	 * @param BackendRegistry|null $backends    Registered backends.
	 */
	public function __construct(
		Config $config,
		Credentials $credentials,
		ConnectionDiagnostic $diagnostic,
		?SiteScope $scope = null,
		?BackendRegistry $backends = null
	) {
		$this->config      = $config;
		$this->credentials = $credentials;
		$this->diagnostic  = $diagnostic;
		$this->scope       = $scope;
		$this->backends    = $backends;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Connection', 'braintum-search-for-solr' );
	}

	/**
	 * Add the menu entry.
	 *
	 * This is the one screen that is also the top-level menu, and it registers
	 * itself as a submenu as well. Without that, WordPress generates a first
	 * submenu item labelled with the menu title, so the list would read
	 * "Braintum Search, Dashboard, Content" and nothing would say which of them holds
	 * the connection settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Braintum Search', 'braintum-search-for-solr' ),
			__( 'Braintum Search', 'braintum-search-for-solr' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-search',
			80
		);

		add_submenu_page(
			self::SLUG,
			$this->label(),
			$this->label(),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Save the submitted connection settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->require_manage();
		check_admin_referer( 'braintum_search_save_connection' );

		$endpoint   = isset( $_POST['endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['endpoint'] ) ) : '';
		$collection = isset( $_POST['collection'] ) ? sanitize_text_field( wp_unslash( $_POST['collection'] ) ) : '';
		$username   = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$private    = ! empty( $_POST['allow_private_ip'] );
		$verify_tls = ! empty( $_POST['verify_tls'] );

		$moved = $endpoint !== $this->config->get_string( 'connection.endpoint' )
			|| $collection !== $this->config->get_string( 'connection.collection' );

		$this->config->set( 'connection.endpoint', $endpoint );
		$this->config->set( 'connection.collection', $collection );

		if ( $moved ) {
			// Everything recorded about the schema described the collection this
			// site was pointed at a moment ago. Keeping it would report a
			// different server's schema as verified, and report an untouched one
			// as tampered with.
			( new AppliedSchema( $this->config ) )->forget();
		}

		$this->config->set( 'connection.allow_private_ip', $private );
		$this->config->set( 'connection.verify_tls', $verify_tls );
		$this->save_backend_choice(
			isset( $_POST['backend'] ) ? sanitize_key( wp_unslash( $_POST['backend'] ) ) : ''
		);

		$this->config->set( 'search.enabled', ! empty( $_POST['search_enabled'] ) );
		$this->config->set( 'search.instant_enabled', ! empty( $_POST['instant_enabled'] ) );
		$this->config->set(
			'search.instant_mode',
			isset( $_POST['instant_mode'] ) && 'suggest' === sanitize_key( wp_unslash( $_POST['instant_mode'] ) ) ? 'suggest' : 'modal'
		);
		$this->config->set( 'search.shop_enabled', ! empty( $_POST['shop_enabled'] ) );

		$notice = 'saved';

		// An empty password field means "leave it alone", not "clear it".
		// Treating a blank submit as a deletion would wipe credentials every
		// time somebody changed the timeout.
		if ( isset( $_POST['password'] ) && '' !== $_POST['password'] ) {
			$stored = $this->credentials->store(
				$username,
				// Not sanitised: a password is an opaque byte string, and
				// sanitize_text_field() would silently mangle valid ones.
				// It is never rendered, only sent as an Authorization header.
				(string) wp_unslash( $_POST['password'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			);

			if ( $stored->is_failure() ) {
				$notice = 'credentials_failed';
			}
		} elseif ( ! empty( $_POST['clear_password'] ) ) {
			$this->credentials->forget();
		}

		$this->back( $notice );
	}

	/**
	 * Render the screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->can_manage() ) {
			return;
		}

		$endpoint   = $this->config->get_string( 'connection.endpoint' );
		$collection = $this->config->get_string( 'connection.collection' );
		$tested     = null;

		if ( '' !== $endpoint ) {
			$tested = $this->diagnostic->run();
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Braintum Search', 'braintum-search-for-solr' ); ?></h1>

			<?php
			$this->render_notice(
				array(
					'saved'              => array( 'success', __( 'Connection settings saved.', 'braintum-search-for-solr' ) ),
					'credentials_failed' => array( 'error', __( 'The settings were saved, but the password could not be stored securely. Set BRAINTUM_SEARCH_SOLR_USER and BRAINTUM_SEARCH_SOLR_PASSWORD in wp-config.php instead.', 'braintum-search-for-solr' ) ),
				)
			);
			?>

			<h2><?php esc_html_e( 'Connection', 'braintum-search-for-solr' ); ?></h2>

			<?php $this->render_backend_choice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="braintum_search_save_connection"/>
				<?php wp_nonce_field( 'braintum_search_save_connection' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="braintum-search-endpoint"><?php esc_html_e( 'Solr address', 'braintum-search-for-solr' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="braintum-search-endpoint"
								name="endpoint"
								class="regular-text code"
								value="<?php echo esc_attr( $endpoint ); ?>"
								placeholder="https://solr.example.com:8983/solr"
								<?php disabled( $this->config->is_locked( 'connection.endpoint' ) ); ?>
							/>
							<p class="description">
								<?php esc_html_e( 'The base address of your Solr server, ending in /solr. A collection name pasted here is ignored; enter it in the next field.', 'braintum-search-for-solr' ); ?>
								<?php $this->render_lock_note( 'connection.endpoint' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="braintum-search-collection"><?php esc_html_e( 'Collection or core', 'braintum-search-for-solr' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="braintum-search-collection"
								name="collection"
								class="regular-text code"
								value="<?php echo esc_attr( $collection ); ?>"
								placeholder="wordpress"
								<?php disabled( $this->config->is_locked( 'connection.collection' ) || ( null !== $this->scope && $this->scope->collection_is_derived() ) ); ?>
							/>
							<p class="description">
								<?php esc_html_e( 'Braintum Search does not create this for you. Create it on your Solr server first.', 'braintum-search-for-solr' ); ?>
								<?php $this->render_lock_note( 'connection.collection' ); ?>
								<?php if ( null !== $this->scope && $this->scope->collection_is_derived() ) : ?>
									<br/>
									<strong>
										<?php
										printf(
											/* translators: %s: collection name. */
											esc_html__( 'This site uses %s, from the network\'s naming pattern. The field above is ignored.', 'braintum-search-for-solr' ),
											'<code>' . esc_html( $this->scope->collection() ) . '</code>'
										);
										?>
									</strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="braintum-search-username"><?php esc_html_e( 'Username', 'braintum-search-for-solr' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="braintum-search-username"
								name="username"
								class="regular-text"
								autocomplete="off"
								value="<?php echo esc_attr( $this->credentials->username() ); ?>"
								<?php disabled( 'constant' === $this->credentials->source() ); ?>
							/>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="braintum-search-password"><?php esc_html_e( 'Password', 'braintum-search-for-solr' ); ?></label>
						</th>
						<td>
							<?php if ( 'constant' === $this->credentials->source() ) : ?>
								<p><strong><?php esc_html_e( 'Set in wp-config.php.', 'braintum-search-for-solr' ); ?></strong></p>
								<p class="description">
									<?php esc_html_e( 'This is the most secure option. Credentials defined there are never written to the database.', 'braintum-search-for-solr' ); ?>
								</p>
							<?php else : ?>
								<?php if ( 'network' === $this->credentials->source() ) : ?>
									<p class="description">
										<?php esc_html_e( 'This site is using the credentials set for the whole network. Enter a password here only if this site talks to a different Solr.', 'braintum-search-for-solr' ); ?>
									</p>
								<?php endif; ?>
								<input
									type="password"
									id="braintum-search-password"
									name="password"
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo esc_attr( $this->credentials->has_password() ? __( 'Saved. Type to replace.', 'braintum-search-for-solr' ) : __( 'Not set', 'braintum-search-for-solr' ) ); ?>"
								/>
								<?php if ( $this->credentials->has_password() ) : ?>
									<p>
										<label>
											<input type="checkbox" name="clear_password" value="1"/>
											<?php esc_html_e( 'Remove the saved password', 'braintum-search-for-solr' ); ?>
										</label>
									</p>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Stored encrypted. That protects it if your database is exposed, but not if the server itself is compromised, because the key lives in wp-config.php. For stronger protection, define BRAINTUM_SEARCH_SOLR_USER and BRAINTUM_SEARCH_SOLR_PASSWORD there instead.', 'braintum-search-for-solr' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Network', 'braintum-search-for-solr' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="allow_private_ip"
									value="1"
									<?php checked( $this->config->get_bool( 'connection.allow_private_ip' ) ); ?>
								/>
								<?php esc_html_e( 'My Solr server is on this machine or a private network', 'braintum-search-for-solr' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Required for addresses like 127.0.0.1 or 10.x.x.x. Braintum Search refuses private addresses unless you confirm, because a plugin that fetches any address an administrator types can be used to probe internal infrastructure. Cloud metadata addresses stay blocked either way.', 'braintum-search-for-solr' ); ?>
							</p>

							<label>
								<input
									type="checkbox"
									name="verify_tls"
									value="1"
									<?php checked( $this->config->get_bool( 'connection.verify_tls', true ) ); ?>
								/>
								<?php esc_html_e( 'Verify TLS certificates', 'braintum-search-for-solr' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave this on unless your Solr uses a self-signed certificate you trust.', 'braintum-search-for-solr' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Search', 'braintum-search-for-solr' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Replace WordPress search', 'braintum-search-for-solr' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="search_enabled"
									value="1"
									<?php checked( $this->config->get_bool( 'search.enabled' ) ); ?>
								/>
								<?php esc_html_e( 'Answer searches from the index', 'braintum-search-for-solr' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave this off until indexing has finished. Turning it on with an empty index would give visitors a site with no search results. If Solr becomes unreachable later, WordPress answers searches itself and visitors see no error.', 'braintum-search-for-solr' ); ?>
							</p>
						</td>
					</tr>

					<?php if ( WooCommerce::is_active() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Shop and product pages', 'braintum-search-for-solr' ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										name="shop_enabled"
										value="1"
										<?php checked( $this->config->get_bool( 'search.shop_enabled' ) ); ?>
									/>
									<?php esc_html_e( 'Answer shop, category and product searches from the index', 'braintum-search-for-solr' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Products are indexed either way. This decides whether WooCommerce asks Solr for them. A query Braintum Search cannot express exactly — a rating filter, or one an extension added — is handed back to WooCommerce rather than answered approximately.', 'braintum-search-for-solr' ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Instant search', 'braintum-search-for-solr' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="instant_enabled"
									value="1"
									<?php checked( $this->config->get_bool( 'search.instant_enabled' ) ); ?>
								/>
								<?php esc_html_e( 'Show results as visitors type', 'braintum-search-for-solr' ); ?>
							</label>
							<fieldset class="braintum-search-instant-mode">
								<legend class="screen-reader-text"><?php esc_html_e( 'How instant search appears', 'braintum-search-for-solr' ); ?></legend>
								<?php $braintum_search_mode = $this->config->get_string( 'search.instant_mode', 'modal' ); ?>
								<p>
									<label>
										<input type="radio" name="instant_mode" value="modal" <?php checked( 'suggest' !== $braintum_search_mode ); ?> />
										<?php esc_html_e( 'Search popup: results with images, prices and content-type tabs', 'braintum-search-for-solr' ); ?>
									</label>
								</p>
								<p>
									<label>
										<input type="radio" name="instant_mode" value="suggest" <?php checked( 'suggest', $braintum_search_mode ); ?> />
										<?php esc_html_e( 'Suggestion list: matching titles under the search field', 'braintum-search-for-solr' ); ?>
									</label>
								</p>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'Attaches to the search field your theme already renders: clicking it, typing into it or pressing its button opens the popup. Works independently of the setting above, so you can try it before switching search over. The popup\'s markup can be overridden from your theme.', 'braintum-search-for-solr' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'braintum-search-for-solr' ) ); ?>
			</form>

			<?php if ( null !== $tested ) : ?>
				<h2><?php esc_html_e( 'Diagnosis', 'braintum-search-for-solr' ); ?></h2>
				<?php $this->render_report( $tested ); ?>

				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the Tools screen. */
						esc_html__( 'Schema changes, reindexing and the run history are on the %s screen.', 'braintum-search-for-solr' ),
						sprintf(
							'<a href="%s">%s</a>',
							esc_url( admin_url( 'admin.php?page=' . ToolsScreen::SLUG ) ),
							esc_html__( 'Tools', 'braintum-search-for-solr' )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the backend chooser, when there is a choice to make.
	 *
	 * Absent when one backend is registered, which is every ordinary install. A
	 * dropdown offering one option is a question with one answer.
	 *
	 * The chooser exists at all because ADR-010 turns on it: Braintum Search Cloud, if
	 * it is ever built, is "a backend choice, not a paywall", and the difference
	 * between an interface to a paid service and locked functionality is whether
	 * the alternative sits in a dropdown beside it or behind an upgrade badge.
	 * An add-on registering its own backend lands here too, with no code change.
	 *
	 * @return void
	 */
	private function render_backend_choice(): void {
		if ( null === $this->backends ) {
			return;
		}

		$available = $this->backends->available();

		if ( count( $available ) < 2 ) {
			return;
		}

		$selected = $this->backends->selected_id();

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="braintum-search-backend"><?php esc_html_e( 'Search backend', 'braintum-search-for-solr' ); ?></label>
				</th>
				<td>
					<select id="braintum-search-backend" name="backend" <?php disabled( $this->config->is_locked( 'connection.backend' ) ); ?>>
						<?php foreach ( $available as $id => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $selected, (string) $id ); ?>>
								<?php echo esc_html( (string) $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Which search engine answers this site. Each backend keeps its own credentials, so switching back does not mean typing them again.', 'braintum-search-for-solr' ); ?>
						<?php $this->render_lock_note( 'connection.backend' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Store the chosen backend, if one was submitted and is real.
	 *
	 * A name that is not registered is discarded rather than stored. Storing it
	 * would leave the site pointed at a backend that does not exist, and the
	 * registry would quietly fall back to another one — which is worse than
	 * refusing, because the screen would then disagree with what is running.
	 *
	 * @param string $chosen Backend identifier submitted, or an empty string.
	 * @return void
	 */
	private function save_backend_choice( string $chosen ): void {
		if ( null === $this->backends || '' === $chosen ) {
			return;
		}

		if ( $this->backends->has( $chosen ) ) {
			$this->config->set( 'connection.backend', $chosen );
		}
	}

	/**
	 * Explain why a field cannot be edited, when it cannot.
	 *
	 * Two answers, and they send an administrator to completely different
	 * places: a constant is in a file on this server, and a network pin is a
	 * checkbox somebody else ticked. Saying the wrong one wastes an afternoon.
	 *
	 * @param string $path Configuration dot path.
	 * @return void
	 */
	private function render_lock_note( string $path ): void {
		switch ( $this->config->lock_source( $path ) ) {
			case 'constant':
				echo '<br/><strong>' . esc_html__( 'Set in wp-config.php.', 'braintum-search-for-solr' ) . '</strong>';
				break;

			case 'network':
				echo '<br/><strong>' . esc_html__( 'Set for the whole network, and locked. A network administrator can change it under Network Admin, Settings, Braintum Search.', 'braintum-search-for-solr' ) . '</strong>';
				break;
		}
	}

	/**
	 * Render the diagnostic report.
	 *
	 * @param DiagnosticReport $report Report to render.
	 * @return void
	 */
	private function render_report( DiagnosticReport $report ): void {
		$marks = array(
			'pass' => '&#10003;',
			'warn' => '&#9888;',
			'fail' => '&#10007;',
		);

		$colours = array(
			'pass' => '#008a20',
			'warn' => '#bd8600',
			'fail' => '#d63638',
		);

		echo '<table class="widefat striped" style="max-width:900px">';
		echo '<tbody>';

		foreach ( $report->checks() as $check ) {
			$status = (string) $check['status'];

			printf(
				'<tr><td style="width:2em;color:%s;font-weight:700">%s</td><td style="width:14em"><strong>%s</strong></td><td>%s',
				esc_attr( $colours[ $status ] ?? 'inherit' ),
				esc_html( html_entity_decode( $marks[ $status ] ?? '', ENT_QUOTES, 'UTF-8' ) ),
				esc_html( (string) $check['label'] ),
				esc_html( (string) $check['detail'] )
			);

			if ( ! empty( $check['context']['operations'] ) ) {
				echo '<ul style="list-style:disc;margin:.5em 0 0 1.5em">';

				foreach ( (array) $check['context']['operations'] as $operation ) {
					echo '<li><code>' . esc_html( (string) $operation ) . '</code></li>';
				}

				echo '</ul>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
