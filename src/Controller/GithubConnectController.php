<?php

namespace Drupal\github_connect\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\github_connect\GithubConnectService;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
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

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * GithubController Class Service.
   *
   * @var \Drupal\github_connect\GithubConnectService
   */
  protected $githubConnectService;

  /**
   * Class constructor.
   */
  public function __construct(AccountInterface $account, $url, Connection $connection, ConfigFactoryInterface $config_factory, Client $client, GithubConnectService $githubConnectService) {
    $this->account = $account;
    $this->url = $url;
    $this->connection = $connection;
    $this->config = $config_factory->getEditable('github_connect.settings');
    $this->httpClient = $client;
    $this->githubConnectService = $githubConnectService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      // Load the service required to construct this class.
      $container->get('current_user'),
      $container->get('url_generator'),
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('github_connect_service')
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
    $client = $this->httpClient;
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
      $github_user = $this->githubConnectService->githubConnectGetGithubUserInfo($token);
      if ($github_user && !empty($github_user['html_url'])) {

        // Check the authmap for an existing associated account.
        $account = $this->githubConnectUserExternalLoad($github_user['html_url']);
      }

      if ($uid == 0) {
        // First the case where an anonymous user attempts a login.
        if ($account) {
          // If there is a user with the token log that user in.
          $this->githubConnectService->githubConnectUserLogin($account);
          $redirect_url = $this->url('<front>');
          $response = new RedirectResponse($redirect_url);
          $response->send();
          return $response;
        }
        else {
          // Otherwise register the user and log in.
          $github_user = $this->githubConnectService->githubConnectGetGithubUserInfo($token);

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
              $this->githubConnectService->githubConnectRegister($github_user, $token);
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
          $github_user = $this->githubConnectService->githubConnectGetGithubUserInfo($token);

          if (!$github_user['email']) {
            drupal_set_message($this->t('We could not connect your GitHub account. You need to have a public email address registered with your GitHub account.'), 'error');
            $response = new RedirectResponse('user/' . $uid . '/github');
            $response->send();
            return $response;
          }

          if ($github_user['html_url']) {
            $this->githubConnectService->githubConnectSaveGithubUser($account, $token);
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
    return FALSE;
  }

  /**
   * Get user from GitHub access token.
   *
   * @param string $token
   *   Access token from GitHub.
   *
   * @return bool|\Drupal\Core\Entity\EntityInterface|null|static
   *   Drupal user.
   */
  public function githubConnectGetTokenUser($token) {
    if ($token) {
      $result = $this->connection->select('github_connect_users', 'g_u')
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
    return FALSE;
  }

  /**
   * Authorizes wheather correct user is logged in or not.
   */
  public function githubConnectUserExternalLoad($authname) {
    $uid = $this->connection->select('github_connect_authmap', 'gca')
      ->fields('gca', ['uid'])
      ->condition('authname', $authname)
      ->execute()->fetchField();

    if ($uid) {
      return User::load($uid);
    }
    else {
      return FALSE;
    }
  }

}
