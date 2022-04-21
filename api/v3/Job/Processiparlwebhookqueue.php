<?php
use CRM_Iparl_ExtensionUtil as E;

use Civi\Iparl\WebhookProcessor;

/**
 * Job.Processiparlwebhookqueue API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_job_Processiparlwebhookqueue_spec(&$spec) {
  $spec['max_time']['description'] = 'Time (seconds) to spend processing the queue. e.g. set this to slightly less than your cron interval. Not setting it, or setting it to zero means no limit.';
}

/**
 * Job.Processiparlwebhookqueue API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_Processiparlwebhookqueue($params) {

  // Ensure we have the latest definitions If we don't have the definitions
  // there's no point running - it would create lots of iparl-webhooks-failed
  // entries that are more of a pain to sort out.
  foreach (['action', 'petition'] as $type) {
    if (WebhookProcessor::getIparlObject($type, TRUE) === NULL) {
      return ['processed' => 0, 'is_error' => 1, 'error_message' => "Failed to load iParl resource: $type"];
    }
  }

  $queue = CRM_Queue_Service::singleton()->create([
    'type'  => 'Sql',
    'name'  => 'iparl-webhooks',
    'reset' => FALSE, // We do NOT want to delete an existing queue!
  ]);

  // Note: we use ERROR_CONTINUE because if there's an error we copy the data
  // to a separate queue.
  $runner = new CRM_Queue_Runner([
    'title'     => ts('iParl webhook processor'),
    'queue'     => $queue,
    'errorMode' => CRM_Queue_Runner::ERROR_CONTINUE,
    //'onEnd' => callback
    //'onEndUrl' => CRM_Utils_System::url('civicrm/demo-queue/done'),
  ]);

  $max = (int) ($params['max_time'] ?? 0);
  if ($max > 0) {
    $maxRunTime = time() + $max; //stop executing next item after 30 seconds
  }
  else {
    $maxRunTime = FALSE;
  }

  $processed = 0;
  $errors = 0;
  do {
    $result = $runner->runNext(false);
    if ($result['is_error']) {
      if ($result['exception'] !== NULL) {
        $msg = $result['exception']->getMessage();
        if ($msg === 'Failed to claim next task') {
          // Queue empty, or another process busy.
          // This is not an error to us, but we need to stop processing.
          break;
        }
        else {
          // Some other exception.
          $errors++;
        }
      }
      else {
        $errors++;
      }
    }
    else {
      // Success
      $processed++;
    }
    if (!$result['is_continue']) {
      break; //all items in the queue are processed.
    }
  } while (!$maxRunTime || time() < $maxRunTime);

  if ($errors) {
    return ['processed' => $processed, 'is_error' => 1, 'error_message' => "$errors errors - see iParl log file."];
  }
  return ['processed' => $processed, 'is_error' => 0];
}
