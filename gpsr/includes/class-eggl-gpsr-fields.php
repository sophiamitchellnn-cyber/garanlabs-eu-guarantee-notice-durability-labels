<?php
if (!defined('ABSPATH')) exit;

/**
 * Field definitions and meta access for the GPSR module, shared by the product editor and the
 * storefront tab so both always agree on the keys and labels.
 */
class EGGL_GPSR_Fields {

    const META_PREFIX = '_eggl_gpsr_';

    /** Contact sections: key => [title, description]. Each has its own on/off toggle. */
    public static function sections() {
        return [
            'manufacturer' => [
                'title'       => __('Manufacturer', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'description' => __('Contact details of the manufacturer, in case a buyer has product safety or compliance questions.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
            'responsible_person' => [
                'title'       => __('Responsible person (EU/UK)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'description' => __('Contact details of the economic operator in the EU (or UK) responsible for product safety — required when the manufacturer is established outside the EU.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
        ];
    }

    /** Contact fields shared by every section: key => label. */
    public static function contact_fields() {
        return [
            'name'     => __('Name', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'address'  => __('Street address', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'address2' => __('Street address 2', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'city'     => __('City', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'postcode' => __('Postal/ZIP code', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'state'    => __('State/Province', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'country'  => __('Country/region', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'phone'    => __('Phone', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'phone2'   => __('Phone 2', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'email'    => __('Email', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'website'  => __('Website', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
    }

    /** Document slots: key => label. */
    public static function documents() {
        return [
            'certificate_of_analysis'    => __('Certificate of Analysis', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'certificate_of_conformity'  => __('Certificate of Conformity', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'declaration_of_conformity'  => __('Declaration of Conformity', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'instructions_for_use'       => __('Instructions for Use', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'user_manual'                => __('User Guide or Manual', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'safety_data_sheet'          => __('Safety Data Sheet', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'installation_instructions'  => __('Installation Instructions', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'troubleshooting_guide'      => __('Troubleshooting Guide', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'other_safety_documents'     => __('Other Safety Documents', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'other_documents'            => __('Other Documents', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
    }

    /** File types accepted for documents (checked again on save). */
    public static function allowed_mime_types() {
        return ['application/pdf', 'image/jpeg', 'image/png'];
    }

    public static function get($product_id, $key) {
        return (string) get_post_meta($product_id, self::META_PREFIX . $key, true);
    }

    public static function is_on($product_id, $key) {
        return self::get($product_id, $key) === 'yes';
    }

    /** Attachment id of document $key, or 0 if unset or the attachment no longer exists. */
    public static function document_id($product_id, $key) {
        $id = (int) self::get($product_id, 'doc_' . $key);
        return ($id && get_post_type($id) === 'attachment') ? $id : 0;
    }

    /** Every meta key this module writes — for uninstall cleanup and the like. */
    public static function meta_keys() {
        $keys = [self::META_PREFIX . 'enabled', self::META_PREFIX . 'documents_enabled', self::META_PREFIX . 'safety_enabled', self::META_PREFIX . 'safety_info'];
        foreach (array_keys(self::sections()) as $section) {
            $keys[] = self::META_PREFIX . $section . '_enabled';
            foreach (array_keys(self::contact_fields()) as $field) {
                $keys[] = self::META_PREFIX . $section . '_' . $field;
            }
        }
        foreach (array_keys(self::documents()) as $doc) {
            $keys[] = self::META_PREFIX . 'doc_' . $doc;
        }
        return $keys;
    }
}
