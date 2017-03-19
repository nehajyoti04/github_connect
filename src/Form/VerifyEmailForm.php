<?php

/**
 * @file
 * Contains Drupal\github_connectForm\AddForm
 */

namespace Drupal\github_connect\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ChangedCommand;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form;
use Drupal\github_connect\Controller\GithubConnectController;
use Drupal\user\UserAuth;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class VerifyEmailForm
 *
 * @package Drupal\github_connect\Form
 */
class VerifyEmailForm extends FormBase {
  /**
   * The current account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  protected $userAuth;

  /**
   * Class constructor.
   */
  // public function __construct(AccountInterface $account, UserAuth $user_auth) {
  //   $this->account = $account;
  //   $this->userAuth = $user_auth;
  // }
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'github_connect_verify_email_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uid = '', $token = '') {
    // $site_name =  $this->configFactory->get('system.site')->get('name');
    if (!$uid) {
      // $account = $this->account;
     $account = \Drupal::currentUser();
    } else {
      $account = \Drupal\user\Entity\User::load($uid);// pass your uid
    }
    $name = $account->get('name')->value;
    $form['name'] = array('#type' => 'hidden', '#value' => $name);
    $form['token'] = array('#type' => 'hidden', '#value' => $token);
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array('#type' => 'submit', '#value' => $this->t('Merge accounts'));

    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $account = user_load_by_name($form_state->getValues()['name']);
    $token = $form_state->getValues()['token'];

    GithubConnectController::_github_connect_save_github_user($account, $token);

    // Log in the connected user.
    GithubConnectController::_github_connect_user_login($account);
    drupal_set_message($this->t('You are now connected with your GitHub account.'));

    $redirect_url = $this->url('<front>');
    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

}
