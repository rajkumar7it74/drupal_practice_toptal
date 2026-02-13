<?php

namespace Drupal\my_link_widget\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\Plugin\Field\FieldWidget\LinkWidget;
use Drupal\node\NodeInterface;

/**
 * Extends core Link widget and adds a dependent select list.
 *
 * @FieldWidget(
 *   id = "link_with_dynamic_select",
 *   label = @Translation("Link (core) + dynamic select"),
 *   field_types = {"link"}
 * )
 */
class LinkWithDynamicSelectWidget extends LinkWidget {

    /**
     * {@inheritdoc}
     */
    public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
        // Build the default link widget (uri + title + options).
        $element = parent::formElement($items, $delta, $element, $form, $form_state);

        $field_name = $this->fieldDefinition->getName();
        $wrapper_id = $field_name . '-dynamic-select-' . $delta;

        $parents = [$field_name, $delta, 'uri'];
        $uri = $form_state->getValue($parents);
        \Drupal::logger('my_module')->debug('URI value at @p: @uri', [
        '@p' => implode(' > ', $parents),
        '@uri' => is_scalar($uri) ? (string) $uri : print_r($uri, TRUE),
        ]);

        // Wrap the widget delta for AJAX replacement.
        $element['#prefix'] = '<div id="' . $wrapper_id . '">';
        $element['#suffix'] = '</div>';

        // Add AJAX on the URI element (works for core widget).
        if (isset($element['uri'])) {
            $element['uri']['#ajax'] = [
            'callback' => [static::class, 'ajaxRefresh'],
            'event' => 'change',
            'wrapper' => $wrapper_id,
            'progress' => ['type' => 'throbber'],
            ];
        }

        // Get current uri value (from form_state first, otherwise entity value).
        $uri = $this->getCurrentUri($items, $delta, $form_state);

        // Build options (static for now; later you can change based on $uri).
        $options = $this->buildSelectOptions($uri);

        // Default selected value (from stored link options, if any).
        $stored = [];
        if (!$items->isEmpty() && isset($items[$delta]->options) && is_array($items[$delta]->options)) {
            $stored = $items[$delta]->options;
        }
        $default_selected = $stored['mywidget']['selected'] ?? '';

        // Add our new select element INSIDE the same widget.
        $element['my_dynamic_select'] = [
            '#type' => 'select',
            '#title' => $this->t('Extra dropdown'),
            '#options' => $options,
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => isset($options[$default_selected]) ? $default_selected : '',
            '#description' => $this->t('This value is stored inside link options.'),
            '#weight' => 50,
        ];

