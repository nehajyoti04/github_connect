<?php

namespace Drupal\github_connect;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity;
use Drupal\Core\Entity\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class GithubConnectPermissions implements ContainerInjectionInterface {

//  use StringTranslationTrait;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   */
  public function __construct(EntityManagerInterface $entity_manager) {
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity.manager'));
  }

  public static function permissions() {
    $perms = [];

    $perms['administer github connect settings'] = array(
      'title' => t('Administer Github Connect settings'),
    );

    return $perms;
  }
}
