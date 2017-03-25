<?php

/**
 * @file
 * Contains Drupal\github_connect\Plugin\Block\GithubConnectBlock.
 */

namespace Drupal\github_connect\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Block\Annotation\Block;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\LinkGeneratorTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Url;
use Drupal\github_connect\GithubConnectConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'github_connect' block.
 *
 * @Block(
 *   id = "github_connect_block",
 *   admin_label = @Translation("Github Connect"),
 * )
 */
class GithubConnectBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface{
  use LinkGeneratorTrait;
  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * RequestStack object for getting requests.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * GithubConnectBlock constructor.
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, RequestStack $requestStack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->configFactory = $config_factory;
    $this->requestStack = $requestStack;

//    $this->setConfiguration($configuration);
//    $this->settings = $config->settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {

    if ($account->isAnonymous() ) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    global $base_url;


    $config = $this->configFactory->get('github_connect.settings');
    $client_id = $config->get('github_connect_client_id');

    $current_request = $this->requestStack->getCurrentRequest();

    $destination = $current_request->query->get('destination');

    $option = [
      'query' => ['client_id' => $client_id, 'scope' => 'user,public', 'uri' => urlencode($base_url . '/github/register/create?destination=' . $destination['destination'])
      ],
    ];
    $link = Url::fromUri('https://github.com/login/oauth/authorize', $option);
    $output = $this->l($this->t('Login with GitHub'), $link);
    return array(
      '#type' => 'markup',
      '#markup' => $output,
    );

  }

}
