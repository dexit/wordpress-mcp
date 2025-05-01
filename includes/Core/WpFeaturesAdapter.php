<?php //phpcs:ignore
declare(strict_types=1);

namespace Automattic\WordpressMcp\Core;

use WP_Feature;

/**
 * Class WpFeaturesAdapter
 * Exposes WordPress features as MCP tools.
 *
 * @package Automattic\WordpressMcp
 */
class WpFeaturesAdapter {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wordpress_mcp_init', array( $this, 'init' ) );
	}

	/**
	 * Initializes the feature registry.
	 */
	public function init(): void {
		if ( ! function_exists( '\wp_feature_registry' ) ) {
			return;
		}

		$features = \wp_feature_registry()->get();

		foreach ( $features as $feature ) {
			$input_schema  = $feature->get_input_schema();
			$output_schema = $feature->get_output_schema();

			if ( empty( $input_schema ) && empty( $output_schema ) ) {
				continue;
			}

			$valid_ops = array( 'create', 'read', 'update', 'delete' );
			$meta      = is_callable( array( $feature, 'get_meta' ) ) ? $feature->get_meta() : array();
			$type      = $meta['mcp_type'] ?? null;
			
			if ( ! in_array( $type, $valid_ops, true ) ) {

				$type = ( $feature->get_type() === \WP_Feature::TYPE_TOOL ) ? 'create' : 'read';
			}

			if ( ! in_array( $type, $valid_ops, true ) ) {
				$type = 'read';
			}

			$the_feature = array(
				'name'                 => 'wp_feature_' . sanitize_title( $feature->get_name() ),
				'description'          => $feature->get_description(),
				'type'                 => $type,
				'inputSchema'          => $input_schema,
				'outputSchema'         => $output_schema
			);
			
			// Handle permissions callback
			if (method_exists($feature, 'get_permission_callback')) {
				$the_feature['permissions_callback'] = array($feature, 'get_permission_callback');
			} else {
				// Default to always requiring login
				$the_feature['permissions_callback'] = function() {
					return is_user_logged_in();
				};
			}

			// Handle REST alias or callback
			if ( $feature->has_rest_alias() ) {
				// Initialize rest_alias as an array first
				$the_feature['rest_alias'] = array();
				
				// Get the REST alias
				$rest_alias = $feature->get_rest_alias();
				
				// Handle rest_alias being a string or array
				if (is_string($rest_alias)) {
					$the_feature['rest_alias']['route'] = $rest_alias;
				} elseif (is_array($rest_alias) && isset($rest_alias['route'])) {
					// It's already an array with route key
					$the_feature['rest_alias']['route'] = $rest_alias['route'];
				} else {
					// Skip this feature if we can't get a valid REST alias
					continue;
				}
				
				// Get the REST method
				$rest_method = $feature->get_rest_method();
				if (is_string($rest_method)) {
					$the_feature['rest_alias']['method'] = $rest_method;
				} else {
					// Default to GET if we can't get a valid method
					$the_feature['rest_alias']['method'] = 'GET';
				}
			} else {
				// Handle callback with call method
				if (method_exists($feature, 'call')) {
					$the_feature['callback'] = function($args) use ($feature) {
						return $feature->call($args);
					};
				} else {
					// Skip features without a valid callback
					continue;
				}
			}

			// Register the tool with MCP
			try {
				new RegisterMcpTool( $the_feature );
			} catch (\Exception $e) {
				// Log error but continue with other features
				error_log('Error registering MCP tool for feature ' . $feature->get_id() . ': ' . $e->getMessage());
			}
		}
	}
}
