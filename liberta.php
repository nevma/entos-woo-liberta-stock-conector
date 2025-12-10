<?php
/**
 * Plugin Name: Nevma Inventory Webhook Liberta
 * Description: Receives inventory updates from external partners Liberta
 * Version: 1.0.0
 * Author: Nevma
 */



/**
 * Inventory Webhook System for WooCommerce
 *
 * This system receives inventory updates from external partners
 * and updates WooCommerce product stock accordingly.
 *
 * @package InventoryWebhook
 * @version 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if WooCommerce is active
 *
 * @return bool
 */
function nvm_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Display admin notice if WooCommerce is not active
 *
 * @return void
 */
function nvm_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Nevma Inventory Webhook Liberta', 'nevma-inventory-webhook' ); ?></strong>
			<?php esc_html_e( 'requires WooCommerce to be installed and active.', 'nevma-inventory-webhook' ); ?>
		</p>
	</div>
	<?php
}

// Check if WooCommerce is active before loading the plugin
if ( ! nvm_is_woocommerce_active() ) {
	add_action( 'admin_notices', 'nvm_woocommerce_missing_notice' );
	return;
}

define( 'NVM_WEBHOOK_API_KEY', 'sdnafnHUIacJOKLbxuwkaheo823u90ujio@H*!9hdewiuhe2309' );
define( 'NVM_WEBHOOK_LOGS', false );


/**
 * Initialize the inventory webhook system
 */
add_action( 'init', 'nvm_init_inventory_webhook' );

/**
 * Initialize webhook REST API endpoint
 *
 * @return void
 */
function nvm_init_inventory_webhook() {
	add_action( 'rest_api_init', 'nvm_register_webhook_endpoint' );
}

/**
 * Register the webhook REST API endpoint
 *
 * @return void
 */
function nvm_register_webhook_endpoint() {
	register_rest_route(
		'nvm/v1',
		'/inventory-liberta',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'nvm_handle_inventory_webhook',
			'permission_callback' => 'nvm_validate_webhook_auth',
		)
	);
}

/**
 * Validate webhook authentication
 *
 * This function checks for a valid API key in the request headers
 * You should set the API key in wp-config.php: define('NVM_WEBHOOK_API_KEY', 'your-secret-key');
 *
 * @param WP_REST_Request $request The REST request object.
 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
 */
function nvm_validate_webhook_auth( $request ) {

	return true;

	// --- Step 1: Get client IP ---
	$ip = $request->get_header( 'x-forwarded-for' );
	if ( ! $ip ) {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	}

	$ip          = trim( explode( ',', $ip )[0] ); // in case of "IP1, IP2"
	$allowed_ips = array(
		'185.186.84.147',
		'185.186.84.30',
	);

	if ( ! in_array( $ip, $allowed_ips, true ) ) {
		nvm_log_webhook_error( 'Unauthorized IP', array( 'ip' => $ip ) );
		return new WP_REST_Response(
			array( 'success' => false ),
			200 // HTTP status code; use 200 if Liberta expects a valid response
		);
		return false;
	}

	return true;

	// // remove this line to enable authentication
	// return true;

	// Get API key from headers
	$provided_key = $request->get_header( 'X-NVM-API-Key' );

	// Get expected API key from wp-config.php
	$expected_key = defined( 'NVM_WEBHOOK_API_KEY' ) ? NVM_WEBHOOK_API_KEY : '';

	// Check if API key is configured
	if ( empty( $expected_key ) ) {
		nvm_log_webhook_error( 'API key not configured in wp-config.php' );
		return new WP_Error(
			'webhook_config_error',
			'Webhook API key not configured',
			array( 'status' => 500 )
		);
	}

	// Validate API key
	if ( empty( $provided_key ) || ! hash_equals( $expected_key, $provided_key ) ) {
		nvm_log_webhook_error( 'Invalid API key provided', array( 'provided_key' => $provided_key ) );
		return new WP_Error(
			'webhook_auth_failed',
			'Invalid API key',
			array( 'status' => 401 )
		);
	}

	return true;
}

/**
 * Handle incoming inventory webhook data
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response object or error.
 */
/**
 * Handle incoming inventory webhook data (single or batch under "rows").
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error
 */
