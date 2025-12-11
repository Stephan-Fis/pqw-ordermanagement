<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Anzeige: Offene Jobs (Split-Queue + Complete-Queue)
 */
function pqw_page_current_jobs() {
    global $pqw_order_management, $wpdb;

    if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
        wp_die( __( 'Nicht autorisiert', 'pqw-order-management' ) );
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Offene Jobs', 'pqw-order-management' ) . '</h1>';

    $table_split = $wpdb->prefix . 'pqw_order_queue';
    $table_complete = $wpdb->prefix . 'pqw_order_complete_queue';

    // load rows from both queues excluding already processed 'done' entries - newest first
    $split_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, order_item_id, status, created_at FROM {$table_split} WHERE status != %s ORDER BY created_at DESC", 'done' ), ARRAY_A );
    $complete_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, order_item_id, status, created_at FROM {$table_complete} WHERE status != %s ORDER BY created_at DESC", 'done' ), ARRAY_A );

    // Renderer helper
    $render_table = function( $rows, $title ) {
        echo '<h2>' . esc_html( $title ) . '</h2>';
        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'Keine Einträge.', 'pqw-order-management' ) . '</p>';
            return;
        }

        echo '<div class="pqw-orders-table">';
        echo '<div class="table-responsive">';
        echo '<table class="table table-striped table-bordered">';
        echo '<thead class="table-dark"><tr>';
        echo '<th><input type="checkbox" id="pqw_select_all_' . esc_attr( $title ) . '" aria-label="Alle auswählen" /></th>';
        echo '<th>ID</th>';
        echo '<th>' . esc_html__( 'Bestellung', 'pqw-order-management' ) . '</th>';
        echo '<th>' . esc_html__( 'Artikel', 'pqw-order-management' ) . '</th>';
        echo '<th>' . esc_html__( 'Kurzbeschreibung', 'pqw-order-management' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'pqw-order-management' ) . '</th>';
        echo '<th>' . esc_html__( 'Erstellt', 'pqw-order-management' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $order_id = isset( $r['order_id'] ) ? intval( $r['order_id'] ) : 0;
            $item_id = isset( $r['order_item_id'] ) ? intval( $r['order_item_id'] ) : 0;
            $created = isset( $r['created_at'] ) ? $r['created_at'] : '';
            $status = isset( $r['status'] ) ? $r['status'] : '';

            $order_link = '';
            $prod_name = '';
            $short = '';

            if ( $order_id ) {
                $edit_link = get_edit_post_link( $order_id );
                if ( $edit_link ) {
                    $order_link = '<a href="' . esc_url( $edit_link ) . '" target="_blank">#' . intval( $order_id ) . '</a>';
                } else {
                    $order_link = '#' . intval( $order_id );
                }

                // try to read order item/product info
                try {
                    $ord = wc_get_order( $order_id );
                    if ( $ord && $item_id ) {
                        $item = $ord->get_item( $item_id );
                        if ( $item && is_a( $item, 'WC_Order_Item_Product' ) ) {
                            // product name from item
                            $prod_name = (string) $item->get_name();
                            // attempt short/full description from product or variation
                            $vid = 0;
                            if ( method_exists( $item, 'get_variation_id' ) ) {
                                $vid = intval( $item->get_variation_id() );
                            }
                            $pid = intval( $item->get_product_id() );
                            $prod_obj = null;
                            if ( $vid > 0 ) {
                                $prod_obj = wc_get_product( $vid );
                            }
                            if ( ! $prod_obj && $pid > 0 ) {
                                $prod_obj = wc_get_product( $pid );
                            }
                            if ( $prod_obj ) {
                                if ( method_exists( $prod_obj, 'get_short_description' ) ) {
                                    $short = (string) $prod_obj->get_short_description();
                                }
                            }
                        }
                    }
                } catch ( Exception $e ) {
                    // ignore
                }
            }

            echo '<tr>';
            echo '<td><input type="checkbox" class="pqw-job-checkbox" name="selected[]" value="' . intval( $r['id'] ) . '" /></td>';
            echo '<td>' . intval( $r['id'] ) . '</td>';
            echo '<td>' . $order_link . '</td>';
            echo '<td>' . esc_html( $prod_name ) . '</td>';
            echo '<td>' . esc_html( wp_trim_words( wp_strip_all_tags( $short ), 20, '…' ) ) . '</td>';
            echo '<td>' . esc_html( $status ) . '</td>';
            echo '<td>' . esc_html( $created ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
    };

    // render split queue table
    $render_table( $split_rows, __( 'Weiterverarbeitungs-Queue', 'pqw-order-management' ) );
    echo '<div style="margin-top:.5rem;margin-bottom:1.5rem;">';
    echo '<button id="pqw_process_selected_split" class="button button-primary" data-queue="split">' . esc_html__( 'Ausgewählte Jobs abarbeiten', 'pqw-order-management' ) . '</button>';
    echo '<div id="pqw_confirm_split" style="display:none;margin-top:.5rem;">';
    echo '<span id="pqw_confirm_split_text"></span> ';
    echo '<button id="pqw_confirm_split_ok" class="button button-primary">' . esc_html__( 'Bestätigen', 'pqw-order-management' ) . '</button> ';
    echo '<button id="pqw_confirm_split_cancel" class="button">' . esc_html__( 'Abbrechen', 'pqw-order-management' ) . '</button>';
    echo '</div>';
    echo '<div id="pqw_processing_split" style="display:none;margin-top:.5rem;">';
    echo '<span id="pqw_processing_split_text">' . esc_html__( 'Verarbeitung läuft...', 'pqw-order-management' ) . '</span> ';
    echo '<button id="pqw_processing_split_cancel" class="button">' . esc_html__( 'Abbrechen', 'pqw-order-management' ) . '</button>';
    echo '</div>';
    echo '</div>';

    // render complete queue table
    $render_table( $complete_rows, __( 'Abschluss-Queue', 'pqw-order-management' ) );
    echo '<div style="margin-top:.5rem;">';
    echo '<button id="pqw_process_selected_complete" class="button button-primary" data-queue="complete">' . esc_html__( 'Ausgewählte Jobs abarbeiten', 'pqw-order-management' ) . '</button>';
    echo '<div id="pqw_confirm_complete" style="display:none;margin-top:.5rem;">';
    echo '<span id="pqw_confirm_complete_text"></span> ';
    echo '<button id="pqw_confirm_complete_ok" class="button button-primary">' . esc_html__( 'Bestätigen', 'pqw-order-management' ) . '</button> ';
    echo '<button id="pqw_confirm_complete_cancel" class="button">' . esc_html__( 'Abbrechen', 'pqw-order-management' ) . '</button>';
    echo '</div>';
    echo '<div id="pqw_processing_complete" style="display:none;margin-top:.5rem;">';
    echo '<span id="pqw_processing_complete_text">' . esc_html__( 'Verarbeitung läuft...', 'pqw-order-management' ) . '</span> ';
    echo '<button id="pqw_processing_complete_cancel" class="button">' . esc_html__( 'Abbrechen', 'pqw-order-management' ) . '</button>';
    echo '</div>';
    echo '</div>';

    // Nonce for AJAX
    $ajax_nonce = wp_create_nonce( 'pqw_process_selected_jobs' );

    // JS: confirmation + processing + cancel/abort
    ?>
    <script>
    (function(){
        var currentXhr = { split:null, complete:null };

        function collectSelected() {
            var nodes = document.querySelectorAll('.pqw-job-checkbox:checked');
            var ids = [];
            nodes.forEach(function(n){ ids.push(n.value); });
            return ids;
        }

        function sendSelected(queue, ids) {
            if (!ids.length) { alert('Keine Einträge ausgewählt.'); return; }
            var fd = new FormData();
            fd.append('action','pqw_process_selected_jobs');
            fd.append('nonce','<?php echo esc_js( $ajax_nonce ); ?>');
            fd.append('queue', queue);
            ids.forEach(function(id){ fd.append('ids[]', id); });

            var xhr = new XMLHttpRequest();
            currentXhr[queue] = xhr;
            // toggle UI: show processing container
            document.getElementById('pqw_processing_' + queue).style.display = '';
            document.getElementById('pqw_confirm_' + queue).style.display = 'none';
            document.getElementById('pqw_process_selected_' + queue).disabled = true;

            xhr.open('POST','<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',true);
            xhr.onreadystatechange = function(){
                if (xhr.readyState === 4) {
                    currentXhr[queue] = null;
                    document.getElementById('pqw_processing_' + queue).style.display = 'none';
                    document.getElementById('pqw_process_selected_' + queue).disabled = false;
                    try {
                        var r = JSON.parse(xhr.responseText);
                        if (r.success) { alert('Erfolgreich verarbeitet: ' + r.data.processed); location.reload(); }
                        else { alert('Fehler: ' + (r.data || r)); }
                    } catch(e) { alert('Unerwartete Antwort vom Server'); }
                }
            };
            xhr.send(fd);
        }

        function showConfirmation(queue){
            var ids = collectSelected();
            if (!ids.length) { alert('Keine Einträge ausgewählt.'); return; }
            var container = document.getElementById('pqw_confirm_' + queue);
            var text = document.getElementById('pqw_confirm_' + queue + '_text');
            text.textContent = ids.length + ' Einträge verarbeiten?';
            container.style.display = '';
        }

        function hideConfirmation(queue){
            var container = document.getElementById('pqw_confirm_' + queue);
            if (container) container.style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function(){
            // bind initial process buttons to show confirmation
            var btnSplit = document.getElementById('pqw_process_selected_split');
            var btnComplete = document.getElementById('pqw_process_selected_complete');
            if (btnSplit) btnSplit.addEventListener('click', function(){ showConfirmation('split'); });
            if (btnComplete) btnComplete.addEventListener('click', function(){ showConfirmation('complete'); });

            // bind confirm/cancel buttons
            ['split','complete'].forEach(function(queue){
                var ok = document.getElementById('pqw_confirm_' + queue + '_ok');
                var cancel = document.getElementById('pqw_confirm_' + queue + '_cancel');
                var processingCancel = document.getElementById('pqw_processing_' + queue + '_cancel');
                if (ok) ok.addEventListener('click', function(){ var ids = collectSelected(); hideConfirmation(queue); sendSelected(queue, ids); });
                if (cancel) cancel.addEventListener('click', function(){ hideConfirmation(queue); });
                if (processingCancel) processingCancel.addEventListener('click', function(){ var x = currentXhr[queue]; if (x) { x.abort(); currentXhr[queue] = null; document.getElementById('pqw_processing_' + queue).style.display = 'none'; document.getElementById('pqw_process_selected_' + queue).disabled = false; alert('Verarbeitung abgebrochen.'); } });
            });

            // select-all per table
            document.querySelectorAll('[id^="pqw_select_all_"]').forEach(function(cb){
                cb.addEventListener('change', function(){
                    var table = this.closest('table'); if (!table) return;
                    table.querySelectorAll('.pqw-job-checkbox').forEach(function(ch){ ch.checked = cb.checked; });
                });
            });
        });
    })();
    </script>
    <?php

    echo '</div>';
}

?>
