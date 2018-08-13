<?php
/**
 * Tests for class WP_HTTPS_Detection.
 *
 * @package PWA
 */

/**
 * Tests for class WP_HTTPS_Detection.
 */
class Test_WP_HTTPS_Detection extends WP_UnitTestCase {

	/**
	 * The response code for an unsucessful loopback request to an HTTPS URL.
	 *
	 * @var int
	 */
	const INCORRECT_RESPONSE_CODE = 301;

	/**
	 * Tested instance.
	 *
	 * @var WP_HTTPS_Detection
	 */
	public $instance;

	/**
	 * The message passed to wp_die().
	 *
	 * @var string
	 */
	public $wp_die_message;

	/**
	 * Setup.
	 *
	 * @inheritdoc
	 */
	public function setUp() {
		parent::setUp();
		$this->instance = new WP_HTTPS_Detection();
		add_filter( 'http_response', array( $this, 'mock_successful_response' ) );
	}

	/**
	 * Test init.
	 *
	 * @covers WP_HTTPS_Detection::init()
	 */
	public function test_init() {
		$this->instance->init();
		$this->assertEquals( 10, has_action( 'wp', array( $this->instance, 'schedule_cron' ) ) );
		$this->assertEquals( 10, has_action( WP_HTTPS_Detection::CRON_HOOK, array( $this->instance, 'update_option_https_support' ) ) );
		$this->assertEquals( PHP_INT_MAX, has_filter( 'cron_request', array( $this->instance, 'ensure_http_if_sslverify' ) ) );
	}

	/**
	 * Test is_https_supported.
	 *
	 * @covers WP_HTTPS_Detection::is_https_supported()
	 */
	public function test_is_https_supported() {
		$this->assertFalse( WP_HTTPS_Detection::is_https_supported() );

		update_option( WP_HTTPS_Detection::HTTPS_SUPPORT_OPTION_NAME, true );
		$this->assertTrue( WP_HTTPS_Detection::is_https_supported() );

		update_option( WP_HTTPS_Detection::HTTPS_SUPPORT_OPTION_NAME, false );
		$this->assertFalse( WP_HTTPS_Detection::is_https_supported() );
	}

	/**
	 * Test get_insecure_content.
	 *
	 * @covers WP_HTTPS_Detection::get_insecure_content()
	 */
	public function test_get_insecure_content() {
		$html_boilerplate = '<!DOCTYPE html><html><head><meta http-equiv="content-type"></head><body>%s</body></html>';
		$insecure_img_src = 'http://example.com/baz';
		$body             = sprintf(
			$html_boilerplate,
			sprintf(
				'<img src="%s">',
				$insecure_img_src
			)
		);
		$this->assertEquals( array( 'passive' => array( $insecure_img_src ) ), $this->instance->get_insecure_content( compact( 'body' ) ) );

		$insecure_audio_src = 'http://example.com/foo';
		$insecure_video_src = 'http://example.com/bar';
		$body               = sprintf(
			$html_boilerplate,
			sprintf(
				'<audio src="%s"></audio><video src="%s"></video>',
				$insecure_audio_src,
				$insecure_video_src
			)
		);
		$insecure_urls      = $this->instance->get_insecure_content( compact( 'body' ) );
		$this->assertTrue( in_array( $insecure_audio_src, $insecure_urls['passive'], true ) );
		$this->assertTrue( in_array( $insecure_video_src, $insecure_urls['passive'], true ) );

		// Allow interpolating tags into the <head>.
		$html_boilerplate    = '<!DOCTYPE html><html><head>%s</head><body>%s</body></html>';
		$insecure_script_src = 'http://example.com/script';
		$insecure_link_href  = 'http://example.com/link';
		$body                = sprintf(
			$html_boilerplate,
			sprintf(
				'<script src="%s"></script><link href="%s" rel="stylesheet">', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
				$insecure_script_src,
				$insecure_link_href
			),
			sprintf(
				'<audio src="%s"></audio>',
				$insecure_audio_src
			)
		);
		$this->assertEquals(
			array(
				'passive' => array( $insecure_audio_src ),
				'active'  => array( $insecure_script_src, $insecure_link_href ),
			),
			$this->instance->get_insecure_content( compact( 'body' ) )
		);
	}

	/**
	 * Test schedule_cron.
	 *
	 * @covers WP_HTTPS_Detection::schedule_cron()
	 */
	public function test_schedule_cron() {
		$this->assertFalse( wp_next_scheduled( WP_HTTPS_Detection::CRON_HOOK ) );

		$this->instance->schedule_cron();
		$this->assertNotFalse( wp_next_scheduled( WP_HTTPS_Detection::CRON_HOOK ) );

		$cron_array       = _get_cron_array();
		$https_check_cron = end( $cron_array );
		$this->assertEquals(
			array(
				'args'     => array(),
				'interval' => DAY_IN_SECONDS / 2,
				'schedule' => 'twicedaily',
			),
			reset( $https_check_cron[ WP_HTTPS_Detection::CRON_HOOK ] )
		);
	}