function nvm_handle_inventory_webhook( $request ) {
	try {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Expected a JSON body.', 'nevma-inventory-webhook' ),
				array( 'status' => 400 )
			);
		}

		// Normalize to an array of row payloads.
		if ( isset( $params['rows'] ) && is_array( $params['rows'] ) ) {
			$rows = $params['rows'];
		} elseif ( ( isset( $params['code'] ) || isset( $params['codeentos'] ) ) && isset( $params['diathesima'] ) ) {
			$rows = array( $params ); // backward-compatible single object
		} else {
			// Missing both a rows array and the minimal single-object fields.
			return new WP_Error(
				'rest_missing_callback_param',
				__( 'Missing parameter(s): code, codeentos, diathesima(in each row or at top level).', 'nevma-inventory-webhook' ),
				array(
					'status' => 400,
					'params' => array( 'code', 'codeentos', 'diathesima' ),
				)
			);
		}

		$results = array();
		$errors  = array();

		nvm_log_webhook_activity(
			'Webhook received (batch)',
			array(
				'rows_count'   => count( $rows ),
				'raw_received' => isset( $params['success'], $params['totalcount'] ) ? array(
					'success'    => (bool) $params['success'],
					'totalcount' => (int) ( $params['totalcount'] ?? 0 ),
				) : null,
			)
		);

		foreach ( $rows as $index => $row ) {
			// Sanity: each row must be an array.
			if ( ! is_array( $row ) ) {
				$errors[] = array(
					'index'   => $index,
					'code'    => null,
					'message' => __( 'Row is not an object.', 'nevma-inventory-webhook' ),
				);
				continue;
			}

			// Validate & normalize one row.
			$validated = nvm_validate_and_normalize_row( $row );
			if ( is_wp_error( $validated ) ) {
				$errors[] = array(
					'index'   => $index,
					'code'    => isset( $row['code'] ) ? sanitize_text_field( $row['code'] ) : null,
					'message' => $validated->get_error_message(),
				);
				continue;
			}

			list( $code, $codeentos, $diathesima ) = $validated;

			// Find product.
			$product = nvm_find_product_by_code( $code, $codeentos );

			if ( ! $product ) {
				$msg = sprintf( __( 'Product not found for code: %s', 'nevma-inventory-webhook' ), $code );
				nvm_log_webhook_error( $msg );
				$errors[] = array(
					'index'   => $index,
					'code'    => $code,
					'message' => $msg,
				);
				continue;
			}

			// Update inventory.
			// $update_result = nvm_update_product_inventory(
			// $product,
			// $diathesima
			// );

			// use action scheduler to update the product inventory
			as_enqueue_async_action( 'hook_nvm_update_product_inventory', array( $product->get_id(), $diathesima ) );
		}

		$response_data = array(
			'success' => empty( $errors ),
			// 'processed' => count( $results ),
			// 'failed'    => count( $errors ),
			// 'results'    => $results,
			// 'errors'     => $errors,
			// 'updated_at' => current_time( 'Y-m-d H:i:s' ),
			// 'version'    => '1.0.0',
		);

		nvm_log_webhook_activity( 'Inventory batch processed', $response_data );

		return new WP_REST_Response( $response_data, empty( $errors ) ? 200 : 207 ); // 207 Multi-Status on partial failures

	} catch ( Exception $e ) {
		$error_message = 'Webhook processing failed: ' . $e->getMessage();
		nvm_log_webhook_error( $error_message );

		return new WP_Error(
			'webhook_processing_failed',
			$error_message,
			array( 'status' => 500 )
		);
	}
}

/**
 * Validate and normalize a single row.
 *
 * Rules:
 * - Require diathesima (int >= 0).
 * - At least one of code or codeentos must be a non-empty string after sanitization.
 * - Allow the other identifier to be empty.
 *
 * @param array $row Row payload.
 * @return array|WP_Error [ $code, $codeentos, $diathesima, $delivery_schedule ]
 */
