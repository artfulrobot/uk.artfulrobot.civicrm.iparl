<?php

use CRM_Iparl_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Iparl\WebhookProcessor;

require_once __DIR__ . '/shared.php';

/**
 * Test basic webhooks
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class IparlTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use IparlShared;

  public function setUpHeadless() {

    // We need a clean place to start from in case the messy QueueTest has run before us
    static $initialReset = FALSE;
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    $r = \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply($initialReset);
    $initialReset = FALSE;

    return $r;
  }

  public function setUp() :void {
    // Remove all cached values.
    unset(\Civi::$statics['iparl_titles_action']);
    unset(\Civi::$statics['iparl_titles_petition']);
    $cache = \CRM_Utils_Cache::create([
      'type' => ['SqlGroup', 'ArrayCache'],
      'name' => 'iparl',
    ]);
    $cache->deleteMultiple(['iparl_titles_action', 'iparl_titles_petition']);

    WebhookProcessor::$iparl_logging = 'phpunit';
  }
  public function tearDown() :void {
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_cache WHERE group_name = 'iparl';");
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_setting WHERE name LIKE 'iparl_%';");
    unset(\Civi::$statics['iparl_titles_petition']);
    unset(\Civi::$statics['iparl_titles_action']);
  }
  /**
   * This is a rather long test.
   *
   * - Submit a webhook
   * - Check the contact was created
   * - check the phone was created
   * - check the address was added
   * - check the activity was added with subject 'Action 123'
   * - configure iparl username (enabling lookup of action titles)
   * - submit another webhook
   * - check that the contact created earlier was found
   * - check that the phone was identified as already there.
   * - check that the address was identified as already there.
   * - check that a new activity was added with subject including action title
   * - check that the lookup of action title data was only called once.
   * - check that accessing lookup for actions again returns cached value
   * - check that lookup fires for petitions data.
   *
   */
  public function testAction() {
    global $iparl_hook_test;

    WebhookProcessor::$iparl_logging = 'phpunit';

    // Mock the iParl XML API.
    $calls = 0;
    $this->mockIparlTitleLookup($calls);
    $this->setMockIParlSetting();

    $result = WebhookProcessor::processQueuedWebhook([
      'actionid' => 123,
      'secret'   => 'helloHorseHeadLikeYourJumper',
      'name'     => 'Wilma',
      'surname'  => 'Flintstone',
      'address1' => 'Cave 123',
      'address2' => 'Cave Street',
      'town'     => 'Rocksville',
      'postcode' => 'SW1A 0AA',
      'country'  => 'UK',
      'email'    => 'wilma@example.com',
      'phone'    => '01234 567890',
      'optin1'   => 1,
      'optin2'   => 1,
      'date'     => '2021-02-03 12:34:56',
    ]);
    $this->assertTrue($result, "Expected success from processWebhook. Here's the log:\n" . implode("\n",WebhookProcessor::$test_log));

    // There should now be a contact for Wilma
    $result = civicrm_api3('Contact', 'get', ['email' => 'wilma@example.com', 'first_name' => 'Wilma', 'last_name' => 'Flintstone']);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $contact_id = current($result['values'])['id'];

    $this->assertArrayRegex([
      "/^Processing queued webhook: .*/",
      'Cache miss on looking up iparl_titles_action, must fetch',
      'Caching 2 results from https://iparlsetup.com/api/superfoo/actions.xml for 1 hour.',
      "Created contact $contact_id because email was not found.",
      "Created phone",
      "Created address",
      "Cache hit (static) on iparl_titles_action",
      "/Created iParl action activity \d+: Action 123: Some demo action/",
      "/Processed \(deprecated\) hook_civicrm_iparl_webhook_post_process .*/",
      "/^Successfully created\/updated contact $contact_id in \d+(\.\d+)?s$/",
    ], WebhookProcessor::$test_log, "Failed testing that a new contact was created.");

    // There should be a phone.
    $result = civicrm_api3('Phone', 'get', ['sequential' => 1, 'contact_id' => $contact_id]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals('01234567890', $result['values'][0]['phone_numeric']);

    // There should be one address.
    $result = civicrm_api3('Address', 'get', [
      'contact_id' => $contact_id,
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals('Cave 123', $result['values'][0]['street_address']);

    // There should be one activity.
    $result = civicrm_api3('Activity', 'get',
      [
     'target_contact_id' => $contact_id,
     'return'               => ["activity_type_id.name", 'subject', 'activity_date_time'],
     'sequential'           => 1
      ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals('Action 123: Some demo action', $result['values'][0]['subject']);
    $this->assertEquals('2021-02-03 12:34:56', $result['values'][0]['activity_date_time']);


    // Check that the hook was fired.
    $this->assertIsArray($iparl_hook_test);
    $this->assertArrayHasKey('contact', $iparl_hook_test);

    // Repeat. There should now be two activities but only one contact
    // We set the username though to obtain more info for the activity.
    WebhookProcessor::$test_log = [];
    $result = WebhookProcessor::processQueuedWebhook([
      'actionid' => 123,
      'secret'   => 'helloHorseHeadLikeYourJumper',
      'name'     => 'Wilma',
      'surname'  => 'Flintstone',
      'address1' => 'Cave 123',
      'address2' => 'Cave Street',
      'town'     => 'Rocksville',
      'postcode' => 'SW1A 0AA',
      'country'  => 'UK',
      'email'    => 'wilma@example.com',
      'phone'    => '01234 567890',
      'optin1'   => 1,
      'optin2'   => 1,
    ]);
    $this->assertTrue($result);

    // There should now be a contact for Wilma
    $result = civicrm_api3('Contact', 'get', ['email' => 'wilma@example.com', 'first_name' => 'Wilma', 'last_name' => 'Flintstone']);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals($contact_id, current($result['values'])['id']);

    $this->assertArrayRegex([
      "/^Processing queued webhook: .*/",
      "Cache hit (static) on iparl_titles_action",
      "Found contact $contact_id by email match.",
      "Phone already present",
      "Address already existed.",
      "Cache hit (static) on iparl_titles_action",
      "/Created iParl action activity \d+: Action 123: Some demo action/",
      "/Processed \(deprecated\) hook_civicrm_iparl_webhook_post_process .*/",
      "/^Successfully created\/updated contact $contact_id in \d+(\.\d+)?s$/",
    ], WebhookProcessor::$test_log, "Failed testing that a 2nd action resulted in the existing contact being updated.");

    // There should be one phone.
    $result = civicrm_api3('Phone', 'get', ['sequential' => 1, 'contact_id' => $contact_id]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals('01234567890', $result['values'][0]['phone_numeric']);

    $result = civicrm_api3('Activity', 'get',
      [
     'target_contact_id' => $contact_id,
     'return'            => ["activity_type_id.name", 'subject'],
     'sequential'        => 1,
     'options'           => ['sort' => 'id'],
      ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(2, $result['count']);
    // The second activity should have a fancier subject
    $this->assertEquals('Action 123: Some demo action', $result['values'][1]['subject']);

    $this->assertEquals(1, $calls);
    $lookup = WebhookProcessor::getIparlObject('action');
    $this->assertIsArray($lookup);
    $this->assertEquals(1, $calls, 'Multiple calls to fetch iParl api resource suggests caching fail.');

    $lookup = WebhookProcessor::getIparlObject('petition');
    $this->assertIsArray($lookup);
    $this->assertEquals(2, $calls);
  }

  /**
   * Check name splitting works.
   */
  public function testNamesSeparateFirstAndLast() {

    $data = [
      'name' => 'Wilma',
      'surname' => 'Flintstone',
    ];
    WebhookProcessor::parseNames($data);
    $this->assertEquals('Wilma', $data['first_name']);
    $this->assertEquals('Flintstone', $data['last_name']);
  }

  /**
   * Check name splitting works.
   */
  public function testNamesCombinedFirstAndLast() {

    $data = ['name' => 'Wilma Flintstone'];
    WebhookProcessor::parseNames($data);
    $this->assertEquals('Wilma', $data['first_name']);
    $this->assertEquals('Flintstone', $data['last_name']);
  }

  /**
   * Check name splitting works.
   */
  public function testNamesCombinedOneWord() {
    $data = [ 'name' => 'Wilma' ];
    WebhookProcessor::parseNames($data);
    $this->assertEquals('Wilma', $data['first_name']);
    $this->assertEquals('', $data['last_name']);
  }

  /**
   * Check name splitting works.
   */
  public function testNamesNone() {
    $this->expectExceptionMessage("iParl webhook requires data in the 'name' field.");
    $data = [];
    WebhookProcessor::parseNames($data);
  }

  /**
   * Check system status warnings/errors.
   */
  public function testChecksWork() {

    $calls = 0;
    $this->mockIparlTitleLookup($calls, TRUE);

    // Abandoned the following approach in favour of narrowing the test to our own code.
    // $result = civicrm_api3('System', 'check');
    // $this->assertEquals(0, $result['is_error'] ?? 1);

    $messages = [];
    iparl_civicrm_check($messages);

    $found_missing_user = FALSE;
    $found_missing_key = FALSE;
    $found_failed_lookup = FALSE;
    $found_failed_webhook = FALSE;
    foreach ($messages as $message) {
      switch ($message->getName()) {
      case 'iparl_missing_user':
        $found_missing_user = TRUE;
        break;
      case 'iparl_missing_webhook_key':
        $found_missing_key = TRUE;
        break;
      case 'iparl_api_fail':
        $found_failed_lookup = TRUE;
        break;
      case 'iparl_webhook_fail':
        $found_failed_webhook = TRUE;
        break;
      default:
        $this->fail("Unexpected message type returned by iparl_civicrm_check: " . $message->getName());
      }
    }
    $this->assertTrue($found_missing_key, 'Expected to find missing webhook key message in system checks');
    $this->assertTrue($found_missing_user, 'Expected to find missing username message in system checks');
    $this->assertFalse($found_failed_lookup, 'Expected not to find failed API message in system checks');
    $this->assertFalse($found_failed_webhook, 'Expected not to find iparl_webhook_fail in system checks');

  }
  public function testChecksReportFailedWebhooks() {

    $queue = CRM_Queue_Service::singleton()->create([
      'type'  => 'Sql',
      'name'  => 'iparl-webhooks-failed',
      'reset' => FALSE, // We do NOT want to delete an existing queue!
    ]);
    $queue->createItem(new CRM_Queue_Task(
      ['CRM_Iparl_Page_IparlWebhook', 'processQueueItem'], // callback
      [['the' => 'data']], // arguments
      "" // title
    ));
    $messages = [];

    // The functionality we're testing:
    iparl_civicrm_check($messages);

    $notFound = TRUE;
    foreach ($messages as $message) {
      if ($message->getName() === 'iparl_webhook_fail') {
        $notFound = FALSE;
        $this->assertRegExp('@<p>The iParl extension found un-processable webhook submissions, 1 total, 1 within the last 2 weeks, latest one at [0-9-]{10} [0-9:]{8}@',
          $message->getMessage()
        );
        $this->assertRegExp('@iParl Webhook errors found \(1 total, 1 recent, latest at [0-9-]{10} [0-9:]{8}\)@', $message->getTitle());
        break;
      }
    }
    $this->assertFalse($notFound, "Expected an iparl_webhook_fail message but found none.");

  }
  /**
   * Check the API works.
   */
  public function testChecksApiWorks() {

    $calls = 0;

    // Set user, since the check is not done unless we have a username.
    Civi::settings()->set('iparl_user_name', 'superfoo');

    // Check a failed API call is detected.
    // Mock title lookup to return NULL, i.e. api unavailable.
    $this->mockIparlTitleLookup($calls, NULL);

    // Test this functionality:
    $messages = [];
    iparl_civicrm_check($messages);

    $found_failed_lookup = FALSE;
    foreach ($messages as $message) {
      switch ($message->getName()) {
      case 'iparl_missing_user':
        $this->fail("Did not expect missing user message but got one.");
        break;
      case 'iparl_api_fail':
        $found_failed_lookup = TRUE;
        break;
      }
    }
    $this->assertTrue($found_failed_lookup, 'Expected to find failed API message in system checks');

    // Check an empty API call is detected.
    // Mock title lookup to return NULL, i.e. api unavailable.
    $this->mockIparlTitleLookup($calls, []);
    // Test this functionality:
    $messages = [];
    iparl_civicrm_check($messages);

    $found_failed_lookup = FALSE;
    foreach ($messages as $message) {
      if ($message->getName() === 'iparl_api_fail') {
        $found_failed_lookup = TRUE;
      }
    }

    $this->assertTrue($found_failed_lookup, 'Expected to find failed API message in system checks for empty result');
  }

}
