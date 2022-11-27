<?php

/**
 * GCP Storage CDN gcp-storage-cdn.php
 *
 * @package Gcp_Storage_Cdn
 */

use Google\Cloud\Storage\StorageClient;

class Gcp_Client_Manager {

	protected $service_authentication_configuration_filepath;
	protected $client_email;

	public function __construct( string $_service_authentication_configuration_filepath )
	{

		// check if the json authentication configuration file is present
		if ( file_exists( $_service_authentication_configuration_filepath ) === false )
		{
			throw new \Exception( 'Missing: Authentication configuration file path can not be located: ' . $_service_authentication_configuration_filepath );
		}

		// read the file contents
		if ( ( $authentication_configuration = @file_get_contents( $_service_authentication_configuration_filepath ) ) === false )
		{
			throw new \Exception( 'Error: Unable to read the authentication configuration file: ' . $_service_authentication_configuration_filepath );
		}

		// parse the contents as json just to be sure - we will also use this later
		if ( ( $authentication_configuration_obj = json_decode( $authentication_configuration ) ) === null )
		{
			throw new \Exception( 'Error: Unable to decode the JSON from the authentication configuration file: ' . $_service_authentication_configuration_filepath );
		}

		// was there any errors or issues with getting the data that we needed from the authentication configuration file
		if ( json_last_error() !== JSON_ERROR_NONE || isset( $authentication_configuration_obj->client_email ) === false || empty( $authentication_configuration_obj->client_email ) === true )
		{
			throw new \Exception( 'Error: Unable to decode the JSON from the authentication configuration file or client email missing: ' . $_service_authentication_configuration_filepath );
		}

		if ( putenv( 'GOOGLE_APPLICATION_CREDENTIALS=' . $_service_authentication_configuration_filepath ) === false )
		{
			throw new \Exception( 'Failed: Setting Google Application Credentials environment variable failed.' );
		}

		$this->client_email = $authentication_configuration_obj->client_email;
		$this->service_authentication_configuration_filepath = $_service_authentication_configuration_filepath;

	}

	public function build_google_client( array $scopes_array = array() ) : Google_Client
	{

		$service_account = new Google_Client();
		$service_account->setAuthConfig( $this->service_authentication_configuration_filepath );
		$service_account->useApplicationDefaultCredentials();
		$service_account->setSubject( $this->client_email );
		$service_account->setApplicationName( 'external-services' );
		$service_account->setAccessType( 'offline' );
		$service_account->setScopes( $scopes_array );

		return $service_account;

	}

	public function create_google_storage_client() : StorageClient
	{
		$conf = array(
			'keyFilePath' => $this->service_authentication_configuration_filepath
		);
		$storage_client = new StorageClient( $conf );
		return $storage_client ;
	}

}
