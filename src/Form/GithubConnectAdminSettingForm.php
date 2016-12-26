<?php

namespace Drupal\github_connect\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * @file
 * Administration page callbacks for the GitHub connect module.
 */

class GithubConnectAdminSettingForm extends ConfigFormBase {
  public function getFormId() {
    return 'github_connect_settings';
  }
  public function getEditableConfigNames() {
    return [
      'github_connect.settings',
    ];

  }
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['github_connect_settings'] = array(
      '#type' => 'fieldset',
      '#title' => t('Github settings'),
      '#description' => t('Fill in the form below. You will first have to create an application at https://github.com/account/applications/new. Main URL should be set to your domain name and Callback URL to your domain name /github/register/create (http://example.com/github/register/create). After saving the application you will be given the Client ID and Client secret.'),
    );

    $form['github_connect_settings']['github_connect_client_id'] = array(
      '#title' => t('Client ID'),
      '#type' => 'textfield',
      '#default_value' => \Drupal::state()->get('github_connect_client_id'),
      '#size' => 50,
      '#maxlength' => 50,
      '#required' => TRUE,
    );

    $form['github_connect_settings']['github_connect_client_secret'] = array(
      '#title' => t('Client secret'),
      '#type' => 'textfield',
      '#default_value' => \Drupal::state()->get('github_connect_client_secret'),
      '#size' => 50,
      '#maxlength' => 50,
      '#required' => TRUE,
    );

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {

    \Drupal::state()->set('github_connect_client_id', $form_state->getValues()['github_connect_client_id']);
    \Drupal::state()->set('github_connect_client_secret', $form_state->getValues()['github_connect_client_secret']);

    $this->config('github_connect.settings')
      ->set('github_connect_client_id', $form_state->getValues()['github_connect_client_id'])
      ->set('github_connect_client_secret', $form_state->getValues()['github_connect_client_secret'])
      ->save();

    // Set values in variables.
    parent::submitForm($form, $form_state);
  }
}