<?php

namespace Drupal\link_fragment_widget\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
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

    // SAFE wrapper id (works even if #parents is string).
    $parents = $element['#parents'] ?? [];
    if (is_string($parents)) {
      $parents = [$parents];
    }
    elseif (!is_array($parents)) {
      $parents = [];
    }
    $wrapper_id = Html::getUniqueId(implode('-', $parents) . '-fragment-wrapper');

    // Default fragment from saved link options (if any).
    $default_fragment = '';
    if (!empty($items[$delta]->options) && is_array($items[$delta]->options)) {
      $default_fragment = $items[$delta]->options['fragment'] ?? '';
    }

    // Get current URI (prefer form_state value if user changed it).
    $uri = '';
    if (isset($element['uri']['#parents']) && is_array($element['uri']['#parents'])) {
      $current = $form_state->getValue($element['uri']['#parents']);
      if (is_string($current)) {
        $uri = $current;
      }
    }
    if ($uri === '' && !empty($element['uri']['#default_value']) && is_string($element['uri']['#default_value'])) {
      $uri = $element['uri']['#default_value'];
    }

    // Build fragment options based on selected URI.
    $fragment_options = $this->buildFragmentOptions($uri);

    // AJAX: refresh fragment dropdown when URI changes.
    if (isset($element['uri'])) {
      $element['uri']['#ajax'] = [
        'callback' => [static::class, 'ajaxFragmentCallback'],
        'event' => 'change',
        'wrapper' => $wrapper_id,
        'progress' => ['type' => 'throbber'],
      ];
    }

    // Wrap fragment select so AJAX replaces only this part.
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
    ];

    return $element;
  }

  /**
   * AJAX callback: return only the fragment wrapper container.
   */
  public static function ajaxFragmentCallback(array &$form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    $parents = $trigger['#parents'] ?? [];

    if (!is_array($parents) || empty($parents)) {
      return $form;
    }

    // Typical parents: [..., uri] -> replace with fragment_wrapper.
    array_pop($parents);
    $parents[] = 'fragment_wrapper';

    return static::nestedElement($form, $parents);
  }

  /**
   * Safe nested element fetcher.
   */
  private static function nestedElement(array $array, array $parents): array {
    $ref = $array;
    foreach ($parents as $p) {
      if (!isset($ref[$p])) {
        return $array;
      }
      $ref = $ref[$p];
    }
    return $ref;
  }

  /**
   * Build fragment options for a given URI.
   */
  private function buildFragmentOptions(string $uri): array {
    $options = ['' => (string) $this->t('- None -')];

    $node = $this->resolveNodeFromUri($uri);
    if (!$node) {
      return $options;
    }

    // Your ho_page paragraphs field.
    $field_name = 'field_ho_page_content';

    if ($node->hasField($field_name)) {
      foreach ($node->get($field_name)->referencedEntities() as $paragraph) {
        $fragment_id = 'paragraph-' . $paragraph->id();
        $options[$fragment_id] = $fragment_id;
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

    if (str_starts_with($uri, 'entity:node/')) {
      $nid = (int) substr($uri, strlen('entity:node/'));
      return $nid > 0 ? Node::load($nid) : NULL;
    }

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
        // Recommended: store fragment in options.
        $value['options']['fragment'] = $fragment;

        // Optional: also append into uri (your requirement).
        if (!empty($value['uri']) && strpos($value['uri'], '#') === FALSE) {
          $value['uri'] .= '#' . $fragment;
        }
      }
    }
    return $values;
  }

}
