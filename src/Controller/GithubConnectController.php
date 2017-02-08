<?php

namespace Drupal\github_connect\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp;
use GuzzleHttp\Psr7\Request;
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
//    print "hello";
//    $response = drupal_http_request($url, $options);
//    $token = $response->data;
    $client = \Drupal::httpClient();
//    $response = $client->request('GET', $url, $options);
    $response = $client->request('POST', $url1);
    $body = (string) $response->getBody();
    list($access_token) = explode('&', $body);


    $token = explode('=', $access_token)[1];

//    \Drupal::logger('response')->notice($response);


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
      print "inside if token";
      // Check if a user exists for the token.
      $account = $this->github_connect_get_token_user($token);

      if ($user->id() == 0) { // First the case where an anonymous user attempts a login
        if ($account) { // If there is a user with the token log that user in.
          \Drupal::logger('account')->notice("user exists");
          $this->_github_connect_user_login($account);
          $response = new RedirectResponse('');
          $response->send();
          print "response send";
          return;
        }
        else { // Otherwise register the user and log in
          \Drupal::logger('inside else')->notice("user does not exist");
          $github_user = $this->_github_connect_get_github_user_info($token);
          \Drupal::logger('github_user')->notice($github_user['email']);

          if ($existing_user_by_mail = user_load_by_mail($github_user['email'])) {
            \Drupal::logger('existing_user_by_mail')->notice("user load by name".$existing_user_by_mail->id());
            \Drupal::logger('token')->notice($token);
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
              \Drupal::logger('existing_user_by_name')->notice("user load by name".$existing_user_by_name->id());
              return $this->redirect('github.username', array('user' => $existing_user_by_name->id(), 'token' => $token));
//              $response = new RedirectResponse('github/username/' . $existing_user_by_name->id() . '/' . $token);
//              $response->send();
//              return;
            } else {
              $this->_github_connect_register($github_user, $token);
//              global $base_url;
              $redirect_url = \Drupal::url('<front>');
              $response = new RedirectResponse($redirect_url);
              $response->send();
//              return;
              \Drupal::logger('this place')->notice("git hub connect register completed.");
//              $response = new RedirectResponse('');
//              $response->send();
              return TRUE;
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
            \Drupal::logger('before html_url')->notice('_github_connect_save_github_user');
            $this->_github_connect_save_github_user($user, $token);

            \Drupal::logger('after html_url')->notice('_github_connect_save_github_user');
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
      $options = array(
        'method' => 'GET',
        'timeout' => 7200,
      );

      \Drupal::logger('token-- request token')->notice($token);

//      $client = \Drupal::client();
//      $result = $client->get('https://www.drupal.org');

      $client = \Drupal::httpClient();
//      $ghuser = $client->request('GET', 'https://api.github.com/user?' . $token);
      $ghuser = $client->request('GET', 'https://api.github.com/user?access_token='.$token.'&scope=user&token_type=bearer');
      // TODO pass timeout value.
      $data = (string) $ghuser->getBody();
      \Drupal::logger('data')->notice($data);
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
      \Drupal::logger('emails - token')->notice($token);
//      $ghuser = $client->request('GET', 'https://api.github.com/user/emails?' . $token);
      $ghuser = $client->request('GET', 'https://api.github.com/user/emails?access_token='. $token . '&scope=user&token_type=bearer');

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
      \Drupal::logger('before 1')->notice('_github_connect_save_github_user');
      self::_github_connect_save_github_user($account, $token);

      \Drupal::logger('after 1')->notice('_github_connect_save_github_user');
      // Log in the stored user.
      self::_github_connect_user_login($account);

      \Drupal::logger('_github_connect_register - redirect')->notice('..');


//      return self::redirect('');
//      if (!$this->isRedirect()) {
//        throw new \InvalidArgumentException(sprintf('The HTTP status code is not a redirect ("%s" given).', $status));
//      }
      global $base_url;
      $redirect_url = \Drupal::url('<front>');
      \Drupal::logger('_github_connect_register - redirect url')->notice($redirect_url);
      \Drupal::logger('_github_connect_register - base url')->notice($base_url);
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
      drupal_set_message(t('Error saving new user.'), 'error');
      return;
    }
  }
  /**
   * Save the new GitHub user in github_connect_users
   */
  public static function _github_connect_save_github_user($account, $token) {
    \Drupal::logger('inside _github_connect_save_github_user')->notice($account->id());
    $github_user = self::_github_connect_get_github_user_info($token);

    // Set the authmap
//    user_set_authmaps($account, array('authname_github_connect' => $github_user['html_url']));

    // Store GitHub user with token.
    if ($account) {
      \Drupal::logger('_github_connect account id')->notice($account->id());
      \Drupal::logger('_github_connect token')->notice($token);
      db_insert('github_connect_users')
        ->fields(array(
          'uid' => $account->id(),
          'access_token' => $token,
          'timestamp' => REQUEST_TIME,
        ))
        ->execute();
      \Drupal::logger('after insert')->notice($token);
    }
  }
}