function nvm_validate_and_normalize_row( array $row ) {
	$required_numeric = array( 'diathesima' );

	$missing = array();
	foreach ( $required_numeric as $key ) {
		if ( ! array_key_exists( $key, $row ) ) {
			$missing[] = $key;
		}
	}

	$code      = isset( $row['code'] ) ? sanitize_text_field( (string) $row['code'] ) : '';
	$codeentos = isset( $row['codeentos'] ) ? sanitize_text_field( (string) $row['codeentos'] ) : '';

	// Require at least one non-empty identifier.
	if ( '' === $code && '' === $codeentos ) {
		return new WP_Error(
			'invalid_row_params',
			__( 'Invalid row: either "code" or "codeentos" must be provided.', 'nevma-inventory-webhook' )
		);
	}

	$diathesima = (int) $row['diathesima'];

	// $delivery_schedule = nvm_extract_delivery_schedule_from_array( $row );

	// Return normalized tuple. Keep both identifiers; downstream can decide which to use.
	return array( $code, $codeentos, $diathesima );
}

/**
 * Extract delivery schedule from an associative array containing
 * delivdate1..5 and qty1..5 pairs. Skips "1900-01-01" and zero qty.
 *
 * @param array $params
 * @return array[]
 */
function nvm_extract_delivery_schedule_from_array( array $params ) {
	$deliveries = array();

	for ( $i = 1; $i <= 5; $i++ ) {
		$date = isset( $params[ "delivdate{$i}" ] ) ? (string) $params[ "delivdate{$i}" ] : '';
		$qty  = isset( $params[ "qty{$i}" ] ) ? (int) $params[ "qty{$i}" ] : 0;

		if ( empty( $date ) || '1900-01-01' === $date || 0 === $qty ) {
			continue;
		}

		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( $d && $d->format( 'Y-m-d' ) === $date ) {
			$deliveries[] = array(
				'date'     => $date,
				'quantity' => $qty,
			);
		}
	}

	return $deliveries;
}


/**
 * Extract delivery schedule from request parameters
 *
 * @param WP_REST_Request $request The REST request object.
 * @return array Array of delivery schedules with dates and quantities.
 */
function nvm_extract_delivery_schedule( $request ) {
	$deliveries = array();

	// Process up to 5 delivery slots
	for ( $i = 1; $i <= 5; $i++ ) {
		$delivery_date = $request->get_param( "delivdate{$i}" );
		$delivery_qty  = $request->get_param( "qty{$i}" );

		// Skip empty or null dates (1900-01-01 indicates no delivery)
		if ( empty( $delivery_date ) || '1900-01-01' === $delivery_date || 0 === intval( $delivery_qty ) ) {
			continue;
		}

		// Validate date format
		$date_obj = DateTime::createFromFormat( 'Y-m-d', $delivery_date );
		if ( $date_obj && $date_obj->format( 'Y-m-d' ) === $delivery_date ) {
			$deliveries[] = array(
				'date'     => $delivery_date,
				'quantity' => intval( $delivery_qty ),
			);
		}
	}

	return $deliveries;
}

/**
 * Find WooCommerce product by code
 *
 * First tries to find by SKU, then by custom meta field '_nvm_product_code'
 *
 * @param string $code Product code to search for.
 * @return WC_Product|null Product object if found, null otherwise.
 */
function nvm_find_product_by_code( $code, $codeentos ) {
	// First, try to find by SKU
	$product_id = wc_get_product_id_by_sku( $codeentos );

	if ( $product_id ) {
		return wc_get_product( $product_id );
	}

	// If not found by SKU, search by custom meta field
	$products = get_posts(
		array(
			'post_type'      => 'product',
			'meta_query'     => array(
				array(
					'key'   => 'liberta_code',
					'value' => $code,
				),
			),
			'posts_per_page' => 1,
		)
	);

	if ( ! empty( $products ) ) {
		return wc_get_product( $products[0]->ID );
	}

	return null;
}

// hook to update the product inventory on async action
add_action( 'hook_nvm_update_product_inventory', 'nvm_update_product_inventory_schedule', 10, 2 );

/**
 * Update product inventory on async action
 *
 * @param array $params Parameters.
 * @return void
 */
function nvm_update_product_inventory_schedule( $product_id, $diathesima ) {

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return;
	}

	nvm_update_product_inventory( $product, $diathesima );
}

