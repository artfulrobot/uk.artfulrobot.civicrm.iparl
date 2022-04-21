<?php

use CRM_Iparl_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Iparl\WebhookProcessor;

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
trait IparlShared {
  public function setMockIParlSetting() {
    Civi::settings()->set('iparl_webhook_key', 'helloHorseHeadLikeYourJumper');
    Civi::settings()->set('iparl_user_name', 'superfoo');
  }
  /**
   * Mock the iParl XML API for the test context.
   *
   * @param int &$calls Counts times it was called.
   * @param mixed $fail. If given and not FALSE, this is the value that any
   * simplexml_load_file call will return. Useful for returning null or []
   */
  public function mockIparlTitleLookup(&$calls, $fail=FALSE) {
    if ($fail !== FALSE) {
      WebhookProcessor::$simplexml_load_file = function($url, $a, $b) use (&$calls, $fail) {
        $calls++;
        return $fail;
      };
    }
    else {
      WebhookProcessor::$simplexml_load_file = function($url, $a, $b) use(&$calls) {
        $calls++;
        switch ($url) {
        case "https://iparlsetup.com/api/superfoo/petitions":
          return simplexml_load_string('<?xml version="1.0"?><xml>
  <petition><title>Some demo petition</title><id>456</id></petition>
  <petition><title>Another demo petition</title><id>678</id></petition>
  </xml>');
          break;

        case "https://iparlsetup.com/api/superfoo/actions.xml":
          return simplexml_load_string('<?xml version="1.0"?><xml>
  <action><title>Some demo action</title><id>123</id></action>
  <action><title>Another demo action</title><id>234</id></action>
  </xml>');
          break;

        default:
          throw new \Exception("unexpected URL: $url");
        }
      };
    }
  }
  /**
   * Given an array of expected strings/regexes and a same-size array of actuals, check they all match.
   *
   * Regex patterns are identified by / at start
   */
  public function assertArrayRegex($expected, $actual) {
    $errors = [];
    $this->assertIsArray($actual);
    foreach ($expected as $i => $pattern) {
      if (!isset($actual[$i])) {
        $errors[] = "- $i => $pattern\n";
        $errors[] = "+ $i => (MISSING)\n";
      }
      elseif (substr($pattern, 0, 1) === '/') {
        // regex match.
        if (!preg_match($pattern, $actual[$i])) {
          $errors[] = "- $i => $pattern\n";
          $errors[] = "+ $i => {$actual[$i]}\n";
        }
      }
      else {
        // String match.
        if ($pattern !== $actual[$i]) {
          $errors[] = "- $i => $pattern\n";
          $errors[] = "+ $i => {$actual[$i]}\n";
        }
      }
    }
    $errors = implode('', $errors);
    if ($errors) {
      $this->fail("Not matching expectations:\n" . $errors . "\n\nFull log\n" . implode("\n", $actual));
    }
  }

}


/**
 * Implement a hook.
 */
function iparl_civicrm_iparl_webhook_post_process($contact, $activity, $webhook_data) {
  global $iparl_hook_test;
  $iparl_hook_test = [
    'contact' => $contact,
    'activity' => $activity,
    'webhook_data' => $webhook_data
  ];
}
