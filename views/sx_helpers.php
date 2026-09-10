<?php declare(strict_types = 1);

/**
 * Helpers de montagem compartilhados pelas duas páginas do módulo.
 * Guardados por function_exists: o mesmo request nunca carrega as duas
 * views, mas custa nada ser à prova disso.
 */

if (!function_exists('sx_tag')) {

function sx_tag(string $name, $content = null, array $attrs = []): CTag {
	$tag = new CTag($name, true, $content);

	foreach ($attrs as $key => $value) {
		$tag->setAttribute($key, $value);
	}

	return $tag;
}

/**
 * Elemento sem fechamento (input, img).
 */
function sx_void(string $name, array $attrs = []): CTag {
	$tag = new CTag($name, false);

	foreach ($attrs as $key => $value) {
		$tag->setAttribute($key, $value);
	}

	return $tag;
}

function sx_card(string $title, $body, string $subtitle = '', array $attrs = [], string $title_id = ''): CTag {
	$heading = [$title];

	if ($subtitle !== '') {
		$heading[] = sx_tag('span', $subtitle);
	}

	$title_attrs = ['class' => 'sx-card-title'];

	if ($title_id !== '') {
		$title_attrs['id'] = $title_id;
	}

	return sx_tag('section', [
		sx_tag('h2', $heading, $title_attrs),
		sx_tag('div', $body, ['class' => 'sx-card-body'])
	], array_merge(['class' => 'sx-card'], $attrs));
}

function sx_field(string $id, string $label, CTag $control): CTag {
	return sx_tag('div', [sx_tag('label', $label, ['for' => $id]), $control], ['class' => 'sx-field']);
}

function sx_input(string $id, string $value, array $attrs = []): CTag {
	return sx_void('input', array_merge(['id' => $id, 'value' => $value], $attrs));
}

/**
 * @param array<string, string> $options
 */
function sx_select(string $id, array $options, string $selected, bool $disabled = false): CTag {
	$items = [];

	foreach ($options as $value => $label) {
		$option = sx_tag('option', $label, ['value' => (string) $value]);

		if ((string) $value === $selected) {
			$option->setAttribute('selected', 'selected');
		}

		$items[] = $option;
	}

	$select = sx_tag('select', $items, ['id' => $id]);

	if ($disabled) {
		$select->setAttribute('disabled', 'disabled');
	}

	return $select;
}

function sx_chart(string $id, string $extra_class = ''): CTag {
	return sx_tag('div', sx_tag('canvas', null, ['id' => $id]), ['class' => trim('sx-chartbox '.$extra_class)]);
}

}
