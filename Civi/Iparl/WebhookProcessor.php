<?php
namespace Civi\Iparl;

use Civi;

class WebhookProcessor {

  public const RECEIVE_EVENT = 'civi.iparl.receive';
  public const PROCESS_EVENT = 'civi.iparl.process';

  /** @var array */
  public static $test_log = [];

  /** @var ?bool Cached copy of the setting 'iparl_logging' */
  public static $iparl_logging;

  /** @var mixed FALSE or (for test purposes) a callback to use in place of simplexml_load_file */
  public static $simplexml_load_file = 'simplexml_load_file';

  /** @var Array */
  protected $chain;

  /**
   * Provided for the Queue Task runner
   */
  public static function processQueueItem($queueTaskContext, $data) {

    $exception = NULL;
    try {
      $isOK = static::processQueuedWebhook($data);
    }
    catch (\Exception $e) {
      $exception = $e;
      $isOK = FALSE;
    }
    if (!$isOK) {
      // Processing this one failed.
      // We'll add it to another queue called 'iparl-webhook-failed' so the
      // data is not lost completely, but note that this queue has no runner(!)
      $queue = \CRM_Queue_Service::singleton()->create([
        'type'  => 'Sql',
        'name'  => 'iparl-webhooks-failed',
        'reset' => FALSE, // We do NOT want to delete an existing queue!
      ]);
      $queue->createItem(new \CRM_Queue_Task(
        ['Civi\\Iparl\\WebhookProcessor', 'processQueueItem'], // callback
        [$data], // arguments
        "" // title
      ));

      if ($exception) {
        // Re-throw excetion.
        throw $exception;
      }
    }
    return $isOK;
  }
  /**
   * Process the data form a webhook.
   *
   * This is separate to processQueueItem for testing purposes.
   */
  public static function processQueuedWebhook(array $data) :bool {
    if (!isset(\Civi::$statics['IparlWebhookProcessor'])) {
      // Allow modification of the processing chain.
      $event = \Civi\Core\Event\GenericHookEvent::create([
        'chain' => [
          'parseNames'     => [static::class, 'parseNames'],
          'findOrCreate'   => [static::class, 'findOrCreate'],
          'mergePhone'     => [static::class, 'mergePhone'],
          'mergeAddress'   => [static::class, 'mergeAddress'],
          'recordActivity' => [static::class, 'recordActivity'],
          'legacyHook'     => [static::class, 'legacyHook'],
        ],
      ]);
      // Allow extensions to alter the processing chain.
      \Civi::dispatcher()->dispatch(static::PROCESS_EVENT, $event);
      \Civi::$statics['IparlWebhookProcessor'] = $event->chain;
    }

    $processor = new static(\Civi::$statics['IparlWebhookProcessor']);
    return $processor->processWebhook($data);
  }

  /**
   *
   */
  public function __construct(array $chain) {
    $this->chain = $chain;
  }

