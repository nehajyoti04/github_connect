<?php

/**
 * @file
 * Contains Drupal\age_calculator\Form\AddForm
 */

namespace Drupal\github_connect\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ChangedCommand;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form;

/**
 * Class AddForm.
 *
 * @package Drupal\github_connect\Form\GithubConnectForm
 */
class GithubConnectForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'github_connect_add';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    global $base_url;

    if (!(\Drupal::currentUser()->isAnonymous())) {
      return FALSE;
    }

    $client_id = \Drupal::state()->get('github_connect_client_id');

    $current_request = \Drupal::service('request_stack')->getCurrentRequest();

    $destination = $current_request->query->get('destination');

    $link = Url::fromUri('https://github.com/login/oauth/authorize?client_id=' . $client_id . '&scope=user,public&redirect_uri=' . urlencode($base_url . '/github/register/create?destination=' . $destination['destination']));
    $output = \Drupal::l(t('Login with GitHub'), $link);

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }
}
