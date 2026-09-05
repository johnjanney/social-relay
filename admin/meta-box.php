<?php
/**
 * The per-post meta box.
 *
 * @package Social_Relay
 *
 * @var WP_Post $post Provided by SRL_Post_Meta::render().
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$srl_post_id  = (int) $post->ID;
$srl_status   = SRL_Post_Meta::get_status( $srl_post_id );
$srl_stored   = get_post_meta( $srl_post_id, SRL_Post_Meta::META_ENABLED, true );
$srl_checked  = '' === $srl_stored ? SRL_Settings::is_enabled() : ( '1' === $srl_stored );
$srl_override = (string) get_post_meta( $srl_post_id, SRL_Post_Meta::META_DELAY_OVERRIDE, true );

wp_nonce_field( SRL_Post_Meta::NONCE_ACTION, SRL_Post_Meta::NONCE_FIELD );
?>
<p>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( SRL_Post_Meta::FIELD_ENABLED ); ?>" value="1" <?php checked( $srl_checked ); ?> />
		<?php esc_html_e( 'Post to X', 'social-relay' ); ?>
	</label>
</p>

<p>
	<label for="srl-delay-override"><?php esc_html_e( 'Delay for this post (minutes)', 'social-relay' ); ?></label>
	<input
		type="number"
		id="srl-delay-override"
		name="<?php echo esc_attr( SRL_Post_Meta::FIELD_DELAY ); ?>"
		value="<?php echo esc_attr( $srl_override ); ?>"
		min="0"
		max="4320"
		class="small-text"
		placeholder="<?php esc_attr_e( 'default', 'social-relay' ); ?>"
	/>
	<span class="description"><?php esc_html_e( 'Leave blank to use the site default.', 'social-relay' ); ?></span>
</p>

<p>
	<strong><?php esc_html_e( 'Status', 'social-relay' ); ?>:</strong><br />
	<?php
	// status_line() escapes every value it interpolates; see SRL_Post_Meta.
	echo wp_kses_post( SRL_Post_Meta::status_line( $srl_post_id ) );
	?>
</p>

<?php if ( '1' === (string) get_post_meta( $srl_post_id, SRL_Post_Meta::META_IMAGE_OMITTED, true ) ) : ?>
	<p class="description">
		<?php
		printf(
			/* translators: %s: reason the image was omitted */
			esc_html__( 'The featured image was not attached: %s', 'social-relay' ),
			esc_html( (string) get_post_meta( $srl_post_id, SRL_Post_Meta::META_IMAGE_REASON, true ) )
		);
		?>
	</p>
<?php endif; ?>

<?php if ( SRL_Post_Meta::STATUS_SCHEDULED === $srl_status ) : ?>
	<p>
		<button
			type="submit"
			class="button"
			name="srl_action"
			value="cancel"
		><?php esc_html_e( 'Cancel scheduled post', 'social-relay' ); ?></button>
	</p>
<?php endif; ?>

<?php if ( in_array( $srl_status, array( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::STATUS_FAILED ), true ) ) : ?>
	<p>
		<button
			type="submit"
			class="button"
			name="srl_action"
			value="repost"
			onclick="return confirm('<?php echo esc_js( __( 'Post this to X again? This creates a second post and is billed again.', 'social-relay' ) ); ?>');"
		><?php esc_html_e( 'Repost now', 'social-relay' ); ?></button>
	</p>
	<p class="description">
		<?php esc_html_e( 'This is the only way to post the same article twice.', 'social-relay' ); ?>
	</p>
<?php endif; ?>
