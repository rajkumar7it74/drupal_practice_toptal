<?php

namespace Drupal\link_fragment_widget\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
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

    // Robust unique wrapper id (safe in nested paragraphs too).
    // Use #array_parents (always an array) if available.
    $ap = $element['#array_parents'] ?? [];
    if (!is_array($ap)) {
      $ap = [];
    }
    $wrapper_id = Html::getUniqueId(implode('-', $ap) . '-fragment-wrapper-' . $delta);

    // Read saved fragment from options (if any).
    $default_fragment = '';
    if (!empty($items[$delta]->options) && is_array($items[$delta]->options)) {
      $default_fragment = (string) ($items[$delta]->options['fragment'] ?? '');
    }

    // Determine current URI (prefer form_state, fallback to default value).
    $uri = '';
    if (!empty($element['uri']['#parents']) && is_array($element['uri']['#parents'])) {
      $current = $form_state->getValue($element['uri']['#parents']);
      if (is_string($current)) {
        $uri = $current;
      }
    }
    if ($uri === '' && !empty($element['uri']['#default_value']) && is_string($element['uri']['#default_value'])) {
      $uri = $element['uri']['#default_value'];
    }

    // Build dropdown options: paragraph-<id> => paragraph-<id>
    $fragment_options = $this->buildFragmentOptions($uri);

    // Attach AJAX to uri field to refresh fragment dropdown.
    if (isset($element['uri'])) {
      $element['uri']['#ajax'] = [
        'callback' => [static::class, 'ajaxFragmentCallback'],
        'event' => 'change',
        'wrapper' => $wrapper_id,
        'progress' => ['type' => 'throbber'],
      ];
    }

    // Wrapper container (ONLY this will be replaced by AJAX).
    $element['fragment_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $wrapper_id],
    ];

    $element['fragment_wrapper']['fragment'] = [
      '#type' => 'select',
      '#title' => $this->t('Fragment'),
      '#options' => $fragment_options,
      '#default_value' => $default_fragment ?: '',
      '#empty_value' => '',
      '#empty_option' => $this->t('- None -'),
    ];

    return $element;
  }

  /**
   * AJAX callback: return ONLY the fragment wrapper container.
   */
  public static function ajaxFragmentCallback(array &$form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();

    // Use #array_parents to locate our widget instance in the built form.
    // Typical array_parents ends with "... uri".
    $ap = $trigger['#array_parents'] ?? NULL;
    if (!is_array($ap) || empty($ap)) {
      // Return an empty container instead of whole form.
      return ['#type' => 'container'];
    }

    // Replace last key 'uri' with 'fragment_wrapper'.
    array_pop($ap);
    $ap[] = 'fragment_wrapper';

    $found = NestedArray::getValue($form, $ap);
    if (is_array($found)) {
      return $found;
    }

    // If not found, return empty container (never whole form).
    return ['#type' => 'container'];
  }

  /**
   * Build fragment options for a given URI.
   *
   * Output format (as requested):
   *   ['paragraph-123' => 'paragraph-123', ...]
   */
  private function buildFragmentOptions(string $uri): array {
    $options = [];

    $node = $this->resolveNodeFromUri($uri);
    if (!$node) {
      return $options;
    }

    // IMPORTANT: change if your field machine name differs.
    $field_name = 'field_ho_page_content';

    if ($node->hasField($field_name)) {
      foreach ($node->get($field_name)->referencedEntities() as $paragraph) {
        $key = 'paragraph-' . $paragraph->id();
        $options[$key] = $key;
      }
    }

    return $options;
  }

  /**
   * Resolve node entity from uri (supports entity:node/N and internal: paths).
   */
  private function resolveNodeFromUri(string $uri): ?Node {
    $uri = trim($uri);
    if ($uri === '') {
      return NULL;
    }

    // entity:node/10
    if (str_starts_with($uri, 'entity:node/')) {
      $nid = (int) substr($uri, strlen('entity:node/'));
      return $nid > 0 ? Node::load($nid) : NULL;
    }

    // internal:/node/10 or internal:/alias
    if (str_starts_with($uri, 'internal:')) {
      try {
        $url = Url::fromUri($uri);
        if ($url->isRouted() && $url->getRouteName() === 'entity.node.canonical') {
          $params = $url->getRouteParameters();
          $nid = (int) ($params['node'] ?? 0);
          return $nid > 0 ? Node::load($nid) : NULL;
        }
      }
      catch (\Exception $e) {
        return NULL;
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues($values, $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $fragment = $value['fragment_wrapper']['fragment'] ?? '';
      unset($value['fragment_wrapper']);

      if (!empty($fragment)) {
        // Recommended storage (Drupal renders it as #fragment).
        $value['options']['fragment'] = $fragment;

        // If you still want literal url#fragment stored in uri too (optional).
        if (!empty($value['uri']) && strpos($value['uri'], '#') === FALSE) {
          $value['uri'] .= '#' . $fragment;
        }
      }
    }
    return $values;
  }

}
