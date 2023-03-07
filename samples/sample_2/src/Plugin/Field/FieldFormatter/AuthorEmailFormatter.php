<?php

namespace Drupal\some_module\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;

/**
 * Plugin implementation of the 'name_author_email' formatter.
 *
 * @FieldFormatter(
 *   id = "name_author_email",
 *   label = @Translation("Author Email"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class AuthorEmailFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Displays the author email.');
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      $element[$delta]['#markup'] = "";
      if ($entity->bundle() != 'author' || !$entity->hasField('field_contact')) {
        continue;
      }
      $contact = $entity->get('field_contact')->get(0)->getValue();
      if (!isset($contact['platform_values']['email']['value'])) {
        continue;
      }
      $element[$delta] = ['#markup' => $contact['platform_values']['email']['value']];
    }

    return $element;
  }

}
