<?php

namespace Drupal\link_fragment_widget\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\Plugin\Field\FieldWidget\LinkWidget;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;

/**
 * Plugin implementation of the 'link_with_fragment' widget.
 *
 * @FieldWidget(
 *   id = "link_with_fragment",
 *   label = @Translation("Link with fragment selector"),
 *   field_types = {
 *     "link"
 *   }
 * )
 */
class LinkWithFragmentWidget extends LinkWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Default fragment value.
    $default_fragment = $items[$delta]->get('options')->getValue()['fragment'] ?? '';

    // Initialize options.
    $fragment_options = ['' => $this->t('- None -')];
if (!empty($element['uri']['#default_value'])) {
        $uri = $element['uri']['#default_value'];
        $url = Url::fromUri($uri);
        if ($url->isRouted() && $url->getRouteName() === 'entity.node.canonical') {
            $node_id = $url->getRouteParameters()['node'];
            $node = Node::load('25053');
            if ($node && $node->hasField('field_ho_page_content')) {
                foreach ($node->get('field_ho_page_content')->referencedEntities() as $paragraph) {
                    // Use the fragment ID you generated in preprocess.
                    $fragment_id = 'paragraph-' . $paragraph->id();
                    // Or use a title field if available.
                    // $label = $paragraph->get('field_title')->value ?: $paragraph->bundle();
                    $fragment_options[$fragment_id] = $fragment_id;
                }
            }
        }
    }

    $element['fragment'] = [
      '#type' => 'select',
      '#title' => $this->t('Fragment'),
      '#options' => $fragment_options,
      '#default_value' => $default_fragment,
    ];

    return $element;
  }

   /**
   * {@inheritdoc}
   */
  public function massageFormValues($values, $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      if (!empty($value['uri']) && !empty($value['fragment'])) {
        // Append the fragment to the URI.
        $value['uri'] .= '#' . $value['fragment'];
      }
    }
    return $values;
  }
}
