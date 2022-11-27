<?php
/**
 * Class BasicTests
 *
 * @package Gcp_Storage_Cdn
 */

use Google\Cloud\Storage\StorageClient;

/**
 * Basic Tests
 */
class BasicTests extends WP_UnitTestCase
{

	protected $service_authentication_configuration_filepath;
	protected $service_account;

	function setUp()
	{
		echo( PHP_EOL );
		$keys_path = realpath( __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets'. DIRECTORY_SEPARATOR . 'keys' );
		$this->service_authentication_configuration_filepath = $keys_path . DIRECTORY_SEPARATOR . 'swingstep-engine-services-db7af5e1c1ec.json';
	}

	private function build_google_client( string $service_authentication_configuration_filepath, array $scopes_array ) : Google_Client
	{

		// check if the json authentication configuration file is present
		if ( file_exists( $service_authentication_configuration_filepath ) === false )
		{
			throw new \Exception( 'Missing: Authentication configuration file path can not be located.' );
		}

		// read the file contents
		if ( ( $authentication_configuration = file_get_contents( $service_authentication_configuration_filepath ) ) === false )
		{
			throw new \Exception( 'Error: Unable to read the authentication configuration file: ' . $service_authentication_configuration_filepath );
		}

		// parse the contents as json just to be sure - we will also use this later
		if ( ( $authentication_configuration_obj = json_decode( $authentication_configuration ) ) === null )
		{
			throw new \Exception( 'Error: Unable to decode the JSON from the authentication configuration file: ' . $service_authentication_configuration_filepath );
		}

		// was there any errors or issues with getting the data that we needed from the authentication configuration file
		if ( json_last_error() !== JSON_ERROR_NONE || empty( $authentication_configuration_obj->client_email ) === true )
		{
			throw new \Exception( 'Error: Unable to decode the JSON from the authentication configuration file or client email missing: ' . $service_authentication_configuration_filepath );
		}

		// set the environment variable
		if ( putenv( 'GOOGLE_APPLICATION_CREDENTIALS=' . $this->service_authentication_configuration_filepath ) === false )
		{
			throw new \Exception( 'Failed: Setting Google Application Credentials environment variable failed.' );
		}

		$service_account = new Google_Client();
		$service_account->setAuthConfig( $this->service_authentication_configuration_filepath );
		$service_account->useApplicationDefaultCredentials();
		$service_account->setSubject( $authentication_configuration_obj->client_email );
		$service_account->setApplicationName( 'external-services' );
		$service_account->setAccessType( 'offline' );
		$service_account->setScopes( $scopes_array );

		return $service_account;

	}

	private function create_google_storage_client() : StorageClient
	{
		$conf = array(
			'keyFilePath' => $this->service_authentication_configuration_filepath
		);
		$storage_client = new StorageClient( $conf );
		return $storage_client ;
	}

	/**
	 * @test
	 * Basic test...
	 */
	public function test_sample() : void
	{

		echo( 'Using service authentication configuration file path: ' . $this->service_authentication_configuration_filepath . PHP_EOL );

		$scopes_array = array(
			// Google_Service_Directory::ADMIN_DIRECTORY_GROUP_READONLY,
			// Google_Service_Directory::ADMIN_DIRECTORY_GROUP_MEMBER_READONLY,
			// Google_Service_Directory::ADMIN_DIRECTORY_USER_READONLY
		);

		$this->service_account = $this->build_google_client( $this->service_authentication_configuration_filepath, $scopes_array );
		$storage_client = $this->create_google_storage_client();

		foreach ( $storage_client->buckets() as $bucket ) {
			echo( 'Bucket: ' . $bucket->name() . PHP_EOL );
			$options = [ 'prefix' => [ 'swingstep.tv/www/' ] ];
			foreach ( $bucket->objects( $options ) as $object ) {
				// var_dump( $object->name() );
				// var_dump( $object->info() );
			}
		}



		// $this->google_service = new Google_Service_Directory( $this->service_account );

		$tmp = true;
		$this->assertTrue( $tmp );
	}

	/**
	 * @test
	 * test_test_animated_gif_detection
	 *
	 * @run phpunit --filter test_animated_gif_detection
	 *
	 */
	//
	public function test_animated_gif_detection() : void
	{
		$img_url = 'https://media.swingstep.net/swingstep.com/www/natalia-assets/Left-Side-400.gif';
		$obj_url_contents = file_get_contents( $img_url );
		$frame_location = 0;
		$count = 0;
		$limit = 0;
		while ( $count < 2 && $limit < 20 ) // prevents search beyond the second frame
		{
			$limit++;
			$where1 = strpos( $obj_url_contents, "\x00\x21\xF9\x04", $frame_location );
			echo( 'frame_location: ' . $frame_location . PHP_EOL );
			if ( $where1 === false ) { break; }
			$frame_location = $where1 + 1;
			$where2 = strpos( $obj_url_contents, "\x00\x2C", $frame_location );
			echo( 'frame_location: ' . $frame_location . PHP_EOL );
			if ( $where2 === false ) { break; }
			if ( $where1 + 8 === $where2 ) { $count++; }
			$frame_location = $where2 + 1;
		}
		echo( 'Limit: ' . $limit );
		$this->assertTrue( $count > 1 );
	}

}
