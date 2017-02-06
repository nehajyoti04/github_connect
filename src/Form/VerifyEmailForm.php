<?php

/**
 * @file
 * Contains Drupal\github_connectForm\AddForm
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
use Drupal\github_connect\Controller\GithubConnectController;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class AddForm.
 *
 * @package Drupal\github_connect\Form\GithubConnectForm
 */
class VerifyEmailForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'github_connect_verify_email_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user='', $token = '') {

    \Drupal::logger('inside build form - user')->notice($user);
    if (!$user) {
      $account = \Drupal::currentUser()->name;
    } else {
      $account = $user;
    }
    $form['message'] = array(
      '#type' => 'item',
      '#title' => t('Email address in use'),
      '#markup' => t('There is already an account associated with your GitHub email address. Type your !site account password to merge accounts.', array('!site' => variable_get('site_name'))),
    );
    $form['name'] = array('#type' => 'hidden', '#value' => $account->name);
    $form['pass'] = array('#type' => 'password',
      '#title' => t('Password'),
      '#description' => t('Enter your password.'),
      '#required' => TRUE,
    );
    $form['token'] = array('#type' => 'hidden', '#value' => $token);
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array('#type' => 'submit', '#value' => t('Merge accounts'));

    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    $name = $form_state->getValues()['name'];
    $password = $form_state->getValues()['pass'];

    if (\Drupal::service('user.auth')->authenticate($name, $password) == FALSE) {
      $form_state->setErrorByName('pass', t('Incorrect password.'));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $account = user_load_by_name($form_state['values']['name']);
    $token = $form_state->getValues()['token'];

    \Drupal::logger('verify form')->notice('_github_connect_save_github_user');
    GithubConnectController::_github_connect_save_github_user($account, $token);
    \Drupal::logger('after verify form')->notice('_github_connect_save_github_user');

    // Log in the connected user.
    GithubConnectController::_github_connect_user_login($account);
    drupal_set_message(t('You are now connected with your GitHub account.'));

//    $url =  Url::fromUserInput(\Drupal::destination()->get())->setAbsolute()->toString();
//    return new RedirectResponse($url);
    $response = new RedirectResponse('');
    $response->send();
    return;

  }

}
