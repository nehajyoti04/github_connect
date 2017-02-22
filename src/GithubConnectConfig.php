<?php

namespace Drupal\github_connect;

use Drupal\Core\Config\ConfigFactoryInterface;


class GithubConnectConfig {

  protected $settings;


  public function __construct(ConfigFactoryInterface $config) {
    $this->settings = $config->get('github_connect.settings');
  }

}
