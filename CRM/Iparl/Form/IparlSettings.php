<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Iparl_Form_IparlSettings extends CRM_Core_Form {
  private $_settingFilter = array('group' => 'iparl');
  // The code following is taken from the example file at
  // <https://github.com/eileenmcnaughton/nz.co.fuzion.civixero/blob/master/CRM/Civixero/Form/XeroSettings.php>
  // except the getFormSettings() function which in the example has some custom
  // stuff we don't need here.
  private $_submittedValues = array();
  private $_settings = array();
  public function buildQuickForm() {

    // Get username
    $iparl_username = civicrm_api3('Setting', 'getvalue', array( 'name' => "iparl_user_name", 'group' => 'iParl Integration Settings' ));
    $iparl_webhook_key = civicrm_api3('Setting', 'getvalue', array( 'name' => "iparl_webhook_key", 'group' => 'iParl Integration Settings' ));
    $webhook_url = CRM_Utils_System::url('civicrm/iparl-webhook', $q=null, $absolute=TRUE);
    $this->assign('webhookUrl', $webhook_url);

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $iparl_username)) {
      $this->assign('usernameUnset', TRUE);
    }

    $settings = $this->getFormSettings();
    foreach ($settings as $name => $setting) {
      if (isset($setting['quick_form_type'])) {
        $add = 'add' . $setting['quick_form_type'];
        if ($add == 'addElement') {
          $this->$add($setting['html_type'], $name, ts($setting['title']), CRM_Utils_Array::value('html_attributes', $setting, array ()));
          //function HTML_QuickForm_select($elementName=null, $elementLabel=null, $options=null, $attributes=null)
        }
        else {
          $this->$add($name, ts($setting['title']));
        }
        $this->assign("{$setting['description']}_description", ts('description'));
      }
    }

    $this->addButtons(array(
      array (
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      )
    ));


    /*
    // add form elements
    $this->add(
      'select', // field type
      'favorite_color', // field name
      'Favorite Color', // field label
      $this->getColorOptions(), // list of options
      TRUE // is required
    );
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));
     */

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $this->_submittedValues = $this->exportValues();
    $this->saveSettings();
    parent::postProcess();
    // Reload cache.
    foreach (['action', 'petition'] as $type) {
      \Civi\Iparl\WebhookProcessor::getIparlObject($type, TRUE);
    }

    CRM_Core_Session::setStatus(ts('iParl settings updated.'));
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }
  // These functions copied from other code.
  /**
   * Get the settings we are going to allow to be set on this form.
   *
   * @return array
   */
  function getFormSettings() {
    if (empty($this->_settings)) {
      $settings = civicrm_api3('setting', 'getfields', array('filters' => $this->_settingFilter));
    }
    $settings = $settings['values'];
    return $settings;
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   *
   * @return array
   */
  function saveSettings() {
    $settings = $this->getFormSettings();
    $values = array_intersect_key($this->_submittedValues, $settings);
    civicrm_api3('setting', 'create', $values);
  }
  /**
   * Set defaults for form.
   *
   * @see CRM_Core_Form::setDefaultValues()
   */
  function setDefaultValues() {
    $existing = civicrm_api3('setting', 'get', array('return' => array_keys($this->getFormSettings())));
    $defaults = array();
    $domainID = CRM_Core_Config::domainID();
    foreach ($existing['values'][$domainID] as $name => $value) {
      $defaults[$name] = $value;
    }
    return $defaults;
  }
}