  /**
   * The main procesing method.
   *
   * It is separate for testing purposes.
   *
   * @param array ($_POST data)
   * @return bool TRUE on success
   */
  public function processWebhook(array $data) :bool {

    try {
      static::iparlLog("Processing queued webhook: " . json_encode($data));
      $start = microtime(TRUE);

      // Before we start, let's make sure we have required info from iParl's API.
      $is_petition = (!empty($data['actiontype']) && $data['actiontype'] === 'petition');
      if (!empty($data['actionid'])) {
        $lookup = static::getIparlObject($is_petition ? 'petition' : 'action');
        if ($lookup === NULL) {
          throw new ExternalAPIFailException(
            "Failed to get API response for " . ($is_petition ? 'petition' : 'action' )
            );
        }
        elseif (!isset($lookup[$data['actionid']])) {
          throw new ExternalAPIFailException(
            ($is_petition ? 'petition' : 'action' )
            . " with actionid $data[actionid] not found in iParl API response"
          );
        }
      }
      foreach ($this->chain as $callable) {
        $callable($data);
      }
      $took = round(microtime(TRUE) - $start, 3);
      static::iparlLog("Successfully created/updated contact $data[contactID] in {$took}s");

      /*
      // Issue #2
      // Provide a hook for custom action on the incoming data.
      $start = microtime(TRUE);
      $unused = CRM_Utils_Hook::$_nullObject;
      $contact = ['id' => $contactID];
      CRM_Utils_Hook::singleton()->invoke(
        ['contact', 'activity', 'data'], // Named useful arguments.
        $contact, $activity, $data, $unused, $unused, $unused,
        'civicrm_iparl_webhook_post_process');
      $took = round(microtime(TRUE) - $start, 3);
      static::iparlLog("Processed hook_civicrm_iparl_webhook_post_process in {$took}s");
       */
    }
    catch (ExternalAPIFailException $e) {
      // re-throw this to handle at a higher level.
      throw $e;
    }
    catch (\Exception $e) {
      static::iparlLog("EXCEPTION: Failed processing: " . $e->getMessage() . "\n" . $e->getTraceAsString());
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Ensure we have name data in incoming data.
   *
   * If "Separate fields for first & last names" is not checked in the config
   *
   * iParl docs say of the 'name' data key:
   *
   * > name    - if the action gathered two name fields, this will be the first name,
   * >           otherwise it will be the complete first/surname combination
   * >
   * > surname - surname if gathered for this action
   *
   * (29 Aug 2019) https://iparlsetup.com/setup/help#supporterwebhook
   *
   * This function looks for 'surname' - if it's set it uses 'name' as first
   * name and surname as last name. Otherwise it tries to separate 'name' into
   * first and last - the first name is the first word before a space, the rest
   * is considered the surname. (Because this is not always right it's better
   * to collect separate first, last names yourself.)
   *
   * Result:
   * - $data gets first_name, last_name set OR
   * - exception is thrown
   */
  public static function parseNames(&$data) {
    $data += ['first_name' => '', 'last_name' => ''];

    $input_surname = trim($data['surname'] ?? '');
    $input_name = trim($data['name'] ?? '');

    if (!empty($input_surname)) {
      $data['last_name'] = $data['surname'];
      $data['first_name'] = $data['name'];
    }
    elseif (!empty($input_name)) {
      $parts = preg_split('/\s+/', $input_name);
      if (count($parts) === 1) {
        // User only supplied one name.
        $data['first_name'] = $parts[0];
        $data['last_name'] = '';
      }
      else {
        $data['first_name'] = array_shift($parts);
        $data['last_name'] = implode(' ', $parts);
      }
    }
    else {
      throw new \Exception("iParl webhook requires data in the 'name' field.");
    }
  }
  /**
   * Results in $data[contactID] being set.
   */
  public static function findOrCreate(array &$input) {
    $result = \Civi\Api4\Email::get(FALSE)
      ->addSelect('contact_id', 'contact.first_name', 'contact.last_name')
      ->setJoin([['Contact AS contact', TRUE, NULL, ['contact.is_deleted', '=', 0], ['contact.is_deceased', '=', 0]]])
      ->addWhere('email', '=', $input['email'])
      ->execute();

    if (!$result->count()) {
      $input['contactID'] = static::createContact($input);
      static::iparlLog("Created contact $input[contactID] because email was not found.");
      return;
    }
    elseif ($result->count() === 1) {
      // Single email found.
      $input['contactID'] = (int) ($result->first()['contact_id']);
      static::iparlLog("Found contact $input[contactID] by email match.");
      return;
    }
    // Left with the case that the email is in there multiple times.
    // name matches.
    $unique_contacts = array();
    foreach ($result as $row) {
      $unique_contacts[(int) $row['contact_id']] = $row;
    }
    // Could be the same contact each time.
    if (count($unique_contacts) === 1) {
      $input['contactID'] = array_keys($unique_contacts)[0];
      static::iparlLog("Found contact $input[contactID] by email match (email is duplicated against same contact).");
      return;
    }

    // We'll go for the first contact whose
    foreach ($unique_contacts as $contactID => $row) {
      if ($input['first_name'] == $row['contact.first_name']
        && (!empty($input['last_name']) && $input['last_name'] == $row['contact.last_name'])) {
        // Found a match on name and email, return that.
        $input['contactID'] = $contactID;
        static::iparlLog("Found contact $contactID by email and name match.");
        return;
      }
    }

    // If we were unable to match on first and last name, try last name only.
    if ($input['last_name']) {
      foreach ($unique_contacts as $contactID => $row) {
        if ($input['last_name'] == $row['contact.last_name']) {
          // Found a match on last name and email, use that.
          $input['contactID'] = $contactID;
          static::iparlLog("Found contact $contactID by email and last name match.");
          return;
        }
      }
    }

    // If we were unable to match on first and last name, try first name only.
    foreach ($unique_contacts as $contactID => $row) {
      if ($input['first_name'] == $row['contact.first_name']) {
        // Found a match on last name and email, use that.
        static::iparlLog("Found contact $contactID by email and first name match.");
        $input['contactID'] = $contactID;
        return;
      }
    }

    // We know the email, but we think it belongs to someone else.
    // Create new contact.
    $input['contactID'] = $contactID = static::createContact($input);
    static::iparlLog("Created contact $contactID because could not match on email and name");
  }
  /**
   * Create a contact, return the ID.
   */
  public static function createContact(array $input) :int {
    $params = array(
      'first_name'   => $input['first_name'],
      'last_name'    => $input['last_name'],
      'contact_type' => 'Individual',
      'email'        => $input['email'],
    );
    $result = civicrm_api3('Contact', 'create', $params);
    return (int) $result['id'];
  }
  /**
   * Ensure we have their phone number.
   *
   * Does not change $input
   */
  public static function mergePhone(array &$input) {
    $phone_numeric = preg_replace('/[^0-9]+/', '', $input['phone'] ?? '');
    if (!$phone_numeric) {
      return;
    }
    $contactID = $input['contactID'];
    // Does this phone exist already?
    $result = civicrm_api3('Phone', 'get', array(
      'contact_id' => $contactID,
      'phone_numeric' => $phone_numeric,
    ));
    if ($result['count'] == 0) {
      // Create the phone.
      static::iparlLog("Created phone");
      $result = civicrm_api3('Phone', 'create', array(
        'contact_id' => $contactID,
        'phone' => $input['phone'],
      ));
    }
    else {
      static::iparlLog("Phone already present");
    }
  }
  /**
   * Ensure we have their address.
   *
   * Does not change $input
   */
  public static function mergeAddress(array &$input) {
    if (empty($input['address1']) || empty($input['town']) || empty($input['postcode'])) {
      // Not enough input.
      return;
    }
    $contactID = $input['contactID'];
    // Mangle address1 and address2 into one field since we don't know how
    // supplimental addresses are configured; they're not always the 2nd line.
    $street_address = trim($input['address1']);
    if (!empty($input['address2'])) {
      $street_address .= ", " . trim($input['address2']);
    }
    // Does this address exist already?
    $result = civicrm_api3('Address', 'get', [
      'contact_id' => $contactID,
      'street_address' => $input['address1'],
      'city' => $input['town'],
      'postal_code' => $input['postcode'],
    ]);
    if ($result['count'] == 0) {
      // Create the address.
      $result = civicrm_api3('Address', 'create', [
        'location_type_id' => "Home",
        'contact_id' => $contactID,
        'street_address' => $input['address1'],
        'city' => $input['town'],
        'postal_code' => $input['postcode'],
      ]);
      static::iparlLog("Created address");
    }
    else {
      static::iparlLog("Address already existed.");
    }
  }
  /**
   * Record the activity.
   *
   * Sets $input[activity] to the Activity.create result
   */
  public static function recordActivity(array &$input) {
    $contactID = $input['contactID'];

    // 'actiontype' key is not present for Lobby Actions, but is present and set to petition for petitions.
    $is_petition = (!empty($input['actiontype']) && $input['actiontype'] === 'petition');

    $subject = ($is_petition ? 'Petition' : 'Action') . " $input[actionid]";
    if (!empty($input['actionid'])) {
      $lookup = static::getIparlObject($is_petition ? 'petition' : 'action');
      if (isset($lookup[$input['actionid']])) {
        $subject .= ": " . $lookup[$input['actionid']];
      }
      else {
        throw new \Exception("Failed to lookup data for actionid "  . json_encode(['needle' => $input['actionid'], 'haystack' => $lookup]));
      }
    }

    $activity_type_declaration= (int) civicrm_api3('OptionValue', 'getvalue',
      ['return' => "value", 'option_group_id' => "activity_type", 'name' => "iparl"]);

    $params = [
      'activity_type_id'  => $activity_type_declaration,
      'target_id'         => $contactID,
      'source_contact_id' => $contactID,
      'subject'           => $subject,
      'details'           => '',
    ];
    if (preg_match('/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $input['date'] ?? '')) {
      // Date looks valid enough
      $params['activity_date_time'] = $input['date'];
    }
    $result = civicrm_api3('Activity', 'create', $params);
    $data['activity'] = $result;
    static::iparlLog("Created iParl action activity $result[id]: $params[subject]");
  }
  /**
   * Obtain a cached array lookup keyed by action/petition id with title as value.
   *
   * Note that you should catch exceptions which could be network failures. @todo
   *
   * @param string $type petition|action
   * @return null|array NULL means unsuccessful at downloading otherwise return
   * array (which may be empty)
   */
  public static function getIparlObject($type, $bypass_cache=FALSE) {
    if ($type !== 'action' && $type !== 'petition') {
      throw new \InvalidArgumentException("getIparlObject \$type must be action or petition. Received " . json_encode($type));
    }
    $cache_key = "iparl_titles_$type";

    // Check static cache - saves cache lookup on the back-end job; we assume it's ok for one full run.
    if (!isset(\Civi::$statics[$cache_key]) || $bypass_cache === TRUE) {
      \Civi::$statics[$cache_key] = NULL;

      // do we have it in SQL cache?
      // Note that this: $cache = Civi::cache(); defaults to a normal array, lost at the end of each request.
      $cache = \CRM_Utils_Cache::create([
        'type' => ['SqlGroup', 'ArrayCache'],
        'name' => 'iparl',
      ]);

      $data = $bypass_cache ? NULL : $cache->get($cache_key, NULL);
      if ($data === NULL) {
        static::iparlLog("Cache " . ($bypass_cache ? 'bypass' : 'miss') . " on looking up $cache_key, must fetch");
        // Fetch from iparl api.
        $iparl_username = Civi::settings()->get("iparl_user_name");
        if ($iparl_username) {
          $url = static::getLookupUrl($type);
          $function = static::$simplexml_load_file;
          $xml = $function($url , null , LIBXML_NOCDATA);
          $file = json_decode(json_encode($xml), TRUE);
          static::iparlLog("Received for $cache_key from $url: " . json_encode($xml));
          if (is_array($file)) {
            // Successfully downloaded data.
            $data = [];
            if (isset($file[$type])) {
              // This will either contain: {item} or [{item}, {item}]
              if (isset($file[$type]['title'])) {
                // We have the first form. Wrap it in an array.
                $list = [$file[$type]];
              }
              else {
                $list = $file[$type];
              }
              foreach ($list as $item) {
                if (!is_array($item)) {
                  Civi::log()->error("Expected to get an array item inside $type but didn't. Got this:\n" . json_encode($file, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                }
                $data[$item['id']] = $item['title'];
              }
            }
            // Cache it for 1 hour, unless it's empty as this probably means
            // something's up. Note that saving the iParl Settings form will
            // force a refresh of this cache.
            if ($data) {
              $cache->set($cache_key, $data, 60*60);
              \Civi::$statics[$cache_key] = $data;
              static::iparlLog("Caching " . count($data) . " results from $url for 1 hour.");
            }
            else {
              static::iparlLog("Not caching data $cache_key: none received? Has someone set them to private? They should be listed at $url");
            }
          }
          else {
            static::iparlLog("Failed to load resource at: $url");
          }
        }
        else {
          static::iparlLog("Missing iparl_user_name, cannot access iParl API");
        }
      }
      else {
        \Civi::$statics[$cache_key] = $data;
        static::iparlLog("Cache hit (sql) on looking up $cache_key");
      }
    }
    else {
      static::iparlLog("Cache hit (static) on $cache_key");
    }
    return \Civi::$statics[$cache_key];
  }
  /**
   * Return the iParl API URL
   *
   * @param string $type petition|action
   * @return string URL
   */
  public static function getLookupUrl(string $type) :string {
    $iparl_username = Civi::settings()->get("iparl_user_name");
    $url = "https://iparlsetup.com/api/$iparl_username/";
    if ($type === 'action') {
      $url .= "actions.xml"; // new .xml extension required ~Autumn 2019
    }
    elseif ($type === 'petition') {
      $url .= "petitions"; // old style, without .xml
    }
    return $url;
  }

  /**
   * Log, if logging is enabled.
   */
  public static function iparlLog($message, $priority=PEAR_LOG_INFO) {

    if (!isset(static::$iparl_logging)) {
      // Look up logging setting and cache it.
      static::$iparl_logging = (int) Civi::settings()->get('iparl_logging');
    }
    if (!static::$iparl_logging) {
      // Logging disabled.
      return;
    }

    if (static::$iparl_logging === 'phpunit') {
      // For test purposes, just append to an array.
      static::$test_log[] = $message;
      //print $message ."\n";
      return;
    }

    $message = "From " . ($_SERVER['REMOTE_ADDR'] ?? '(unavailable)') . ": $message";
    $out = FALSE;
    $component = 'iparl';
    \CRM_Core_Error::debug_log_message($message, $out, $component, $priority);
  }
  /**
   * Before 1.6.0 we fired hook_civicrm_iparl_webhook_post_process
   * to let local custom extensions do something more with the data,
   * /after/ we had done our thing.
   *
   * We can now do a lot more customisation by listening for the Symfony event
   * \Civi\Iparl\WebhookProcessor::PROCESS_EVENT
   * and changing the processing chain, potentially rewriting the whole thing,
   * or replacing certain bits or adding processing at any point.
   *
   * This method is here for backwards compatibility, but should be considered deprecated.
   */
  public static function legacyHook(array &$data) {
      $start = microtime(TRUE);
      $unused = NULL;
      $contact = ['id' => $data['contactID']];
      \CRM_Utils_Hook::singleton()->invoke(
        ['contact', 'activity', 'data'], // Named useful arguments.
        $contact, $data['activity'], $data, $unused, $unused, $unused,
        'civicrm_iparl_webhook_post_process');
      $took = round(microtime(TRUE) - $start, 3);
      static::iparlLog("Processed (deprecated) hook_civicrm_iparl_webhook_post_process in {$took}s");
  }
}
