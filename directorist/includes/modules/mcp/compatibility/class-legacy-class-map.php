<?php
/**
 * Compatibility mapping for public PHP APIs moved by the refactor.
 *
 * @package Directorist\MCP
 */

namespace Directorist\MCP\Compatibility;

defined( 'ABSPATH' ) || exit;

/** Central map; contains no authorization or product behavior. */
class Legacy_Class_Map {
    /**
     * Return a class alias or a forwarding-facade file for an old API.
     *
     * @param string $name Registered action or object name.
     */
    public static function resolve( string $name ): ?string {
        $aliases = [
            'Access'               => 'Directorist\MCP\Runtime\Access_Control',
            'Read_Guard'           => 'Directorist\MCP\Safety\Read_Guard',
            'Failure'              => 'Directorist\MCP\Support\Failure',
            'Read_Support'         => 'Directorist\MCP\Support\Read_Support',
            'Read_Contracts'       => 'Directorist\MCP\Support\Read_Contracts',
            'Domain_Fields'        => 'Directorist\MCP\Extensions\Domain_Fields',
            'Read_Contributions'   => 'Directorist\MCP\Extensions\Read_Contributions',
            'Extension_Operations' => 'Directorist\MCP\Extensions\External_Operations',
            'Order_Operations'     => 'Directorist\MCP\Tool_Providers\Orders\Financial_Operations',
            'Money'                => 'Directorist\MCP\Tool_Providers\Orders\Money',
            'Submission_Adapter'   => 'Directorist\MCP\Tool_Providers\Listings\Submission_Adapter',
            'Dashboard_Guidance'   => 'Directorist\MCP\Tool_Providers\Dashboard\Guidance',
        ];
        return $aliases[ $name ] ?? null;
    }
}
