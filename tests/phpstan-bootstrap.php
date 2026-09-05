<?php
/**
 * PHPStan bootstrap.
 *
 * Declares constants that PHPStan cannot infer because they are defined at
 * runtime by the plugin bootstrap. Without these, level 6 reports every use of
 * them as an unknown constant, which buries the findings that matter.
 *
 * @package Social_Relay
 */

define( 'SRL_VERSION', '0.0.0' );
define( 'SRL_PLUGIN_FILE', __DIR__ . '/../social-relay.php' );
define( 'SRL_PLUGIN_DIR', __DIR__ . '/..' );
define( 'SRL_PLUGIN_URL', 'https://example.test/wp-content/plugins/social-relay/' );
