<?php

namespace Directorist\Integrations\HelpGent;

use Directorist\Helper;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-enquiry-settings.php';

final class Builder_Field {
    public static function definition(): array {
        $active    = function_exists( 'helpgent' );
        $installed = $active || Helper::is_plugin_installed( 'helpgent' );
        $forms     = self::form_options();
        $logo      = 'images/helpgent-icon.svg';

        $widget = [
            'type'          => 'widget',
            'label'         => __( 'HelpGent Enquiry', 'directorist' ),
            'icon'          => file_get_contents( ATBDP_DIR . 'assets/' . $logo ),
            'iconType'      => 'svg',
            'allowMultiple' => true,
            'options'       => [
                'value'       => [
                    'type'        => 'helpgent-form',
                    'label'       => __( 'HelpGent form', 'directorist' ),
                    'value'       => $forms[0]['value'] ?? '',
                    'options'     => $forms,
                    'pluginSetup' => [
                        'active'      => $active,
                        'installed'   => $installed,
                        'canInstall'  => current_user_can( 'install_plugins' ),
                        'canActivate' => $installed ? current_user_can( 'activate_plugin', 'helpgent/helpgent.php' ) : current_user_can( 'activate_plugins' ),
                        'formsUrl'    => admin_url( 'admin.php?page=helpgent#/forms' ),
                        'logoUrl'     => DIRECTORIST_ASSETS . $logo,
                    ],
                ],
                'heading'     => [
                    'type'  => 'text',
                    'label' => __( 'Heading', 'directorist' ),
                    'value' => __( 'Enquire about this listing', 'directorist' ),
                ],
                'description' => [
                    'type'  => 'textarea',
                    'label' => __( 'Description', 'directorist' ),
                    'value' => __( 'Send text, voice, video, files, or a screen recording directly to the listing owner.', 'directorist' ),
                ],
                'enquiry_settings' => [
                    'type'       => 'helpgent-enquiry-settings',
                    'field_type' => 'advanced',
                    'settingKey' => Enquiry_Settings::KEY,
                    'defaults'   => Enquiry_Settings::defaults(),
                ],
            ],
        ];

        if ( class_exists( Integration::class, false ) && Integration::NEW_LEAD_LOCKS_ENABLED ) {
            $widget['options']['protect_details'] = [
                'type'  => 'toggle',
                'label' => __( 'Protect contact details and media until unlocked', 'directorist' ),
                'value' => false,
            ];
        }

        return $widget;
    }

    public static function form_options(): array {
        if ( ! function_exists( 'helpgent' ) ) {
            return [];
        }

        $repository = helpgent_singleton( \HelpGent\App\Repositories\FormRepository::class );
        $published = static function ( $query ) {
            $query->where( 'form.status', 'publish' );
        };
        $forms = [];
        $page = 1;
        add_action( 'helpgent_form_select_list_query', $published );
        try {
            do {
                $batch = $repository->select_list( 100, $page++ );
                $forms = array_merge( $forms, $batch );
            } while ( count( $batch ) === 100 );
        } finally {
            remove_action( 'helpgent_form_select_list_query', $published );
        }
        usort(
            $forms, static function ( $a, $b ) {
                return strcasecmp( $a->title, $b->title );
            }
        );

        return array_map(
            static function ( $form ) {
                return [
                    'label' => (string) $form->title,
                    'value' => (string) $form->id,
                ];
            },
            $forms
        );
    }
}
