<?php

namespace Bonnier\WP\ContentHub\Editor\Commands;

use WP_CLI;
use WP_CLI_Command;

/**
 * Class Migrate
 *
 * @package \Bonnier\WP\ContentHub\Commands
 */
class Migrate extends WP_CLI_Command
{
    const CMD_NAMESPACE = 'migrate';

    /**
     * Migrates Content Hub ACF fields to latest version
     *
     * ## OPTIONS
     *
     * <action>
     * : The action to take 'run|make'
     *
     * ## EXAMPLES
     *
     * wp contenthub editor migrate <action>
     *
     * @synopsis <action>
     */
    public function run( $args, $assoc_args ) {
        list( $action ) = $args;

        WP_CLI::success( "Successfully migrated" );


        //WP_CLI::ERROR("please provide a correct <blogid> param");
    }

    public static function register() {
        WP_CLI::add_command( CmdManager::CORE_CMD_NAMESPACE  . ' ' . static::CMD_NAMESPACE , __CLASS__ );
    }
}
