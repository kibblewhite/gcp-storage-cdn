<?php

/**
 * GCP Storage CDN gcp-storage-cdn.php
 *
 * 	https://github.com/googleapis/google-cloud-php-storage
 *
 * @package Gcp_Storage_Cdn
 */

class Gcp_Storage_Cdn {

	private static $instance;
	private static $gcp_storage_cdn_option_settings = array();
	private $text_domain = GCP_STORAGE_CDN_TEXT_DOMAIN;
	private $option_name = GCP_STORAGE_CDN_OPTION_NAME;
	private $gcp_storage_cdn_capability = 'manage_gcp_storage_cdn';
	private $gcp_storage_cdn_page = 'gcp_storage_cdn_page';
	private $gcp_storage_cdn_group = 'gcp_storage_cdn_group';

	private static $image_copy_resampled_keys = array(
		'dst_x', // x-coordinate of destination point.
		'dst_y', // y-coordinate of destination point.
		'src_x', // x-coordinate of source point.
		'src_y', // y-coordinate of source point.
		'dst_w', // Destination width.
		'dst_h', // Destination height.
		'src_w', // Source width.
		'src_h', // Source height.
	);

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
		add_action( 'init', array( $this, 'init' ) );
		load_plugin_textdomain( $this->text_domain, false, GCP_STORAGE_CDN_PLUGIN_DIRECTORY . 'languages' ); // will load => $this->text_domain . '-' . $locale . '.mo' (i.e. gcp-storage-cdn-en_GB.mo)
		register_activation_hook( GCP_STORAGE_CDN_PLUGIN_BASE_FILE, array( $this, 'plugin_activation' ) );
		register_deactivation_hook( GCP_STORAGE_CDN_PLUGIN_BASE_FILE, array( $this, 'plugin_deactivation' ) );
	}

	public static function write_log( $log ) : void { if ( is_array( $log ) || is_object( $log ) ) { error_log( print_r( $log, true ) ); } else { error_log( $log ); } }

	public static function get_instance() : Gcp_Storage_Cdn
	{
		if ( ! isset( self::$instance ) ) { self::$instance = new self(); }
		return self::$instance;
	}

	public static function get_option() : array
	{
		if ( empty( self::$gcp_storage_cdn_option_settings ) === true ) {
			self::$gcp_storage_cdn_option_settings = get_option( GCP_STORAGE_CDN_OPTION_NAME ) ?? array();
		}
		return self::$gcp_storage_cdn_option_settings;
	}

	public function init() : void { }

	public function plugins_loaded() : void
	{
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'load-upload.php', array( $this, 'add_tabs' ), 20 );
		add_filter( 'update_user_metadata', array( $this, 'update_user_metadata' ), 10, 6 );
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 20 );
		add_filter( 'image_downsize', array( $this, 'image_downsize' ), 10, 3 );
		add_filter( 'wp_get_attachment_url', array( $this, 'wp_get_attachment_url' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'wp_calculate_image_srcset' ), 10, 5 );
		if ( !current_user_can( 'administer_media_upload' ) ) {
			add_filter( 'wp_check_filetype_and_ext', array( $this, 'wp_check_filetype_and_ext' ), 10 );
		}
	}

	////////////////////////////////////////////////////////// upload_per_page

	public function update_user_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value )
	{
		if ( strcasecmp( 'upload_per_page', $meta_key ) === 0 )
		{	// note (kibble|2021-07-30): why are people trying to view 700 images at once lol
			return ( $meta_value >= 1 && $meta_value <= 50 ) ? $check : false;
		}
		return $check;
	}

	public function wp_check_filetype_and_ext( $validate, string $file, string $filename, ?array $mimes, $real_mime ) : array
	{
		return compact( false, false, false );
	}

	public static function build_image_meta_sizes( array $image_meta, array $wp_registered_image_subsizes = array() ) : array
	{

		$settings = self::get_option();

		$img_processor_url_prefix = isset( $settings['image_processor_url_prefix'] ) === true ? $settings['image_processor_url_prefix'] : null;
		if ( empty( $img_processor_url_prefix ) === true || ( $image_processor_url_prefix = filter_var( $img_processor_url_prefix, FILTER_VALIDATE_URL ) ) === false )
		{
			return $image_meta['sizes'];
		}

		parse_str( $settings['image_processor_transform_query_extension'], $image_processor_transform_extension_array );

		$image_meta_size_full = isset( $image_meta['sizes']['full'] ) === true ? $image_meta['sizes']['full'] : array();
		if ( empty( $image_meta_size_full ) === true ) { return $image_meta['sizes']; }

		$is_animate_gif = isset( $image_meta['is_animate_gif'] ) === true ? $image_meta['is_animate_gif'] : false;
		$image_meta_is_animate_gif = filter_var( $is_animate_gif, FILTER_VALIDATE_BOOLEAN );
		if ( $image_meta_is_animate_gif === true ) { return $image_meta['sizes']; }

		$image_sizes = array_merge( $wp_registered_image_subsizes, array(
			'full' => array(
				'width'		=> $image_meta_size_full['width'],
				'height'	=> $image_meta_size_full['height'],
				'crop'		=> false
			)
		) );

		foreach ( $image_sizes as $key => $resize ) {

			$image_resize_dimensions_array = image_resize_dimensions( $image_meta_size_full['width'], $image_meta_size_full['height'], $resize['width'], $resize['height'], $resize['crop'] );
			if ( $image_resize_dimensions_array === false && $key == 'full' ) {
				$image_resize_dimensions_array = array(	0, 0, 0, 0, $image_meta_size_full['width'], $image_meta_size_full['height'], $image_meta_size_full['width'], $image_meta_size_full['height'] );
			}

			if ( is_array( $image_resize_dimensions_array ) === false ) {
				unset( $image_sizes[ $key ] );
				continue;
			}

			$resize_dimensions = array_combine( self::$image_copy_resampled_keys, $image_resize_dimensions_array );
			$base_transform_parameters_array = array_merge( $image_processor_transform_extension_array, array(
				'w'	=> $resize_dimensions['dst_w'],
				'h'	=> $resize_dimensions['dst_h']
			) );

			$transform_parameters_array = array_merge(
				$base_transform_parameters_array,
				$resize['crop'] === true ? [ 'crop' => implode( ',', array(
					$resize_dimensions['dst_w'],
					$resize_dimensions['dst_h'],
					$resize_dimensions['src_x'],
					$resize_dimensions['src_y']
				)
			) ] : [ 'fit' => 'max' ] );

			$transformative_parsed_url_array = parse_url( $image_meta['source_url'] );
			if ( $transformative_parsed_url_array === false ) {
				self::write_log( 'Source URL Failing to parse correctly: ' . isset( $image_meta['source_url'] ) === true ? $image_meta['source_url'] : 'Source URL Not Set or Missing' );
				continue;
			}

			$image_processor_url_array = array(
				rtrim( $image_processor_url_prefix, DIRECTORY_SEPARATOR ),
				DIRECTORY_SEPARATOR,
				$transformative_parsed_url_array['host'],
				DIRECTORY_SEPARATOR,
				http_build_query( $transform_parameters_array ),
				$transformative_parsed_url_array['path'],
			);

			$image_sizes[ $key ] = array(
				'file'		=> implode( empty_string(), $image_processor_url_array ),
				'width'		=> $resize_dimensions['dst_w'],
				'height'	=> $resize_dimensions['dst_h'],
				'mime-type'	=> $image_meta['mime_type']
			);

		}

		return $image_sizes;

	}

	private static function attempt_rebuild_image_meta_sizes( int $attachment_id, ?array $image_meta = null, bool $force = false ) : array
	{

		list(
			'registered_image_subsizes' => $wp_registered_image_subsizes,
			'registered_image_subsizes_checksum' => $wp_registered_image_subsizes_checksum
		) = self::get_wp_registered_image_subsizes_and_checksum();

		$image_meta = $image_meta ?? wp_get_attachment_metadata( $attachment_id );
		if ( $wp_registered_image_subsizes_checksum == $image_meta['registered_image_subsizes_checksum'] && $force === false ) { return $image_meta; }

		$image_meta['sizes'] = self::build_image_meta_sizes( $image_meta, $wp_registered_image_subsizes );
		$image_meta['registered_image_subsizes_checksum'] = $wp_registered_image_subsizes_checksum;
		wp_update_attachment_metadata( $attachment_id, $image_meta );

		return $image_meta;

	}

	/**
	 * https://developer.wordpress.org/reference/functions/image_downsize/
	 *
	 * @param bool|array    $downsize   Whether to short-circuit the image downsize.
	 * @param int           $id         Attachment ID for image.
	 * @param string|int[]  $size       Requested image size. Can be any registered image size name, or
	 *                                  an array of width and height values in pixels (in that order).
	 * @return bool|array
	 */
	public function image_downsize( $downsize, $id, $size )
	{

		$settings = $this->get_option();
		$image_meta = wp_get_attachment_metadata( $id );

		$is_cdn_resource = isset( $image_meta[ 'is_cdn_resource' ] ) === true ? $image_meta[ 'is_cdn_resource' ] : false;
		$is_image_metadata_cdn_resource = filter_var( $is_cdn_resource, FILTER_VALIDATE_BOOLEAN );
		if ( $is_image_metadata_cdn_resource !== true ) { return false; }

		$is_animated_gif = isset( $image_meta[ 'is_animated_gif' ] ) === true ? $image_meta[ 'is_animated_gif' ] : false;
		$is_image_metadata_animated_gif = filter_var( $is_animated_gif, FILTER_VALIDATE_BOOLEAN );
		if ( $is_image_metadata_animated_gif === true )
		{
			return array(
				$image_meta[ 'source_url' ],
				$image_meta[ 'sizes' ][ 'full' ][ 'width' ],
				$image_meta[ 'sizes' ][ 'full' ][ 'height' ],
				false
			);
		 }

		$image_meta = self::attempt_rebuild_image_meta_sizes( $id, $image_meta );

		$downsize_request_is_array = is_array( $size );
		$found_metadata_size_dataset = ( $downsize_request_is_array === false ) ? isset( $image_meta[ 'sizes' ][ $size ] ) : false;

		if ( $is_image_metadata_cdn_resource === true && $found_metadata_size_dataset === true && $downsize_request_is_array === false ) {
			return array(
				$image_meta[ 'sizes' ][ $size ][ 'file' ],
				$image_meta[ 'sizes' ][ $size ][ 'width' ],
				$image_meta[ 'sizes' ][ $size ][ 'height' ],
				( $size == 'full' ) ? false : true
			);
		}

		list( $downsize_request_w, $downsize_request_h ) = $size;
		if ( $downsize_request_is_array === false ) {
			list( 'registered_image_subsizes' => $wp_registered_image_subsizes ) = self::get_wp_registered_image_subsizes_and_checksum();
			if ( isset( $wp_registered_image_subsizes[ $size ] ) ) {
				list( 'width' => $downsize_request_w, 'height' => $downsize_request_h ) = $wp_registered_image_subsizes[ $size ];
			}
		}

		$composite_metadata_size = implode( empty_string(), array( $downsize_request_w, 'x', $downsize_request_h ) );
		$found_composite_metadata_size_dataset = ( $downsize_request_is_array === false ) ? isset( $image_meta['sizes'][ $composite_metadata_size ] ) === true : false;
		if ( $is_image_metadata_cdn_resource === true && $found_composite_metadata_size_dataset === true ) {
			return array(
				$image_meta['sizes'][ $composite_metadata_size ]['file'],
				$image_meta['sizes'][ $composite_metadata_size ]['width'],
				$image_meta['sizes'][ $composite_metadata_size ]['height'],
				( $size == 'full' ) ? false : true
			);
		}

		if ( isset( $image_meta['sizes']['full'] ) === false || isset( $image_meta['source_url'] ) === false ) { return false; }
		$transformative_parsed_url_array = parse_url( $image_meta['source_url'] );
		if ( $transformative_parsed_url_array === false ) { return false; }

		$transform_parameters_array = array(
			'w' => $downsize_request_w,
			'h' => $downsize_request_h
		);
		$transformative_parsed_url_array = parse_url( $image_meta['source_url'] );
		if ( $transformative_parsed_url_array === false ) { return false; }

		$img_processor_url_prefix = isset( $settings['image_processor_url_prefix'] ) === true ? $settings['image_processor_url_prefix'] : null;
		if ( empty( $img_processor_url_prefix ) === true || ( $image_processor_url_prefix = filter_var( $img_processor_url_prefix, FILTER_VALIDATE_URL ) ) === false ) { return false; }

		$image_processor_url_array = array(
			rtrim( $image_processor_url_prefix, DIRECTORY_SEPARATOR ),
			DIRECTORY_SEPARATOR,
			$transformative_parsed_url_array['host'],
			DIRECTORY_SEPARATOR,
			http_build_query( $transform_parameters_array ),
			$transformative_parsed_url_array['path'],
		);

		return array(
			implode( empty_string(), $image_processor_url_array ),
			$downsize_request_w,
			$downsize_request_h,
			true
		);

	}

	public function wp_calculate_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {

		$is_cdn_resource = isset( $image_meta['is_cdn_resource'] ) ? $image_meta['is_cdn_resource'] : false;
		$is_image_metadata_cdn_resource = filter_var( $is_cdn_resource, FILTER_VALIDATE_BOOLEAN );
		if ( $is_image_metadata_cdn_resource !== true ) { return $sources; }

		$is_animated_gif = isset( $image_meta[ 'is_animated_gif' ] ) === true ? $image_meta[ 'is_animated_gif' ] : false;
		$is_image_metadata_animated_gif = filter_var( $is_animated_gif, FILTER_VALIDATE_BOOLEAN );
		if ( $is_image_metadata_animated_gif === true ) { return $sources; }

		$image_meta = self::attempt_rebuild_image_meta_sizes( $attachment_id, $image_meta );

		$sources = array();
		$image_sizes = $image_meta['sizes'];
		list( $image_width, $image_height ) = $size_array;
		foreach ( $image_sizes as $image ) {
			if ( is_array( $image ) === false ) { continue; }
			if ( wp_image_matches_ratio( $image_width, $image_height, $image['width'], $image['height'] ) ) {
				$sources[ $image['width'] ] = array(
					'url'			=> $image['file'],
					'descriptor'	=> 'w',
					'value'			=> $image['width'],
				);
			}
		}

		return $sources;

	}

	public function wp_get_attachment_url( $url, $attachment_id ) {

		$image_meta = wp_get_attachment_metadata( $attachment_id );
		$source_url_is_set = isset( $image_meta['source_url'] );
		$transformative_parsed_url_array = parse_url( $source_url_is_set ? $image_meta['source_url'] : null );

		$is_cdn_resource = isset( $image_meta['is_cdn_resource'] ) === true ? $image_meta['is_cdn_resource'] : false;
		$is_image_metadata_cdn_resource = filter_var( $is_cdn_resource, FILTER_VALIDATE_BOOLEAN );

		$is_animated_gif = isset( $image_meta[ 'is_animated_gif' ] ) === true ? $image_meta[ 'is_animated_gif' ] : false;
		$is_image_metadata_animated_gif = filter_var( $is_animated_gif, FILTER_VALIDATE_BOOLEAN );

		if ( in_array( false, array(
			$is_image_metadata_cdn_resource === true,
			$is_image_metadata_animated_gif === false,
			$source_url_is_set === true,
			isset( $image_meta['sizes']['full'] ) === true,
			$transformative_parsed_url_array !== false
		) ) === true ) { return $url; }


		$settings = $this->get_option();
		$img_processor_url_prefix = isset( $settings['image_processor_url_prefix'] ) === true ? $settings['image_processor_url_prefix'] : null;
		if ( empty( $img_processor_url_prefix ) === true || ( $image_processor_url_prefix = filter_var( $img_processor_url_prefix, FILTER_VALIDATE_URL ) ) === false ) { return $url; }

		parse_str( $settings['image_processor_transform_query_extension'], $image_processor_transform_extension_array );
		$transform_parameters_array = array_merge(
			$image_processor_transform_extension_array,
			array(
				'w' => $image_meta['sizes']['full']['width'],
				'h' => $image_meta['sizes']['full']['height']
			)
		);

		$image_processor_url_array = array(
			rtrim( $image_processor_url_prefix, DIRECTORY_SEPARATOR ),
			DIRECTORY_SEPARATOR,
			$transformative_parsed_url_array['host'],
			DIRECTORY_SEPARATOR,
			http_build_query( $transform_parameters_array ),
			$transformative_parsed_url_array['path'],
		);

		return implode( empty_string(), $image_processor_url_array );

	}

	public static function get_wp_registered_image_subsizes_and_checksum() : array
	{
		// TODO (kibble|2021-02-05): To dynamically override the image subsizes, this is where is should be done.
		//								- Create values in the admin UI panel to list and create new image subsizes
		//								- Hook: tools_page_cdn_image_import_tools // 'admin/tools_page.php'
		$wp_registered_image_subsizes = wp_get_registered_image_subsizes();

		ksort( $wp_registered_image_subsizes );
		uasort( $wp_registered_image_subsizes, function( $a, $b ) {
			$cmp_ar = array(
				'a_w'	=> isset( $a['width'] ) ? $a['width'] : 1,
				'a_h'	=> isset( $a['height'] ) ? $a['height'] : 1,
				'b_w'	=> isset( $b['width'] ) ? $b['width'] : 1,
				'b_h'	=> isset( $b['height'] ) ? $b['height'] : 1,
			);
			return ( $cmp_ar['a_w'] * $cmp_ar['a_h'] ) <=> ( $cmp_ar['b_w'] * $cmp_ar['b_h'] );
		} );

		return array(
			'registered_image_subsizes_checksum' => md5( serialize( $wp_registered_image_subsizes ) ),
			'registered_image_subsizes' => $wp_registered_image_subsizes
		);
	}

	public function add_tabs() : void
	{
		get_current_screen()->add_help_tab( array(
			'id'       => 'synchronise_help_tab',
			'title'    => __( 'Synchronise GCP', $this->text_domain ),
			'content'  => '<h3>Synchronise with GCP Storage</h3>',
			'callback' => array( $this, 'prepare' )
		) );
	}

	private function include_file_with_variables( string $filename, ?array $variables = array() ) : void
	{
		extract( $variables );
		include( $filename );
	}

	public function prepare( $screen, $tab ) : void
	{
		$this->include_file_with_variables( 'admin/synchronise_help_tab.php', array(
			'screen' => $screen,
			'tab' => $tab
		) );
	}

	//////////////////////////////////////////////////////////

	public function admin_enqueue_scripts( $hook ) : void
	{
		switch( $hook )
		{
			case 'upload.php':
			{
				wp_register_script( 'gcp-storage-cdn', plugins_url( '../assets/js/gcp-storage-cdn.admin.js', __FILE__ ), array( 'jquery' ), GCP_STORAGE_CDN_VERSION, true );
				$gcps = array( 'ajax_endpoint_sync_url' => '/index.php/wp-json/' . GCP_STORAGE_CDN_ENDPOINTS_NAMESPACE_V1 . '/sync' );
				wp_localize_script( 'gcp-storage-cdn', 'gcps', $gcps );
				wp_enqueue_script( 'gcp-storage-cdn' );
				wp_register_style( 'gcp-storage-cdn', plugins_url( '../assets/css/gcp-storage-cdn.admin.css', __FILE__ ), null, GCP_STORAGE_CDN_VERSION );
				wp_enqueue_style( 'gcp-storage-cdn' );
				break;
			}
		}
	}

	public function admin_init_sanitize_callback( $str ) : array
	{
		// set_transient( 'gcp_storage_cdn_admin_notices_' . get_current_user_id(), json_encode( array( 'type' => 'success', 'message' => 'Settings saved.' ) ), 10 );
		return $str;
	}

	public function print_section_info() : void { _e( '', $this->text_domain ); }
	public function html_text_field_callback( ?array $field_array ) : void
	{
		$settings = $this->get_option();
		$settings_field_id = isset( $field_array['field_id'] ) === true ? $field_array['field_id'] : 0;
		$value = esc_attr( isset( $settings[ $settings_field_id ] ) === true ? $settings[ $settings_field_id ] : '' );
		echo( '<input id="' . $settings_field_id . '" type="text" name="' . $this->option_name . '[' . $settings_field_id . ']" size="80" value="' . $value . '" />' );
	}

	public function generate_certificate_authentication_json_select_dropdown( ?array $field_array ) : void
	{

		$settings = $this->get_option();
		$select_certificate_authenticaion_json_files_value_text_pair = new SimpleStringValueTextPair();
		$files = @list_files( GCP_STORAGE_CDN_CERTIFICATE_AUTHENTICATION_JSON_DIRECTORY, 1 );
		foreach ( $files as $file ) {
			if ( is_file( $file ) === false ) { continue; }
			$file_pathinfo = pathinfo( $file );
			if ( strcasecmp( $file_pathinfo['extension'], 'json' ) !== 0 ) { continue; }
			$filesize = size_format( filesize( $file ) );
			$filename = basename( $file );
			$select_certificate_authenticaion_json_files_value_text_pair->Add(
				str_replace( [ GCP_STORAGE_CDN_PLUGIN_DIRECTORY ], [], $file ),
				$filename . ' (' . date ("F d Y H:i:s", filemtime( $file ) ) . ' - ' . $filesize  . ')'
			);
		}

		$settings_field_id = isset( $field_array['field_id'] ) === true ? $field_array['field_id'] : 0;
		$value = esc_attr( isset( $settings[ $settings_field_id ] ) ? $settings[ $settings_field_id ] : '' );
		$result = $this->build_select_dropdown_options_html( $select_certificate_authenticaion_json_files_value_text_pair, $value, $settings_field_id );
		echo( $result );

	}

	public function generate_available_gcp_buckets_select_dropdown( ?array $field_array ) : void
	{

		$result = 'Please ensure that you have selected and included a valid JSON authentication certificate from Google. Save your changes and return here to select a storage bucket.';
		$settings = $this->get_option();
		$gcp_storage_client = null;
		if ( empty( $settings['certificate_authentication_json'] ) === false )
		{
			try
			{
				$auth_cert_json_filepath = realpath( GCP_STORAGE_CDN_PLUGIN_DIRECTORY . $settings['certificate_authentication_json'] );
				$gcp_storage_manager = new Gcp_Client_Manager( $auth_cert_json_filepath );
				$gcp_storage_client = $gcp_storage_manager->create_google_storage_client();

				$select_gcp_buckets_value_text_pair = new SimpleStringValueTextPair();
				foreach ( $gcp_storage_client->buckets() as $bucket ) {
					$bucket_info = $bucket->info();
					$select_gcp_buckets_value_text_pair->Add( $bucket_info['id'], $bucket_info['name'] );
				}

				$settings_field_id = isset( $field_array['field_id'] ) === true ? $field_array['field_id'] : 0;
				$value = esc_attr( isset( $settings[ $settings_field_id ] ) ? $settings[ $settings_field_id ] : '' );
				$result = $this->build_select_dropdown_options_html( $select_gcp_buckets_value_text_pair, $value, $settings_field_id );
			}
			catch( Exception $ex )
			{
				$gcp_storage_client = null;
			}
		}

		echo( $result );

	}

	public function generate_directory_listing_from_selected_bucket( ?array $field_array ) : void
	{

		$result = 'Please ensure that you have selected a GCP Storage Bucket.';
		$settings = $this->get_option();
		$gcp_storage_client = null;
		if ( empty( $settings['certificate_authentication_json'] ) === false && empty( $settings['gcp_bucket'] ) === false )
		{
			try
			{
				$auth_cert_json_filepath = realpath( GCP_STORAGE_CDN_PLUGIN_DIRECTORY . $settings['certificate_authentication_json'] );
				$gcp_storage_manager = new Gcp_Client_Manager( $auth_cert_json_filepath );
				$gcp_storage_client = $gcp_storage_manager->create_google_storage_client();
				$gcp_storage_bucket = $gcp_storage_client->bucket( $settings['gcp_bucket'] );

				$select_gcp_directory_objects_value_text_pair = new SimpleStringValueTextPair();
				$options = [ 'maxResults' => 3000 ];
				foreach ( $gcp_storage_bucket->objects( $options ) as $bucket_obj ) {
					$obj_info = $bucket_obj->info();
					if ( $obj_info['size'] != 0 ) { continue; }
					$select_gcp_directory_objects_value_text_pair->Add( $obj_info['name'], $obj_info['name'] );
				}

				$settings_field_id = isset( $field_array['field_id'] ) === true ? $field_array['field_id'] : 0;
				$value = esc_attr( isset( $settings[ $settings_field_id ] ) ? $settings[ $settings_field_id ] : '' );
				$result = $this->build_select_dropdown_options_html( $select_gcp_directory_objects_value_text_pair, $value, $settings_field_id );
			}
			catch( Exception $ex )
			{
				$gcp_storage_client = null;
			}
		}

		echo( $result );

	}

	private function build_select_dropdown_options_html( SimpleStringValueTextPair $select_options_value_text_pair, string $value, string $settings_field_id ) : string
	{
		$select_options_array = $select_options_value_text_pair->Generate();
		$results = '<select name="' . $this->option_name . '[' . $settings_field_id . ']" id="' . $settings_field_id . '"><option></option>';
		foreach ( $select_options_array as $option ) {
			$results .= '<option value="'. $option['value'] . '"' . ( $value == $option['value'] ? ' selected' : '' ) . '>' . $option['text'] . '</option>';
		}
		$results .= '</select>';
		return $results;
	}

	public function admin_init() : void
	{

		$gcp_storage_cdn_section = 'gcp_storage_cdn_section';

		register_setting( $this->gcp_storage_cdn_group, $this->option_name, array(
			'type' => 'string',
			'sanitize_callback' => array( $this, 'admin_init_sanitize_callback' ),
			'default' => null,
		) );

		add_settings_section(
			$gcp_storage_cdn_section,
			__( 'General Settings', $this->text_domain ),
			array( $this, 'print_section_info' ),
			$this->gcp_storage_cdn_page
		);

		add_settings_field(
			'certificate_authentication_json',
			__( 'Authentication JSON Certificate File', $this->text_domain ),
			array( $this, 'generate_certificate_authentication_json_select_dropdown' ),
			$this->gcp_storage_cdn_page,
			$gcp_storage_cdn_section,
			array( 'field_id' => 'certificate_authentication_json' )
		);

		add_settings_field(
			'gcp_bucket',
			__( 'Select GCP Storage Bucket', $this->text_domain ),
			array( $this, 'generate_available_gcp_buckets_select_dropdown' ),
			$this->gcp_storage_cdn_page,
			$gcp_storage_cdn_section,
			array( 'field_id' => 'gcp_bucket' )
		);

		add_settings_field(
			'gcp_prefix_filter',
			__( 'Prefix Filter', $this->text_domain ),
			array( $this, 'generate_directory_listing_from_selected_bucket' ),
			$this->gcp_storage_cdn_page,
			$gcp_storage_cdn_section,
			array( 'field_id' => 'gcp_prefix_filter' )
		);

		add_settings_field(
			'image_processor_url_prefix',
			__( 'HTTP Image Processsor URL Prefix', $this->text_domain ),
			array( $this, 'html_text_field_callback' ),
			$this->gcp_storage_cdn_page,
			$gcp_storage_cdn_section,
			array( 'field_id' => 'image_processor_url_prefix' )
		);

		add_settings_field(
			'image_processor_transform_query_extension',
			__( 'HTTP Image Processsor Extra Transforms', $this->text_domain ),
			array( $this, 'html_text_field_callback' ),
			$this->gcp_storage_cdn_page,
			$gcp_storage_cdn_section,
			array( 'field_id' => 'image_processor_transform_query_extension' )
		);

	}

	public function options_page() : void { include( 'admin/options_page.php' ); }
	// public function tools_page() : void { include( 'admin/tools_page.php' ); }
	public function admin_menu() : void
	{
		if ( !current_user_can( $this->gcp_storage_cdn_capability ) ) { return; }
		add_options_page( __( 'GCP Storage CDN Settings', $this->text_domain ), __( 'GCP Storage CDN Settings', $this->text_domain ), $this->gcp_storage_cdn_capability, $this->gcp_storage_cdn_page, array( $this, 'options_page' ) );
		// add_management_page( 'gcp_storage_cdn_tool', __( 'GCP Storage CDN Tool', 'cdn-image-import' ), $this->gcp_storage_cdn_capability, 'gcp_storage_cdn_tools', array( $this, 'tools_page' ) );
	}

	//////////////////////////////////////////////////////////

	public function plugin_activation( $network_wide ) : void
	{
		if ( is_multisite() && $network_wide ) {
			$ms_sites = (array) get_sites();
			if ( 0 < count( $ms_sites ) ) {
				foreach ( $ms_sites as $ms_site ) {
					switch_to_blog( $ms_site->blog_id );
					$this->plugin_activated();
					restore_current_blog();
				}
			}
		} else {
			$this->plugin_activated();
		}
	}

	private function plugin_activated() : void
	{
		$admin_role = get_role( 'administrator' );
		$admin_role->add_cap( $this->gcp_storage_cdn_capability, true );
		$admin_role->add_cap( 'administer_media_upload', true );
		flush_rewrite_rules();
	}

	public function plugin_deactivation( $network_wide ) : void
	{
		if ( is_multisite() && $network_wide ) {
			$ms_sites = (array) get_sites();
			if ( 0 < count( $ms_sites ) ) {
				foreach ( $ms_sites as $ms_site ) {
					switch_to_blog( $ms_site->blog_id );
					$this->plugin_deactivated();
					restore_current_blog();
				}
			}
		} else {
			$this->plugin_deactivated();
		}
	}

	private function plugin_deactivated() : void
	{
		global $wp_roles;
		$delete_caps = array( $this->gcp_storage_cdn_capability, 'administer_media_upload' );
		foreach ( $delete_caps as $cap ) {
			foreach ( array_keys( $wp_roles->roles ) as $role ) {
				$wp_roles->remove_cap( $role, $cap );
			}
		}
		flush_rewrite_rules();
	}

	/**
	 * Set query clauses in the SQL statement
	 *
	 * @return array
	 *
	 * @since    0.6.0
	 */
	public static function posts_clauses( $pieces ) {

		global $wp_query, $wpdb;

		$vars = $wp_query->query_vars;
		if ( empty( $vars ) ) {
			$vars = ( isset( $_REQUEST['query'] ) ) ? $_REQUEST['query'] : array();
		}

		// Rewrite the where clause
		if ( ! empty( $vars['s'] ) && ( ( isset( $_REQUEST['action'] ) && 'query-attachments' == $_REQUEST['action'] ) || 'attachment' == $vars['post_type'] ) ) {
			$pieces['where'] = " AND $wpdb->posts.post_type = 'attachment' AND ($wpdb->posts.post_status = 'inherit' OR $wpdb->posts.post_status = 'private')";

			if ( class_exists('WPML_Media') ) {
				global $sitepress;
				//get current language
				$lang = $sitepress->get_current_language();
				$pieces['where'] .= $wpdb->prepare( " AND t.element_type='post_attachment' AND t.language_code = %s", $lang );
			}

			if ( ! empty( $vars['post_parent'] ) ) {
				$pieces['where'] .= " AND $wpdb->posts.post_parent = " . $vars['post_parent'];
			} elseif ( isset( $vars['post_parent'] ) && 0 === $vars['post_parent'] ) {
				// Get unattached attachments
				$pieces['where'] .= " AND $wpdb->posts.post_parent = 0";
			}

			if ( ! empty( $vars['post_mime_type'] ) ) {
				// Use esc_like to escape slash
				$like = '%' . $wpdb->esc_like( $vars['post_mime_type'] ) . '%';
				$pieces['where'] .= $wpdb->prepare( " AND $wpdb->posts.post_mime_type LIKE %s", $like );
			}

			if ( ! empty( $vars['m'] ) ) {
				$year = substr( $vars['m'], 0, 4 );
				$monthnum = substr( $vars['m'], 4 );
				$pieces['where'] .= $wpdb->prepare( " AND YEAR($wpdb->posts.post_date) = %d AND MONTH($wpdb->posts.post_date) = %d", $year, $monthnum );
			} else {
				if ( ! empty( $vars['year'] ) && 'false' != $vars['year'] ) {
					$pieces['where'] .= $wpdb->prepare( " AND YEAR($wpdb->posts.post_date) = %d", $vars['year'] );
				}

				if ( ! empty( $vars['monthnum'] ) && 'false' != $vars['monthnum'] ) {
					$pieces['where'] .= $wpdb->prepare( " AND MONTH($wpdb->posts.post_date) = %d", $vars['monthnum'] );
				}
			}

			// search for keyword "s"
			$like = '%' . $wpdb->esc_like( $vars['s'] ) . '%';
			$pieces['where'] .= $wpdb->prepare( " AND ( ($wpdb->posts.ID LIKE %s) OR ($wpdb->posts.post_title LIKE %s) OR ($wpdb->posts.guid LIKE %s) OR ($wpdb->posts.post_content LIKE %s) OR ($wpdb->posts.post_excerpt LIKE %s)", $like, $like, $like, $like, $like );
			$pieces['where'] .= $wpdb->prepare( " OR ($wpdb->postmeta.meta_key = '_wp_attachment_image_alt' AND $wpdb->postmeta.meta_value LIKE %s)", $like );
			$pieces['where'] .= $wpdb->prepare( " OR ($wpdb->postmeta.meta_key = '_wp_attachment_metadata' AND $wpdb->postmeta.meta_value LIKE %s)", $like );
			$pieces['where'] .= $wpdb->prepare( " OR ($wpdb->postmeta.meta_key = '_wp_attached_file' AND $wpdb->postmeta.meta_value LIKE %s)", $like );

			// Get taxes for attachements
			$taxes = get_object_taxonomies( 'attachment' );
			if ( ! empty( $taxes ) ) {
				$pieces['where'] .= $wpdb->prepare( " OR (tter.slug LIKE %s) OR (ttax.description LIKE %s) OR (tter.name LIKE %s)", $like, $like, $like );
			}

			$pieces['where'] .= " )";

			$pieces['join'] .= " LEFT JOIN $wpdb->postmeta ON $wpdb->posts.ID = $wpdb->postmeta.post_id";

			// Get taxes for attachements
			$taxes = get_object_taxonomies( 'attachment' );
			if ( ! empty( $taxes ) ) {
				$on = array();
				foreach ( $taxes as $tax ) {
					$on[] = "ttax.taxonomy = '$tax'";
				}
				$on = '( ' . implode( ' OR ', $on ) . ' )';

				$pieces['join'] .= " LEFT JOIN $wpdb->term_relationships AS trel ON ($wpdb->posts.ID = trel.object_id) LEFT JOIN $wpdb->term_taxonomy AS ttax ON (" . $on . " AND trel.term_taxonomy_id = ttax.term_taxonomy_id) LEFT JOIN $wpdb->terms AS tter ON (ttax.term_id = tter.term_id) ";
			}

			$pieces['distinct'] = 'DISTINCT';

			$pieces['orderby'] = "$wpdb->posts.post_date DESC";
		}

		return $pieces;
	}

}
