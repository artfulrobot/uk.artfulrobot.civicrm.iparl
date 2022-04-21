<?php

use CRM_Iparl_ExtensionUtil as E;
use Civi\Iparl\WebhookProcessor;

require_once 'iparl.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function iparl_civicrm_config(&$config) {
  _iparl_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function iparl_civicrm_xmlMenu(&$files) {
  _iparl_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function iparl_civicrm_install() {
  _iparl_civix_civicrm_install();

  /**
   * Helper function for creating data structures.
   *
   * @param string $entity - name of the API entity.
   * @param Array $params_min parameters to use for search.
   * @param Array $params_extra these plus $params_min are used if a create call
   *              is needed.
   */
  $api_get_or_create = function ($entity, $params_min, $params_extra) {
    $params_min += array('sequential' => 1);
    $result = civicrm_api3($entity, 'get', $params_min);
    if (!$result['count']) {
      // Couldn't find it, create it now.
      $result = civicrm_api3($entity, 'create', $params_extra + $params_min);
    }
    return $result['values'][0];
  };

  // We need an iParl activity type
  $activity_type = $api_get_or_create('OptionValue', array(
    'option_group_id' => "activity_type",
    'name' => "iparl",
  ),
  array( 'label' => 'iParl action' ));

  $url = CRM_Utils_System::url('civicrm/admin/setting/iparl');
  CRM_Core_Session::setStatus(ts("You must now <a href='$url'>configure the iParl extension</a>."));
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function iparl_civicrm_uninstall() {
  _iparl_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function iparl_civicrm_enable() {
  _iparl_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function iparl_civicrm_disable() {
  $cache = CRM_Utils_Cache::create([
    'type' => ['SqlGroup', 'ArrayCache'],
    'name' => 'iparl',
  ]);
  foreach (['action', 'petition'] as $type) {
    $cache_key = "iparl_titles_$type";
    $cache->delete($cache_key);
  }
  _iparl_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function iparl_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _iparl_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function iparl_civicrm_managed(&$entities) {
  _iparl_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * @param array $caseTypes
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function iparl_civicrm_caseTypes(&$caseTypes) {
  _iparl_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function iparl_civicrm_angularModules(&$angularModules) {
_iparl_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function iparl_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _iparl_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

function iparl_civicrm_navigationMenu(&$menu) {
  _iparl_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label'      => E::ts('iParl Integration Settings'),
    'name'       => 'iparl-settings',
    'url'        => 'civicrm/admin/setting/iparl',
    'permission' => 'administer CiviCRM',
  ]);
}

/**
 * Implements hook_civicrm_check
 *
 * - Check if we have a webhook key.
 * - Check if we are configured with an iparl username
 * - Check if we can fetch iparl data
 *
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_check/
 */
function iparl_civicrm_check(&$messages) {

  $config_link = '<a href="'
    . CRM_Utils_System::url('civicrm/admin/setting/iparl')
    . '" >Configure iParl Extension</a>';
  $iparl_webhook_key = Civi::settings()->get('iparl_webhook_key');

  if (!$iparl_webhook_key) {
    $messages[] = new CRM_Utils_Check_Message(
      'iparl_missing_webhook_key',
      'You have not set your iParl webhook key. When iParl sends information to
      CiviCRM it also sends this secret key (or password) so that CiviCRM knows
      the incoming data is actually from iParl and not from some spammer! This
      needs to be set up at both ends. The iParl extension requires this to function. '
      . $config_link,
      'Missing iParl webhook key',
      'error',
      'exclamation-circle'
    );
  }

  $iparl_user_name = Civi::settings()->get('iparl_user_name');
  if (!$iparl_user_name) {
    $messages[] = new CRM_Utils_Check_Message(
      'iparl_missing_user',
      '<p>You have not set your iParl username. Your username is used to lookup
      the names of actions and petitions so that the activities recorded make more sense.</p>
      <p>e.g. without your username activity subjects will be like "Action 123", but
      with your username it will be like "Action 123: Call for immediate divestment from fossil fuels"
      </p><p>' . $config_link . '</p>',
      'Missing iParl username',
      'error',
      'exclamation-circle'
    );
  }
  else {
    // Ok we have username, see if we can load the data.
    $is_error = FALSE;
    $api_fails = [];

    foreach (['action', 'petition'] as $type) {
      $result = WebhookProcessor::getIparlObject($type, TRUE);
      if (empty($result)) {
        if ($result === NULL) {
          // Actual failure.
          $is_error = TRUE;
          $api_fails[] = "Error: Failed to load $type titles from " . htmlspecialchars(WebhookProcessor::getLookupUrl($type));
        }
        else {
          $api_fails[] = "Note: Lookup found no $type titles from " . htmlspecialchars(WebhookProcessor::getLookupUrl($type)) . " (this is fine if you haven't made any {$type}s on iParl.)";
        }
      }
    }

    if ($api_fails) {
      $messages[] = new CRM_Utils_Check_Message(
        'iparl_api_fail',
        '<p>The iParl extension found:</p><ul><li>'
        .implode('</li><li>', $api_fails)
        . '</li></ul>'
        . ($is_error ? '<p>Is your username correct? Could the API URL have changed? Could be a temporary failure?</p>' : '')
        . '<p>' . $config_link . '</p>',
        ($is_error ? 'Error' : 'Notice about') . ' fetching data from iParl API',
        $is_error ? 'error' : 'notice'
      );
    }

  }

  $errorCount = CRM_Core_DAO::executeQuery("SELECT COUNT(*) total,  sum(submit_time > NOW() - INTERVAL 2 WEEK) recent, MAX(submit_time) latest FROM civicrm_queue_item WHERE queue_name = 'iparl-webhooks-failed';");
  $errorCount->fetch();
  if ($errorCount->total > 0) {
    $messages[] = new CRM_Utils_Check_Message(
        'iparl_webhook_fail',
        "<p>The iParl extension found un-processable webhook submissions, $errorCount->total total, $errorCount->recent within the last 2 weeks, latest one at {$errorCount->latest}. This can be the case if someone puts spam data into the iParl forms and it passes it along to us. These submissions have not been (fully) processed and you will find details in the iParl log file.</p>",
        'iParl Webhook errors found (' . $errorCount->total . " total, " . $errorCount->recent . " recent, latest at " . $errorCount->latest . ')',
        'warning'
      );
  }
}

