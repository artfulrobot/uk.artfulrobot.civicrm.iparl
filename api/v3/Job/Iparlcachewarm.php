<?php
use CRM_Iparl_ExtensionUtil as E;

/**
 * Job.Iparlcachewarm API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_job_Iparlcachewarm_spec(&$spec) {
}

/**
 * Job.Iparlcachewarm API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_Iparlcachewarm($params) {

  $webhook = new CRM_Iparl_Page_IparlWebhook();
  foreach (['action', 'petition'] as $type) {
    $webhook->getIparlObject($type, TRUE);
  }
  return civicrm_api3_create_success([], $params, 'Job', 'Iparlcachewarm');
}
