<?php
/**
 * Plugin Name:     GCP Storage CDN
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     For importing CDN Media from Google's Cloud Storage
 * Author:          kibblewhite@live.com
 * Author URI:      kibblewhite@live.com
 * Text Domain:     gcp-storage-cdn
 * Domain Path:     /languages
 * Version:         1.1.1
 *
 * @package         Gcp_Storage_Cdn
 */

function gcp_storage_cdn_settings() : string {
	global $wpdb;
	return $wpdb->prefix . 'gcp_storage_cdn_settings';
}

if ( function_exists( 'empty_string' ) === false ) {
	function empty_string() : ?string { return null; }
}

define( 'GCP_STORAGE_CDN_VERSION', '1.1.1' );
define( 'GCP_STORAGE_CDN_TEXT_DOMAIN', 'gcp-storage-cdn' );
define( 'GCP_STORAGE_CDN_PLUGIN_DIRECTORY', plugin_dir_path( __FILE__ ) );
define( 'GCP_STORAGE_CDN_PLUGIN_BASE_FILE', plugin_basename( __FILE__ ) );
define( 'GCP_STORAGE_CDN_CERTIFICATE_AUTHENTICATION_JSON_DIRECTORY', realpath( plugin_dir_path( __FILE__ ) . 'assets/keys' ) );
define( 'GCP_STORAGE_CDN_OPTION_NAME', gcp_storage_cdn_settings() );
define( 'GCP_STORAGE_CDN_ENDPOINTS_NAMESPACE_V1', 'gcp-storage-cdn/v1' );
define( 'GCP_STORAGE_CDN_OBJECT_HASH_POSTMETA_KEY', 'gcp_object_hash' );
define( 'GCP_STORAGE_CDN_OBJECT_MD5HASH_POSTMETA_KEY', 'gcp_object_md5_hash' );
define( 'GCP_STORAGE_CDN_SUPPORTED_MIME_TYPES_CSV', 'image/gif, image/jpeg, image/png' );

require_once( __DIR__ . '/vendor/autoload.php' );
require_once( __DIR__ . '/src/SimpleStringValueTextPair.php' );
require_once( __DIR__ . '/src/Gcp_Endpoints_API.php' );
require_once( __DIR__ . '/src/Gcp_Client_Manager.php' );
require_once( __DIR__ . '/src/Gcp_Storage_Cdn.php' );

/**
 * Init Gcp_Storage_Cdn & Gcp_Endpoints_API
 */
Gcp_Storage_Cdn::get_instance();
Gcp_Endpoints_API::get_instance();

