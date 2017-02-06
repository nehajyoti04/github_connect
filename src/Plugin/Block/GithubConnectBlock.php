<?php

/**
 * @file
 * Contains Drupal\github_connect\Plugin\Block\GithubConnectBlock.
 */

namespace Drupal\github_connect\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Block\Annotation\Block;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Block\BlockBase;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a 'age_calculator' block.
 *
 * @Block(
 *   id = "github_connect_block",
 *   admin_label = @Translation("Github Connect"),
 * )
 */
class GithubConnectBlock extends BlockBase implements BlockPluginInterface{


  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {

    if ($account->isAnonymous() ) {
      return AccessResult::allowed();
//      return AccessResult::allowed()->addCacheContexts(['route.name']);
    }
    return AccessResult::forbidden();

//    return AccessResult::allowedIfHasPermission($account, 'access content');
//    if (!$account->isAnonymous() ) {
//      return AccessResult::allowed()->addCacheContexts(['route.name']);
//    }
//    return AccessResult::forbidden();

  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    global $base_url;
    \Drupal::logger('anonymous user')->notice(\Drupal::currentUser()->isAnonymous());
    if (!(\Drupal::currentUser()->isAnonymous())) {
      \Drupal::logger('anonymous user')->notice(\Drupal::currentUser()->isAnonymous());
      $items = array('content' => '');
      return array(
        '#items' => $items);
//      return FALSE;
    }

    $client_id = \Drupal::state()->get('github_connect_client_id');

    $current_request = \Drupal::service('request_stack')->getCurrentRequest();

    $destination = $current_request->query->get('destination');

    $option = [
      'query' => ['client_id' => $client_id, 'scope' => 'user,public', 'uri' => urlencode($base_url . '/github/register/create?destination=' . $destination['destination'])
      ],
    ];
    $link = Url::fromUri('https://github.com/login/oauth/authorize', $option);
//    $link = Url::fromUri('https://github.com/login/oauth/authorize?client_id=');
    $output = \Drupal::l(t('Login with GitHub'), $link);
    \Drupal::logger('login link')->notice($output);

//    $items = array('content' => $output);
    return array(
      '#type' => 'markup',
      '#markup' => $output,
    );

  }



}
