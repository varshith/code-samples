<?php

namespace Drupal\custom\Plugin\search_api\processor;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Handle lifetime fields in entities.
 *
 * @SearchApiProcessor(
 *   id = "custom_content",
 *   label = @Translation("Extract content from paragraphs and index as text"),
 *   description = @Translation("Extract text from paragraph reference in articles and index them separately."),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class CustomEntityContent extends ProcessorPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $processor->setTypeManager($container->get('entity_type.manager'));
    $processor->setRenderer($container->get('renderer'));
    $processor->setLogger($container->get('logger.factory'));

    return $processor;
  }

  /**
   * Retrieves the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  public function getTypeManager() {
    return $this->entityTypeManager ?: \Drupal::service('entity_type.manager');
  }

  /**
   * Sets the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $typeManager
   *   The new entity type manager.
   *
   * @return $this
   */
  public function setTypeManager(EntityTypeManagerInterface $type_manager) {
    $this->entityTypeManager = $type_manager;
    return $this;
  }

  /**
   * Retrieves the renderer.
   *
   * @return \Drupal\Core\Render\RendererInterface
   *   The renderer.
   */
  public function getRenderer() {
    return $this->renderer ?: \Drupal::service('renderer');
  }

  /**
   * Sets the renderer.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The new renderer.
   *
   * @return $this
   */
  public function setRenderer(RendererInterface $renderer) {
    $this->renderer = $renderer;
    return $this;
  }

  /**
   * Sets logger Factory.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The databse logger.
   *
   * @return $this
   */
  public function setLogger(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory->get('custom');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Content (Calculated)'),
        'description' => $this->t('Content extracted from field_content field'),
        'type' => 'search_api_html',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['custom_content'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $entity = NULL;
    $paragraph_extracted = '';

    try {
      $entity = $item->getOriginalObject()->getValue();
      $paragraph_extracted = $this->extractParagraphContent($entity);
    }
    catch (\Exception $e) {
      $this->loggerFactory->warning($this->t("Content:%id -> paragraph content cannot be extracted! Skipping...", [
        '%id' => $entity->id(),
      ]));
    }

    if (!$paragraph_extracted) {
      return;
    }

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, 'custom_content');
    foreach ($fields as $field) {
      $field->addValue($paragraph_extracted);
    }
  }

  /**
   * Helper function to extract paragraph content from entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string
   *   The extracted content string.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function extractParagraphContent(EntityInterface $entity) {
    $paragraph_fields = [];
    $fields = $entity->getFields();
    foreach ($fields as $field) {
      $def = $field->getFieldDefinition();
      $settings = $def->getFieldStorageDefinition()->getSettings();
      if ($def->getType() != 'entity_reference_revisions' || !isset($settings['target_type']) || $settings['target_type'] != 'paragraph') {
        continue;
      }
      $paragraph_fields[] = $field->getValue();
    }
    if (empty($paragraph_fields)) {
      return '';
    }

    $paragraph_extracted = '';
    foreach ($paragraph_fields as $field_value) {
      foreach ($field_value as $paragraph) {
        $view_builder = $this->getTypeManager()->getViewBuilder('paragraph');
        $entity = $this->getTypeManager()->getStorage('paragraph')->load($paragraph['target_id']);
        if (!$entity) {
          continue;
        }
        $view = $view_builder->view($entity);
        $render = $this->getRenderer()->renderPlain($view);
        $paragraph_extracted .= trim(strip_tags($render)) . ' ';
      }
    }

    return $paragraph_extracted;
  }

}
