<?php

namespace EGGL\PriceHistory\Includes;

use EGGL\PriceHistory\Admin\PPH_Admin;
use EGGL\PriceHistory\Admin\Edit_Product;
use EGGL\PriceHistory\Modules\Chart\Chart_Admin;
use EGGL\PriceHistory\Modules\Chart\Edit_Product as ChartEditProduct;
use EGGL\PriceHistory\Modules\Chart\Chart_Public;
use EGGL\PriceHistory\Modules\LowestPrice\LP_Admin;
use EGGL\PriceHistory\Modules\LowestPrice\LP_Public;
use EGGL\PriceHistory\Modules\LowestPrice\LP_Custom;
use EGGL\PriceHistory\Modules\AnchorPrice\AP_Admin;
use EGGL\PriceHistory\Modules\AnchorPrice\Edit_Product as AnchorEditProduct;
use EGGL\PriceHistory\Modules\AnchorPrice\AP_Public;
use EGGL\PriceHistory\Modules\AnchorPrice\Resolver;
use EGGL\PriceHistory\Modules\AnchorPrice\Compliance as AnchorCompliance;
use EGGL\PriceHistory\Modules\DigitalPriceList\DPL_Admin;
use EGGL\PriceHistory\Modules\DigitalPriceList\Generator;
use EGGL\PriceHistory\Modules\DigitalPriceList\Router;
use EGGL\PriceHistory\Modules\DigitalPriceList\REST_Controller;
use EGGL\PriceHistory\Modules\DigitalPriceList\Unit_Price;
use EGGL\PriceHistory\Modules\DigitalPriceList\External_Source;
use EGGL\PriceHistory\Modules\PriceComplianceManager\PCM_Admin;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class PPH_Plugin {
    protected $plugin_name;

    protected $version;

    public function __construct() {
        if ( defined( 'EGGL_PH_VERSION' ) ) {
            $this->version = EGGL_PH_VERSION;
        } else {
            $this->version = '1.0.0';
        }
        $this->plugin_name = 'eggl-price-history';
        $this->load_dependencies();
    }

    private function load_dependencies() {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/helper.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/settings-api.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/settings.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/admin.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/edit-product.php';
        /**
         * 
         * MODULES
         * 
         */
        // Chart
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/chart/helper.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/chart/admin.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/chart/edit-product.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/chart/public.php';
        // Lowest Price
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/lowest-price/helper.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/lowest-price/admin.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/lowest-price/public.php';
        // Anchor price
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/anchor-price/helper.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/anchor-price/resolver.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/anchor-price/admin.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/anchor-price/edit-product.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/anchor-price/public.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/anchor-price/compliance.php';
        // Price Compliance Manager
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/price-compliance-manager/registry.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/price-compliance-manager/product-filters.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/price-compliance-manager/editor.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/price-compliance-manager/csv.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/price-compliance-manager/admin.php';
        // Digital Price List
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/helper.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/mapper.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/unit-price.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/external.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/generator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/router.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/rest.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'modules/digital-price-list/admin.php';
    }

    private function chart_module() {
        if ( defined( 'EGGL_PH_MODULE_CHART' ) && EGGL_PH_MODULE_CHART === false ) {
            return;
        }
        new Chart_Admin($this->get_plugin_name(), $this->get_version());
        new ChartEditProduct();
        new Chart_Public($this->get_plugin_name(), $this->get_version());
    }

    private function lowest_price_module() {
        if ( defined( 'EGGL_PH_MODULE_LOWEST_PRICE' ) && EGGL_PH_MODULE_LOWEST_PRICE === false ) {
            return;
        }
        new LP_Admin($this->get_plugin_name(), $this->get_version());
        new LP_Public($this->get_plugin_name(), $this->get_version());
    }

    private function anchor_price_module() {
        if ( defined( 'EGGL_PH_MODULE_ANCHOR_PRICE' ) && EGGL_PH_MODULE_ANCHOR_PRICE === false ) {
            return;
        }
        // Settings UI (incl. the Enable switch) always loads so it stays reachable.
        new AP_Admin();
        if ( empty( EGGL_PH_OPTIONS['anchor_price']['enable'] ) ) {
            return;
        }
        new AnchorEditProduct();
        new AP_Public();
        new Resolver();
        new AnchorCompliance();
    }

    private function digital_price_list_module() {
        if ( defined( 'EGGL_PH_MODULE_DIGITAL_PRICE_LIST' ) && EGGL_PH_MODULE_DIGITAL_PRICE_LIST === false ) {
            return;
        }
        // Always loads: settings UI must stay reachable, and Router/REST/Generator gate on the Enable
        // setting themselves so a 404 (not a PHP notice) is what a disabled feature's URLs return.
        new DPL_Admin();
        new Generator();
        new Router();
        new REST_Controller();
        new Unit_Price();
        new External_Source();
    }

    private function price_compliance_manager_module() {
        new PCM_Admin();
    }

    public function run() {
        new PPH_Admin($this->get_plugin_name(), $this->get_version());
        new Edit_Product();
        $this->chart_module();
        $this->lowest_price_module();
        $this->anchor_price_module();
        $this->digital_price_list_module();
        $this->price_compliance_manager_module();
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_version() {
        return $this->version;
    }

}
