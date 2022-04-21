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
 *
 * @group headless
 */
class QueueTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface  {
  use IparlShared;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }
  public function setup():void {
    WebhookProcessor::$iparl_logging = 'phpunit';
    $this->setMockIParlSetting();
  }
  /**
   * Check the queue system works.
   */
  public function testQueues() {
    $webhook = new CRM_Iparl_Page_IparlWebhook();

    $calls = 0;
    $this->mockIparlTitleLookup($calls);

    $sharedDemoData = [
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
    ];
    $webhook->queueWebhook($sharedDemoData);

    $webhook->queueWebhook([
      'postcode' => 'some super-long-spammy data that will cause address.create to fail',
    ] + $sharedDemoData);

    $result = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks';");
    $this->assertEquals(2, $result, "Expected 2 queue items after queing two items.");

    // Process the queue
    try {
      $result = civicrm_api3('Job', 'Processiparlwebhookqueue', []);
      $this->fail("We expected an exception to be thrown by Processiparlwebhookqueue because we included data that should cause an error, but no exception was thrown.");
    }
    catch (\Exception $e) {
      // We expect this.
      $this->assertEquals("1 errors - see iParl log file.", $e->getMessage(), "We expect the Job to throw an exception because we included data that would cause an error, but we got an unexpected error message.");
    }

    $result = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks';");
    $this->assertEquals(0, $result, "Found iparl-webhooks queue items after processing queue but there should not be any");

    $result = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks-failed';");
    $this->assertEquals(1, $result, "Expected to find 1 iparl-webhooks-failed queue items");

  }

  public function testSpam() {
    $calls = 0;
    $this->mockIparlTitleLookup($calls);

    $sharedDemoData = [
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
    ];

    $webhook = new CRM_Iparl_Page_IparlWebhook();
    try {
      // This should be rejected.
      $webhook->queueWebhook([
        'name' => 'Visit my site http://example.com/spam',
      ] + $sharedDemoData);
      $this->fail("Expected web address in name field to be rejected.");
    }
    catch (\InvalidArgumentException $e) {
      $this->assertEquals("SPAM: name found to contain a URL; rejecting data.", $e->getMessage());
    }

    // This should work.
    $webhook->queueWebhook([
      // The red heart is not considered emoji(!) bt the blue one is.
      // This is a known limitation of or emoji removal.
      'name' => 'Sweetie♥️💙',
      'surname' => 'Rainbows🌈',
    ] + $sharedDemoData);

    $result = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks';");
    $this->assertEquals(1, $result, "Expected 1 queue item.");
    /** @var \CRM_Queue_Task */
    $task = unserialize(CRM_Core_DAO::singleValueQuery("SELECT data FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks';"));
    $data = $task->arguments[0];
    $this->assertEquals('Sweetie♥️', $data['name']);
    $this->assertEquals('Rainbows', $data['surname']);
  }
  public function tearDown() :void {

    // Note, for some reason this test leaves data in the database; it must do something
    // outside of a transaction I think. So we do our own cleanup here:
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_queue_item WHERE queue_name IN ('iparl-webhooks', 'iparl-webhooks-failed');");

    $contactID = CRM_Core_DAO::singleValueQuery("SELECT contact_id FROM civicrm_email WHERE email = 'wilma@example.com';");
    if ($contactID) {
      $result = civicrm_api3('Contact', 'delete', [
        'skip_undelete' => 1,
        'contact_id' => $contactID,
      ]);
      $this->assertEquals(0, $result['is_error']);
    }
    $contactID = CRM_Core_DAO::singleValueQuery("SELECT contact_id FROM civicrm_email WHERE email = 'wilma@example.com';");
    $this->assertNull($contactID);

    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_cache WHERE group_name = 'iparl';");
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_setting WHERE name LIKE 'iparl_%';");
  }

}