	/**
	 * Test update_https_support_options.
	 *
	 * @covers WP_HTTPS_Detection::update_https_support_options()
	 */
	public function test_update_https_support_options() {
		$this->instance->update_https_support_options();
		$this->assertTrue( get_option( WP_HTTPS_Detection::HTTPS_SUPPORT_OPTION_NAME ) );

		// There should be HTTPS support, as check_https_support() should return true.
		add_filter( 'http_response', array( $this, 'mock_error_response' ) );
		$this->instance->update_https_support_options();
		$this->assertTrue( get_option( WP_HTTPS_Detection::HTTPS_SUPPORT_OPTION_NAME ) );
		remove_filter( 'http_response', array( $this, 'mock_error_response' ) );

		// The response is a 301, so the option value should be false.
		add_filter( 'http_response', array( $this, 'mock_incorrect_response' ) );
		$this->instance->update_https_support_options();
		$this->assertFalse( get_option( WP_HTTPS_Detection::HTTPS_SUPPORT_OPTION_NAME ) );
		remove_filter( 'http_response', array( $this, 'mock_incorrect_response' ) );
	}

	/**
	 * Test check_https_support.
	 *
	 * @covers WP_HTTPS_Detection::check_https_support()
	 */
	public function test_check_https_support() {
		add_filter( 'http_response', array( $this, 'mock_successful_response' ) );
		$https_support = $this->instance->check_https_support();
		$this->assertEquals(
			array( 'code' => 200 ),
			$https_support['response']
		);
		$this->assertContains( '<link rel="manifest"', $https_support['body'] );

		// The response should have a code of 301.
		add_filter( 'http_response', array( $this, 'mock_incorrect_response' ) );
		$https_support = $this->instance->check_https_support();
		$this->assertEquals( array( 'code' => self::INCORRECT_RESPONSE_CODE ), $https_support['response'] );
		remove_filter( 'http_response', array( $this, 'mock_incorrect_response' ) );
	}

	/**
	 * Test has_proper_manifest.
	 *
	 * @covers WP_HTTPS_Detection::has_proper_manifest()
	 */
	public function test_has_proper_manifest() {
		$html_boilerplate          = '<!DOCTYPE html><html><head>%s</head><body></body></html>';
		$document_without_manifest = sprintf( $html_boilerplate, '<meta property="og:type" content="website" />' );
		$this->assertFalse( $this->instance->has_proper_manifest( $document_without_manifest ) );

		$document_with_incorrect_manifest_url = sprintf( $html_boilerplate, '<link rel="manifest" href="https://example.com/incorrect-manifest-location"><link rel="test-link-no-href">' );
		$this->assertFalse( $this->instance->has_proper_manifest( $document_with_incorrect_manifest_url ) );

		$document_with_proper_manifest = sprintf( $html_boilerplate, '<link rel="manifest" href="' . rest_url( WP_Web_App_Manifest::REST_NAMESPACE . WP_Web_App_Manifest::REST_ROUTE ) . '">' );
		$this->assertTrue( $this->instance->has_proper_manifest( $document_with_proper_manifest ) );
	}

	/**
	 * Test ensure_http_if_sslverify.
	 *
	 * @covers WP_HTTPS_Detection::ensure_http_if_sslverify()
	 */
	public function test_ensure_http_if_sslverify() {

		// The arguments don't have an HTTPS URL and 'sslverify' isn't true, so they shouldn't change.
		$http_url               = 'http://example.com';
		$allowed_cron_arguments = array(
			'url'  => $http_url,
			'args' => array(
				'sslverify' => false,
			),
		);
		$this->assertEquals( $allowed_cron_arguments, $this->instance->ensure_http_if_sslverify( $allowed_cron_arguments ) );

		// With an HTTPS URL and 'sslverify' => true, this should change 'sslverify' to false.
		$https_url                 = 'https://example.com';
		$disallowed_cron_arguments = array(
			'url'  => $https_url,
			'args' => array(
				'sslverify' => true,
			),
		);
		$cron_arguments_sslverify_true['args']['sslverify'] = true;
		$filtered_cron_arguments                            = $this->instance->ensure_http_if_sslverify( $disallowed_cron_arguments );
		$this->assertFalse( $filtered_cron_arguments['args']['sslverify'] );
		$this->assertEquals( $https_url, $filtered_cron_arguments['url'] );

		// The URL is HTTP, so 'sslverify' => true is allowed, and the arguments shouldn't change.
		$allowed_cron_arguments_http        = $disallowed_cron_arguments;
		$allowed_cron_arguments_http['url'] = $http_url;
		$this->assertEquals( $allowed_cron_arguments_http, $this->instance->ensure_http_if_sslverify( $allowed_cron_arguments_http ) );
	}

	/**
	 * Alters the response, to simulate a scenario where HTTPS isn't supported.
	 *
	 * @param WP_HTTP_Requests_Response $response The response object.
	 * @return WP_HTTP_Requests_Response $response The filtered response object.
	 */
	public function mock_incorrect_response( $response ) {
		$response['response']['code'] = self::INCORRECT_RESPONSE_CODE;
		return $response;
	}

	/**
	 * Alters the response to be a WP_Error.
	 *
	 * @return WP_Error An error response.
	 */
	public function mock_error_response() {
		return new WP_Error();
	}

	/**
	 * Overrides the response body from wp_remote_get().
	 *
	 * This mocks the expected response,
	 * by adding a <link rel="manifest"> with the correct href value.
	 *
	 * @return array The response.
	 */
	public function mock_successful_response() {
		return array(
			'body'     => sprintf( '<html><head><link rel="manifest" href="%s"></head><body></body></html>', rest_url( WP_Web_App_Manifest::REST_NAMESPACE . WP_Web_App_Manifest::REST_ROUTE ) ),
			'response' => array(
				'code' => 200,
			),
		);
	}
}