        return $element;
    }

    /**
     * AJAX callback: return the widget delta wrapper.
     */
    public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
        $trigger = $form_state->getTriggeringElement();
        // Parents look like: [field_link, 0, uri]
        $parents = $trigger['#parents'];
        $field_name = $parents[0];
        $delta = $parents[1];

        return $form[$field_name]['widget'][$delta];
    }

    /**
     * Save our select value into LinkItem 'options' so it persists.
     */
    public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
        $values = parent::massageFormValues($values, $form, $form_state);

        foreach ($values as &$value) {
        if (!isset($value['options']) || !is_array($value['options'])) {
            $value['options'] = [];
        }

        // Our custom select is present at the same level as uri/title.
        $selected = $value['my_dynamic_select'] ?? '';

        // Persist it inside link options.
        $value['options']['mywidget']['selected'] = $selected;

        // Remove the extra key so Field API doesn’t complain.
        unset($value['my_dynamic_select']);
        }
        return $values;
    }

    /**
     * Get current URI from form_state or existing item.
     */
    protected function getCurrentUri(FieldItemListInterface $items, int $delta, FormStateInterface $form_state): string {
        $field_name = $this->fieldDefinition->getName();

        // In the link widget, uri value is usually at: field_name][delta][uri
        $uri = $form_state->getValue([$field_name, $delta, 'uri']);
        if (is_string($uri) && $uri !== '') {
        return $uri;
        }

        // Fallback to stored value.
        if (!$items->isEmpty() && isset($items[$delta]->uri) && $items[$delta]->uri !== NULL) {
        return (string) $items[$delta]->uri;
        }

        return '';
    }

    /**
     * Static for now. Later you can generate based on $uri condition.
     */
    protected function buildSelectOptions(string $uri): array {
        $node = $this->resolveNodeFromUri($uri);
        if (!$node) {
            return [];
        }
        $options = [];
        // Loop all fields (includes base fields + config fields).
        foreach ($node->getFieldDefinitions() as $field_name => $definition) {
            // Skip internal/system fields you usually don't want in UI.
            if (str_starts_with($field_name, 'revision_') || in_array($field_name, [
            'uuid', 'vid', 'revision_id', 'langcode',
            'default_langcode', 'revision_default',
            'revision_translation_affected',
            'content_translation_source', 'content_translation_outdated',
            ], TRUE)) {
            continue;
            }

            // Only show fields that exist on the entity instance.
            if (!$node->hasField($field_name)) {
            continue;
            }

            /** @var \Drupal\Core\Field\FieldItemListInterface $field */
            $field = $node->get($field_name);

            // If empty, you can skip OR show "(empty)".
            if ($field->isEmpty()) {
                continue; // or: $value_text = '(empty)';
            }

            $label = $definition->getLabel();
            $value_text = $this->stringifyField($field);

            // Limit very long values for dropdown readability.
            $value_text = $this->truncate($value_text, 120);

            // Key = field machine name. Label = "Field Label: value".
            $options[$field_name] = $label . ': ' . $value_text;
        }
        return $options;
    }

    /**
     * Converts a field to readable string (handles references, lists, text, etc).
     */
    protected function stringifyField(FieldItemListInterface $field): string {
        $definition = $field->getFieldDefinition();
        $type = $definition->getType();

        // Entity reference fields: show referenced entity labels.
        if ($type === 'entity_reference' || $type === 'entity_reference_revisions') {
            $labels = [];
            foreach ($field->referencedEntities() as $entity) {
                $labels[] = $entity->label();
            }
            return implode(', ', array_filter($labels));
        }

        // File/image: show file names.
        if (in_array($type, ['image', 'file'], TRUE)) {
            $names = [];
            foreach ($field->referencedEntities() as $file) {
                $names[] = $file->label();
            }
            return implode(', ', array_filter($names));
        }

        // Link field: show uri + title.
        if ($type === 'link') {
            $out = [];
            foreach ($field as $item) {
                $u = (string) ($item->uri ?? '');
                $t = (string) ($item->title ?? '');
                $out[] = trim($t . ' ' . $u);
            }
            return implode(' | ', array_filter($out));
        }

        // Default: join all item main values.
        // For most fields, ->value exists; for text_long also exists.
        $vals = [];
        foreach ($field as $item) {
            if (isset($item->value)) {
                $vals[] = (string) $item->value;
            }
            else {
                // Fallback: export item array.
                $vals[] = json_encode($item->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return implode(', ', array_filter($vals));
    }
    /**
     * Resolve node from link URI or alias.
     */
    protected function resolveNodeFromUri(string $uri): ?NodeInterface {
        $uri = trim($uri);
        if ($uri === '') {
            return NULL;
        }

        // Case 1: Linkit style entity URI: entity:node/123
        if (preg_match('#^entity:node/(\d+)$#', $uri, $m)) {
            $nid = (int) $m[1];
            $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
            return ($node instanceof NodeInterface) ? $node : NULL;
        }

        // Case 2: internal:/some-alias OR /some-alias OR some-alias
        // Normalize to internal path.
        if (str_starts_with($uri, 'internal:')) {
            $path = substr($uri, strlen('internal:'));
        }
        else {
            $path = $uri;
        }

        // If it's an absolute URL (http/https), ignore for now (no node).
        if (preg_match('#^https?://#i', $path)) {
            return NULL;
        }

        // Ensure leading slash.
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        // Convert alias -> internal system path using alias manager.
        $internal = \Drupal::service('path_alias.manager')->getPathByAlias($path);

        // If it maps to /node/123 => load node.
        if (preg_match('#^/node/(\d+)$#', $internal, $m)) {
            $nid = (int) $m[1];
            $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
            return ($node instanceof NodeInterface) ? $node : NULL;
        }
        return NULL;
    }

    protected function truncate(string $text, int $limit = 120): string {
        $text = preg_replace('/\s+/', ' ', trim($text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit - 1) . '…';
    }
}
