<?php

namespace Drupal\ctu_comments\Plugin\Field\FieldFormatter;

use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ctu_comments\CtuCommentsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'ctu_comments_field_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "ctu_comments_field_formatter",
 *   label = @Translation("Ctu comments field formatter"),
 *   field_types = {
 *     "comment"
 *   }
 * )
 */
class CtuCommentsFieldFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'view_mode' => 'default',
        'pager_id' => 0,
      ] + parent::defaultSettings();
  }

  /**
   * The comment storage.
   *
   * @var \Drupal\comment\CommentStorageInterface
   */
  protected $storage;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The comment render controller.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface
   */
  protected $viewBuilder;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The entity form builder.
   *
   * @var \Drupal\Core\Entity\EntityFormBuilderInterface
   */
  protected $entityFormBuilder;

  /**
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   */
  protected $routeMatch;

  /**
   * @param \Drupal\ctu_comments\CtuCommentsService $ctu_comments_service
   */
  protected $ctu_comments_service;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('current_user'),
      $container->get('entity.manager'),
      $container->get('entity.form_builder'),
      $container->get('current_route_match'),
      $container->get('ctu_comments.service')
    );
  }

  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, AccountInterface $current_user, EntityManagerInterface $entity_manager, EntityFormBuilderInterface $entity_form_builder, RouteMatchInterface $route_match, CtuCommentsService $ctuCommentsService) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->viewBuilder = $entity_manager->getViewBuilder('comment');
    $this->storage = $entity_manager->getStorage('comment');
    $this->currentUser = $current_user;
    $this->entityManager = $entity_manager;
    $this->entityFormBuilder = $entity_form_builder;
    $this->routeMatch = $route_match;
    $this->ctu_comments_service = $ctuCommentsService;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = [];
    $view_modes = $this->getViewModes();
    $element['view_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Comments view mode'),
      '#description' => $this->t('Select the view mode used to show the list of comments.'),
      '#default_value' => $this->getSetting('view_mode'),
      '#options' => $view_modes,
      // Only show the select element when there are more than one options.
      '#access' => count($view_modes) > 1,
    ];
    $element['pager_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Pager ID'),
      '#options' => range(0, 10),
      '#default_value' => $this->getSetting('pager_id'),
      '#description' => $this->t("Unless you're experiencing problems with pagers related to this field, you should leave this at 0. If using multiple pagers on one page you may need to set this number to a higher value so as not to conflict within the ?page= array. Large values will add a lot of commas to your URLs, so avoid if possible."),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $view_mode = $this->getSetting('view_mode');
    $view_modes = $this->getViewModes();
    $view_mode_label = isset($view_modes[$view_mode]) ? $view_modes[$view_mode] : 'default';
    $summary = [$this->t('Comment view mode: @mode', ['@mode' => $view_mode_label])];
    if ($pager_id = $this->getSetting('pager_id')) {
      $summary[] = $this->t('Pager ID: @id', ['@id' => $pager_id]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $output = [];

    $field_name = $this->fieldDefinition->getName();
    $entity = $items->getEntity();

    $status = $items->status;

    if ($status != CommentItemInterface::HIDDEN && empty($entity->in_preview) &&
      // Comments are added to the search results and search index by
      // comment_node_update_index() instead of by this formatter, so don't
      // return anything if the view mode is search_index or search_result.
      !in_array($this->viewMode, ['search_result', 'search_index'])) {
      $comment_settings = $this->getFieldSettings();

      // Only attempt to render comments if the entity has visible comments.
      // Unpublished comments are not included in
      // $entity->get($field_name)->comment_count, but unpublished comments
      // should display if the user is an administrator.
      $elements['#cache']['contexts'][] = 'user.permissions';
      if ($this->currentUser->hasPermission('access comments') || $this->currentUser->hasPermission('administer comments')) {
        $output['comments'] = [];

        if ($entity->get($field_name)->comment_count || $this->currentUser->hasPermission('administer comments')) {
          $mode = $comment_settings['default_mode'];
          $comments_per_page = $comment_settings['per_page'];
          $comments = $this->ctu_comments_service->loadThread($entity, $field_name, $mode, $comments_per_page, $this->getSetting('pager_id'));
          if ($comments) {
            $build = $this->viewBuilder->viewMultiple($comments, $this->getSetting('view_mode'));
            $build['#attached']['library'][] = 'ctu_comments/ctu_comments.commands';
            $build['pager']['#type'] = 'pager';
            // CommentController::commentPermalink() calculates the page number
            // where a specific comment appears and does a subrequest pointing to
            // that page, we need to pass that subrequest route to our pager to
            // keep the pager working.
            $build['pager']['#route_name'] = $this->routeMatch->getRouteObject();
            $build['pager']['#route_parameters'] = $this->routeMatch->getRawParameters()->all();
            if ($this->getSetting('pager_id')) {
              $build['pager']['#element'] = $this->getSetting('pager_id');
            }
            $output['comments'] += $build;
          }
        }
      }

      // Append comment form if the comments are open and the form is set to
      // display below the entity. Do not show the form for the print view mode.
      if ($status == CommentItemInterface::OPEN && $comment_settings['form_location'] == CommentItemInterface::FORM_BELOW && $this->viewMode != 'print') {
        // Only show the add comment form if the user has permission.
        $elements['#cache']['contexts'][] = 'user.roles';
        if ($this->currentUser->hasPermission('post comments')) {
          $output['comment_form'] = [
            '#lazy_builder' => ['comment.lazy_builders:renderForm', [
              $entity->getEntityTypeId(),
              $entity->id(),
              $field_name,
              $this->getFieldSetting('comment_type'),
            ]],
            '#create_placeholder' => TRUE,
          ];
        }
      }

      $elements[] = $output + [
          '#comment_type' => $this->getFieldSetting('comment_type'),
          '#comment_display_mode' => $this->getFieldSetting('default_mode'),
          'comments' => [],
          'comment_form' => [],
        ];
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    if ($mode = $this->getSetting('view_mode')) {
      if ($bundle = $this->getFieldSetting('comment_type')) {
        /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display */
        if ($display = EntityViewDisplay::load("comment.$bundle.$mode")) {
          $dependencies[$display->getConfigDependencyKey()][] = $display->getConfigDependencyName();
        }
      }
    }
    return $dependencies;
  }

  /**
   * Provides a list of comment view modes for the configured comment type.
   *
   * @return array
   *   Associative array keyed by view mode key and having the view mode label
   *   as value.
   */
  protected function getViewModes() {
    return $this->entityManager->getViewModeOptionsByBundle('comment', $this->getFieldSetting('comment_type'));
  }
}
