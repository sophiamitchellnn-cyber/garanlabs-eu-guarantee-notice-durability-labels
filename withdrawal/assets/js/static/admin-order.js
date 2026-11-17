window.eggl_wd = window.eggl_wd || {};
window.eggl_wd.admin_order = window.eggl_wd.admin_order || {};

( function( $, eggl_wd ) {
    /**
     * Core
     */
    eggl_wd.admin_order = {
        params: {},

        init: function () {
            var self  = eggl_wd.admin_order;
            self.params = eggl_wd_admin_order_params;

            $( document ).on( 'click', '.eggl-wd-reject-withdrawal-request-start', self.onRejectRequest );
            $( document ).on( 'click', '.eggl-wd-needs-confirmation', self.onConfirm );
            $( document ).on( 'click', '.eggl-wd-order-withdrawal-order-save', self.onSave );
            $( document ).on( 'click', '.eggl-wd-order-toggle-order-search', self.onToggleInlineEdit );
            $( document ).on( 'click', 'a.wc-action-button-eggl-wd-reject', self.onToggleInlineEdit );

            self.initEnhanced();
        },

        onToggleInlineEdit: function( e ) {
            var self = eggl_wd.admin_order,
                $parent = $( this ).parents( 'td' );

            e.preventDefault();

            $parent.find( '.eggl-wd-order-inline-edit-wrapper' ).toggleClass( 'hidden' );

            return false;
        },

        onSave: function () {
            var self = eggl_wd.admin_order,
                saveType = $( this ).data( 'save' ),
                id = $( this ).data( 'id' ),
                $parent = $( this ).parents( 'tr' ),
                $this = $( this );

            var value = $parent.find( '#' + saveType + '_' + id ).val();
            let props = {}

            props[ saveType ] = value;

            const data = {
                order_id: id,
                action:   'eggl_wd_save_withdrawal_order',
                security: self.params.save_order_nonce,
                props: props,
                inline_action: $this.data( 'action' ) ? $this.data( 'action' ) : 'edit'
            };

            $this.addClass( 'eggl-wd-is-loading disabled' );
            $this.append( '<span class="spinner is-active"></span>' );
            $this.prop( 'disabled', true );

            $.ajax( {
                type: 'POST',
                url: self.params.ajax_url,
                data: data,
                dataType: 'json',
            }).done( function ( response ) {
                $this.removeClass( 'eggl-wd-is-loading disabled' );
                $this.find( '.spinner' ).remove();
                $this.prop( 'disabled', false );
                $this.addClass( 'eggl-wd-success' );

                setTimeout( () => {
                    window.location.reload();
                }, 50 );
            }).fail( function ( xhr ) {
                $this.removeClass( 'eggl-wd-is-loading disabled' );
                $this.find( '.spinner' ).remove();
                $this.prop( 'disabled', false );
            });

            return false;
        },

        initEnhanced: function() {
            var self = eggl_wd.admin_order;

            try {
                $( document.body )
                    .on( 'wc-enhanced-select-init', function() {
                        // Ajax order search boxes
                        $( ':input.eggl-wd-order-search' ).filter( ':not(.enhanced)' ).each( function() {
                            var select2_args = {
                                allowClear:  $( this ).data( 'allow_clear' ) ? true : false,
                                placeholder: $( this ).data( 'placeholder' ),
                                minimumInputLength: $( this ).data( 'minimum_input_length' ) ? $( this ).data( 'minimum_input_length' ) : '1',
                                escapeMarkup: function( m ) {
                                    return m;
                                },
                                ajax: {
                                    url:         self.params.ajax_url,
                                    dataType:    'json',
                                    delay:       1000,
                                    data:        function( params ) {
                                        return {
                                            term:     params.term,
                                            action:   'eggl_wd_json_search_orders',
                                            security: self.params.search_orders_nonce,
                                            exclude:  $( this ).data( 'exclude' )
                                        };
                                    },
                                    processResults: function( data ) {
                                        var terms = [];
                                        if ( data ) {
                                            $.each( data, function( id, text ) {
                                                terms.push({
                                                    id: id,
                                                    text: text
                                                });
                                            });
                                        }
                                        return {
                                            results: terms
                                        };
                                    },
                                    cache: true
                                }
                            };

                            $( this ).selectWoo( select2_args ).addClass( 'enhanced' );
                        });
                    });

                $( 'html' ).on( 'click', function( event ) {
                    if ( this === event.target ) {
                        $( ':input.eggl-wd-order-search' ).filter( '.select2-hidden-accessible' ).selectWoo( 'close' );
                    }
                } );
            } catch( err ) {
                // If select2 failed (conflict?) log the error but don't stop other scripts breaking.
                window.console.log( err );
            }
        },

        onConfirm: function( e ) {
            var self = eggl_wd.admin_order,
                msg = $( this ).data( 'confirm' );

            if ( ! confirm( msg ) ) {
                e.preventDefault();
            }
        },

        onRejectRequest: function() {
            var self = eggl_wd.admin_order,
                $wrapper = $( this ).parents( '.eggl-wd-order-withdrawal-request' );

            $wrapper.find( '.eggl-wd-reject-withdrawal-request-form' ).toggleClass( 'hidden' );
            $wrapper.find( '#eggl_wd_reject_reason' ).focus();

            return false;
        },
    };

    $( document ).ready( function() {
        eggl_wd.admin_order.init();
    });
})( jQuery, window.eggl_wd );
