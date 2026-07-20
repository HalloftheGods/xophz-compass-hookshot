<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www.hallofthegods.com
 * @since      1.0.0
 * @package    Xophz_Compass_Hookshot
 * @subpackage Xophz_Compass_Hookshot/admin
 */

class Xophz_Compass_Hookshot_Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function init() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_compass_webhook', [ $this, 'save_webhook_meta' ] );
	}

	public function add_meta_boxes() {
		add_meta_box(
			'hookshot_config',
			__( 'Webhook Configuration', 'xophz-compass-hookshot' ),
			[ $this, 'render_config_meta_box' ],
			'compass_webhook',
			'normal',
			'high'
		);

		add_meta_box(
			'hookshot_log_details',
			__( 'Webhook Log Details', 'xophz-compass-hookshot' ),
			[ $this, 'render_log_meta_box' ],
			'compass_wh_log',
			'normal',
			'high'
		);
	}

	public function render_config_meta_box( $post ) {
		wp_nonce_field( 'hookshot_save_meta', 'hookshot_meta_nonce' );

		$type   = get_post_meta( $post->ID, 'hookshot_type', true ) ?: 'incoming';
		$status = get_post_meta( $post->ID, 'hookshot_status', true ) ?: 'active';
		$secret = get_post_meta( $post->ID, 'hookshot_secret', true ) ?: wp_generate_password( 24, false );
		$url    = get_post_meta( $post->ID, 'hookshot_target_url', true );
		$event  = get_post_meta( $post->ID, 'hookshot_trigger_event', true );

		?>
		<style>
			.hookshot-meta-table { width: 100%; border-collapse: collapse; }
			.hookshot-meta-table th { text-align: left; padding: 10px; width: 200px; vertical-align: top; }
			.hookshot-meta-table td { padding: 10px; }
			.hookshot-meta-table input[type="text"], .hookshot-meta-table select { width: 100%; max-width: 400px; }
			.hookshot-meta-table code { background: #f0f0f0; padding: 4px; border-radius: 3px; }
		</style>
		<table class="hookshot-meta-table">
			<tr>
				<th><label for="hookshot_type"><?php _e( 'Webhook Type', 'xophz-compass-hookshot' ); ?></label></th>
				<td>
					<select name="hookshot_type" id="hookshot_type">
						<option value="incoming" <?php selected( $type, 'incoming' ); ?>>Incoming (Receive Data)</option>
						<option value="outgoing" <?php selected( $type, 'outgoing' ); ?>>Outgoing (Send Data)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="hookshot_status"><?php _e( 'Status', 'xophz-compass-hookshot' ); ?></label></th>
				<td>
					<select name="hookshot_status" id="hookshot_status">
						<option value="active" <?php selected( $status, 'active' ); ?>>Active</option>
						<option value="inactive" <?php selected( $status, 'inactive' ); ?>>Inactive</option>
					</select>
				</td>
			</tr>
			<tr id="row_incoming" <?php if ( $type === 'outgoing' ) echo 'style="display:none;"'; ?>>
				<th><label for="hookshot_secret"><?php _e( 'Incoming Secret Key', 'xophz-compass-hookshot' ); ?></label></th>
				<td>
					<input type="text" name="hookshot_secret" id="hookshot_secret" value="<?php echo esc_attr( $secret ); ?>" />
					<p class="description">Endpoint URL: <code><?php echo esc_url( rest_url( 'xophz/v1/hookshot/incoming/' . $secret ) ); ?></code></p>
				</td>
			</tr>
			<tr id="row_outgoing_url" <?php if ( $type === 'incoming' ) echo 'style="display:none;"'; ?>>
				<th><label for="hookshot_target_url"><?php _e( 'Target URL', 'xophz-compass-hookshot' ); ?></label></th>
				<td>
					<input type="url" name="hookshot_target_url" id="hookshot_target_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://hooks.zapier.com/..." />
				</td>
			</tr>
			<tr id="row_outgoing_event" <?php if ( $type === 'incoming' ) echo 'style="display:none;"'; ?>>
				<th><label for="hookshot_trigger_event"><?php _e( 'Trigger Action (WP Hook)', 'xophz-compass-hookshot' ); ?></label></th>
				<td>
					<input type="text" name="hookshot_trigger_event" id="hookshot_trigger_event" value="<?php echo esc_attr( $event ); ?>" placeholder="e.g., user_register" />
				</td>
			</tr>
		</table>
		<script>
			document.getElementById('hookshot_type').addEventListener('change', function() {
				if (this.value === 'incoming') {
					document.getElementById('row_incoming').style.display = 'table-row';
					document.getElementById('row_outgoing_url').style.display = 'none';
					document.getElementById('row_outgoing_event').style.display = 'none';
				} else {
					document.getElementById('row_incoming').style.display = 'none';
					document.getElementById('row_outgoing_url').style.display = 'table-row';
					document.getElementById('row_outgoing_event').style.display = 'table-row';
				}
			});
		</script>
		<?php
	}

	public function save_webhook_meta( $post_id ) {
		if ( ! isset( $_POST['hookshot_meta_nonce'] ) || ! wp_verify_nonce( $_POST['hookshot_meta_nonce'], 'hookshot_save_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = [ 'hookshot_type', 'hookshot_status', 'hookshot_secret', 'hookshot_target_url', 'hookshot_trigger_event' ];

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
	}

	public function render_log_meta_box( $post ) {
		$parent_id = get_post_meta( $post->ID, 'wh_log_parent_id', true );
		$direction = get_post_meta( $post->ID, 'wh_log_direction', true );
		$status    = get_post_meta( $post->ID, 'wh_log_status', true );
		$payload   = get_post_meta( $post->ID, 'wh_log_payload', true );
		$headers   = get_post_meta( $post->ID, 'wh_log_headers', true );
		$response  = get_post_meta( $post->ID, 'wh_log_response', true );

		?>
		<style>
			.hookshot-log-table { width: 100%; border-collapse: collapse; }
			.hookshot-log-table th { text-align: left; padding: 10px; width: 150px; border-bottom: 1px solid #ddd; }
			.hookshot-log-table td { padding: 10px; border-bottom: 1px solid #ddd; }
			.hookshot-log-table pre { background: #1e1e1e; color: #d4d4d4; padding: 10px; border-radius: 4px; overflow-x: auto; }
		</style>
		<table class="hookshot-log-table">
			<tr>
				<th>Direction</th>
				<td><strong><?php echo esc_html( strtoupper( $direction ) ); ?></strong></td>
			</tr>
			<tr>
				<th>HTTP Status</th>
				<td><code><?php echo esc_html( $status ); ?></code></td>
			</tr>
			<?php if ( $parent_id ) : ?>
			<tr>
				<th>Webhook Config</th>
				<td><a href="<?php echo get_edit_post_link( $parent_id ); ?>">View Configuration</a></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th>Payload Data</th>
				<td>
					<?php 
						$decoded_payload = json_decode( $payload, true );
						$display_payload = $decoded_payload ? json_encode( $decoded_payload, JSON_PRETTY_PRINT ) : $payload;
					?>
					<pre><?php echo esc_html( $display_payload ); ?></pre>
				</td>
			</tr>
			<?php if ( $direction === 'incoming' && $headers ) : ?>
			<tr>
				<th>Request Headers</th>
				<td>
					<?php 
						$decoded_headers = json_decode( $headers, true );
						$display_headers = $decoded_headers ? json_encode( $decoded_headers, JSON_PRETTY_PRINT ) : $headers;
					?>
					<pre><?php echo esc_html( $display_headers ); ?></pre>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( $direction === 'outgoing' && $response ) : ?>
			<tr>
				<th>Response Body</th>
				<td>
					<?php 
						$decoded_response = json_decode( $response, true );
						$display_response = $decoded_response ? json_encode( $decoded_response, JSON_PRETTY_PRINT ) : $response;
					?>
					<pre><?php echo esc_html( $display_response ); ?></pre>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}
}
