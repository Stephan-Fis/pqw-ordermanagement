<?php
/**
 * Plugin Name: PQW Order-Management
 * Plugin URI:  https://fischer-it.eu/pqw-order-management
 * Description: Admin page that displays WooCommerce "in Bearbeitung" orders grouped by customer in a Bootstrap-styled responsive table.
 * Version:     1.7.2-251019_22
 * Author:      Stephan Fischer
 * Author URI:  https://fischer-it.eu
 * Text Domain: pqw-order-management
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQW_Order_Management {

	const VERSION = '1.7.2-251019_22';
	const PLUGIN_SLUG = 'pqw-order-management';

	// Store main menu hook suffix
	protected $menu_hook = '';

	public function __construct() {
		// Enqueue only on our admin page(s)
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

		// Register top-level menu (so future subpages can be added under this)
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Include subpage handlers (admin only)
		if ( is_admin() ) {
			$base = plugin_dir_path( __FILE__ ) . 'pages/';
			require_once $base . 'split-name.php';
			require_once $base . 'split-item.php';
			require_once $base . 'complete-name.php';
			require_once $base . 'complete-item.php';
		}

		// AJAX endpoints for status polling and async processor trigger
		add_action( 'wp_ajax_pqw_queue_status', array( $this, 'ajax_queue_status' ) );
		add_action( 'wp_ajax_pqw_process_queue_async', array( $this, 'ajax_process_queue_async' ) );

		// AJAX endpoints for completion queue
		add_action( 'wp_ajax_pqw_complete_queue_status', array( $this, 'ajax_complete_queue_status' ) );
		add_action( 'wp_ajax_pqw_process_complete_queue_async', array( $this, 'ajax_process_complete_queue_async' ) );

		// Register cleanup hook for cron
		add_action( 'pqw_cleanup_queue', array( $this, 'cleanup_old_queue' ) );
	}

	/**
	 * Enqueue Bootstrap CSS only on our plugin admin page(s).
	 */
	public function admin_enqueue( $hook ) {
		// Only enqueue on our plugin page or related subpages that include the plugin slug in hook
		if ( $hook !== $this->menu_hook && false === strpos( $hook, self::PLUGIN_SLUG ) ) {
			return;
		}

		// Bootstrap 5 local CSS from plugin assets folder
		wp_enqueue_style(
			'pqw-bootstrap',
			plugin_dir_url( __FILE__ ) . 'assets/bootstrap.min.css',
			array(),
			'5.3.2'
		);

		// Optional small admin styles for spacing and responsive behaviour
		wp_add_inline_style( 'pqw-bootstrap', '
			.pqw-orders-table { margin-top: 10px; }
			.pqw-customer-total { font-weight: 600; background: #f8f9fa; }
			.pqw-customer-name { font-weight: 700; }
			.pqw-small { font-size: .9rem; color: #6c757d; }
			.pqw-order-count { font-weight: 500; margin-left: 8px; color: #6c757d; }

			/* Responsive: hide table head and show labels on each cell on small screens */
			@media (max-width: 768px) {
				.pqw-orders-table .table thead { display: none; }
				.pqw-orders-table .table, 
				.pqw-orders-table .table tbody, 
				.pqw-orders-table .table tr, 
				.pqw-orders-table .table td { display: block; width: 100%; }
				.pqw-orders-table .table tr { margin-bottom: 1rem; border-bottom: 1px solid #dee2e6; }
				.pqw-orders-table .table td { 
					padding: .5rem; 
					text-align: left; 
				}
				.pqw-orders-table .table td[data-label]::before {
					content: attr(data-label) ": ";
					font-weight: 700;
					display: inline-block;
					width: 40%;
					color: #212529;
				}
				/* keep subtotal full width */
				.pqw-orders-table .pqw-customer-total td { text-align: right; }
			}
		' );
	}

	/**
	 * Register a top-level admin menu so future subpages can be added under it.
	 */
	public function add_admin_menu() {
		// Only users who can manage WooCommerce or manage_options may see it
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			return;
		}

		// add_menu_page returns the $hook_suffix for the page (landing/overview)
		$this->menu_hook = add_menu_page(
			'PQW Order-Management',
			'PQW Orders',
			'manage_woocommerce',
			self::PLUGIN_SLUG,
			array( $this, 'render_admin_page' ), // landing page
			'dashicons-list-view',
			56
		);

		// Subpages: now callbacks are functions defined in pages/*.php
		add_submenu_page(
			self::PLUGIN_SLUG,
			'Bestellung weiterverarbeiten - Name',
			'Bestellung weiterverarbeiten - Name',
			'manage_woocommerce',
			self::PLUGIN_SLUG . '_split_name',
			'pqw_page_split_name'
		);

		add_submenu_page(
			self::PLUGIN_SLUG,
			'Bestellung weiterverarbeiten - Artikel',
			'Bestellung weiterverarbeiten - Artikel',
			'manage_woocommerce',
			self::PLUGIN_SLUG . '_split_item',
			'pqw_page_split_item'
		);

		add_submenu_page(
			self::PLUGIN_SLUG,
			'Bestellung abschließen - Name',
			'Bestellung abschließen - Name',
			'manage_woocommerce',
			self::PLUGIN_SLUG . '_complete_name',
			'pqw_page_complete_name'
		);

		add_submenu_page(
			self::PLUGIN_SLUG,
			'Bestellung abschließen - Artikel',
			'Bestellung abschließen - Artikel',
			'manage_woocommerce',
			self::PLUGIN_SLUG . '_complete_item',
			'pqw_page_complete_item'
		);
	}

	/**
	 * Landing / Overview page (top-level).
	 */
	public function render_admin_page() {
		echo '<div class="wrap">';
		echo '<h1>PQW Order-Management</h1>';
		echo '<p>Wählen Sie eine Aktion aus dem Menü links:</p>';
		echo '<ul>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PLUGIN_SLUG . '_split_name' ) ) . '">Bestellung weiterverarbeiten - Name</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PLUGIN_SLUG . '_split_item' ) ) . '">Bestellung weiterverarbeiten - Artikel</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PLUGIN_SLUG . '_complete_name' ) ) . '">Bestellung abschließen - Name</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PLUGIN_SLUG . '_complete_item' ) ) . '">Bestellung abschließen - Artikel</a></li>';
		echo '</ul>';
		echo '<h6>Version ' . esc_html( self::VERSION ) . '</h6>';
		echo '</div>';
	}

	/**
	 * Generic subpage renderer.
	 * Modes:
	 *  - split_name, split_item  => button "Bestellungen weiterverarbeiten", set status to 'on-hold'
	 *  - complete_name, complete_item => button "Bestellung abschließen", set status to 'completed'
	 */
	protected function render_subpage( $mode ) {
		// determine labels and desired status
		$is_split = strpos( $mode, 'split' ) === 0;
		$button_label = $is_split ? __( 'Bestellungen weiterverarbeiten', 'pqw-order-management' ) : __( 'Bestellung abschließen', 'pqw-order-management' );
		$target_status = $is_split ? 'on-hold' : 'completed';
		$nonce_action = 'pqw_action_' . $mode;

		// Handle POST for this subpage
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pqw_subpage'] ) && $_POST['pqw_subpage'] === $mode ) {
			// capability check
			if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
				wp_die( __( 'Nicht autorisiert', 'pqw-order-management' ) );
			}
			// nonce
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
				wp_die( __( 'Nonce ungültig', 'pqw-order-management' ) );
			}

			$selected_customers = isset( $_POST['customers'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['customers'] ) ) : array();
			$selected_customers = array_filter( array_unique( $selected_customers ) );

			if ( empty( $selected_customers ) ) {
				$redirect = add_query_arg( array( 'page' => self::PLUGIN_SLUG . '_' . $mode, 'pqw_updated' => 0 ), admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}

			$customers = $this->get_processing_customers();

			// Immediate processing only allowed when exactly 1 customer selected
			if ( count( $selected_customers ) === 1 ) {
				$customer_key = reset( $selected_customers );
				$updated = 0;
				if ( isset( $customers[ $customer_key ] ) ) {
					$order_ids = array();
					foreach ( $customers[ $customer_key ]['rows'] as $r ) {
						if ( ! empty( $r['order_id'] ) ) {
							$order_ids[] = intval( $r['order_id'] );
						}
					}
					$order_ids = array_unique( $order_ids );
					foreach ( $order_ids as $oid ) {
						$order = wc_get_order( $oid );
						if ( $order && $target_status !== $order->get_status() ) {
							$order->update_status( $target_status, sprintf( __( 'Status via PQW Order-Management (%s) gesetzt', 'pqw-order-management' ), $mode ) );
							$updated++;
						}
					}
				}
				$redirect = add_query_arg( array( 'page' => self::PLUGIN_SLUG . '_' . $mode, 'pqw_updated' => $updated ), admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}

			// Otherwise: queue entries (use centralized queue_rows)
			$all_rows = array();
			foreach ( $selected_customers as $customer_key ) {
				if ( ! isset( $customers[ $customer_key ] ) ) {
					continue;
				}
				foreach ( $customers[ $customer_key ]['rows'] as $r ) {
					$all_rows[] = $r;
				}
			}

			$inserted = 0;
			if ( ! empty( $all_rows ) ) {
				$inserted = $this->queue_rows( $all_rows );
			}

			// Redirect with count
			$redirect = add_query_arg( array( 'page' => self::PLUGIN_SLUG . '_' . $mode, 'pqw_queued' => $inserted ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Render page
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $button_label ) . ' — ' . esc_html( str_replace( '_', ' ', $mode ) ) . '</h1>';

		// Notices (update / queued)
		if ( isset( $_GET['pqw_updated'] ) ) {
			$cnt = absint( $_GET['pqw_updated'] );
			if ( $cnt > 0 ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d Bestellung verarbeitet.', '%d Bestellungen verarbeitet.', $cnt, 'pqw-order-management' ), $cnt ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Keine Bestellungen ausgewählt oder keine Änderungen erforderlich.', 'pqw-order-management' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['pqw_queued'] ) ) {
			$cnt = absint( $_GET['pqw_queued'] );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Queue-Einträge angelegt.', 'pqw-order-management' ), $cnt ) ) . '</p></div>';
		}

		// Check Woocommerce
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning"><p><strong>WooCommerce nicht aktiv.</strong> PQW Order-Management benötigt WooCommerce.</p></div>';
			echo '</div>';
			return;
		}

		$customers = $this->get_processing_customers();
		if ( empty( $customers ) ) {
			echo '<p>Keine "in Bearbeitung" Bestellungen gefunden.</p>';
			echo '</div>';
			return;
		}

		// Form
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PLUGIN_SLUG . '_' . $mode ) ) . '">';
		wp_nonce_field( $nonce_action );
		echo '<input type="hidden" name="pqw_subpage" value="' . esc_attr( $mode ) . '" />';
		echo '<input type="hidden" name="pqw_submode" value="' . esc_attr( $mode ) . '" />';
		// action area
		echo '<p>';
		echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
		$desc = $is_split ? __( 'Markierte Personen: alle Bestellungen/Artikel dieser Person werden getrennt/auf "wartend" gesetzt.', 'pqw-order-management' ) : __( 'Markierte Personen: alle Bestellungen/Artikel dieser Person werden abgeschlossen.', 'pqw-order-management' );
		echo '<span class="description">' . esc_html( $desc ) . '</span>';
		echo '</p>';

		$this->render_orders_table( $customers );

		echo '</form>';

		// Inline JS: select all toggle for customer checkboxes
		?>
		<script type="text/javascript">
			(function(){
				var selectAll = document.getElementById('pqw_select_all');
				if (selectAll) {
					selectAll.addEventListener('change', function(){
						var checkboxes = document.querySelectorAll('input.pqw-customer-checkbox');
						for (var i=0;i<checkboxes.length;i++){
							checkboxes[i].checked = selectAll.checked;
						}
					});
				}
			})();
		</script>
		<?php

		echo '</div>';
	}

	/**
	 * Fetch orders with status 'processing' and group them by customer.
	 * Now also stores order_item_id for queue entries.
	 */
	public function get_processing_customers() {
		$args = array(
			'limit'  => -1,
			'orderby'=> 'date',
			'order'  => 'ASC',
			'return' => 'objects',
			'status' => 'processing',
		);

		$orders = wc_get_orders( $args );

		if ( empty( $orders ) ) {
			return array();
		}

		$customers = array();

		foreach ( $orders as $order ) {
			/** @var WC_Order $order */
			$user_id = $order->get_user_id();
			$billing_email = $order->get_billing_email();
			$billing_first = $order->get_billing_first_name();
			$billing_last  = $order->get_billing_last_name();

			$customer_key = $user_id ? 'user_' . intval( $user_id ) : 'guest_' . sanitize_email( $billing_email );

			if ( ! isset( $customers[ $customer_key ] ) ) {
				$customers[ $customer_key ] = array(
					'user_id'       => $user_id,
					'email'         => $billing_email,
					'first_name'    => $billing_first,
					'last_name'     => $billing_last,
					'rows'          => array(),
				);
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}

				$product = $item->get_product();
				$product_id = $item->get_product_id();
				$product_name = $item->get_name();
				$qty = $item->get_quantity();
				$line_total = floatval( $item->get_total() );

				$short_desc = '';
				$full_desc  = '';
				if ( $product && is_object( $product ) ) {
					$short_desc = $product->get_short_description();
					$full_desc  = $product->get_description();
				}

				$customers[ $customer_key ]['rows'][] = array(
					'order_id'       => $order->get_id(),
					'order_item_id'  => $item_id,
					'product_id'     => $product_id,
					'product_name'   => $product_name,
					'short_desc'     => $short_desc,
					'full_desc'      => $full_desc,
					'quantity'       => $qty,
					'line_total'     => $line_total,
				);
			}
		}

		// Sort customers by name
		uasort( $customers, function( $a, $b ) {
			$name_a = trim( $a['last_name'] . ' ' . $a['first_name'] );
			$name_b = trim( $b['last_name'] . ' ' . $b['first_name'] );
			if ( $name_a === $name_b ) {
				return strcmp( $a['email'], $b['email'] );
			}
			return strcasecmp( $name_a, $name_b );
		} );

		return $customers;
	}

	/**
	 * Render the table for a prepared $customers array.
	 * Now: one checkbox per customer; removed customer total row.
	 */
	public function render_orders_table( $customers ) {
		echo '<div class="pqw-orders-table">';
		echo '<div class="table-responsive">';
		echo '<table class="table table-striped table-bordered">';
		echo '<thead class="table-dark"><tr>';
		// New first column: Select (per customer)
		echo '<th scope="col"><input type="checkbox" id="pqw_select_all" aria-label="Alle auswählen" /></th>';
		echo '<th scope="col">Person</th>';
		echo '<th scope="col">Article</th>';
		echo '<th scope="col">Short description</th>';
		echo '<th scope="col">Description</th>';
		echo '<th scope="col">Amount</th>';
		echo '<th scope="col">Price</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $customers as $cust_key => $cust_data ) {
			$display_name = trim( $cust_data['first_name'] . ' ' . $cust_data['last_name'] );
			if ( empty( $display_name ) ) {
				$display_name = $cust_data['email'] ? $cust_data['email'] : __( 'Gast', 'pqw-order-management' );
			}
			$rows = $cust_data['rows'];
			if ( empty( $rows ) ) {
				continue;
			}

			$first_row = true;
			foreach ( $rows as $row ) {
				echo '<tr>';

				// Select column: show checkbox only on the first row for this customer
				if ( $first_row ) {
					echo '<td data-label="Select"><input type="checkbox" name="customers[]" value="' . esc_attr( $cust_key ) . '" class="pqw-customer-checkbox" /></td>';
				} else {
					echo '<td data-label="Select"></td>';
				}

				if ( $first_row ) {
					$person_html  = '<div class="pqw-customer-name">' . esc_html( $display_name ) . '</div>';
					//$person_html .= '<div class="pqw-small">' . esc_html( $cust_data['email'] ) . '</div>';
					// data-label for responsive view; rowspan kept for desktop
					echo '<td rowspan="' . esc_attr( count( $rows ) ) . '" data-label="Person">' . $person_html . '</td>';
					$first_row = false;
				}

				// Article
				echo '<td data-label="Article">' . esc_html( $row['product_name'] ) . '</td>';
				// Short description
				echo '<td data-label="Short description">' . esc_html( wp_trim_words( wp_strip_all_tags( $row['short_desc'] ), 20, '…' ) ) . '</td>';
				// Full description
				echo '<td data-label="Description">' . esc_html( wp_trim_words( wp_strip_all_tags( $row['full_desc'] ), 30, '…' ) ) . '</td>';
				// Amount
				echo '<td data-label="Amount">' . intval( $row['quantity'] ) . '</td>';
				// Price
				echo '<td data-label="Price">' . wc_price( $row['line_total'] ) . '</td>';
				echo '</tr>';
			}

			// no customer subtotal row anymore
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>'; // .table-responsive
		echo '</div>'; // .pqw-orders-table
	}

	/**
	 * Activation / DB table creation.
	 */
	public static function activate() {
		global $wpdb;
		$table = $wpdb->prefix . 'pqw_order_queue';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			order_item_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY order_id (order_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Create separate complete queue table
		$complete_table = $wpdb->prefix . 'pqw_order_complete_queue';
		$sql2 = "CREATE TABLE IF NOT EXISTS {$complete_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			order_item_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY order_id (order_id)
		) {$charset_collate};";
		dbDelta( $sql2 );

		// Schedule daily cleanup if not scheduled
		if ( ! wp_next_scheduled( 'pqw_cleanup_queue' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'pqw_cleanup_queue' );
		}
	}

	/**
	 * Remove old queue entries processed >= 7 days ago.
	 */
	public function cleanup_old_queue() {
		global $wpdb;
		$table = $wpdb->prefix . 'pqw_order_queue';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		// Delete only entries marked done and older than cutoff
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = %s AND processed_at <= %s",
				'done',
				$cutoff
			)
		);
	}

	/**
	 * Plugin deactivation: clear scheduled events.
	 */
	public static function deactivate() {
		// clear cleanup schedule
		$next = wp_next_scheduled( 'pqw_cleanup_queue' );
		if ( $next ) {
			wp_unschedule_event( $next, 'pqw_cleanup_queue' );
		}
		// clear process queue schedule if any
		$next2 = wp_next_scheduled( 'pqw_process_queue' );
		if ( $next2 ) {
			wp_unschedule_event( $next2, 'pqw_process_queue' );
		}
	}

	/**
	 * Insert rows into queue without duplicates. Returns number of inserted entries.
	 * Triggers background processor via non-blocking admin-ajax request and schedules wp-cron fallback.
	 */
	public function queue_rows( $rows ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pqw_order_queue';
		$inserted = 0;

		foreach ( $rows as $r ) {
			$ord_id  = isset( $r['order_id'] ) ? intval( $r['order_id'] ) : 0;
			$item_id = isset( $r['order_item_id'] ) ? intval( $r['order_item_id'] ) : 0;
			if ( ! $ord_id ) {
				continue;
			}
			// check duplicate (pending)
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND order_item_id = %d AND status = %s",
					$ord_id,
					$item_id,
					'pending'
				)
			);
			if ( intval( $exists ) > 0 ) {
				continue; // skip duplicate
			}

			$ok = $wpdb->insert(
				$table,
				array(
					'order_id'      => $ord_id,
					'order_item_id' => $item_id,
					'status'        => 'pending',
					'created_at'    => current_time( 'mysql', 1 ),
				),
				array( '%d', '%d', '%s', '%s' )
			);

			if ( $ok ) {
				$inserted++;
			}
		}

		// schedule wp-cron fallback if not scheduled
		if ( $inserted > 0 && ! wp_next_scheduled( 'pqw_process_queue' ) ) {
			wp_schedule_single_event( time() + 5, 'pqw_process_queue' );
		}

		// Trigger background processing via non-blocking admin-ajax call
		if ( $inserted > 0 ) {
			$ajax_url = admin_url( 'admin-ajax.php' );
			$body = array(
				'action' => 'pqw_process_queue_async',
				'nonce'  => wp_create_nonce( 'pqw_process_queue' ),
			);
			// non-blocking
			wp_remote_post( $ajax_url, array( 'body' => $body, 'timeout' => 0.01, 'blocking' => false ) );
		}

		return $inserted;
	}

	/**
	 * Insert rows into complete-queue without duplicates. Returns number of inserted entries.
	 * Triggers background processor via non-blocking admin-ajax request and schedules wp-cron fallback.
	 */
	public function queue_complete_rows( $rows ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pqw_order_complete_queue';
		$inserted = 0;

		foreach ( $rows as $r ) {
			$ord_id  = isset( $r['order_id'] ) ? intval( $r['order_id'] ) : 0;
			$item_id = isset( $r['order_item_id'] ) ? intval( $r['order_item_id'] ) : 0;
			if ( ! $ord_id ) {
				continue;
			}
			// check duplicate (pending)
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND order_item_id = %d AND status = %s",
					$ord_id,
					$item_id,
					'pending'
				)
			);
			if ( intval( $exists ) > 0 ) {
				continue; // skip duplicate
			}

			$ok = $wpdb->insert(
				$table,
				array(
					'order_id'      => $ord_id,
					'order_item_id' => $item_id,
					'status'        => 'pending',
					'created_at'    => current_time( 'mysql', 1 ),
				),
				array( '%d', '%d', '%s', '%s' )
			);

			if ( $ok ) {
				$inserted++;
			}
		}

		// schedule wp-cron fallback if not scheduled
		if ( $inserted > 0 && ! wp_next_scheduled( 'pqw_process_complete_queue' ) ) {
			wp_schedule_single_event( time() + 5, 'pqw_process_complete_queue' );
		}

		// Trigger background processing via non-blocking admin-ajax call (same approach as split)
		if ( $inserted > 0 ) {
			$ajax_url = admin_url( 'admin-ajax.php' );
			$body = array(
				'action' => 'pqw_process_complete_queue_async',
				'nonce'  => wp_create_nonce( 'pqw_process_complete_queue' ),
			);
			// non-blocking
			wp_remote_post( $ajax_url, array( 'body' => $body, 'timeout' => 0.01, 'blocking' => false ) );
		}

		return $inserted;
	}

	/**
	 * AJAX handler: return pending queue count
	 */
	public function ajax_queue_status() {
		// capability check
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'unauthorized', 403 );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'pqw_order_queue';
		$pending = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' ) ) );
		wp_send_json_success( array( 'pending' => $pending ) );
	}

	/**
	 * AJAX handler: triggered asynchronously to start processing queue.
	 */
	public function ajax_process_queue_async() {
		// nonce + capability
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pqw_process_queue' ) ) {
			wp_send_json_error( 'bad_nonce', 400 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'unauthorized', 403 );
		}

		// run the queue processor (process a batch and reschedule if needed)
		do_action( 'pqw_process_queue' );

		wp_send_json_success( 'started' );
	}

	/**
	 * AJAX handler: return pending complete-queue count
	 */
	public function ajax_complete_queue_status() {
		// capability check
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'unauthorized', 403 );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'pqw_order_complete_queue';
		$pending = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' ) ) );
		wp_send_json_success( array( 'pending' => $pending ) );
	}

	/**
	 * AJAX handler: triggered asynchronously to start processing complete-queue.
	 */
	public function ajax_process_complete_queue_async() {
		// nonce + capability
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pqw_process_complete_queue' ) ) {
			wp_send_json_error( 'bad_nonce', 400 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'unauthorized', 403 );
		}

		// run the complete queue processor (process a batch and reschedule if needed)
		do_action( 'pqw_process_complete_queue' );

		wp_send_json_success( 'started' );
	}

	/**
	 * Move given order item IDs from $order_id into a new order.
	 * Returns true on success (at least one item moved), false otherwise.
	 */
	public function split_order_items( $order_id, $item_ids = array() ) {
		if ( empty( $order_id ) || empty( $item_ids ) || ! class_exists( 'WC_Order' ) ) {
			return false;
		}

		$order = wc_get_order( intval( $order_id ) );
		if ( ! $order ) {
			return false;
		}

		// collect items to move (ensure items still exist)
		$to_move = array();
		foreach ( $item_ids as $iid ) {
			$iid = intval( $iid );
			$item = $order->get_item( $iid );
			if ( $item && is_a( $item, 'WC_Order_Item_Product' ) ) {
				$to_move[ $iid ] = $item;
			}
		}
		if ( empty( $to_move ) ) {
			return false;
		}

		$moved_count = 0;

		// For each item: create a separate new order containing only that item
		foreach ( $to_move as $iid => $item ) {
			// create new order
			$new_order = wc_create_order();

			// copy customer / address data
			try {
				$billing = $order->get_address( 'billing' );
				if ( is_array( $billing ) ) {
					$new_order->set_address( $billing, 'billing' );
				}
				$shipping = $order->get_address( 'shipping' );
				if ( is_array( $shipping ) ) {
					$new_order->set_address( $shipping, 'shipping' );
				}
				$cust_id = $order->get_user_id();
				if ( $cust_id ) {
					$new_order->set_customer_id( $cust_id );
				}
			} catch ( Exception $e ) {
				// ignore non-fatal
			}

			// create a new item for this new order
			$product_id  = $item->get_product_id();
			$qty         = $item->get_quantity();
			$line_total  = $item->get_total();
			$product_obj = wc_get_product( $product_id );

			$new_item = new WC_Order_Item_Product();
			if ( $product_obj ) {
				$new_item->set_product( $product_obj );
			}
			$new_item->set_quantity( $qty );
			$new_item->set_total( $line_total );
			$new_item->set_name( $item->get_name() );

			// copy basic meta
			$meta = $item->get_meta_data();
			if ( ! empty( $meta ) ) {
				foreach ( $meta as $m ) {
					$new_item->add_meta_data( $m->key, $m->value );
				}
			}

			$new_order->add_item( $new_item );

			// Optionally copy shipping/tax/fee items if required (not implemented here)

			// calculate totals and save new order
			$new_order->calculate_totals( true );
			$new_order->save();

			// remove item from original order
			$order->remove_item( $iid );

			// If new order total is 0 => cancel it automatically, otherwise set to on-hold
			$new_total = floatval( $new_order->get_total() );
			if ( 0.0 >= $new_total ) {
				$new_order->update_status( 'cancelled', __( 'Automatisch storniert (0 €) nach Weiterverarbeitung', 'pqw-order-management' ) );
			} else {
				$new_order->update_status( 'on-hold', __( 'Aufgesplittet via PQW Order-Management', 'pqw-order-management' ) );
			}

			$moved_count++;
		}

		// update original order totals and save
		$order->calculate_totals( true );
		$order->save();

		// if original order now has no items -> mark it cancelled; otherwise if total is 0 -> cancel it
		$remaining_items = $order->get_items();
		if ( empty( $remaining_items ) ) {
			// statt löschen: auf "cancelled" setzen, damit Audit/History erhalten bleibt
			if ( 'cancelled' !== $order->get_status() ) {
				$order->update_status( 'cancelled', __( 'Automatisch storniert (keine Positionen) nach Weiterverarbeitung', 'pqw-order-management' ) );
			}
		} else {
			$orig_total = floatval( $order->get_total() );
			if ( 0.0 >= $orig_total ) {
				$order->update_status( 'cancelled', __( 'Automatisch storniert (0 €) nach Weiterverarbeitung', 'pqw-order-management' ) );
			} else {
				$order->add_order_note( __( 'Positionen ausgegliedert via PQW Order-Management', 'pqw-order-management' ) );
				$order->save();
			}
		}

		return ( $moved_count > 0 );
	}
}

