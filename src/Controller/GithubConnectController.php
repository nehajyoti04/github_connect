<?php

namespace Drupal\github_connect\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\views\Plugin\views\argument\StringArgument;
use GuzzleHttp;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class GithubConnectController.
 *
 * @package Drupal\github_connect\Controller
 */
class GithubConnectController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The URL of the link.
   *
   * @var \Drupal\Core\Url
   */
  protected $url;

  protected $config;

  /**
   * Class constructor.
   */
  public function __construct(AccountInterface $account, $url, ConfigFactoryInterface $config_factory) {
    $this->account = $account;
    $this->url = $url;
    $this->config = $config_factory->getEditable('github_connect.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
    // Load the service required to construct this class.
      $container->get('current_user'),
      $container->get('url_generator'),
      $container->get('config.factory')
    );
  }

  /**
   * Process an Github authentication.
   */
  public function githubConnectGetAccessToken() {
    // Get current user data.
    $uid = $this->account->id();
    $account = $this->account;
    $client_id = $this->config->get('github_connect_client_id');
    $client_secret = $this->config->get('github_connect_client_secret');

    // The response code after first call to GitHub.
    $code = $_GET['code'];
    $url = 'https://github.com/login/oauth/access_token?';
    $options = array(
      'data' => 'client_id=' . $client_id . '&client_secret=' . $client_secret . '&code=' . $code,
      'method' => 'POST',
    );
    $url1 = $url . $options['data'];
    $client = \Drupal::httpClient();
    $response = $client->request('POST', $url1);
    $body = (string) $response->getBody();
    list($access_token) = explode('&', $body);

    $token = explode('=', $access_token)[1];

    if (empty($token)) {
      return FALSE;
    }

    if ($token) {
      // Check if a user exists for the token.
      // Get user details from GitHub to handle user association.
      $github_user = $this->githubConnectGetGithubUserInfo($token);
      if ($github_user && !empty($github_user['html_url'])) {
        // Check the authmap for an existing associated account.
        $account = $this->githubConnectUserExternalLoad($github_user['html_url']);
      }

      if ($uid == 0) {
        // First the case where an anonymous user attempts a login.
        if ($account) {
          // If there is a user with the token log that user in.
          $this->githubConnectUserLogin($account);
          $redirect_url = $this->url('<front>');
          $response = new RedirectResponse($redirect_url);
          $response->send();
          return $response;
        }
        else {
          // Otherwise register the user and log in.
          $github_user = $this->githubConnectGetGithubUserInfo($token);

          if ($existing_user_by_mail = user_load_by_mail($github_user['email'])) {
            // If a user with this email address exists, let him connect the
            // github account to his already created account.
            return $this->redirect('github_connect.verify', array('uid' => $existing_user_by_mail->id(), 'token' => $token));
          }
          else {
            // Otherwise make sure there is no account with the same username.
            if ($existing_user_by_name = user_load_by_name($github_user['login'])) {
              return $this->redirect('github.username', array('user' => $existing_user_by_name->id(), 'token' => $token));
            }
            else {
              $this->githubConnectRegister($github_user, $token);
              $redirect_url = $this->url('<front>');
              $response = new RedirectResponse($redirect_url);
              $response->send();
              return $response;
            }
          }
        }
      }
      else {
        // Second the case where an logged in user attempts to attach his github
        // account.
        if ($account) {
          // If there is a user with the token, throw an error.
          drupal_set_message($this->t('Your GitHub account could not be connected, it is already coupled with another user.'), 'error');
          $response = new RedirectResponse('user/' . $account->id() . '/github');
          $response->send();
          return $response;
        }
        else {
          $github_user = $this->githubConnectGetGithubUserInfo($token);

          if (!$github_user['email']) {
            drupal_set_message($this->t('We could not connect your GitHub account. You need to have a public email address registered with your GitHub account.'), 'error');
            $response = new RedirectResponse('user/' . $uid . '/github');
            $response->send();
            return $response;
          }

          if ($github_user['html_url']) {
            $this->githubConnectSaveGithubUser($account, $token);
            drupal_set_message($this->t('Your GitHub account is now connected.'));
            $response = new RedirectResponse('user/' . $uid . '/github');
            $response->send();
            return $response;
          }
        }
      }
    }
    else {
      // If we didn't get a token, connection to Github failed.
      drupal_set_message($this->t('Failed connecting to GitHub.'), 'error');
      $response = new RedirectResponse('');
      $response->send();
      return $response;
    }
  }

  /**
   * Get user from GitHub access token.
   *
   * @param $token string
   *   Access token from GitHub.
   *
   * @return bool|\Drupal\Core\Entity\EntityInterface|null|static
   *   Drupal user.
   */
  public function githubConnectGetTokenUser($token) {
    if ($token) {
      $result = db_select('github_connect_users', 'g_u')
        ->fields('g_u', array('uid', 'access_token'))
        ->condition('access_token', $token, '=')
        ->execute()
        ->fetchAssoc();

      $uid = $result['uid'];
      if (empty($uid)) {
        return FALSE;
      }
      return User::load($uid);
    }
  }

  /**
   * Log the user with the given account in.
   */
  public static function githubConnectUserLogin($account) {
    $uid = $account->id();
    $user = User::load($uid);
    user_login_finalize($user);
  }

  /**
   * Get the user info provided by github.
   *
   * @param $token StringArgument
   *   The token for the github user.
   */
  public static function githubConnectGetGithubUserInfo($token) {
    $cache = &drupal_static(__FUNCTION__);

    if (!is_null($cache)) {
      $github_user = $cache;
    }
    else {
      // Collects the User information from GitHub.
      $client = \Drupal::httpClient();
      $ghuser = $client->request('GET', 'https://api.github.com/user?access_token=' . $token . '&scope=user&token_type=bearer');
      // TODO pass timeout value.
      $data = (string) $ghuser->getBody();
      $github_user = Json::decode($data);
      $github_user_emails = self::githubConnectGetGithubUserEmails($token);
      $github_user['email'] = $github_user_emails[0]['email'];
    }

    return $github_user;
  }

  /**
   * Get the private email addresses from the user.
   */
  public static function githubConnectGetGithubUserEmails($token) {
    $cache = &drupal_static(__FUNCTION__);

    if (!is_null($cache)) {
      $github_user_emails = $cache;
    }
    else {
      // Collects the User information from GitHub.
      $client = \Drupal::httpClient();
      $ghuser = $client->request('GET', 'https://api.github.com/user/emails?access_token=' . $token . '&scope=user&token_type=bearer');
      $data = (string) $ghuser->getBody();
      $github_user_emails = Json::decode($data);
    }

    return $github_user_emails;
  }

  /**
   * Register new user.
   */
  public function githubConnectRegister($github_user, $token) {
    $username = $github_user['login'];

    $userinfo = array(
      'name' => $username,
      'mail' => $github_user['email'],
      'pass' => user_password(),
      'status' => 1,
      'access' => REQUEST_TIME,
      'init' => $github_user['email'],
    );

    $account = entity_create('user', $userinfo);
    $account->save();

    if ($account) {
      $this->githubConnectSaveGithubUser($account, $token);

      // Log in the stored user.
      self::githubConnectUserLogin($account);
      global $base_url;
      $response = new RedirectResponse($base_url);
      $response->send();
      return;
    }
    else {
      drupal_set_message($this->t('Error saving new user.'), 'error');
      return;
    }
  }

  /**
   * Authorizes wheather correct user is logged in or not.
   */
  public function githubConnectUserExternalLoad($authname) {
    $uid = db_query("SELECT uid FROM {github_connect_authmap} WHERE authname = :authname", array(':authname' => $authname))->fetchField();

    if ($uid) {
      return User::load($uid);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Save the new GitHub user in github_connect_users.
   */
  public function githubConnectSaveGithubUser($account, $token) {
    $github_user = self::githubConnectGetGithubUserInfo($token);

    db_insert('github_connect_authmap')
      ->fields(array(
        'uid' => $account->id(),
        'provider' => 'github_connect',
        'authname' => $github_user['html_url'],
      ))
      ->execute();

    // Store GitHub user with token.
    if ($account) {
      db_insert('github_connect_users')
        ->fields(array(
          'uid' => $account->id(),
          'access_token' => $token,
          'timestamp' => REQUEST_TIME,
        ))
        ->execute();
    }
  }

}
