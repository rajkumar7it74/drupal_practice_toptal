<?php

namespace Drupal\link_fragment_widget\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\link\Plugin\Field\FieldWidget\LinkWidget;
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

    // Default fragment from stored link options (if any).
    $default_fragment = '';
    if (!empty($items[$delta]->options) && is_array($items[$delta]->options)) {
      $default_fragment = $items[$delta]->options['fragment'] ?? '';
    }

    // Get current URI (prefer form_state value if user changed it).
    $uri = '';
    if (isset($element['uri']['#parents'])) {
      $current = $form_state->getValue($element['uri']['#parents']);
      if (is_string($current)) {
        $uri = $current;
      }
    }
    if ($uri === '' && !empty($element['uri']['#default_value']) && is_string($element['uri']['#default_value'])) {
      $uri = $element['uri']['#default_value'];
    }

    // Build fragment options.
    $fragment_options = ['' => $this->t('- None -')];

    // Only try to resolve internal node links.
    if ($uri && str_starts_with($uri, 'entity:node/')) {
      $nid = (int) substr($uri, strlen('entity:node/'));
      if ($nid > 0) {
        $node = Node::load($nid);
        if ($node && $node->hasField('field_ho_page_content')) {
          foreach ($node->get('field_ho_page_content')->referencedEntities() as $paragraph) {
            $fragment_id = 'paragraph-' . $paragraph->id();
            $fragment_options[$fragment_id] = $fragment_id;
          }
        }
      }
    }
    elseif ($uri && str_starts_with($uri, 'internal:')) {
      // Handle internal:/node/123 or internal:/some-alias
      try {
        $url = Url::fromUri($uri);
        if ($url->isRouted() && $url->getRouteName() === 'entity.node.canonical') {
          $params = $url->getRouteParameters();
          $nid = (int) ($params['node'] ?? 0);
          if ($nid > 0) {
            $node = Node::load($nid);
            if ($node && $node->hasField('field_ho_page_content')) {
              foreach ($node->get('field_ho_page_content')->referencedEntities() as $paragraph) {
                $fragment_id = 'paragraph-' . $paragraph->id();
                $fragment_options[$fragment_id] = $fragment_id;
              }
            }
          }
        }
      }
      catch (\Exception $e) {
        // Ignore invalid URIs.
      }
    }

    $element['fragment'] = [
      '#type' => 'select',
      '#title' => $this->t('Fragment'),
      '#options' => $fragment_options,
      '#default_value' => $default_fragment ?: '',
      '#empty_value' => '',
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues($values, $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $fragment = $value['fragment'] ?? '';
      unset($value['fragment']);

      if (!empty($fragment)) {
        // Best practice: store fragment in options.
        $value['options']['fragment'] = $fragment;

        // If you still want uri to contain #fragment, do this too (optional).
        if (!empty($value['uri']) && strpos($value['uri'], '#') === FALSE) {
          $value['uri'] .= '#' . $fragment;
        }
      }
    }
    return $values;
  }

}

