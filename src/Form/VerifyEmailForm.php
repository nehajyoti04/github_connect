<?php


namespace Drupal\github_connect\Form;

use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Entity\EntityManagerInterface;

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
use Drupal\user\Entity\User;
use Drupal\user\UserAuth;
use Drupal\user\UserAuthInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
/**
 * Validates user authentication credentials.
 */
class VerifyEmailForm extends FormBase implements UserAuthInterface {

  /**
   * The password hashing service.
   *
   * @var \Drupal\Core\Password\PasswordInterface
   */
  protected $password_checker;

  /**
   * The current account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Constructs a UserAuth object.
   *
   * @param \Drupal\Core\Password\PasswordInterface $password_checker
   *   The password service.
   */
  public function __construct(PasswordInterface $password_checker, AccountInterface $account) {
    $this->password_checker = $password_checker;
    $this->account = $account;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('password')
    );
  }

  /**
   * Validates user authentication credentials.
   *
   * @param string $username
   *   The user name to authenticate.
   * @param string $password
   *   A plain-text password, such as trimmed text from form values.
   * @return int|bool
   *   The user's uid on success, or FALSE on failure to authenticate.
   */
  public function authenticate($name, $password){
    $uid = FALSE;
    if (!empty($name) && !empty($password)) {
      $account = user_load_by_name($name);
      if ($account) {
        // Allow alternate password hashing schemes.
        $x = $account->getPassword();
        if ($this->password_checker->check($password, $x)) {
          // Successful authentication.
          $uid = $account->id();

          // // Update user to new password scheme if needed.
          // if (user_needs_new_hash($account)) {
          //   user_save($account, array('pass' => $password));
          // }
        }
      }
    }
    return $uid;
  }

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
    // $site_name =  $this->config->get('system.site')->get('name');
    $site_name = $this->config('system.site')->get('name');
    if (!$uid) {
      $account = $this->account;
//      $account = \Drupal::currentUser();
    } else {
      $account = \Drupal\user\Entity\User::load($uid);// pass your uid
    }
    $name = $account->get('name')->value;
    $form['message'] = array(
      '#type' => 'item',
      '#title' => $this->t('Email address in use'),
      '#markup' => $this->t('There is already an account associated with your GitHub email address. Type your %site account password to merge accounts.', array('%site' => $site_name)),
    );
    $form['name'] = array('#type' => 'hidden', '#value' => $name);
    $form['pass'] = array('#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Enter your password.'),
      '#required' => TRUE,
    );
    $form['token'] = array('#type' => 'hidden', '#value' => $token);
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array('#type' => 'submit', '#value' => $this->t('Merge accounts'));
    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $name = $form_state->getValues()['name'];
    $password = $form_state->getValues()['pass'];

    if ($this->github_connect_authenticate($name, $password) == FALSE) {
      $form_state->setErrorByName('pass', $this->t('Incorrect password.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $account = user_load_by_name($form_state->getValue('name'));
    $token = $form_state->getValue('token');

    GithubConnectController::_github_connect_save_github_user($account, $token);

    // Log in the connected user.
    GithubConnectController::_github_connect_user_login($account);
    drupal_set_message($this->t('You are now connected with your GitHub account.'));

    $redirect_url = $this->url('<front>');
    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

  public function github_connect_authenticate($name, $password) {
    $uid = FALSE;
    if (!empty($name) && !empty($password)) {
      $account = user_load_by_name($name);
      if ($account) {
        // Allow alternate password hashing schemes.
        if ($this->password_checker->check($password, $account->getPassword())) {
          // Successful authentication.
          $uid = $account->id();
        }
      }
    }
    return $uid;
  }

}
