/**
 * "Reject withdrawal request" popup for the Withdrawals → Requests table and the
 * WooCommerce orders list: asks for an optional reason (sent to the customer in the
 * rejection email) instead of squeezing a textarea into the Actions column.
 *
 * Also used for the bulk "Reject" actions: the reason is added to the bulk form and
 * applied to every selected request.
 */
( function( $ ) {
    'use strict';

    var params = window.eggl_wd_admin_order_params || {},
        dialog = document.getElementById( 'eggl-wd-reject-dialog' ),
        BULK_ACTIONS = [ 'mark_rejected', 'eggl_wd_reject_withdrawal_requests' ],
        $dialog, $reason, $submit, $error, $title, $number,
        singleTitle, requestId = 0, bulkForm = null, bulkConfirmed = false;

    if ( ! dialog || typeof dialog.showModal !== 'function' ) {
        return; // no <dialog> support: links and bulk actions work as before, without a reason
    }

    $dialog     = $( dialog );
    $reason     = $( '#eggl-wd-reject-reason' );
    $submit     = $dialog.find( '.eggl-wd-reject-dialog__submit' );
    $error      = $dialog.find( '.eggl-wd-reject-dialog__error' );
    $title      = $dialog.find( '.eggl-wd-reject-dialog__title' );
    $number     = $dialog.find( '.eggl-wd-reject-dialog__number' );
    singleTitle = $title.text();

    function requestIdFromLink( href ) {
        var match = /[?&]order_id=(\d+)/.exec( href || '' );
        return match ? parseInt( match[1], 10 ) : 0;
    }

    function open( mode, label ) {
        var isBulk = 'bulk' === mode;

        $title.text( isBulk ? $dialog.data( 'bulkTitle' ) : singleTitle );
        $number.text( label );
        $dialog.find( '.eggl-wd-reject-dialog__single' ).prop( 'hidden', isBulk );
        $dialog.find( '.eggl-wd-reject-dialog__bulk' ).prop( 'hidden', ! isBulk );
        $reason.val( '' );
        $error.prop( 'hidden', true ).text( '' );
        $submit.prop( 'disabled', false ).removeClass( 'is-busy' );

        dialog.showModal();
        $reason.trigger( 'focus' );
    }

    function showError() {
        $error.text( $dialog.data( 'errorText' ) ).prop( 'hidden', false );
        $submit.prop( 'disabled', false ).removeClass( 'is-busy' );
    }

    // Single request. Bound on body (not document) so it runs before — and stops — the old inline-textarea toggle.
    $( document.body ).on( 'click', 'a.wc-action-button-eggl-wd-reject', function( e ) {
        requestId = requestIdFromLink( $( this ).attr( 'href' ) );
        bulkForm  = null;

        if ( ! requestId ) {
            return; // let the link do its job
        }

        e.preventDefault();
        e.stopPropagation();

        open( 'single', $( this ).closest( 'tr' ).find( '.order-view strong' ).first().text() || ( '#' + requestId ) );
    } );

    // Bulk "Reject": ask once, then submit the list form with the reason added.
    $( document ).on( 'submit', 'form', function( e ) {
        var form = this,
            top = $( form ).find( 'select[name="action"]' ).val(),
            bottom = $( form ).find( 'select[name="action2"]' ).val(),
            action = ( top && '-1' !== top ) ? top : bottom,
            checked;

        if ( bulkConfirmed || -1 === BULK_ACTIONS.indexOf( action ) ) {
            return;
        }

        checked = $( form ).find( 'tbody th.check-column input[type="checkbox"]:checked, tbody td.check-column input[type="checkbox"]:checked' ).length;

        if ( ! checked ) {
            return; // nothing selected: let WordPress handle it as usual
        }

        e.preventDefault();
        requestId = 0;
        bulkForm  = form;

        open( 'bulk', '(' + checked + ')' );
    } );

    $dialog.on( 'click', '.eggl-wd-reject-dialog__cancel', function( e ) {
        e.preventDefault();
        dialog.close();
    } );

    // Click on the backdrop closes the dialog.
    dialog.addEventListener( 'click', function( e ) {
        if ( e.target === dialog ) {
            dialog.close();
        }
    } );

    $dialog.find( 'form' ).on( 'submit', function( e ) {
        e.preventDefault();

        $submit.prop( 'disabled', true ).addClass( 'is-busy' );
        $error.prop( 'hidden', true ).text( '' );

        if ( bulkForm ) {
            $( bulkForm ).find( 'input[name="eggl_wd_bulk_reason"]' ).remove();
            $( '<input type="hidden" name="eggl_wd_bulk_reason" />' ).val( $reason.val() ).appendTo( bulkForm );
            bulkConfirmed = true;
            dialog.close();
            bulkForm.submit();
            return;
        }

        $.ajax( {
            type: 'POST',
            url: params.ajax_url,
            dataType: 'json',
            data: {
                action: 'eggl_wd_save_withdrawal_order',
                security: params.save_order_nonce,
                order_id: requestId,
                inline_action: 'reject',
                props: { rejection_reason: $reason.val() }
            }
        } ).done( function( response ) {
            if ( response && response.success ) {
                window.location.reload();
                return;
            }
            showError();
        } ).fail( showError );
    } );
}( jQuery ) );
