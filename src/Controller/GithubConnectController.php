<?php

namespace Drupal\github_connect\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use GuzzleHttp\Exception\RequestException;
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
//    $response = drupal_http_request($url, $options);
//    $token = $response->data;
    try {
      $response = \Drupal::httpClient()->get($url, $options);
      $token = (string) $response->getBody();
      if (empty($token)) {
        return FALSE;
      }
    }
    catch (RequestException $e) {
      return FALSE;
    }

    if ($token) {
      // Check if a user exists for the token.
      $account = $this->github_connect_get_token_user($token);

      if ($user->id() == 0) { // First the case where an anonymous user attempts a login
        if ($account) { // If there is a user with the token log that user in.
          $this->_github_connect_user_login($account);
          $response = new RedirectResponse('');
          $response->send();
          return;
        }
        else { // Otherwise register the user and log in
          $github_user = $this->_github_connect_get_github_user_info($token);

          if ($existing_user_by_mail = user_load_by_mail($github_user['email'])) {
            // If a user with this email address exists, let him connect the github account to his already created account.
            $response = new RedirectResponse('github/verify/email/' . $existing_user_by_mail->uid . '/' . $token);
            $response->send();
            return;
          }
          else {
            // Otherwise make sure there is no account with the same username
            if ($existing_user_by_name = user_load_by_name($github_user['login'])) {
              $response = new RedirectResponse('github/username/' . $existing_user_by_name->uid . '/' . $token);
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
          $response = new RedirectResponse('user/' . $user->uid . '/github');
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
      $ghuser = \Drupal::httpClient()->get('https://api.github.com/user?' . $token, $options);
      $github_user = Json::decode($ghuser->data);

      $github_user_emails = $this->_github_connect_get_github_user_emails($token);
      $github_user['email'] = $github_user_emails[0];
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
      $ghuser = \Drupal::httpClient()->get('https://api.github.com/user/emails?' . $token, $options);
      $github_user_emails = Json::decode($ghuser->data);
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

    $account = \Drupal::entity_create('user', $userinfo);
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
      db_insert('github_connect_users')
        ->fields(array(
          'uid' => $account->uid,
          'access_token' => $token,
          'timestamp' => REQUEST_TIME,
        ))
        ->execute();
    }
  }
}