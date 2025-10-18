<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestellung abschließen - Name (zeigt on-hold Bestellungen)
 */
function pqw_page_complete_name() {
	global $pqw_order_management;
	$mode = 'complete_name';
	$button_label = __( 'Bestellung abschließen', 'pqw-order-management' );
	$nonce_action = 'pqw_action_' . $mode;

	// Handle complete POST: always queue selected customers' order rows into complete queue
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pqw_subpage'] ) && $_POST['pqw_subpage'] === $mode && ! isset( $_POST['pqw_export_action'] ) ) {
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( __( 'Nicht autorisiert', 'pqw-order-management' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( __( 'Nonce ungültig', 'pqw-order-management' ) );
		}

		$selected_customers = isset( $_POST['customers'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['customers'] ) ) : array();
		$selected_customers = array_filter( array_unique( $selected_customers ) );

		if ( empty( $selected_customers ) ) {
			$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_complete_queued' => 0 ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Build rows for complete queue
		$customers_all = array(); // reuse loader code to fetch on-hold orders
		$args = array( 'limit'=>-1, 'status'=>'on-hold', 'return'=>'objects' );
		$orders = wc_get_orders( $args );
		if ( $orders ) {
			foreach ( $orders as $order ) {
				$user_id = $order->get_user_id();
				$billing_email = $order->get_billing_email();
				$customer_key = $user_id ? 'user_' . intval( $user_id ) : 'guest_' . sanitize_email( $billing_email );
				if ( ! isset( $customers_all[ $customer_key ] ) ) {
					$customers_all[ $customer_key ] = array( 'rows' => array() );
				}
				foreach ( $order->get_items() as $item_id => $item ) {
					if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
					$customers_all[ $customer_key ]['rows'][] = array(
						'order_id' => $order->get_id(),
						'order_item_id' => $item_id,
					);
				}
			}
		}

		$rows = array();
		foreach ( $selected_customers as $ck ) {
			if ( empty( $customers_all[ $ck ] ) ) continue;
			foreach ( $customers_all[ $ck ]['rows'] as $r ) {
				$rows[] = array( 'order_id' => intval( $r['order_id'] ), 'order_item_id' => intval( $r['order_item_id'] ) );
			}
		}

		$inserted = 0;
		if ( ! empty( $rows ) ) {
			$inserted = $pqw_order_management->queue_complete_rows( $rows );
		}

		$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_complete_queued' => $inserted ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html( $button_label ) . '</h1>';

	// Notices (left as before)
	// ...existing notices...

	// Check WooCommerce
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p><strong>WooCommerce nicht aktiv.</strong> PQW Order-Management benötigt WooCommerce.</p></div>';
		echo '</div>';
		return;
	}

	// Load on-hold orders and group by customer (same as export build)
	$args = array(
		'limit'  => -1,
		'orderby'=> 'date',
		'order'  => 'ASC',
		'return' => 'objects',
		'status' => 'on-hold',
	);
	$orders = wc_get_orders( $args );
	$customers = array();
	if ( $orders ) {
		foreach ( $orders as $order ) {
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
				$customers[ $customer_key ]['rows'][] = array(
					'order_id'       => $order->get_id(),
					'order_item_id'  => $item_id,
					'product_id'     => $item->get_product_id(),
					'product_name'   => $item->get_name(),
					'quantity'       => $item->get_quantity(),
					'line_total'     => floatval( $item->get_total() ),
				);
			}
		}
	}

	// Neuer Abschnitt: berechne Gesamtpreis pro Kunde und ergänze das Array
	if ( ! empty( $customers ) ) {
		foreach ( $customers as $k => &$c ) {
			$total = 0.0;
			if ( ! empty( $c['rows'] ) ) {
				foreach ( $c['rows'] as $r ) {
					$total += floatval( $r['line_total'] );
				}
			}
			$c['total'] = $total;
		}
		unset( $c );
	}

	if ( empty( $customers ) ) {
		echo '<p>Keine "wartend" Bestellungen gefunden.</p>';
		echo '</div>';
		return;
	}

	// expose customers for client-side export and load SheetJS
	?>
	<script type="text/javascript">
		/* filepath: c:\xampp\htdocs\wp\wp-content\plugins\pqw-order-management\pages\complete-name.php */
		var pqw_export_customers = <?php echo wp_json_encode( $customers ); ?>;
	</script>
	<script src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . '../assets/xlsx.full.min.js' ); ?>"></script>
	<script type="text/javascript">
		// wait until DOM ready so the export button exists
		document.addEventListener('DOMContentLoaded', function () {
			function formatPrice(num){
				return (Math.round((num||0)*100)/100).toFixed(2);
			}

			// local timestamp helper for filenames
			function localDateStamp(date){
				var pad = function(n){ return (n<10 ? '0' + n : n); };
				var y = date.getFullYear();
				var m = pad(date.getMonth() + 1);
				var d = pad(date.getDate());
				var hh = pad(date.getHours());
				var mm = pad(date.getMinutes());
				var ss = pad(date.getSeconds());
				return y + '-' + m + '-' + d + '_' + hh + '-' + mm + '-' + ss;
			}

			function exportSelected(){
				var checked = document.querySelectorAll('input[name="customers[]"]:checked');
				if (!checked || checked.length === 0) {
					alert('Bitte mindestens eine Person auswählen zum Export.');
					return;
				}
				var out = [];
				for (var i=0;i<checked.length;i++){
					var key = checked[i].value;
					var c = pqw_export_customers[key];
					if (!c) continue;
					var display = ((c.first_name||'') + ' ' + (c.last_name||'')).trim();
					if (!display) display = c.email || 'Gast';
					// berechne Gesamt (falls noch nicht vorhanden im Objekt)
					var custTotal = parseFloat(c.total || 0);
					if (c.rows && c.rows.length) {
						for (var j=0;j<c.rows.length;j++){
							var r = c.rows[j];
							var qty = parseInt(r.quantity || 0, 10) || 0;
							var lineTotal = parseFloat(r.line_total || 0);
							// Stückpreis berechnen (falls Menge > 0)
							var unitPrice = qty ? (lineTotal / qty) : lineTotal;
							// erste Zeile: Person + Gesamtpreis, sonst leere Felder
							out.push({
								'Person': (j === 0) ? display : '',
								'Artikel': r.product_name || '',
								'Menge': qty,
								'Preis': formatPrice(unitPrice),
								'Gesamtpreis': (j === 0) ? formatPrice(custTotal) : ''
							});
						}
					}
				}
				if (out.length === 0) {
					alert('Keine Daten gefunden zum Export.');
					return;
				}
				// build workbook and trigger download
				var ws = XLSX.utils.json_to_sheet(out, {header:['Person','Artikel','Menge','Preis','Gesamtpreis']});
				var wb = XLSX.utils.book_new();
				XLSX.utils.book_append_sheet(wb, ws, 'Orders');
				var fname = 'pqw_orders_export_' + localDateStamp(new Date()) + '.xlsx';
				XLSX.writeFile(wb, fname);
			}

			var btn = document.getElementById('pqw_export_btn');
			if (btn) btn.addEventListener('click', exportSelected);
		});
	</script>
	<?php

	// Form with complete + EXPORT (client-side)
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . PQW_Order_Management::PLUGIN_SLUG . '_' . $mode ) ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<input type="hidden" name="pqw_subpage" value="' . esc_attr( $mode ) . '" />';

	echo '<p>';
	echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
	// changed: client-side export button (no form submit)
	echo '<button type="button" id="pqw_export_btn" class="button" style="margin-right:10px;">' . esc_html__( 'Export XLSX', 'pqw-order-management' ) . '</button>';
	echo '<span class="description">Markierte Personen: alle Bestellungen/Artikel dieser Person werden abgeschlossen.</span>';
	echo '</p>';

	// Render table with columns: Name | Artikel | Menge | Preis | Gesamtpreis
	echo '<div class="pqw-orders-table"><div class="table-responsive">';
	echo '<table class="table table-striped table-bordered">';
	echo '<thead class="table-dark"><tr>';
	echo '<th scope="col"><input type="checkbox" id="pqw_select_all" aria-label="Alle auswählen" /></th>';
	echo '<th scope="col">Name</th>';
	echo '<th scope="col">Artikel</th>';
	echo '<th scope="col">Menge</th>';
	echo '<th scope="col">Preis</th>';
	echo '<th scope="col">Gesamtpreis</th>';
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
		// berechne Gesamtpreis für diese Person
		$cust_total = 0.0;
		foreach ( $rows as $r ) {
			$cust_total += floatval( $r['line_total'] );
		}

		$first_row = true;
		foreach ( $rows as $row ) {
			echo '<tr>';
			if ( $first_row ) {
				echo '<td rowspan="' . esc_attr( count( $rows ) ) . '"><input type="checkbox" name="customers[]" value="' . esc_attr( $cust_key ) . '" class="pqw-customer-checkbox" /></td>';
				echo '<td rowspan="' . esc_attr( count( $rows ) ) . '">' . esc_html( $display_name ) . '</td>';
				$first_row = false;
			}
			echo '<td>' . esc_html( $row['product_name'] ) . '</td>';
			echo '<td>' . intval( $row['quantity'] ) . '</td>';
			// Stückpreis berechnen (linie_total / menge)
			$unit_price = ( intval( $row['quantity'] ) > 0 ) ? ( floatval( $row['line_total'] ) / intval( $row['quantity'] ) ) : floatval( $row['line_total'] );
			echo '<td>' . wc_price( $unit_price ) . '</td>';
			// Gesamtpreis nur in oberster Zeile mit rowspan
			if ( ! isset( $printed_total_for_customer ) || $printed_total_for_customer !== $cust_key ) {
				echo '<td rowspan="' . esc_attr( count( $rows ) ) . '">' . wc_price( $cust_total ) . '</td>';
				$printed_total_for_customer = $cust_key;
			}
			echo '</tr>';
		}
		// clear marker for next customer
		unset( $printed_total_for_customer );
	}

	echo '</tbody></table></div></div>';

	echo '</form>';

	// select all JS
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

	// NEW: centered overlay + spinner and polling JS when pqw_complete_queued is present
	if ( isset( $_GET['pqw_complete_queued'] ) && intval( $_GET['pqw_complete_queued'] ) > 0 ) :
		$queued = intval( $_GET['pqw_complete_queued'] );
		?>
		<script type="text/javascript">
		(function(){
			// inject spinner keyframes + minimal styles
			var style = document.createElement('style');
			style.type = 'text/css';
			style.appendChild(document.createTextNode(
				'@keyframes pqw-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
				'#pqw_complete_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
				'#pqw_complete_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
				'.pqw-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:pqw-spin 1s linear infinite;margin-bottom:12px;}'
			));
			document.head.appendChild(style);

			// create overlay element
			var overlay = document.createElement('div');
			overlay.id = 'pqw_complete_overlay';

			var box = document.createElement('div');
			box.id = 'pqw_complete_box';

			var spinner = document.createElement('div');
			spinner.className = 'pqw-spinner';

			var msg = document.createElement('div');
			msg.id = 'pqw_complete_msg';
			msg.style.textAlign = 'center';
			msg.style.fontSize = '14px';
			msg.style.color = '#222';
			msg.textContent = 'Verarbeite ' + <?php echo $queued; ?> + ' Einträge...';

			box.appendChild(spinner);
			box.appendChild(msg);
			overlay.appendChild(box);
			document.body.appendChild(overlay);

			// remove pqw_complete_queued from URL so reload doesn't retrigger the overlay
			try {
				var u = new URL(window.location.href);
				u.searchParams.delete('pqw_complete_queued');
				history.replaceState && history.replaceState(null, '', u.toString());
			} catch (e) { /* ignore */ }

			// poll complete queue status
			function checkStatus(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					try {
						var res = JSON.parse(xhr.responseText);
						if (res && res.success && res.data) {
							var pending = parseInt(res.data.pending,10);
							var msgEl = document.getElementById('pqw_complete_msg');
							msgEl.textContent = 'Verbleibend: ' + pending;
							if (pending > 0) {
								setTimeout(checkStatus, 1500);
							} else {
								msgEl.textContent = 'Abarbeitung abgeschlossen.';
								setTimeout(function(){
									window.location.reload();
								}, 1000);
							}
						}
					} catch(e){}
				};
				xhr.send('action=pqw_complete_queue_status');
			}
			// start polling shortly
			setTimeout(checkStatus, 800);
		})();
		</script>
	<?php
	endif;

	echo '</div>';

	// Notices: show complete-queue notice if present
	if ( isset( $_GET['pqw_complete_queued'] ) ) {
		$cnt = absint( $_GET['pqw_complete_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Complete-Queue Einträge angelegt.', 'pqw-order-management' ), $cnt ) ) . '</p></div>';
	}

	// Overlay JS: when using pqw_complete_queued, poll complete queue status (replace previous pqw_queue polling)
	// ...wherever the overlay/polling JS is rendered, replace action=pqw_queue_status with action=pqw_complete_queue_status and remove pqw_complete_queued from URL similarly...
}