// --- Activation: create queue table ---
register_activation_hook( __FILE__, array( 'PQW_Order_Management', 'activate' ) );

// Initialize plugin and expose instance globally for page handlers
global $pqw_order_management;
$pqw_order_management = new PQW_Order_Management();

/**
 * Global queue processor (processes pending entries, updates status to done and reschedules if needed)
 */
if ( ! function_exists( 'pqw_process_queue_handler' ) ) {
	function pqw_process_queue_handler() {
		global $wpdb, $pqw_order_management;
		$table = $wpdb->prefix . 'pqw_order_queue';

		// Fetch a batch of pending entries
		$limit = 20;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", 'pending', $limit ) );

		if ( empty( $rows ) ) {
			return;
		}

		// group by order_id
		$grouped = array();
		foreach ( $rows as $r ) {
			$oid = intval( $r->order_id );
			if ( ! isset( $grouped[ $oid ] ) ) {
				$grouped[ $oid ] = array();
			}
			$grouped[ $oid ][] = $r;
		}

		foreach ( $grouped as $order_id => $entries ) {
			// collect item ids for this order
			$item_ids = array();
			foreach ( $entries as $e ) {
				if ( ! empty( $e->order_item_id ) ) {
					$item_ids[] = intval( $e->order_item_id );
				}
			}

			// If there are item ids -> perform split operation for this order
			if ( ! empty( $item_ids ) && isset( $pqw_order_management ) ) {
				$pqw_order_management->split_order_items( $order_id, $item_ids );
			} else {
				// fallback: if no item ids, just set order status to on-hold
				$ord = wc_get_order( $order_id );
				if ( $ord ) {
					if ( 'on-hold' !== $ord->get_status() ) {
						$ord->update_status( 'on-hold', __( 'Status via PQW Order-Management (Queue) gesetzt', 'pqw-order-management' ) );
					}
					// If this order has no items, cancel it
					$items = $ord->get_items();
					if ( empty( $items ) ) {
						if ( 'cancelled' !== $ord->get_status() ) {
							$ord->update_status( 'cancelled', __( 'Automatisch storniert (keine Positionen) nach Abarbeitung', 'pqw-order-management' ) );
						}
					} else {
						// If total is 0, also cancel
						$ord_total = floatval( $ord->get_total() );
						if ( 0.0 >= $ord_total ) {
							if ( 'cancelled' !== $ord->get_status() ) {
								$ord->update_status( 'cancelled', __( 'Automatisch storniert (0 €) nach Abarbeitung', 'pqw-order-management' ) );
							}
						}
					}
				}
			}

			// mark processed rows as done
			$ids = wp_list_pluck( $entries, 'id' );
			if ( ! empty( $ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status = %s, processed_at = %s WHERE id IN ($placeholders)",
						array_merge( array( 'done', current_time( 'mysql', 1 ) ), $ids )
					)
				);
			}
		}

		// If there are still pending entries, schedule next run shortly
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' ) );
		if ( $remaining && intval( $remaining ) > 0 ) {
			if ( ! wp_next_scheduled( 'pqw_process_queue' ) ) {
				wp_schedule_single_event( time() + 5, 'pqw_process_queue' );
			}
		}
	}
	add_action( 'pqw_process_queue', 'pqw_process_queue_handler' );
}

