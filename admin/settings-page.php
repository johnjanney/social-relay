<?php
/**
 * The settings page.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$srl_settings   = SRL_Settings::all();
$srl_cred_state = SRL_Settings::credentials_state();
$srl_cron_state = SRL_Cron_Health::status();
$srl_month      = gmdate( 'Y-m' );
$srl_usage      = SRL_Usage::for_month( $srl_month );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Social Relay', 'social-relay' ); ?></h1>

	<?php settings_errors( SRL_Settings::OPTION ); ?>

	<?php if ( SRL_Settings::CRED_UNREADABLE === $srl_cred_state ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'The stored API keys cannot be read.', 'social-relay' ); ?></strong>
				<?php esc_html_e( 'This site\'s security salts appear to have changed, which makes previously saved keys undecryptable. Enter the four keys again below. The keys in your X account are fine and do not need regenerating.', 'social-relay' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Cron health', 'social-relay' ); ?></h2>
	<?php
	$srl_last = SRL_Cron_Health::last_run();
	switch ( $srl_cron_state ) {
		case 'ok':
			printf(
				'<p><span style="color:#008a20;">&#9679;</span> %s</p>',
				sprintf(
					/* translators: %s: local time */
					esc_html__( 'Real cron is running. Last run: %s.', 'social-relay' ),
					esc_html( SRL_Post_Meta::local_time( (int) $srl_last ) )
				)
			);
			break;

		case 'unverified':
			printf(
				'<p><span style="color:#dba617;">&#9679;</span> <strong>%s</strong> %s</p>',
				esc_html__( 'Unverified.', 'social-relay' ),
				esc_html__( 'WP-Cron is still triggered by visitors, so delays will be approximate and can stretch to hours on a quiet site. Set DISABLE_WP_CRON and call wp-cron.php from a real system cron every minute. Until then this panel cannot tell you the truth, because loading this page runs cron itself.', 'social-relay' )
			);
			break;

		case 'never':
			printf(
				'<p><span style="color:#d63638;">&#9679;</span> %s</p>',
				esc_html__( 'Cron has not run since the plugin was activated. Scheduled posts will not go out.', 'social-relay' )
			);
			break;

		default:
			printf(
				'<p><span style="color:#d63638;">&#9679;</span> %s</p>',
				sprintf(
					/* translators: %s: local time */
					esc_html__( 'Cron last ran at %s, which is too long ago. Scheduled posts may be stuck.', 'social-relay' ),
					esc_html( SRL_Post_Meta::local_time( (int) $srl_last ) )
				)
			);
	}
	?>

	<h2><?php esc_html_e( 'API usage this month', 'social-relay' ); ?></h2>
	<?php if ( empty( $srl_usage ) ) : ?>
		<p><?php esc_html_e( 'No API requests yet this month.', 'social-relay' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:40em;">
			<thead><tr>
				<th><?php esc_html_e( 'Endpoint', 'social-relay' ); ?></th>
				<th><?php esc_html_e( 'Requests', 'social-relay' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $srl_usage as $srl_endpoint => $srl_count ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) $srl_endpoint ); ?></code></td>
					<td><?php echo esc_html( (string) $srl_count ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Counted locally, including failed requests, because X bills for those too. Treat this as indicative: the Developer Console is authoritative.', 'social-relay' ); ?>
		</p>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'srl_settings_group' ); ?>

		<h2><?php esc_html_e( 'Settings', 'social-relay' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enabled', 'social-relay' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( SRL_Settings::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $srl_settings['enabled'] ) ); ?> />
						<?php esc_html_e( 'Post new articles to X', 'social-relay' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="srl-delay-value"><?php esc_html_e( 'Default delay', 'social-relay' ); ?></label></th>
				<td>
					<input type="number" id="srl-delay-value" class="small-text" min="0"
						name="<?php echo esc_attr( SRL_Settings::OPTION ); ?>[delay_value]"
						value="<?php echo esc_attr( (string) $srl_settings['delay_value'] ); ?>" />
					<select name="<?php echo esc_attr( SRL_Settings::OPTION ); ?>[delay_unit]">
						<option value="minutes" <?php selected( 'minutes', $srl_settings['delay_unit'] ); ?>><?php esc_html_e( 'minutes', 'social-relay' ); ?></option>
						<option value="hours" <?php selected( 'hours', $srl_settings['delay_unit'] ); ?>><?php esc_html_e( 'hours', 'social-relay' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Maximum 72 hours. The delay means "not before", not "at": the post goes out at the first cron run at or after that time.', 'social-relay' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="srl-prefix"><?php esc_html_e( 'Prefix', 'social-relay' ); ?></label></th>
				<td>
					<input type="text" id="srl-prefix" class="regular-text" maxlength="120"
						name="<?php echo esc_attr( SRL_Settings::OPTION ); ?>[prefix]"
						value="<?php echo esc_attr( (string) $srl_settings['prefix'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="srl-suffix"><?php esc_html_e( 'Suffix', 'social-relay' ); ?></label></th>
				<td>
					<input type="text" id="srl-suffix" class="regular-text" maxlength="120"
						name="<?php echo esc_attr( SRL_Settings::OPTION ); ?>[suffix]"
						value="<?php echo esc_attr( (string) $srl_settings['suffix'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Email on failure', 'social-relay' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( SRL_Settings::OPTION ); ?>[email_on_failure]" value="1" <?php checked( ! empty( $srl_settings['email_on_failure'] ) ); ?> />
						<?php esc_html_e( 'Email the site administrator when a post fails', 'social-relay' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'X API credentials', 'social-relay' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Stored encrypted. A saved key is never shown again; leave a field blank to keep what is stored, or type a new value to replace it.', 'social-relay' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php
			$srl_labels = array(
				'api_key'             => __( 'API Key', 'social-relay' ),
				'api_secret'          => __( 'API Key Secret', 'social-relay' ),
				'access_token'        => __( 'Access Token', 'social-relay' ),
				'access_token_secret' => __( 'Access Token Secret', 'social-relay' ),
			);
			foreach ( $srl_labels as $srl_key => $srl_label ) :
				?>
				<tr>
					<th scope="row"><label for="srl-<?php echo esc_attr( $srl_key ); ?>"><?php echo esc_html( $srl_label ); ?></label></th>
					<td>
						<input type="password" autocomplete="off" class="regular-text"
							id="srl-<?php echo esc_attr( $srl_key ); ?>"
							name="<?php echo esc_attr( SRL_Settings::OPTION ); ?>[<?php echo esc_attr( $srl_key ); ?>]"
							value="" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: masked value */
								esc_html__( 'Stored: %s', 'social-relay' ),
								esc_html( SRL_Settings::masked( $srl_key ) )
							);
							?>
						</p>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2><?php esc_html_e( 'Connectivity test', 'social-relay' ); ?></h2>
	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect marker to choose a message; no state changes here.
	$srl_test_result = isset( $_GET['srl_test'] ) ? sanitize_key( wp_unslash( (string) $_GET['srl_test'] ) ) : '';

	if ( 'ok' === $srl_test_result ) {
		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Test post sent. Check your X timeline, and see the log below for the response.', 'social-relay' ) );
	} elseif ( 'check_ok' === $srl_test_result ) {
		$srl_handle = (string) get_transient( 'srl_checked_handle' );
		delete_transient( 'srl_checked_handle' );
		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			sprintf(
				/* translators: %s: X account handle */
				esc_html__( 'Credentials are valid. X reports these keys belong to @%s. Nothing was posted.', 'social-relay' ),
				esc_html( '' !== $srl_handle ? $srl_handle : '?' )
			)
		);
	} elseif ( '' !== $srl_test_result ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			sprintf(
				/* translators: %s: outcome slug */
				esc_html__( 'The test did not succeed (%s). The response is in the log below.', 'social-relay' ),
				esc_html( $srl_test_result )
			)
		);
	}
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Check credentials', 'social-relay' ); ?></th>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="srl_check_credentials" />
					<?php wp_nonce_field( 'srl_check_credentials' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Check credentials', 'social-relay' ); ?></button>
					<p class="description">
						<?php esc_html_e( 'Asks X who these keys belong to. Costs about $0.010 and posts nothing. Use this one when you just want to know the keys work.', 'social-relay' ); ?>
					</p>
				</form>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Send test post', 'social-relay' ); ?></th>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="srl_send_test" />
					<?php wp_nonce_field( 'srl_send_test' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Send test post', 'social-relay' ); ?></button>
					<p class="description">
						<?php esc_html_e( 'Publishes a short, timestamped message to your X timeline. Costs about $0.015. This is the only check that proves the posting path end to end. It contains no link, so it is billed at the cheap rate; a real post from this plugin always contains a link and costs about $0.200.', 'social-relay' ); ?>
					</p>
				</form>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Recent activity', 'social-relay' ); ?></h2>
	<?php
	$srl_rows = SRL_Log::recent( 50 );
	if ( empty( $srl_rows ) ) :
		?>
		<p><?php esc_html_e( 'Nothing logged yet.', 'social-relay' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'social-relay' ); ?></th>
				<th><?php esc_html_e( 'Post', 'social-relay' ); ?></th>
				<th><?php esc_html_e( 'Event', 'social-relay' ); ?></th>
				<th><?php esc_html_e( 'HTTP', 'social-relay' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'social-relay' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $srl_rows as $srl_row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $srl_row->created_at ); ?></td>
					<td><?php echo esc_html( '' !== $srl_row->post_title ? (string) $srl_row->post_title : '—' ); ?></td>
					<td><?php echo esc_html( (string) $srl_row->event ); ?></td>
					<td><?php echo esc_html( null === $srl_row->http_status ? '—' : (string) $srl_row->http_status ); ?></td>
					<td>
						<?php
						// Never interpreted as markup: an upstream error body
						// is attacker-influenceable and this is an
						// administrator-only screen.
						echo esc_html( mb_substr( (string) $srl_row->message, 0, 300 ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
