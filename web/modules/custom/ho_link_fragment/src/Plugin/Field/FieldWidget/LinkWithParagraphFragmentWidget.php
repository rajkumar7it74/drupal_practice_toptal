<?php

declare(strict_types=1);

namespace Drupal\ho_link_fragment\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\LinkWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Link widget + fragment dropdown populated from target page paragraphs (AJAX).
 *
 * @FieldWidget(
 *   id = "link_with_paragraph_fragment",
 *   label = @Translation("Link with paragraph fragment (AJAX)"),
 *   field_types = {
 *     "link"
 *   }
 * )
 */
final class LinkWithParagraphFragmentWidget extends LinkWidget implements ContainerFactoryPluginInterface {

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    $field_definition,
    array $settings,
    array $third_party_settings,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PathValidatorInterface $pathValidator,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('path.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // LinkWidget usually creates $element['uri'], $element['title'], $element['options'].
    // We add a fragment select that updates via AJAX when uri changes.
    $wrapper_id = 'ho-fragment-wrapper-' . $delta . '-' . preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $element['#field_name']);

    $current_fragment = '';
    if (!empty($items[$delta]->options['fragment'])) {
      $current_fragment = (string) $items[$delta]->options['fragment'];
    }

    // Attach AJAX to the uri element (when link changes => rebuild fragment options).
    if (isset($element['uri'])) {
      $element['uri']['#ajax'] = [
        'callback' => [static::class, 'ajaxUpdateFragmentOptions'],
        'event' => 'change',
        'wrapper' => $wrapper_id,
        'progress' => ['type' => 'throbber'],
      ];
    }

    $uri_value = $this->getCurrentUriValue($form_state, $element, $delta) ?? ($items[$delta]->uri ?? '');

    $options = $this->buildFragmentOptionsFromUri((string) $uri_value);

    $element['ho_fragment'] = [
      '#type' => 'select',
      '#title' => $this->t('Fragment (Paragraph id)'),
      '#options' => $options,
      '#default_value' => $current_fragment ?: '',
      '#empty_option' => $this->t('- None -'),
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
      '#description' => $this->t('Select a paragraph fragment from the target page.'),
      // Optional: only show when internal entity/internal path. Keep it simple.
    ];

    return $element;
  }

  /**
   * AJAX callback to refresh fragment dropdown.
   */
  public static function ajaxUpdateFragmentOptions(array &$form, FormStateInterface $form_state): array {
    // Find the triggering element and walk up to the widget element.
    $trigger = $form_state->getTriggeringElement();
    $parents = $trigger['#parents'] ?? [];

    // Replace last key (uri) with ho_fragment to return that element.
    // Typical parents: [field_name, delta, uri]
    if (!empty($parents)) {
      array_pop($parents);
      $parents[] = 'ho_fragment';
      return static::nestedElement($form, $parents);
    }

    // Fallback: return whole form.
    return $form;
  }

  /**
   * Helper to safely get nested element.
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
   * {@inheritdoc}
   *
   * Here we save as url#fragment.
   * Best practice: store fragment inside options['fragment'].
   * If you REALLY want uri to contain #fragment, we also append it.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $values = parent::massageFormValues($values, $form, $form_state);

    foreach ($values as &$value) {
      $fragment = $value['ho_fragment'] ?? '';
      unset($value['ho_fragment']);

      if (!empty($fragment) && !empty($value['uri'])) {
        // Preferred: store in options fragment (Drupal will render with #fragment).
        $value['options']['fragment'] = $fragment;

        // Optional (as you asked): also persist as url#fragment in uri string.
        // Avoid double fragments.
        if (strpos($value['uri'], '#') === FALSE) {
          $value['uri'] .= '#' . $fragment;
        }
      }
    }

    return $values;
  }

  /**
   * Get current uri from form_state for this widget instance.
   */
  private function getCurrentUriValue(FormStateInterface $form_state, array $element, int $delta): ?string {
    $parents = $element['uri']['#parents'] ?? NULL;
    if (!$parents) {
      return NULL;
    }
    $val = $form_state->getValue($parents);
    return is_string($val) ? $val : NULL;
  }

  /**
   * Build fragment select options from a given uri.
   */
  private function buildFragmentOptionsFromUri(string $uri): array {
    $entity = $this->resolveEntityFromUri($uri);
    if (!$entity) {
      return [];
    }

    $options = [];
    $visited = [];

    // Collect paragraph fragments recursively.
    $this->collectParagraphFragments($entity, $options, $visited);

    // Example: key should match your HTML id format.
    // If twig uses id="p{{ paragraph.id() }}", fragment must be "p123".
    $final = [];
    foreach ($options as $pid => $label) {
      $final['p' . $pid] = $label . ' (p' . $pid . ')';
    }

    return $final;
  }

  /**
   * Convert uri (entity:node/123 or internal:/path) to an entity if possible.
   */
  private function resolveEntityFromUri(string $uri): ?EntityInterface {
    $uri = trim($uri);
    if ($uri === '') {
      return NULL;
    }

    // entity:node/123
    if (str_starts_with($uri, 'entity:node/')) {
      $nid = (int) substr($uri, strlen('entity:node/'));
      if ($nid > 0) {
        return $this->entityTypeManager->getStorage('node')->load($nid);
      }
      return NULL;
    }

    // internal:/some-path
    if (str_starts_with($uri, 'internal:')) {
      $internal_path = substr($uri, strlen('internal:'));
      $url = $this->pathValidator->getUrlIfValid($internal_path);
      if (!$url) {
        return NULL;
      }
      $route_params = $url->getRouteParameters();

      // Most common: node route.
      if (!empty($route_params['node'])) {
        $nid = (int) $route_params['node'];
        return $this->entityTypeManager->getStorage('node')->load($nid);
      }

      return NULL;
    }

    // External URL: cannot resolve paragraphs.
    return NULL;
  }

  /**
   * Recursively collect paragraphs from entity_reference_revisions fields.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param array $options
   * @param array $visited
   */
  private function collectParagraphFragments(EntityInterface $entity, array &$options, array &$visited): void {
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }
      $settings = $definition->getSettings();
      if (($settings['target_type'] ?? '') !== 'paragraph') {
        continue;
      }
      if (!$entity->hasField($field_name)) {
        continue;
      }

      /** @var \Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemListInterface $list */
      $list = $entity->get($field_name);
      $paragraphs = $list->referencedEntities();

      foreach ($paragraphs as $p) {
        if (!$p instanceof EntityInterface) {
          continue;
        }
        $pid = (int) $p->id();
        if ($pid <= 0 || isset($visited[$pid])) {
          continue;
        }
        $visited[$pid] = TRUE;

        $bundle = method_exists($p, 'bundle') ? $p->bundle() : 'paragraph';
        $options[$pid] = "Paragraph {$pid} ({$bundle})";

        // Recurse further if that paragraph references more paragraphs.
        $this->collectParagraphFragments($p, $options, $visited);
      }
    }
  }

}
