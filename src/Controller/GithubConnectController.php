<?php

namespace Drupal\github_connect\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\externalauth\AuthmapInterface;
use Drupal\externalauth\ExternalAuth;
use Drupal\user\Entity\User;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\user\UserInterface;
use Drupal\externalauth\ExternalAuthInterface;

class GithubConnectController extends ControllerBase {
  public static $modules = array('system', 'user', 'field', 'externalauth');
  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The Authmap service.
   *
   * @var \Drupal\externalauth\AuthmapInterface
   */
  protected $authmap;

  /**
   * The ExternalAuth service.
   *
   * @var \Drupal\externalauth\ExternalAuth
   */
  protected $externalauth;

  /**
   * @var AccountInterface $account
   */
  protected $account;

  /**
   * The URL of the link.
   *
   * @var \Drupal\Core\Url
   */
  protected $url;

  /**
   * Class constructor.
   */
  public function __construct(AccountInterface $account, $url, EntityTypeManagerInterface $entity_type_manager) {
    $this->account = $account;
    $this->url = $url;
    $this->userStorage = $entity_type_manager->getStorage('user');
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
      $container->get('entity.manager')->getStorage('user')
    );
  }


  public function github_connect_get_access_token() {
    // Get current user data.
    $uid = $this->account->id();

    $config = $this->configFactory->get('github_connect.settings');
    $client_id = $config->get('github_connect_client_id');
    $client_secret = $config->get('github_connect_client_secret');

    // The response code after first call to GitHub.
    $code = $_GET['code'];

    $url = 'https://github.com/login/oauth/access_token?';
    $options = array(
      'data' => 'client_id=' . $client_id . '&client_secret=' . $client_secret . '&code=' . $code,
      'method' => 'POST',
    );
//    $url1= Url::fromUri($url, $options['data']);
    $url1= $url.$options['data'];

//    $response = drupal_http_request($url, $options);
//    $token = $response->data;
    $client = \Drupal::httpClient();
//    $response = $client->request('GET', $url, $options);
    $response = $client->request('POST', $url1);
    $body = (string) $response->getBody();
    list($access_token) = explode('&', $body);


    $token = explode('=', $access_token)[1];



//    https://github.com/login/oauth/authorize?client_id=b2235973f6ec11c2e212&scope=user,public&
    //redirect_uri=http%3A%2F%2Fdrupal7-2%2Fgithub%2Fregister%2Fcreate%3Fdestination%3Duser
//    $response = \Drupal::httpClient()->get($url, $options);
//    print "after reponse";
//    $token = (string) $response->getBody();
//    print "before empty token";
    if (empty($token)) {
//      print "empty token";
      return FALSE;
    }

    if ($token) {
      // Check if a user exists for the token.
      $account = $this->github_connect_get_token_user($token);
      if ($uid == 0) { // First the case where an anonymous user attempts a login
        if ($account) { // If there is a user with the token log that user in.
          $this->_github_connect_user_login($account);
          $response = new RedirectResponse('');
          $response->send();
          return $response;
        }
        else { // Otherwise register the user and log in
          $github_user = $this->_github_connect_get_github_user_info($token);

          if ($existing_user_by_mail = user_load_by_mail($github_user['email'])) {
            // If a user with this email address exists, let him connect the github account to his already created account.
            return $this->redirect('github_connect.verify', array('uid' => $existing_user_by_mail->id(), 'token' => $token));
//            $url = 'github/verify/email/' . $existing_user_by_mail->id() . '/' . $token;
//            return new RedirectResponse($url);
//            $response = new RedirectResponse();
//            $response->send();
//            return;
          }
          else {

            // Otherwise make sure there is no account with the same username
            if ($existing_user_by_name = user_load_by_name($github_user['login'])) {
              return $this->redirect('github.username', array('user' => $existing_user_by_name->id(), 'token' => $token));
//              $response = new RedirectResponse('github/username/' . $existing_user_by_name->id() . '/' . $token);
//              $response->send();
//              return;
            } else {
              $this->_github_connect_register($github_user, $token);
//              global $base_url;
              $redirect_url = $this->url('<front>');
              $response = new RedirectResponse($redirect_url);
              $response->send();
//              return;
//              $response = new RedirectResponse('');
//              $response->send();
              return $response;
            }
          }
        }
      }
      else { // Second the case where an logged in user attempts to attach his github account
        if ($account) {
          // If there is a user with the token, throw an error.
          drupal_set_message($this->t('Your GitHub account could not be connected, it is already coupled with another user.'), 'error');
          $response = new RedirectResponse('user/' . $account->id() . '/github');
          $response->send();
          return $response;
        }
        else {
          $github_user = $this->_github_connect_get_github_user_info($token);

          if (!$github_user['email']) {
            drupal_set_message($this->t('We could not connect your GitHub account. You need to have a public email address registered with your GitHub account.'), 'error');
            $response = new RedirectResponse('user/' . $uid . '/github');
            $response->send();
            return $response;
          }

          if ($github_user['html_url']) {
//            $this->_github_connect_save_github_user($user, $token);
            $this->_github_connect_save_github_user($account, $token);

            drupal_set_message($this->t('Your GitHub account is now connected.'));
            $response = new RedirectResponse('user/' . $uid . '/github');
            $response->send();
            return $response;
          }
        }
      }
    }
    else {
      // If we didn't get a token, connection to Github failed
      drupal_set_message($this->t('Failed connecting to GitHub.'), 'error');
      $response = new RedirectResponse('');
      $response->send();
      return $response;
    }


  }

  /**
   * Get user from GitHub access token.
   *
   * @param $token Access token from GitHub
   * @return $user Drupal user
   */
  public function github_connect_get_token_user($token) {
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
      return $this->userStorage->load($uid);

//      return user_load($uid);
    }
  }

  /**
   * Log the user with the given account in
   */
  public static function _github_connect_user_login($account) {
    $form_state['uid'] = $account->id();
//    user_login_submit(array(), $form_state);
  }

  /**
   * Get the user info provided by github
   *
   * @param $token The token for the github user
   */
  public static function _github_connect_get_github_user_info($token, $request_token = '') {
    $cache = &drupal_static(__FUNCTION__);

    if (!is_null($cache)) {
      $github_user = $cache;
    } else {
      // Collects the User information from GitHub.
//      $options = array(
//        'method' => 'GET',
//        'timeout' => 7200,
//      );
//      $client = \Drupal::client();
//      $result = $client->get('https://www.drupal.org');

      $client = \Drupal::httpClient();
//      $ghuser = $client->request('GET', 'https://api.github.com/user?' . $token);
      $ghuser = $client->request('GET', 'https://api.github.com/user?access_token='.$token.'&scope=user&token_type=bearer');
      // TODO pass timeout value.
      $data = (string) $ghuser->getBody();
//      $ghuser = \Drupal::httpClient()->get('https://api.github.com/user?' . $token, $options);
      $github_user = Json::decode($data);


      $github_user_emails = self::_github_connect_get_github_user_emails($token);
      $github_user['email'] = $github_user_emails[0]['email'];
//      print '<pre>'; print_r("github user emails"); print '</pre>';
//      print '<pre>'; print_r($github_user_emails); print '</pre>';
    }

    return $github_user;
  }

  /**
   * Get the private email addresses from the user
   *
   * @param $token The token for the github user
   */
  static function _github_connect_get_github_user_emails($token) {
    $cache = &drupal_static(__FUNCTION__);

    if (!is_null($cache)) {
      $github_user_emails = $cache;
    } else {
      // Collects the User information from GitHub.
      $options = array(
        'method' => 'GET',
        'timeout' => 7200,
      );

      $client = \Drupal::httpClient();
//      $ghuser = $client->request('GET', 'https://api.github.com/user/emails?' . $token);
      $ghuser = $client->request('GET', 'https://api.github.com/user/emails?access_token='. $token . '&scope=user&token_type=bearer');

//      $ghuser = \Drupal::httpClient()->get('https://api.github.com/user/emails?' . $token, $options);
      $data = (string) $ghuser->getBody();
      $github_user_emails = Json::decode($data);
    }

    return $github_user_emails;
  }
  /**
   * Register new user.
   */
  public function _github_connect_register($github_user, $token) {
//    module_load_include('inc', 'github_connect');

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
      $this->_github_connect_save_github_user($account, $token);

      // Log in the stored user.
      self::_github_connect_user_login($account);



//      return self::redirect('');
//      if (!$this->isRedirect()) {
//        throw new \InvalidArgumentException(sprintf('The HTTP status code is not a redirect ("%s" given).', $status));
//      }
      global $base_url;
      $redirect_url = $this->url('<front>');
      $response = new RedirectResponse($base_url);
      $response->send();
      return;
//      return $this->redirect('');
//      $url =  Url::fromUserInput(\Drupal::destination()->get())->setAbsolute()->toString();
//      return new RedirectResponse($url);
//      $response = new RedirectResponse('');
//      $response->send();
//      return;
    }
    else {
      drupal_set_message($this->t('Error saving new user.'), 'error');
      return;
    }
  }
  /**
   * Save the new GitHub user in github_connect_users
   */
  public function _github_connect_save_github_user($account, $token) {
    $github_user = self::_github_connect_get_github_user_info($token);

    // Set the authmap.
//    $s = new ExternalAuth;
//    $s->register($account, 'github_connect', $github_user['html_url']);
//    db_insert('authmap')
//      ->fields(array(
//        'uid' => $account->id(),
//        'provider' => 'github_connect',
//        'authname' => $github_user['html_url'],
//      ))
//      ->execute();
//    $this->authmap = \Drupal::service('externalauth.authmap');
    $x = \Drupal::service('externalauth.authmap');
    $x->save($account, 'github_connect',$github_user['html_url']);
    // Login.
    \Drupal::service('externalauth.externalauth')->login($github_user['html_url'], 'github_connect');
//    user_set_authmaps($account, array('authname_github_connect' => $github_user['html_url']));
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