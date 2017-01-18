<?php

namespace Drupal\github_connect\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\user\UserInterface;

class GithubConnectController extends ControllerBase {

  public function github_connect_get_access_token() {
    $user = \Drupal::currentUser();

//
//    module_load_include('inc', 'github_connect');
//
    $client_id = \Drupal::state()->get('github_connect_client_id');
    $client_secret = \Drupal::state()->get('github_connect_client_secret');
//
    // The response code after first call to GitHub.
    $code = $_GET['code'];
//
    $url = 'https://github.com/login/oauth/access_token?';
    $options = array(
      'data' => 'client_id=' . $client_id . '&client_secret=' . $client_secret . '&code=' . $code,
      'method' => 'POST',
    );
//    $url1= Url::fromUri($url, $options['data']);
    $url1= $url.$options['data'];
//    print '<pre>'; print_r("url1"); print '</pre>';
//    print '<pre>'; print_r($url1); print '</pre>';exit;
//    \Drupal::logger('url 1')->notice($url1);
    print "hello";
//    $response = drupal_http_request($url, $options);
//    $token = $response->data;
    $client = \Drupal::httpClient();
//    $response = $client->request('GET', $url, $options);
    $response = $client->request('POST', $url1);

//    \Drupal::logger('response')->notice($response);


//    https://github.com/login/oauth/authorize?client_id=b2235973f6ec11c2e212&scope=user,public&
    //redirect_uri=http%3A%2F%2Fdrupal7-2%2Fgithub%2Fregister%2Fcreate%3Fdestination%3Duser

//    $response = \Drupal::httpClient()->get($url, $options);
    print "after reponse";
    $token = (string) $response->getBody();
    print "before empty token";
    if (empty($token)) {
      print "empty token";
      return FALSE;
    }

    if ($token) {
      print "inside if token";
      // Check if a user exists for the token.
      $account = $this->github_connect_get_token_user($token);

      if ($user->id() == 0) { // First the case where an anonymous user attempts a login
        if ($account) { // If there is a user with the token log that user in.
          $this->_github_connect_user_login($account);
          $response = new RedirectResponse('');
          $response->send();
          print "response send";
          return;
        }
        else { // Otherwise register the user and log in
          $github_user = $this->_github_connect_get_github_user_info($token);
//          \Drupal::logger('github_user')->notice($github_user['email']);

          if ($existing_user_by_mail = user_load_by_mail($github_user['email'])) {
            \Drupal::logger('existing_user_by_mail')->notice("user load by name".$existing_user_by_mail->id());
            // If a user with this email address exists, let him connect the github account to his already created account.
            $response = new RedirectResponse('github/verify/email/' . $existing_user_by_mail->id() . '/' . $token);
            $response->send();
            return;
          }
          else {
            // Otherwise make sure there is no account with the same username
            if ($existing_user_by_name = user_load_by_name($github_user['login'])) {
              \Drupal::logger('existing_user_by_name')->notice("user load by name".$existing_user_by_name->id());
              $response = new RedirectResponse('github/username/' . $existing_user_by_name->id() . '/' . $token);
              $response->send();
              return;
            } else {
              $this->_github_connect_register($github_user, $token);
              $response = new RedirectResponse('');
              $response->send();
              return;
            }
          }
        }
      }
      else { // Second the case where an logged in user attempts to attach his github account
        if ($account) {
          // If there is a user with the token, throw an error.
          drupal_set_message(t('Your GitHub account could not be connected, it is already coupled with another user.'), 'error');
          $response = new RedirectResponse('user/' . $account->id() . '/github');
          $response->send();
          return;
        }
        else {
          $github_user = $this->_github_connect_get_github_user_info($token);

          if (!$github_user['email']) {
            drupal_set_message(t('We could not connect your GitHub account. You need to have a public email address registered with your GitHub account.'), 'error');
            $response = new RedirectResponse('user/' . $user->id() . '/github');
            $response->send();
            return;
          }

          if ($github_user['html_url']) {
            $this->_github_connect_save_github_user($user, $token);

            drupal_set_message(t('Your GitHub account is now connected.'));
            $response = new RedirectResponse('user/' . $user->id() . '/github');
            $response->send();
            return;
          }
        }
      }
    }
    else {
      // If we didn't get a token, connection to Github failed
      drupal_set_message(t('Failed connecting to GitHub.'), 'error');
      $response = new RedirectResponse('');
      $response->send();
      return;
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

      return user_load($uid);
    }
  }

  /**
   * Log the user with the given account in
   */
  function _github_connect_user_login($account) {
    $form_state['uid'] = $account->id();
//    user_login_submit(array(), $form_state);
  }

  /**
   * Get the user info provided by github
   *
   * @param $token The token for the github user
   */
  function _github_connect_get_github_user_info($token) {
    $cache = &drupal_static(__FUNCTION__);

    if (!is_null($cache)) {
      $github_user = $cache;
    } else {
      // Collects the User information from GitHub.
      $options = array(
        'method' => 'GET',
        'timeout' => 7200,
      );

      \Drupal::logger('token')->notice($token);

//      $client = \Drupal::client();
//      $result = $client->get('https://www.drupal.org');

      $client = \Drupal::httpClient();
      $ghuser = $client->request('GET', 'https://api.github.com/user?' . $token);
      // TODO pass timeout value.
      $data = (string) $ghuser->getBody();
      \Drupal::logger('data')->notice($data);
//      $ghuser = \Drupal::httpClient()->get('https://api.github.com/user?' . $token, $options);
      $github_user = Json::decode($data);


      $github_user_emails = $this->_github_connect_get_github_user_emails($token);
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
  function _github_connect_get_github_user_emails($token) {
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
      $ghuser = $client->request('GET', 'https://api.github.com/user/emails?' . $token);

//      $ghuser = \Drupal::httpClient()->get('https://api.github.com/user/emails?' . $token, $options);
      $data = (string) $ghuser->getBody();
      \Drupal::logger('emails - data')->notice($data);
      $github_user_emails = Json::decode($data);
    }

    return $github_user_emails;
  }
  /**
   * Register new user.
   */
  function _github_connect_register($github_user, $token) {
    module_load_include('inc', 'github_connect');

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
      $this->_github_connect_user_login($account);

      $response = new RedirectResponse('');
      $response->send();
      return;
    }
    else {
      drupal_set_message(t('Error saving new user.'), 'error');
      return;
    }
  }
  /**
   * Save the new GitHub user in github_connect_users
   */
  function _github_connect_save_github_user($account, $token) {
    $github_user = $this->_github_connect_get_github_user_info($token);

    // Set the authmap
//    user_set_authmaps($account, array('authname_github_connect' => $github_user['html_url']));

    // Store GitHub user with token.
    if ($account) {
      \Drupal::logger('_github_connect account id')->notice($account->id());
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