<?php
namespace Drupal\github_connect;

class GithubConnectAdminSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'github_connect_admin_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('github_connect.settings');

    foreach (Element::children($form) as $variable) {
      $config->set($variable, $form_state->getValue($form[$variable]['#parents']));
    }
    $config->save();

    if (method_exists($this, '_submitForm')) {
      $this->_submitForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['github_connect.settings'];
  }

  public function buildForm(array $form_state, \Drupal\Core\Form\FormStateInterface $form_state) {

    $form['github_connect_settings'] = [
      '#type' => 'fieldset',
      '#title' => t('Github settings'),
      '#description' => t('Fill in the form below. You will first have to create an application at https://github.com/account/applications/new. Main URL should be set to your domain name and Callback URL to your domain name /github/register/create (http://example.com/github/register/create). After saving the application you will be given the Client ID and Client secret.'),
    ];

    // @FIXME
    // Could not extract the default value because it is either indeterminate, or
    // not scalar. You'll need to provide a default value in
    // config/install/github_connect.settings.yml and config/schema/github_connect.schema.yml.
    $form['github_connect_settings']['github_connect_client_id'] = [
      '#title' => t('Client ID'),
      '#type' => 'textfield',
      '#default_value' => \Drupal::config('github_connect.settings')->get('github_connect_client_id'),
      '#size' => 50,
      '#maxlength' => 50,
      '#required' => TRUE,
    ];

    // @FIXME
    // Could not extract the default value because it is either indeterminate, or
    // not scalar. You'll need to provide a default value in
    // config/install/github_connect.settings.yml and config/schema/github_connect.schema.yml.
    $form['github_connect_settings']['github_connect_client_secret'] = [
      '#title' => t('Client secret'),
      '#type' => 'textfield',
      '#default_value' => \Drupal::config('github_connect.settings')->get('github_connect_client_secret'),
      '#size' => 50,
      '#maxlength' => 50,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

}