/**
 * Update product inventory information
 *
 * @param WC_Product $product         WooCommerce product object.
 * @param int        $diathesima      Available stock quantity.
 * @param array      $delivery_schedule Array of delivery schedules.
 * @return array|WP_Error Update result or error.
 */
function nvm_update_product_inventory( $product, $diathesima ) {
	try {

		$changed = false;

		$old_stock        = (int) $product->get_stock_quantity();
		$old_manage_stock = (bool) $product->get_manage_stock();
		$old_status       = $product->get_stock_status(); // 'instock' | 'outofstock' | 'onbackorder'

		$new_status = ( $diathesima > 0 ) ? 'instock' : 'outofstock';

		// Enable stock management only if not already enabled.
		if ( true !== $old_manage_stock ) {
			$product->set_manage_stock( true );
			$changed = true;
		}

		// Update stock qty only if it actually changes.
		if ( (int) $diathesima !== $old_stock ) {
			$product->set_stock_quantity( (int) $diathesima );
			$changed = true;
		}

		// Update stock status only if it changes.
		if ( $new_status !== $old_status ) {
			$product->set_stock_status( $new_status );
			$changed = true;
		}

		if ( $changed ) {
			$product->update_meta_data( '_nvm_last_webhook_liberta_update', current_time( 'Y-m-d H:i:s' ) );
			$product->save();
		}

		// After saving variation, update parent variable stock status.
		if ( $changed && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			$parent    = wc_get_product( $parent_id );

			if ( $parent && $parent->is_type( 'variable' ) ) {
				// Force WooCommerce to recalc parent stock status.
				wc_update_product_stock_status( $parent_id );
				$parent->save();
			}
		}

		// Trigger stock status change hooks for other plugins/themes
		do_action( 'nvm_inventory_updated', $product, $diathesima, $old_stock );

		return array(
			'success'   => true,
			'old_stock' => $old_stock,
			'new_stock' => $diathesima,
		);

	} catch ( Exception $e ) {
		return new WP_Error(
			'inventory_update_failed',
			'Failed to update product inventory: ' . $e->getMessage(),
			array( 'status' => 500 )
		);
	}
}

/**
 * Log webhook activity for debugging and monitoring
 *
 * @param string $message Log message.
 * @param array  $context Additional context data.
 * @return void
 */
function nvm_log_webhook_activity( $message, $context = array() ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$log_entry = array(
			'timestamp' => current_time( 'Y-m-d H:i:s' ),
			'message'   => $message,
			'context'   => $context,
		);

		if ( defined( 'NVM_WEBHOOK_LOGS' ) && NVM_WEBHOOK_LOGS ) {
			error_log( '[NVM Webhook] ' . wp_json_encode( $log_entry ) );
		}
	}

	// Optionally store in database for dashboard viewing
	nvm_store_webhook_log( $message, $context, 'info' );
}

/**
 * Log webhook errors
 *
 * @param string $message Error message.
 * @param array  $context Additional context data.
 * @return void
 */
function nvm_log_webhook_error( $message, $context = array() ) {
	$log_entry = array(
		'timestamp' => current_time( 'Y-m-d H:i:s' ),
		'error'     => $message,
		'context'   => $context,
	);

	if ( defined( 'NVM_WEBHOOK_LOGS' ) && NVM_WEBHOOK_LOGS ) {
		error_log( '[NVM Webhook ERROR] ' . wp_json_encode( $log_entry ) );
	}

	// Store error in database
	nvm_store_webhook_log( $message, $context, 'error' );
}

/**
 * Store webhook logs in database for admin dashboard viewing
 *
 * @param string $message Log message.
 * @param array  $context Context data.
 * @param string $level   Log level (info, error, warning).
 * @return void
 */
function nvm_store_webhook_log( $message, $context = array(), $level = 'info' ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'nvm_webhook_logs';

	// Create table if it doesn't exist
	nvm_create_webhook_logs_table();

	$wpdb->insert(
		$table_name,
		array(
			'message'    => $message,
			'context'    => wp_json_encode( $context ),
			'level'      => $level,
			'created_at' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s' )
	);
}

/**
 * Create webhook logs table
 *
 * @return void
 */
function nvm_create_webhook_logs_table() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'nvm_webhook_logs';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        message text NOT NULL,
        context longtext,
        level varchar(20) DEFAULT 'info',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY level (level),
        KEY created_at (created_at)
    ) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Admin dashboard to view webhook logs
 */
