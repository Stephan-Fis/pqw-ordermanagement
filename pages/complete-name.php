<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestellung abschließen - Name (zeigt on-hold Bestellungen)
 */
function om_page_complete_name() {
	global $order_management;
	$mode = 'complete_name';
	$button_label = __( 'Bestellung abschließen', 'om-order-management' );
	$nonce_action = 'om_action_' . $mode;

	// Handle complete POST: always queue selected customers' order rows into complete queue
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['om_subpage'] ) && $_POST['om_subpage'] === $mode && ! isset( $_POST['om_export_action'] ) ) {
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( __( 'Nicht autorisiert', 'om-order-management' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( __( 'Nonce ungültig', 'om-order-management' ) );
		}

		$selected_customers = isset( $_POST['customers'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['customers'] ) ) : array();
		$selected_customers = array_filter( array_unique( $selected_customers ) );

		if ( empty( $selected_customers ) ) {
			$redirect = add_query_arg( array( 'page' => Order_Management::PLUGIN_SLUG . '_' . $mode, 'om_complete_queued' => 0 ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// Build rows for complete queue
		// reuse centralized loader so variation_id logic is identical to split pages
		$customers_all = method_exists( $order_management, 'get_customers_by_status' ) ? $order_management->get_customers_by_status( 'on-hold' ) : array();

		$rows = array();
		foreach ( $selected_customers as $ck ) {
			if ( empty( $customers_all[ $ck ] ) ) continue;
			foreach ( $customers_all[ $ck ]['rows' ] as $r ) {
				$rows[] = array( 'order_id' => intval( $r['order_id' ]), 'order_item_id' => intval( $r['order_item_id' ] ) );
			}
		}

		$inserted = 0;
		if ( ! empty( $rows ) ) {
			$inserted = $order_management->queue_complete_rows( $rows );
		}

		$redirect = add_query_arg( array( 'page' => Order_Management::PLUGIN_SLUG . '_' . $mode, 'om_complete_queued' => $inserted ), admin_url( 'admin.php' ) );
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

	// use central loader so complete view uses the same variation-id extraction as split
	$customers = method_exists( $order_management, 'get_customers_by_status' ) ? $order_management->get_customers_by_status( 'on-hold' ) : array();

	// Neuer Abschnitt: nur echte Varianten (variation_id > 0) zusammenfassen,
	// Nicht-Varianten bleiben einzelne Zeilen (pro order_item), so wie bei split.
	if ( ! empty( $customers ) ) {
		foreach ( $customers as $k => &$c ) {
			// preserve original rows for per-order-item meta extraction (used for Optionen)
			$c['orig_rows'] = isset( $c['rows'] ) ? $c['rows'] : array();
			$agg = array();
			if ( ! empty( $c['rows' ] ) ) {
				foreach ( $c['rows' ] as $r ) {
					$pid = ! empty( $r['product_id'] ) ? intval( $r['product_id'] ) : 0;
					$vid = ! empty( $r['variation_id'] ) ? intval( $r['variation_id'] ) : 0;

					if ( $vid > 0 ) {
						// Varianten über alle Bestellungen zusammenfassen
						$key = 'vid_' . $vid;
						// Produktname ohne Variantenlabel (keine Anzeige der Varianten-Name/ID)
						$name_field = isset( $r['product_name'] ) ? $r['product_name'] : '';

						if ( ! isset( $agg[ $key ] ) ) {
							$agg[ $key ] = array(
								'product_id'    => $vid,
								'product_name'  => $name_field,
								'variation_id'  => $vid,
								'variant_label' => ( ! empty( $r['variant_label'] ) ? $r['variant_label'] : '' ),
								'quantity'      => 0,
								'line_total'    => 0.0,
								'is_variant'    => true,
							);
						}
						$agg[ $key ]['quantity' ]  += intval( isset( $r['quantity'] ) ? $r['quantity'] : 0 );
						$agg[ $key ]['line_total'] += floatval( isset( $r['line_total'] ) ? $r['line_total'] : 0 );

					} else {
						// Keine Variante: jetzt nach Produkt-ID zusammenfassen (mehrere gleiche Artikel -> eine Artikelzeile, Optionen in mehreren Zeilen)
						$key = 'p' . $pid;
						if ( ! isset( $agg[ $key ] ) ) {
							$agg[ $key ] = array(
								'product_id'    => $pid,
								'product_name'  => isset( $r['product_name'] ) ? $r['product_name'] : '',
								'variation_id'  => 0,
								'variant_label' => '',
								'quantity'      => 0,
								'line_total'    => 0.0,
								'is_variant'    => false,
							);
						}
						$agg[ $key ]['quantity' ]  += intval( isset( $r['quantity'] ) ? $r['quantity'] : 0 );
						$agg[ $key ]['line_total'] += floatval( isset( $r['line_total'] ) ? $r['line_total'] : 0 );
					}
				}
			}

			// Replace rows with aggregated, sorted list (alphabetical by product_name)
			$rows = array_values( $agg );
			usort( $rows, function( $a, $b ) {
				return strcasecmp( $a['product_name'], $b['product_name'] );
			} );
			$c['rows'] = $rows;

			// recompute total from aggregated rows
			$total = 0.0;
			foreach ( $c['rows'] as $r ) {
				$total += floatval( $r['line_total'] );
			}
			$c['total'] = $total;
		}
		unset( $c );
	}

	// Sortiere Kunden alphabetisch nach Name (Vorname Nachname). Fallback auf E-Mail oder "Gast".
	if ( ! empty( $customers ) ) {
		uasort( $customers, function( $a, $b ) {
			$la = trim( isset( $a['last_name'] ) ? $a['last_name'] : '' );
			$lb = trim( isset( $b['last_name'] ) ? $b['last_name'] : '' );
			// compare last names first
			if ( $la !== '' || $lb !== '' ) {
				$cmp = strcasecmp( $la, $lb );
				if ( $cmp !== 0 ) {
					return $cmp;
				}
			}
			// then compare first names
			$fa = trim( isset( $a['first_name'] ) ? $a['first_name'] : '' );
			$fb = trim( isset( $b['first_name'] ) ? $b['first_name' ] : '' );
			if ( $fa !== '' || $fb !== '' ) {
				$cmp2 = strcasecmp( $fa, $fb );
				if ( $cmp2 !== 0 ) {
					return $cmp2;
				}
			}
			// final fallback: full display name / email / Gast
			$da = trim( ( isset( $a['first_name'] ) ? $a['first_name'] : '' ) . ' ' . ( isset( $a['last_name'] ) ? $a['last_name'] : '' ) );
			$db = trim( ( isset( $b['first_name'] ) ? $b['first_name'] : '' ) . ' ' . ( isset( $b['last_name'] ) ? $b['last_name'] : '' ) );
			if ( $da === '' ) {
				$da = ! empty( $a['email'] ) ? $a['email'] : __( 'Gast', 'om-order-management' );
			}
			if ( $db === '' ) {
				$db = ! empty( $b['email'] ) ? $b['email'] : __( 'Gast', 'om-order-management' );
			}
			return strcasecmp( $da, $db );
		} );
	}

	if ( empty( $customers ) ) {
		echo '<p>Keine "wartend" Bestellungen gefunden.</p>';
		echo '</div>';
		return;
	}

	// expose customers for client-side export and load SheetJS
	?>
	<script type="text/javascript">
		/* filepath: c:\xampp\htdocs\wp\wp-content\plugins\om-order-management\pages\complete-name.php */
		var om_export_customers = <?php echo wp_json_encode( $customers ); ?>;
	</script>
	<script src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . '../assets/xlsx.full.min.js' ); ?>"></script>
	<script type="text/javascript">
		// wait until DOM ready so the export button exists
		document.addEventListener('DOMContentLoaded', function () {
			function formatPrice(num){
				try {
					// Fixed two decimals and use comma as decimal separator
					return (Math.round((num||0)*100)/100).toFixed(2).replace('.', ',');
				} catch(e) {
					return '0,00';
				}
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
				// collect checked customer keys
				var checkedBoxes = document.querySelectorAll('input[name="customers[]"]:checked');
				if (!checkedBoxes || checkedBoxes.length === 0) {
					alert('Bitte mindestens eine Person auswählen zum Export.');
					return;
				}
				var checkedKeys = {};
				for (var i=0;i<checkedBoxes.length;i++){ checkedKeys[ checkedBoxes[i].value ] = true; }

				// prefer new class, fallback to old om- class
				var table = document.querySelector('.om-orders-table table') || document.querySelector('.om-orders-table table');
				if (!table) { alert('Keine Tabelle gefunden'); return; }

				// build a minimal table clone that only contains rows for checked customers
				var thead = table.querySelector('thead');
				var tbody = table.querySelector('tbody');
				var tmpTable = document.createElement('table');
				tmpTable.className = table.className;
				if (thead) tmpTable.appendChild(thead.cloneNode(true));
				var tmpTbody = document.createElement('tbody');

				var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
				for (var r = 0; r < rows.length; r++) {
					var row = rows[r];
					// support both old and new checkbox class names
					var cb = row.querySelector('input.om-customer-checkbox') || row.querySelector('input.om-customer-checkbox');
 					if (cb) {
 						// this is the first row for a customer
 						var key = cb.value;
 						if ( checkedKeys[key] ) {
 							// include this row and all following rows until next customer row
 							tmpTbody.appendChild(row.cloneNode(true));
 							var s = r+1;
 							while (s < rows.length && !rows[s].querySelector('input.om-customer-checkbox')) {
 								tmpTbody.appendChild(rows[s].cloneNode(true));
 								s++;
 							}
 							r = s-1; // skip processed rows
 						} else {
 							// skip this customer block
 							var s2 = r+1;
 							while (s2 < rows.length && !rows[s2].querySelector('input.om-customer-checkbox')) s2++;
 							r = s2-1;
 						}
 					}
 				}
				tmpTable.appendChild(tmpTbody);

				// replace inputs in clone with textual representation
				var inputs = tmpTable.querySelectorAll('input,select,textarea');
				for (var k=0;k<inputs.length;k++){
					var el = inputs[k];
					var text = '';
					if (el.type === 'checkbox' || el.type === 'radio') {
						text = el.checked ? '1' : '';
					} else {
						text = el.value || el.getAttribute('aria-label') || '';
					}
					var txtNode = document.createTextNode(text);
					if (el.parentNode) el.parentNode.replaceChild(txtNode, el);
				}

				try {
					var wb = XLSX.utils.table_to_book(tmpTable, {sheet: 'Orders'});
					var fname = 'om_orders_export_' + localDateStamp(new Date()) + '.xlsx';
					XLSX.writeFile(wb, fname);
				} catch ( e ) {
					alert('Export fehlgeschlagen');
				}
			}

			var btn = document.getElementById('om_export_btn');
			if (btn) btn.addEventListener('click', exportSelected);
		});
	</script>
	<?php

	// Form with complete + EXPORT (client-side)
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . Order_Management::PLUGIN_SLUG . '_' . $mode ) ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<input type="hidden" name="om_subpage" value="' . esc_attr( $mode ) . '" />';

	echo '<p>';
	echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
	// changed: client-side export button (no form submit)
	echo '<button type="button" id="om_export_btn" class="button" style="margin-right:10px;">' . esc_html__( 'Export XLSX', 'om-order-management' ) . '</button>';
	echo '<span class="description">Markierte Personen: alle Bestellungen/Artikel dieser Person werden abgeschlossen.</span>';
	echo '</p>';

	// Render table with columns: Name | Artikel | Menge | Preis | Gesamtpreis
	echo '<div class="om-orders-table"><div class="table-responsive">';
	echo '<table class="table table-striped table-bordered">';
	echo '<thead class="table-dark"><tr>';
	echo '<th scope="col"><input type="checkbox" id="om_select_all" aria-label="Alle auswählen" /></th>';
	echo '<th scope="col">Name</th>';
	echo '<th scope="col">E-Mail</th>';
	echo '<th scope="col">Artikel</th>';
	echo '<th scope="col">Menge</th>';
	echo '<th scope="col">Preis</th>';
	echo '<th scope="col">Optionen</th>';
	echo '<th scope="col">Gesamtpreis</th>';
	echo '</tr></thead>';
	echo '<tbody>';

	foreach ( $customers as $cust_key => $cust_data ) {
		// Anzeige: "Nachname, Vorname" (Fallback: E-Mail, dann "Gast")
		$ln = trim( (string) $cust_data['last_name'] );
		$fn = trim( (string) $cust_data['first_name'] );
		if ( $ln !== '' ) {
			$display_name = $fn !== '' ? $ln . ', ' . $fn : $ln;
		} else {
			$display_name = $fn !== '' ? $fn : ( $cust_data['email'] ? $cust_data['email'] : __( 'Gast', 'om-order-management' ) );
		}

		$items = isset( $cust_data['rows'] ) ? $cust_data['rows'] : array();
		if ( empty( $items ) ) {
			continue;
		}

		// berechne Gesamtpreis für diese Person
		$cust_total = 0.0;
		foreach ( $items as $r ) {
			$cust_total += floatval( isset( $r['line_total'] ) ? $r['line_total'] : 0 );
		}

		// Collect per-item option/meta rows from original (pre-aggregated) rows
		$per_item_options = array();
		$per_item_counts = array();
		$total_rows_for_customer = 0;
		$orig_rows = isset( $cust_data['orig_rows'] ) ? $cust_data['orig_rows'] : array();

		foreach ( $items as $ikey => $it ) {
			$option_rows = array();
			if ( ! empty( $orig_rows ) && is_array( $orig_rows ) ) {
				foreach ( $orig_rows as $rrow ) {
					$r_vid = isset( $rrow['variation_id'] ) ? intval( $rrow['variation_id'] ) : 0;
					$r_pid = isset( $rrow['product_id'] ) ? intval( $rrow['product_id'] ) : 0;
					$r_oid = isset( $rrow['order_id'] ) ? intval( $rrow['order_id'] ) : 0;
					$r_iid = isset( $rrow['order_item_id'] ) ? intval( $rrow['order_item_id'] ) : 0;
					if ( $r_iid <= 0 || $r_oid <= 0 ) {
						continue;
					}
					if ( ( isset( $it['variation_id'] ) && intval( $it['variation_id'] ) > 0 && $r_vid === intval( $it['variation_id'] ) ) || ( isset( $it['variation_id'] ) && intval( $it['variation_id'] ) === 0 && $r_pid === intval( $it['product_id'] ) ) ) {
						$order = wc_get_order( $r_oid );
						if ( ! $order ) {
							continue;
						}
						$item = $order->get_item( $r_iid );
						if ( ! $item ) {
							continue;
						}
						$meta_data = method_exists( $item, 'get_meta_data' ) ? $item->get_meta_data() : array();
						$meta_parts = array();
						if ( ! empty( $meta_data ) ) {
							foreach ( $meta_data as $md ) {
								if ( empty( $md->key ) ) {
									continue;
								}
								$mkey = $md->key;
								if ( strpos( $mkey, '_' ) === 0 ) {
									continue;
								}
								$mval = $item->get_meta( $mkey );
								if ( $mval === '' || $mval === null ) {
									continue;
								}
								if ( is_array( $mval ) ) {
									$mval = implode( ', ', array_map( 'strval', $mval ) );
								} else {
									$mval = (string) $mval;
								}
								$title = $mkey;
								if ( false !== strpos( $mkey, '_' ) ) {
									$parts_k = explode( '_', $mkey, 2 );
									if ( isset( $parts_k[1] ) && $parts_k[1] !== '' ) {
										$title = $parts_k[1];
									}
								}
								$title = str_replace( array( '-', '_' ), ' ', $title );
								$title = ucwords( trim( $title ) );
								$meta_parts[] = $title . ': ' . $mval;
							}
						}
						if ( empty( $meta_parts ) ) {
							$option_rows[] = '';
						} else {
							$option_rows[] = implode( '; ', $meta_parts );
						}
					}
				}
			}
			if ( empty( $option_rows ) ) {
				$option_rows[] = '';
			}
			$per_item_options[ $ikey ] = $option_rows;
			$per_item_counts[ $ikey ] = count( $option_rows );
			$total_rows_for_customer += $per_item_counts[ $ikey ];
		}

		// Render rows: customer rowspan = $total_rows_for_customer
		$first_customer_row = true;
		foreach ( $items as $ikey => $it ) {
			$option_rows = isset( $per_item_options[ $ikey ] ) && is_array( $per_item_options[ $ikey ] ) ? $per_item_options[ $ikey ] : array( '' );
			$article_rowspan = isset( $per_item_counts[ $ikey ] ) && intval( $per_item_counts[ $ikey ] ) > 0 ? intval( $per_item_counts[ $ikey ] ) : 1;

			// product display + variant label
			$prod = isset( $it['product_name'] ) ? trim( $it['product_name'] ) : '';
			$variant_label = ! empty( $it['variant_label'] ) ? $it['variant_label'] : '';
			$prod_display = esc_html( $prod );
			if ( $variant_label !== '' ) {
				$prod_display = $prod_display . ' — ' . esc_html( $variant_label );
			}

			$qty = intval( isset( $it['quantity'] ) ? $it['quantity'] : 0 );
			$unit_price = ( $qty > 0 ) ? ( floatval( isset( $it['line_total'] ) ? $it['line_total'] : 0 ) / $qty ) : floatval( isset( $it['line_total'] ) ? $it['line_total'] : 0 );

			$first_opt = true;
			foreach ( $option_rows as $opt_text ) {
				echo '<tr>';
				if ( $first_customer_row ) {
					echo '<td rowspan="' . esc_attr( $total_rows_for_customer ) . '"><input type="checkbox" name="customers[]" value="' . esc_attr( $cust_key ) . '" class="om-customer-checkbox" /></td>';
					echo '<td rowspan="' . esc_attr( $total_rows_for_customer ) . '">' . esc_html( $display_name ) . '</td>';
					$email_val = isset( $cust_data['email'] ) ? $cust_data['email'] : '';
					echo '<td rowspan="' . esc_attr( $total_rows_for_customer ) . '" data-label="E-Mail">' . esc_html( $email_val ) . '</td>';
					$first_customer_row = false;
				}

				if ( $first_opt ) {
					echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Artikel">' . $prod_display . '</td>';
					echo '<td rowspan="' . esc_attr( $article_rowspan ) . '">' . intval( $qty ) . '</td>';
					echo '<td rowspan="' . esc_attr( $article_rowspan ) . '">' . wc_price( $unit_price ) . '</td>';
					echo '<td data-label="Optionen">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $opt_text ), 20, '…' ) ) . '</td>';
					if ( ! isset( $printed_total_for_customer ) || $printed_total_for_customer !== $cust_key ) {
						echo '<td rowspan="' . esc_attr( $total_rows_for_customer ) . '">' . wc_price( $cust_total ) . '</td>';
						$printed_total_for_customer = $cust_key;
					}
					echo '</tr>';
					$first_opt = false;
				} else {
					echo '<td data-label="optionen">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $opt_text ), 20, '…' ) ) . '</td>';
					echo '</tr>';
				}
			}
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
			var selectAll = document.getElementById('om_select_all');
			if (selectAll) {
				selectAll.addEventListener('change', function(){
					var checkboxes = document.querySelectorAll('input.om-customer-checkbox');
					for (var i=0;i<checkboxes.length;i++){
						checkboxes[i].checked = selectAll.checked;
					}
				});
			}
		})();
	</script>
	<?php

	// NEW: centered overlay + spinner and polling JS when om_complete_queued is present
	if ( isset( $_GET['om_complete_queued'] ) && intval( $_GET['om_complete_queued'] ) > 0 ) :
		$queued = intval( $_GET['om_complete_queued'] );
		?>
		<script type="text/javascript">
		(function(){
			// inject spinner keyframes + minimal styles
			var style = document.createElement('style');
			style.type = 'text/css';
			style.appendChild(document.createTextNode(
				'@keyframes om-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
				'#om_complete_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
				'#om_complete_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
				'.om-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:om-spin 1s linear infinite;margin-bottom:12px;}'
			));
			document.head.appendChild(style);

			// create overlay element
			var overlay = document.createElement('div');
			overlay.id = 'om_complete_overlay';

			var box = document.createElement('div');
			box.id = 'om_complete_box';

			var spinner = document.createElement('div');
			spinner.className = 'om-spinner';

			var msg = document.createElement('div');
			msg.id = 'om_complete_msg';
			msg.style.textAlign = 'center';
			msg.style.fontSize = '14px';
			msg.style.color = '#222';
			msg.textContent = 'Verarbeite ' + <?php echo $queued; ?> + ' Einträge...';

			box.appendChild(spinner);
			box.appendChild(msg);
			overlay.appendChild(box);
			document.body.appendChild(overlay);

			// remove om_complete_queued from URL so reload doesn't retrigger the overlay
			try {
				var u = new URL(window.location.href);
				u.searchParams.delete('om_complete_queued');
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
							var msgEl = document.getElementById('om_complete_msg');
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
				xhr.send('action=om_complete_queue_status');
			}
			// start polling shortly
			setTimeout(checkStatus, 800);
		})();
		</script>
	<?php
	endif;

	echo '</div>';

	// Notices: show complete-queue notice if present
	if ( isset( $_GET['om_complete_queued'] ) ) {
		$cnt = absint( $_GET['om_complete_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Complete-Queue Einträge angelegt.', 'om-order-management' ), $cnt ) ) . '</p></div>';
	}

	// Overlay JS: when using om_complete_queued, poll complete queue status (replace previous om_queue polling)
	// ...wherever the overlay/polling JS is rendered, replace action=om_queue_status with action=om_complete_queue_status and remove om_complete_queued from URL similarly...

	// NEW: ensure processing starts also when there are pending complete-queue entries (page load)
	?>
	<script type="text/javascript">
		(function(){
			// helper: create overlay if not present
			function omCreateCompleteOverlay(initial){
				if (document.getElementById('om_complete_overlay')) return;
				var style = document.createElement('style');
				style.type = 'text/css';
				style.appendChild(document.createTextNode(
					'@keyframes om-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
					'#om_complete_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
					'#om_complete_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
					'.om-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:om-spin 1s linear infinite;margin-bottom:12px;}'
				));
				document.head.appendChild(style);

				var overlay = document.createElement('div');
				overlay.id = 'om_complete_overlay';

				var box = document.createElement('div');
				box.id = 'om_complete_box';

				var spinner = document.createElement('div');
				spinner.className = 'om-spinner';

				var msg = document.createElement('div');
				msg.id = 'om_complete_msg';
				msg.style.textAlign = 'center';
				msg.style.fontSize = '14px';
				msg.style.color = '#222';
				msg.textContent = 'Verarbeite ' + (initial||0) + ' Einträge...';

				box.appendChild(spinner);
				box.appendChild(msg);
				overlay.appendChild(box);
				document.body.appendChild(overlay);
			}

			function omRemoveQueryParam(param){
				try {
					var u = new URL(window.location.href);
					u.searchParams.delete(param);
					history.replaceState && history.replaceState(null, '', u.toString());
				} catch (e) { /* ignore */ }
			}

			function omCheckCompleteStatus(cb){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					try {
						var res = JSON.parse(xhr.responseText);
						cb && cb(res);
					} catch(e){
						cb && cb(null);
					}
				};
				xhr.send('action=om_complete_queue_status');
			}

			function omTriggerCompleteProcessing(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.send('action=om_process_complete_queue_async');
			}

			function omStartCompletePolling(){
				(function poll(){
					omCheckCompleteStatus(function(res){
						try {
							if (res && res.success && res.data) {
								var pending = parseInt(res.data.pending,10) || 0;
								var msgEl = document.getElementById('om_complete_msg');
								if (msgEl) msgEl.textContent = 'Verbleibend: ' + pending;
								if (pending > 0) {
									setTimeout(poll, 1500);
								} else {
									if (msgEl) msgEl.textContent = 'Abarbeitung abgeschlossen.';
									setTimeout(function(){ window.location.reload(); }, 1000);
								}
							} else {
								// failed response - retry after delay
								setTimeout(poll, 2500);
							}
						} catch(e){
							setTimeout(poll, 2500);
						}
					});
				})();
			}

			// On load: check whether there are pending entries and if so start processing+polling
			document.addEventListener('DOMContentLoaded', function(){
				// avoid double-running if user already triggered via om_complete_queued flow
				if (document.getElementById('om_complete_overlay')) return;
				omCheckCompleteStatus(function(res){
					if (res && res.success && res.data) {
						var pending = parseInt(res.data.pending,10) || 0;
						if (pending > 0) {
							omCreateCompleteOverlay(pending);
							omRemoveQueryParam('om_complete_queued');
							// trigger server-side async processing once and start polling
							omTriggerCompleteProcessing();
							// small delay to let server start
							setTimeout(omStartCompletePolling, 700);
						}
					}
				});
			});
		})();
	</script>
	<?php
}
