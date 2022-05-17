<?php
use \Civi\Iparl\WebhookProcessor;

/**
 *
 * @file
 * Webhook endpoint for iParl.
 *
 * Finds/creates contact, creates action. Success is indicated by simply responding "OK".
 *
 * @author Rich Lott / Artful Robot
 * @copyright Rich Lott 2019
 * see licence.
 *
 * At time of writing, the iParl API provides:
 *
 * from: https://iparlsetup.com/help/output-api.php
 *
 * - actionid     The ID number of the action. This displays in the URL of each action and can also be accessed as an XML file using the 'List actions API' referred to here.
 * - secret       Secret string set when you set up this function. Testing for this in your script will allow you to filter out other, potentially hostile, attempts to feed information into your system. Not used in the redirect data string.
 * - name         if the action gathered two name fields, this will be the first name, otherwise it will be the complete first/surname combination
 * - surname      Surname, if gathered (update Aug 2019, was lastname)
 * - address1     Address line 1
 * - address2     Address line 2
 * - town         Town
 * - postcode     Postcode
 * - country      Country
 * - email        Email address
 * - phone        Phone number
 * - childid      The Child ID number of the sub-action if set. Some actions allow a supporter to select a pathway which will present them with one or another model letter.
 * - target       The email address used in actions which email a single target
 * - personid     The TheyWorkForYou.com personid value for the supporter's MP, if identified in the action. This can be used as the id value in the TheyWorkForYou getMP API method.
 * - mpname
 * - const        ??
 * - council
 * - region
 * - optin1
 * - optin2
 *
 * For petitions we *also* get:
 *
 * - actiontype: 'petition'
 * - actionid   Refers to the petition's ID.
 * - comment
 *
 */
class CRM_Iparl_Page_IparlWebhook extends CRM_Core_Page {

  /**
   * Provided for backwards compatibility for webhook data queued before v 1.6
   *
   * Not used from 1.6 on.
   */
  public static function processQueueItem($queueTaskContext, $data) {
    return WebhookProcessor::processQueueItem($queueTaskContext, $data);
  }

  public function run() {
    try {
      /** @var array Holds data that we will allow onto the queue. By default, everything. */
      $clean = $_POST;
      $this->queueWebhook($clean);
      echo "OK";
    }
    catch (Exception $e) {
      \Civi\Iparl\WebhookProcessor::iparlLog("EXCEPTION, webhook dropped: ". $e->getMessage() .  "\nWhile processing: " . json_encode($_POST));
      header("$_SERVER[SERVER_PROTOCOL] 400 Bad request");
    }
    exit;
  }
  public function checkRequiredFields(array &$clean) {
    $errors = [];
    foreach (['secret', 'email'] as $_) {
      if (empty($clean[$_])) {
        $errors[] = $_;
      }
    }
    if ($errors) {
      throw new Exception("POST data is invalid or incomplete. Missing: " . implode(', ', $errors));
    }
  }

  public function checkSecret(array &$clean) {
    // Check secret.
    $key = Civi::settings()->get("iparl_webhook_key");
    if (empty($key)) {
      throw new Exception("iParl secret not configured.");
    }
    if ($clean['secret'] !== $key) {
      throw new Exception("iParl key mismatch.");
    }
    // We do not need to store this in the queue.
    unset($clean['secret']);
  }

  /**
   * Check all possible name fields.
   * If any contain http or www then rejcet the data completely.
   * Remove emoji.
   */
  public function firewallNames(array &$clean) {
    foreach (['name', 'surname', 'first_name', 'last_name'] as $nameField) {
      if (!empty($clean[$nameField])) {
        // Reject if it looks to contain a URL
        if (preg_match('@(http|www\.|//)@i', $clean[$nameField])) {
          throw new \InvalidArgumentException("SPAM: $nameField found to contain a URL; rejecting data.");
        }
        // Strip out emoji that we can easily identify.
        // Note that emoji are scattered throughout the unicode range,
        // with new ones added here there and everywhere with each unicode release.
        // This would be a big job to maintain and make for slow processing,
        // so instead we only filter out those in the known big blocks.
        $clean[$nameField] = preg_replace(
          "/[\u{1f300}-\u{1f5ff}\u{e000}-\u{f8ff}]/u",
          '',
          $clean[$nameField]
        );
      }
    }
  }
  /**
   */
  public function queueWebhook($data) {

    $event = Civi\Core\Event\GenericHookEvent::create([
      'raw' => $data,
      'chain' => [
        'checkRequiredFields' => [$this, 'checkRequiredFields'],
        'checkSecret' => [$this, 'checkSecret'],
        'firewallNames' => [$this, 'firewallNames'],
      ],
    ]);
    // Allow extensions to alter the processing chain.
    Civi::dispatcher()->dispatch(WebhookProcessor::RECEIVE_EVENT, $event);

    // Filter the data by any filters we need.
    // They can throw exceptions if we should give up.
    foreach ($event->chain as $callable) {
      $callable($data);
    }

    $queue = CRM_Queue_Service::singleton()->create([
      'type'  => 'Sql',
      'name'  => 'iparl-webhooks',
      'reset' => FALSE, // We do NOT want to delete an existing queue!
    ]);
    $queue->createItem(new CRM_Queue_Task(
      ['Civi\\Iparl\\WebhookProcessor', 'processQueueItem'], // callback
      [$data], // arguments
      "" // title
    ));
  }
}