// --- Complete queue processor ---
if ( ! function_exists( 'pqw_process_complete_queue_handler' ) ) {
	function pqw_process_complete_queue_handler() {
		global $wpdb;
		$table = $wpdb->prefix . 'pqw_order_complete_queue';

		// Fetch a batch of pending entries
		$limit = 20;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", 'pending', $limit ) );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $r ) {
			$ord = wc_get_order( intval( $r->order_id ) );
			if ( $ord ) {
				// determine if order has items or total > 0
				$items = $ord->get_items();
				$ord_total = floatval( $ord->get_total() );

				if ( empty( $items ) ) {
					// no items -> cancel to keep history
					if ( 'cancelled' !== $ord->get_status() ) {
						$ord->update_status( 'cancelled', __( 'Automatisch storniert (keine Positionen) nach Abschluss-Queue', 'pqw-order-management' ) );
					}
				} elseif ( 0.0 >= $ord_total ) {
					// zero total -> cancel
					if ( 'cancelled' !== $ord->get_status() ) {
						$ord->update_status( 'cancelled', __( 'Automatisch storniert (0 €) nach Abschluss-Queue', 'pqw-order-management' ) );
					}
				} else {
					// normal case: complete if not already
					if ( 'completed' !== $ord->get_status() ) {
						$ord->update_status( 'completed', __( 'Abgeschlossen via PQW Order-Management (Queue)', 'pqw-order-management' ) );
					}
				}
			}

			$wpdb->update(
				$table,
				array( 'status' => 'done', 'processed_at' => current_time( 'mysql', 1 ) ),
				array( 'id' => intval( $r->id ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// If there are still pending entries, schedule next run shortly
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' ) );
		if ( $remaining && intval( $remaining ) > 0 ) {
			if ( ! wp_next_scheduled( 'pqw_process_complete_queue' ) ) {
				wp_schedule_single_event( time() + 5, 'pqw_process_complete_queue' );
			}
		}
	}
	add_action( 'pqw_process_complete_queue', 'pqw_process_complete_queue_handler' );
}

// register deactivation hook
register_deactivation_hook( __FILE__, array( 'PQW_Order_Management', 'deactivate' ) );
