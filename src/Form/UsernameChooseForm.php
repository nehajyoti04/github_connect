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
class UsernameChooseForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'github_connect_username_choose_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user='', $token = '') {

    if (!$user) {
      $account = \Drupal::currentUser()->name;
    } else {
      $account = $user;
    }
    $form['message'] = array(
      '#type' => 'item',
      '#title' => t('Username in use'),
      '#markup' => t('There is already an account associated with your GitHub account name %account_name. Please choose a
        different username for use on %site. This will not change your github username and you will continue to be able
        to log in with your github account.',
        array(
          '%site' => \Drupal::state()->get('site_name'),
          '%account_name' => $account,
        )),
    );
    $form['name'] = array('#type' => 'hidden', '#value' => $account->name);
    $form['name_new'] = array('#type' => 'textfield',
      '#title' => t('New username'),
      '#description' => t('Enter another username.'),
      '#required' => TRUE,
    );
    $form['token'] = array('#type' => 'hidden', '#value' => $token);

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array('#type' => 'submit', '#value' => t('Submit username'));

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    $name_new = $form_state['values']['name_new'];

    if (user_load_by_name($name_new)) {
      $form_state->setErrorByName('name_new', t('This username already exists, please choose another one.'));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $token = $form_state->getValues()['token'];
    $github_user = GithubConnectController::_github_connect_get_github_user_info($token);
    // Change the login name to the newly selected name
    $github_user['login'] = $form_state['values']['name_new'];

    GithubConnectController::_github_connect_register($github_user, $token);
    $url =  Url::fromUserInput(\Drupal::destination()->get())->setAbsolute()->toString();
    return new RedirectResponse($url);
  }

}
