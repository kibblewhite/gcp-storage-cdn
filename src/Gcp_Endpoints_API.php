<?php

class Gcp_Endpoints_API
{
	private static $instance;
	private $namespace = GCP_STORAGE_CDN_ENDPOINTS_NAMESPACE_V1;
	private $gcp_object_hash_postmeta_key = GCP_STORAGE_CDN_OBJECT_HASH_POSTMETA_KEY;
	private $gcp_object_md5_hash_postmeta_key = GCP_STORAGE_CDN_OBJECT_MD5HASH_POSTMETA_KEY;
	private $gcp_supported_mime_types_array;
	private $gcp_storage_cdn;

	public function __construct()
	{
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		$this->gcp_storage_cdn = Gcp_Storage_Cdn::get_instance();
		$this->gcp_supported_mime_types_array = array_map( 'trim', str_getcsv( GCP_STORAGE_CDN_SUPPORTED_MIME_TYPES_CSV ) );
	}

	public static function get_instance() : Gcp_Endpoints_API
	{
		if ( !isset( self::$instance ) ) { self::$instance = new self(); }
		return self::$instance;
	}

	public function rest_api_init() : void
	{
		register_rest_route( $this->namespace, 'sync', array(
			'methods'					=> WP_REST_Server::CREATABLE,
			'callback'					=> array( $this, 'sync' ),
			'permission_callback'		=> '__return_true'
		) );
	}

