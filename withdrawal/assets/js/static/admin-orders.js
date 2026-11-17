window.eggl_wd = window.eggl_wd || {};
window.eggl_wd.admin_orders = window.eggl_wd.admin_orders || {};

( function( $, eggl_wd ) {
    /**
     * Core
     */
    eggl_wd.admin_orders = {
        params: {},

        init: function () {
            var self  = eggl_wd.admin_orders;
            self.params = eggl_wd_admin_orders_params;

            $( document ).on( 'click', '.order-preview-trigger:not(.disabled)', self.onPreview );
        },

        onPreview: function () {
            var $previewButton = $( this ),
                self  = eggl_wd.admin_orders,
                $order_id      = $previewButton.data( 'orderId' );

            if ( $previewButton.data( 'order-data' ) ) {
                $( this ).WCBackboneModal({
                    template: 'wc-modal-view-order',
                    variable : $previewButton.data( 'orderData' )
                });
            } else {
                $previewButton.addClass( 'disabled' );

                $.ajax({
                    url:     self.params.ajax_url,
                    data:    {
                        order_id: $order_id,
                        action  : 'eggl_wd_get_withdrawal_details',
                        security: self.params.preview_nonce
                    },
                    type:    'GET',
                    success: function( response ) {
                        $( '.order-preview' ).removeClass( 'disabled' );

                        if ( response.success ) {
                            $previewButton.data( 'orderData', response.data );

                            $( this ).WCBackboneModal({
                                template: 'wc-modal-view-order',
                                variable : response.data
                            });
                        }
                    }
                });
            }
            return false;
        },
    };

    $( document ).ready( function() {
        eggl_wd.admin_orders.init();
    });
})( jQuery, window.eggl_wd );
