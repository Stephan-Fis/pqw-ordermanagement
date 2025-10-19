<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestellungen weiterverarbeiten - Artikel
 *
 * Rendert eine Artikel-basierte Ansicht (je unique Produkt eine Zeile).
 * Checkbox per Produkt: name="items[]" value="{product_id}"
 */
function pqw_page_split_item() {
	global $pqw_order_management;

	$mode = 'split_item';
	$button_label = __( 'Bestellungen weiterverarbeiten (Artikel)', 'pqw-order-management' );
	$nonce_action  = 'pqw_action_' . $mode;

	// Handle POST
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pqw_subpage'] ) && $_POST['pqw_subpage'] === $mode ) {
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( __( 'Nicht autorisiert', 'pqw-order-management' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( __( 'Nonce ungültig', 'pqw-order-management' ) );
		}

		$items = isset( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : array();
		$items = array_filter( array_unique( array_map( 'strval', $items ) ) );
		if ( empty( $items ) ) {
			$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_updated' => 0 ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// parse items into map: order_id => array(order_item_id,...)
		$by_order = array();
		$customers = $pqw_order_management->get_processing_customers();
		foreach ( $customers as $cust_data ) {
			foreach ( $cust_data['rows'] as $r ) {
				$order_id = isset( $r['order_id'] ) ? intval( $r['order_id'] ) : 0;
				$item_id  = isset( $r['order_item_id'] ) ? intval( $r['order_item_id'] ) : 0;
				$prod_id  = isset( $r['product_id'] ) ? intval( $r['product_id'] ) : 0;
				if ( $order_id && $item_id && in_array( $prod_id, array_map( 'intval', $items ), true ) ) {
					if ( ! isset( $by_order[ $order_id ] ) ) {
						$by_order[ $order_id ] = array();
					}
					$by_order[ $order_id ][] = $item_id;
				}
			}
		}

		if ( empty( $by_order ) ) {
			$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_updated' => 0 ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// ALWAYS queue: convert to simple rows and push
		$rows = array();
		foreach ( $by_order as $oid => $iids ) {
			foreach ( $iids as $iid ) {
				$rows[] = array( 'order_id' => $oid, 'order_item_id' => $iid );
			}
		}
		$inserted = $pqw_order_management->queue_rows( $rows );
		$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_queued' => $inserted ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// Render page header
	echo '<div class="wrap">';
	echo '<h1>' . esc_html( $button_label ) . '</h1>';

	// Notices
	if ( isset( $_GET['pqw_updated'] ) ) {
		$cnt = absint( $_GET['pqw_updated'] );
		if ( $cnt > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Artikel erfolgreich aufgesplittet.', 'pqw-order-management' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Keine Artikel verarbeitet oder Fehler.', 'pqw-order-management' ) . '</p></div>';
		}
	}
	if ( isset( $_GET['pqw_queued'] ) ) {
		$cnt = absint( $_GET['pqw_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Queue-Einträge angelegt.', 'pqw-order-management' ), $cnt ) ) . '</p></div>';
	}

	// Check WooCommerce
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p><strong>WooCommerce nicht aktiv.</strong> PQW Order-Management benötigt WooCommerce.</p></div>';
		echo '</div>';
		return;
	}

	// Fetch data (customers -> rows) and aggregate by product_id
	$customers = $pqw_order_management->get_processing_customers();
	$aggregated = array(); // product_id => [product_name, short_desc, full_desc, quantity]
	foreach ( $customers as $cust_data ) {
		foreach ( $cust_data['rows'] as $r ) {
			$pid = isset( $r['product_id'] ) ? intval( $r['product_id'] ) : 0;
			if ( ! $pid ) {
				continue;
			}
			if ( ! isset( $aggregated[ $pid ] ) ) {
				$aggregated[ $pid ] = array(
					'product_id'   => $pid,
					'product_name' => isset( $r['product_name'] ) ? $r['product_name'] : '',
					'short_desc'   => isset( $r['short_desc'] ) ? $r['short_desc'] : '',
					'full_desc'    => isset( $r['full_desc'] ) ? $r['full_desc'] : '',
					'quantity'     => 0,
				);
			}
			$aggregated[ $pid ]['quantity'] += isset( $r['quantity'] ) ? intval( $r['quantity'] ) : 0;
		}
	}

	if ( empty( $aggregated ) ) {
		echo '<p>Keine "in Bearbeitung" Bestellungen / Artikel gefunden.</p>';
		echo '</div>';
		return;
	}

	// Form
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . PQW_Order_Management::PLUGIN_SLUG . '_' . $mode ) ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<input type="hidden" name="pqw_subpage" value="' . esc_attr( $mode ) . '" />';

	echo '<p>';
	echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
	echo '<span class="descr//iption">' . esc_html__( 'Markierte Artikel werden aus ihren Bestellungen ausgegliedert und in neue Bestellungen verschoben (pro Original-Bestellung).', 'pqw-order-management' ) . '</span>';
	echo '</p>';

	// Table header (aggregated view) — no order column
	echo '<div class="pqw-orders-table"><div class="table-responsive">';
	echo '<table class="table table-striped table-bordered">';
	echo '<thead class="table-dark"><tr>';
	echo '<th scope="col"><input type="checkbox" id="pqw_select_all_items" aria-label="Alle auswählen" /></th>';
	echo '<th scope="col">Artikel</th>';
	echo '<th scope="col">Beschreibung</th>';
	echo '<th scope="col">Kurzbeschreibung</th>';
	echo '<th scope="col">Gesamtmenge</th>';
	echo '</tr></thead><tbody>';

	foreach ( $aggregated as $agg ) {
		$pid   = intval( $agg['product_id'] );
		$prod  = esc_html( $agg['product_name'] );
		$short = esc_html( wp_trim_words( wp_strip_all_tags( $agg['short_desc'] ), 20, '…' ) );
		$full  = esc_html( wp_trim_words( wp_strip_all_tags( $agg['full_desc'] ), 30, '…' ) );
		$qty   = intval( $agg['quantity'] );
		$labelVal = esc_attr( $pid );

		echo '<tr>';
		echo '<td data-label="Auswählen"><input type="checkbox" name="items[]" value="' . $labelVal . '" class="pqw-item-checkbox" /></td>';
		echo '<td data-label="Artikel">' . $prod . '</td>';
		echo '<td data-label="Beschreibung">' . $full . '</td>';
		echo '<td data-label="Kurzbeschreibung">' . $short . '</td>';
		echo '<td data-label="Gesamtmenge">' . $qty . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table></div></div>';

	echo '</form>';

	// JS: select all items + Export
	?>
	<script type="text/javascript">
		(function(){
			var selectAll = document.getElementById('pqw_select_all_items');
			if (selectAll) {
				selectAll.addEventListener('change', function(){
					var checkboxes = document.querySelectorAll('input.pqw-item-checkbox');
					for (var i=0;i<checkboxes.length;i++){
						checkboxes[i].checked = selectAll.checked;
					}
				});
			}

			// Hilfsfunktion: SheetJS nur bei Bedarf laden
			function loadSheetJS(cb){
				if (window.XLSX) { cb(); return; }
				var s = document.createElement('script');
				// load local copy from plugin assets folder
				s.src = '<?php echo esc_url( plugin_dir_url( __FILE__ ) . "../assets/xlsx.full.min.js" ); ?>';
 				s.onload = cb;
 				document.head.appendChild(s);
 			}

			function exportTableToXlsx(filename){
				var table = document.querySelector('.pqw-orders-table table');
				if (!table) { alert('Keine Tabelle gefunden'); return; }
				var clone = table.cloneNode(true);
				var inputs = clone.querySelectorAll('input,select,textarea');
				for (var i=0;i<inputs.length;i++){
					var el = inputs[i];
					var text = '';
					if (el.type === 'checkbox' || el.type === 'radio') {
						text = el.checked ? '1' : '';
					} else {
						text = el.value || el.getAttribute('aria-label') || '';
					}
					var txtNode = document.createTextNode(text);
					el.parentNode.replaceChild(txtNode, el);
				}
				try {
					var wb = XLSX.utils.table_to_book(clone, {sheet: 'Sheet1'});
					XLSX.writeFile(wb, filename);
				} catch (e){
					alert('Export fehlgeschlagen');
				}
			}

			var expBtn = document.getElementById('pqw_export_xlsx');
			if (expBtn){
				expBtn.addEventListener('click', function(){
					loadSheetJS(function(){
						var d = new Date();
						function localDateStamp(date){
							var pad = function(n){ return (n<10 ? '0' + n : n); };
							var y = date.getFullYear();
							var m = pad(date.getMonth() + 1);
							var day = pad(date.getDate());
							var hh = pad(date.getHours());
							var mm = pad(date.getMinutes());
							var ss = pad(date.getSeconds());
							var tz = -date.getTimezoneOffset();
							var sign = tz >= 0 ? '+' : '-';
							var tzH = pad(Math.floor(Math.abs(tz) / 60));
							var tzM = pad(Math.abs(tz) % 60);
							return y + '-' + m + '-' + day + '_' + hh + '-' + mm + '-' + ss + '_' + sign + tzH + tzM;
						}
						var fname = 'pqw-split-item-' + localDateStamp(d) + '.xlsx';
						exportTableToXlsx(fname);
					});
				});
			}
		})();
	</script>

<!-- NEW: starte Queue-Verarbeitung beim Laden der Seite, falls noch Einträge vorhanden -->
	<script type="text/javascript">
		(function(){
			function pqwCreateQueueOverlay(initial){
				if (document.getElementById('pqw_queue_overlay')) return;
				var style = document.createElement('style');
				style.type = 'text/css';
				style.appendChild(document.createTextNode(
					'@keyframes pqw-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
					'#pqw_queue_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
					'#pqw_queue_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
					'.pqw-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:pqw-spin 1s linear infinite;margin-bottom:12px;}'
				));
				document.head.appendChild(style);

				var overlay = document.createElement('div');
				overlay.id = 'pqw_queue_overlay';

				var box = document.createElement('div');
				box.id = 'pqw_queue_box';

				var spinner = document.createElement('div');
				spinner.className = 'pqw-spinner';

				var msg = document.createElement('div');
				msg.id = 'pqw_queue_msg';
				msg.style.textAlign = 'center';
				msg.style.fontSize = '14px';
				msg.style.color = '#222';
				msg.textContent = 'Verarbeite ' + (initial||0) + ' Einträge...';

				box.appendChild(spinner);
				box.appendChild(msg);
				overlay.appendChild(box);
				document.body.appendChild(overlay);
			}

			function pqwRemoveQueryParam(param){
				try {
					var u = new URL(window.location.href);
					u.searchParams.delete(param);
					history.replaceState && history.replaceState(null, '', u.toString());
				} catch (e) { /* ignore */ }
			}

			function pqwCheckQueueStatus(cb){
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
				xhr.send('action=pqw_queue_status');
			}

			function pqwTriggerQueueProcessing(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.send('action=pqw_process_queue_async');
			}

			function pqwStartQueuePolling(){
				(function poll(){
					pqwCheckQueueStatus(function(res){
						try {
							if (res && res.success && res.data) {
								var pending = parseInt(res.data.pending,10) || 0;
								var msgEl = document.getElementById('pqw_queue_msg');
								if (msgEl) msgEl.textContent = 'Verbleibend: ' + pending;
								if (pending > 0) {
									setTimeout(poll, 1500);
								} else {
									if (msgEl) msgEl.textContent = 'Abarbeitung abgeschlossen.';
									setTimeout(function(){ window.location.reload(); }, 1000);
								}
							} else {
								// auf Fehler warten und erneut versuchen
								setTimeout(poll, 2500);
							}
						} catch(e){
							setTimeout(poll, 2500);
						}
					});
				})();
			}

			// Beim Laden prüfen, ob Queue Einträge hat -> Overlay + Trigger + Polling starten
			document.addEventListener('DOMContentLoaded', function(){
				// nicht doppelt starten, falls bereits Overlay vorhanden (z.B. durch ?pqw_queued)
				if (document.getElementById('pqw_queue_overlay')) return;
				pqwCheckQueueStatus(function(res){
					if (res && res.success && res.data) {
						var pending = parseInt(res.data.pending,10) || 0;
						if (pending > 0) {
							pqwCreateQueueOverlay(pending);
							pqwRemoveQueryParam('pqw_queued');
							// einmalig Verarbeitung auslösen
							pqwTriggerQueueProcessing();
							// kleine Verzögerung, damit Server starten kann
							setTimeout(pqwStartQueuePolling, 700);
						}
					}
				});
			});
		})();
	</script>
	<?php


	// After the form output add centered overlay + spinner and polling JS when pqw_queued is present:
	if ( isset( $_GET['pqw_queued'] ) && intval( $_GET['pqw_queued'] ) > 0 ) :
		$queued = intval( $_GET['pqw_queued'] );
		?>
		<script type="text/javascript">
		(function(){
			// inject spinner keyframes + minimal styles (same look as other pages)
			var style = document.createElement('style');
			style.type = 'text/css';
			style.appendChild(document.createTextNode(
				'@keyframes pqw-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
				'#pqw_queue_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
				'#pqw_queue_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
				'.pqw-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:pqw-spin 1s linear infinite;margin-bottom:12px;}'
			));
			document.head.appendChild(style);

			// create overlay element
			var overlay = document.createElement('div');
			overlay.id = 'pqw_queue_overlay';

			var box = document.createElement('div');
			box.id = 'pqw_queue_box';

			var spinner = document.createElement('div');
			spinner.className = 'pqw-spinner';

			var msg = document.createElement('div');
			msg.id = 'pqw_queue_msg';
			msg.style.textAlign = 'center';
			msg.style.fontSize = '14px';
			msg.style.color = '#222';
			msg.textContent = 'Verarbeite ' + <?php echo $queued; ?> + ' Einträge...';

			box.appendChild(spinner);
			box.appendChild(msg);
			overlay.appendChild(box);
			document.body.appendChild(overlay);

			// remove pqw_queued from URL so reload doesn't retrigger the overlay
			try {
				var u = new URL(window.location.href);
				u.searchParams.delete('pqw_queued');
				history.replaceState && history.replaceState(null, '', u.toString());
			} catch (e) { /* ignore */ }

			// poll status
			function checkStatus(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					try {
						var res = JSON.parse(xhr.responseText);
						if (res && res.success && res.data) {
							var pending = parseInt(res.data.pending,10);
							var msgEl = document.getElementById('pqw_queue_msg');
							msgEl.textContent = 'Verbleibend: ' + pending;
							if (pending > 0) {
								setTimeout(checkStatus, 1500);
							} else {
								msgEl.textContent = 'Abarbeitung abgeschlossen.';
								// nach kurzer Verzögerung die Seite neu laden (ohne pqw_queued)
								setTimeout(function(){
									window.location.reload();
								}, 1000);
							}
						}
					} catch(e){}
				};
				xhr.send('action=pqw_queue_status');
			}
			// start polling shortly
			setTimeout(checkStatus, 800);
		})();
		</script>
	<?php
	endif;

	echo '</div>';
}