add_action( 'admin_menu', 'nvm_add_webhook_admin_menu', 10 );

/**
 * Add webhook admin menu
 * Checks if SAP Connector menu exists and adds as submenu if available.
 * Otherwise adds under Tools menu.
 *
 * @return void
 */
function nvm_add_webhook_admin_menu() {
	global $menu;

	$sap_menu_exists = false;
	if ( is_array( $menu ) ) {
		foreach ( $menu as $menu_item ) {
			if ( isset( $menu_item[2] ) && 'sap-connector' === $menu_item[2] ) {
				$sap_menu_exists = true;
				break;
			}
		}
	}

	if ( $sap_menu_exists ) {
		// Add as submenu under SAP Connector if it exists.
		add_submenu_page(
			'sap-connector',
			'Liberta Inventory',
			'Liberta Inventory',
			'manage_options',
			'nvm-webhook-logs',
			'nvm_webhook_logs_page'
		);
	} else {
		// Add under Tools menu if SAP Connector doesn't exist.
		add_management_page(
			'Inventory Webhook Logs',
			'Webhook Logs',
			'manage_options',
			'nvm-webhook-logs',
			'nvm_webhook_logs_page'
		);
	}
}

/**
 * Display webhook logs admin page
 *
 * @return void
 */
function nvm_webhook_logs_page() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'nvm_webhook_logs';

	// Handle log cleanup
	if ( isset( $_POST['clear_logs'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'clear_webhook_logs' ) ) {
		$wpdb->query( "TRUNCATE TABLE $table_name" );
		echo '<div class="notice notice-success"><p>Webhook logs cleared successfully.</p></div>';
	}

	// Get recent logs
	$logs = $wpdb->get_results(
		"SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 100"
	);

	?>
	<div class="wrap">
		<h1>Inventory Webhook Logs</h1>

		<p><strong>Webhook URL:</strong> <code><?php echo esc_url( rest_url( 'nvm/v1/inventory-liberta' ) ); ?></code></p>

		<form method="post">
			<?php wp_nonce_field( 'clear_webhook_logs' ); ?>
			<input type="submit" name="clear_logs" class="button" value="Clear All Logs"
					onclick="return confirm('Are you sure you want to clear all webhook logs?')">
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Time</th>
					<th>Level</th>
					<th>Message</th>
					<th>Context</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr>
						<td colspan="4">No webhook logs found.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td>
								<span class="nvm-log-level nvm-log-<?php echo esc_attr( $log->level ); ?>">
									<?php echo esc_html( ucfirst( $log->level ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $log->message ); ?></td>
							<td>
								<?php if ( ! empty( $log->context ) ) : ?>
									<details>
										<summary>View Details</summary>
										<pre><?php echo esc_html( $log->context ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<style>
	.nvm-log-level {
		padding: 2px 6px;
		border-radius: 3px;
		font-size: 11px;
		font-weight: bold;
	}
	.nvm-log-info { background: #d4edda; color: #155724; }
	.nvm-log-error { background: #f8d7da; color: #721c24; }
	.nvm-log-warning { background: #fff3cd; color: #856404; }
	</style>
	<?php
}

/**
 * Utility function to get webhook logs for external use
 *
 * @param int    $limit Number of logs to retrieve.
 * @param string $level Filter by log level.
 * @return array Array of log entries.
 */
function nvm_get_webhook_logs( $limit = 50, $level = '' ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'nvm_webhook_logs';

	$sql = "SELECT * FROM $table_name";

	if ( ! empty( $level ) ) {
		$sql .= $wpdb->prepare( ' WHERE level = %s', $level );
	}

	$sql .= ' ORDER BY created_at DESC';

	if ( $limit > 0 ) {
		$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
	}

	return $wpdb->get_results( $sql );
}

/**
 * Add custom action hook that fires when inventory is updated
 * Other plugins/themes can hook into this
 *
 * Example usage:
 * add_action('nvm_inventory_updated', function($product, $new_stock, $old_stock) {
 *     // Your custom logic here
 * });
 */
