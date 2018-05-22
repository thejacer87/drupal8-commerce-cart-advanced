<?php

namespace Drupal\commerce_cart_advanced\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure example settings for this site.
 */
class CommerceCartAdvancedSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_cart_advanced_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'commerce_cart_advanced.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_cart_advanced.settings');

    $form['display_non_current_carts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show non current carts'),
      '#default_value' => $config->get('display_non_current_carts'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable('commerce_cart_advanced.settings')
      // Set the submitted configuration setting.
      ->set('display_non_current_carts', $form_state->getValue('display_non_current_carts'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
