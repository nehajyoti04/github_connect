<?php /**
 * @file
 * Contains \Drupal\github_connect\Plugin\Block\GithubConnectLogin.
 */

namespace Drupal\github_connect\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides the GithubConnectLogin block.
 *
 * @Block(
 *   id = "github_connect_github_connect-login",
 *   admin_label = @Translation("Github connect")
 * )
 */
class GithubConnectLogin extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    /**
     * @FIXME
     * hook_block_view() has been removed in Drupal 8. You should move your
     * block's view logic into this method and delete github_connect_block_view()
     * as soon as possible!
     */
    return github_connect_block_view('github_connect-login');
  }

  
}