	public function sync( WP_REST_Request $request ) : void
	{
		global $wpdb;
		$settings = $this->gcp_storage_cdn->get_option();

		if ( isset( $settings['gcp_bucket'] ) === false || empty( $settings['gcp_bucket'] ) === true )
		{
			wp_send_json_error( new WP_REST_Response( 'No bucket defined in the settings. Go back and make sure it is set correctly.' ), 422 );
		}

		$auth_cert_json_filepath = realpath( GCP_STORAGE_CDN_PLUGIN_DIRECTORY . $settings['certificate_authentication_json'] );
		$gcp_storage_manager = new Gcp_Client_Manager( $auth_cert_json_filepath );
		$gcp_storage_client = $gcp_storage_manager->create_google_storage_client();
		$gcp_storage_bucket = $gcp_storage_client->bucket( $settings['gcp_bucket'] );

		$gcp_files_collection = array();
		$options = [ 'prefix' => [ $settings['gcp_prefix_filter'] ], 'maxResults' => 3000 ];
		foreach ( $gcp_storage_bucket->objects( $options ) as $object ) {

			$obj_info = $object->info();
			if ( $obj_info['size'] == 0 ) { continue; }
			if ( in_array( $obj_info['contentType'], $this->gcp_supported_mime_types_array ) === false ) { continue; }
			if ( isset( $obj_info['metadata'] ) === false ) { $obj_info['metadata'] = array( 'alt' => '', 'caption' => '', 'excerpt' => '', 'width' => '', 'height' => '' ); }

			// note (kibble|2021-07-19): use the object's bucket and name to build the public url as explained > https://cloud.google.com/storage/docs/request-endpoints#access_to_public_objects
			$obj_info['url'] = implode( DIRECTORY_SEPARATOR, array( 'https:/', $obj_info['bucket'], str_replace( ' ', '%20', $obj_info['name'] ) ) );
			$obj_info[ $this->gcp_object_hash_postmeta_key ] = base64_encode( implode( ':', array( $obj_info['md5Hash'], $obj_info['etag'] ) ) );
			$obj_info[ $this->gcp_object_md5_hash_postmeta_key ] = $obj_info['md5Hash'];

			$is_animated_gif_metadata_missing = isset( $obj_info['metadata']['is_animated_gif'] ) === false || empty( $obj_info['metadata']['is_animated_gif'] ) === true;
			$width_metadata_missing = isset( $obj_info['metadata']['width'] ) === false || empty( $obj_info['metadata']['width'] ) === true;
			$height_metadata_missing = isset( $obj_info['metadata']['height'] ) === false || empty( $obj_info['metadata']['height'] ) === true;

			if ( $is_animated_gif_metadata_missing === true || $width_metadata_missing === true || $height_metadata_missing === true )
			{
				$obj_url_contents = file_get_contents( $obj_info['url'] );
			}

			if ( $is_animated_gif_metadata_missing === true )
			{
				$frame_location = 0;
				$count = 0;
				$limit = 0;
				while ( $count < 2 && $limit < 20 ) // prevents search beyond the second frame
				{
					$limit++;
					$where1 = strpos( $obj_url_contents, "\x00\x21\xF9\x04", $frame_location );
					if ( $where1 === false ) { break; }
					$frame_location = $where1 + 1;
					$where2 = strpos( $obj_url_contents, "\x00\x2C", $frame_location );
					if ( $where2 === false ) { break; }
					if ( $where1 + 8 === $where2 ) { $count++; }
					$frame_location = $where2 + 1;
				}
				$obj_info['metadata']['is_animated_gif'] = $count > 1 ? true : false;
				// todo (kibble|2021-09-10): check that the net line is correct - should be without the image resizing/processing url
				if ( $obj_info['metadata']['is_animated_gif'] === true ) { $obj_info['url'] = $obj_info['url']; }
			}

			if ( $width_metadata_missing === true || $height_metadata_missing === true )
			{
				try
				{
					list( $width, $height, $type, $attr ) = getimagesizefromstring( $obj_url_contents );
					$obj_info['metadata'] = array_merge( $obj_info['metadata'] , array(
						'width'		=> intval( $width ),
						'height'	=> intval( $height )
					) );
					$object->update( [ 'metadata' => $obj_info['metadata'] ] );
				}
				catch ( Exception $ex )
				{
					Gcp_Storage_Cdn::write_log( $ex->getMessage() );
					continue;
				}
			}

			array_push( $gcp_files_collection, $obj_info );
		}

		$gcp_md5_hash_array = array_column( $gcp_files_collection, $this->gcp_object_md5_hash_postmeta_key );
		$sql = "
			SELECT
				ID, guid,
				{$this->gcp_object_md5_hash_postmeta_key}.meta_value AS {$this->gcp_object_md5_hash_postmeta_key},
				{$this->gcp_object_hash_postmeta_key}.meta_value AS {$this->gcp_object_hash_postmeta_key}

			FROM
				{$wpdb->posts}

			INNER JOIN
				{$wpdb->postmeta} AS {$this->gcp_object_hash_postmeta_key} ON {$wpdb->posts}.ID = {$this->gcp_object_hash_postmeta_key}.post_id
				AND {$this->gcp_object_hash_postmeta_key}.meta_key = '{$this->gcp_object_hash_postmeta_key}'

			INNER JOIN
				{$wpdb->postmeta} AS {$this->gcp_object_md5_hash_postmeta_key} ON {$wpdb->posts}.ID = {$this->gcp_object_md5_hash_postmeta_key}.post_id
				AND {$this->gcp_object_md5_hash_postmeta_key}.meta_key = '{$this->gcp_object_md5_hash_postmeta_key}'

			WHERE {$wpdb->posts}.post_type = 'attachment'
				AND {$this->gcp_object_md5_hash_postmeta_key}.meta_value IN ( '" . implode( "', '", $gcp_md5_hash_array ) . "' )";

		$existing_objs = $wpdb->get_results( $sql );
		$existing_objs_hashes = array_column( $existing_objs, $this->gcp_object_hash_postmeta_key );
		$existing_objs_md5_hashes = array_column( $existing_objs, $this->gcp_object_md5_hash_postmeta_key );

		list(
			'registered_image_subsizes' => $wp_registered_image_subsizes,
			'registered_image_subsizes_checksum' => $wp_registered_image_subsizes_checksum
		) = Gcp_Storage_Cdn::get_wp_registered_image_subsizes_and_checksum();

		$response_array = array();

		foreach( $gcp_files_collection as $gcp_obj ) {

			// if the incoming item from the gcp storage has got the same md5 & etag hash (gcp_object_hash) then there are no changes - continue to next item...
			if ( in_array( $gcp_obj[ $this->gcp_object_hash_postmeta_key ], $existing_objs_hashes ) === true ) { continue; }

			$attachment = array(
				'guid'				=> $gcp_obj['url'],
				'post_mime_type'	=> $gcp_obj['contentType'],
				'post_title'		=> sanitize_text_field( empty( $gcp_obj['metadata']['caption'] ?? '' ) === true ? wp_basename( preg_replace( '/\.[^.]+$/', '', $gcp_obj['name'] ) ) : $gcp_obj['metadata']['caption'] ?? '' ),
				'post_content'		=> sanitize_text_field( $gcp_obj['metadata']['alt'] ?? '' ),
				'post_excerpt'		=> html_entity_decode( sanitize_text_field( $gcp_obj['metadata']['excerpt'] ?? '' ) )
			);

			$attachment_metadata = array_merge( $gcp_obj['metadata'] ?? array(), array(
				'width'										=> $gcp_obj['metadata']['width'],
				'height'									=> $gcp_obj['metadata']['height'],
				'is_animated_gif'							=> $gcp_obj['metadata']['is_animated_gif'],
				'is_cdn_resource'							=> true,
				'file'										=> wp_basename( $gcp_obj['url'] ),
				'source_url'								=> $gcp_obj['url'],
				'mime_type'									=> $gcp_obj['contentType'],
				'registered_image_subsizes_checksum'		=> $wp_registered_image_subsizes_checksum,
				'sizes'										=>	array(
																	'full' => array(
																		'width'		=> $gcp_obj['metadata']['width'],
																		'height'	=> $gcp_obj['metadata']['height'],
																		'crop'		=> false
																	)
																),
				$this->gcp_object_hash_postmeta_key			=> $gcp_obj[ $this->gcp_object_hash_postmeta_key ],
				$this->gcp_object_md5_hash_postmeta_key		=> $gcp_obj[ $this->gcp_object_md5_hash_postmeta_key ]
			) );

			$attachment_metadata['sizes'] = Gcp_Storage_Cdn::build_image_meta_sizes( $attachment_metadata, $wp_registered_image_subsizes );

			// reassociate the attachment array to the entity in the database by the key's ID...
			$key = array_search( $gcp_obj[ $this->gcp_object_md5_hash_postmeta_key ], array_column( $existing_objs, $this->gcp_object_md5_hash_postmeta_key ) );
			if ( in_array( $gcp_obj[ $this->gcp_object_md5_hash_postmeta_key ], $existing_objs_md5_hashes ) && $key !== false ) { $attachment['ID'] = $existing_objs[ $key ]->ID; }

			// // TODO (kibble|2020-11-04): add filter to allow other developers to write an import method
			// //  - any items they import themselves should return
			// //	- either the item again for this process to finish importing
			// //  - or null to discard / otherwise double import might occur
			$attachment_id = ( in_array( $gcp_obj[ $this->gcp_object_md5_hash_postmeta_key ], $existing_objs_md5_hashes ) ) ? wp_update_post( $attachment ) : wp_insert_attachment( $attachment );
			wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

			$this->upsert_postmeta( $attachment_id, '_wp_attachment_image_alt', $attachment['post_content'] );
			$this->upsert_postmeta( $attachment_id, $this->gcp_object_hash_postmeta_key, $gcp_obj[ $this->gcp_object_hash_postmeta_key ] );
			$this->upsert_postmeta( $attachment_id, $this->gcp_object_md5_hash_postmeta_key, $gcp_obj[ $this->gcp_object_md5_hash_postmeta_key ] );

			unset( $attachment['guid'] );
			$result_array = array_merge( [ 'url' => $gcp_obj['url'] ], $attachment );

			if ( isset( $attachment_metadata['sizes']['full'] ) === true && empty( $attachment_metadata['sizes']['full']['file'] ) === false ) {
				$result_array['url'] = $attachment_metadata['sizes']['full']['file'];
			}

			array_push( $response_array, $result_array );

		}

		$response_header_array = array( [ 'x-records-processed' => count ( $response_array ) ] );
		wp_send_json_success( new WP_REST_Response( $response_array, 200, $response_header_array ) );
	}

	private function upsert_postmeta( int $attachment_id, string $postmeta_key, string $postmeta_value ) : void
	{
		if ( empty( $postmeta_value ) === false && add_post_meta( $attachment_id, $postmeta_key, $postmeta_value, true ) === false ) {
			update_post_meta( $attachment_id, $postmeta_key, $postmeta_value );
		}
	}

}