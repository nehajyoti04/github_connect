<?php
namespace Drupal\github_connect;

/**
 * Default controller for the github_connect module.
 */
class DefaultController extends ControllerBase {

  public function github_connect_get_access_token() {
    global $user;

    module_load_include('inc', 'github_connect');

    // @FIXME
    // Could not extract the default value because it is either indeterminate, or
    // not scalar. You'll need to provide a default value in
    // config/install/github_connect.settings.yml and config/schema/github_connect.schema.yml.
    $client_id = \Drupal::config('github_connect.settings')->get('github_connect_client_id');
    // @FIXME
    // Could not extract the default value because it is either indeterminate, or
    // not scalar. You'll need to provide a default value in
    // config/install/github_connect.settings.yml and config/schema/github_connect.schema.yml.
    $client_secret = \Drupal::config('github_connect.settings')->get('github_connect_client_secret');

    // The response code after first call to GitHub.
    $code = $_GET['code'];

    $url = 'https://github.com/login/oauth/access_token?';
    $options = [
      'data' => 'client_id=' . $client_id . '&client_secret=' . $client_secret . '&code=' . $code,
      'method' => 'POST',
    ];
    $response = drupal_http_request($url, $options);
    $token = $response->data;

    if ($token) {
      // Check if a user exists for the token.
      $account = github_connect_get_token_user($token);

      if ($user->uid == 0) {
        // First the case where an anonymous user attempts a login
        if ($account) {
          // If there is a user with the token log that user in.
          _github_connect_user_login($account);
          drupal_goto();
        }
        else {
          // Otherwise register the user and log in
          $github_user = _github_connect_get_github_user_info($token);

          if ($existing_user_by_mail = user_load_by_mail($github_user['email'])) {
            // If a user with this email address exists, let him connect the github account to his already created account.
            drupal_goto('github/verify/email/' . $existing_user_by_mail->uid . '/' . $token);
          }
          else {
            // Otherwise make sure there is no account with the same username
            if ($existing_user_by_name = user_load_by_name($github_user['login'])) {
              drupal_goto('github/username/' . $existing_user_by_name->uid . '/' . $token);
            }
            else {
              _github_connect_register($github_user, $token);
              drupal_goto();
            }
          }
        }
      }
      else {
        // Second the case where an logged in user attempts to attach his github account
        if ($account) {
          // If there is a user with the token, throw an error.
          drupal_set_message(t('Your GitHub account could not be connected, it is already coupled with another user.'), 'error');
          drupal_goto('user/' . $user->uid . '/github');
        }
        else {
          $github_user = _github_connect_get_github_user_info($token);

          if (!$github_user['email']) {
            drupal_set_message(t('We could not connect your GitHub account. You need to have a public email address registered with your GitHub account.'), 'error');
            drupal_goto('user/' . $user->uid . '/github');
          }

          if ($github_user['html_url']) {
            _github_connect_save_github_user($user, $token);

            drupal_set_message(t('Your GitHub account is now connected.'));
            drupal_goto('user/' . $user->uid . '/github');
          }
        }
      }
    }
    else {
      // If we didn't get a token, connection to Github failed
      drupal_set_message(t('Failed connecting to GitHub.'), 'error');
      drupal_goto();
    }
  }

  public function github_user_account($account) {
    drupal_set_title(format_username($account));

    // Check to see if we got a response
    $result = openid_complete();
    if ($result['status'] == 'success') {
      $identity = $result['openid.claimed_id'];
      $query = db_insert('authmap')
        ->fields([
        'uid' => $account->uid,
        'authname' => $identity,
        'module' => 'openid',
      ])
        ->execute();
      drupal_set_message(t('Successfully added %identity', [
        '%identity' => $identity
        ]));
    }

    $header = [t('GitHub account'), t('Operations')];
    $rows = [];

    $result = db_query("SELECT * FROM {authmap} WHERE module='github_connect' AND uid=:uid", [
      ':uid' => $account->uid
      ]);
    foreach ($result as $identity) {
      // @FIXME
// l() expects a Url object, created from a route name or external URI.
// $rows[] = array(check_plain($identity->authname), l(t('Delete'), 'user/' . $account->uid . '/github/delete/' . $identity->aid));

    }

    $build['github_table'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    ];
    if (!$result->rowCount()) {
      $build['github_user_connect'] = drupal_get_form('github_user_connect');
    }
    return $build;
  }

}
