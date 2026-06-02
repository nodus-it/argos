<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Renders an edit page heading as "record name + optional chip", matching the
 * task-detail header (name next to a chip, actions on the right). Edit pages
 * use this for a uniform detail header across the settings/worker sections.
 *
 * Override argosHeadingAttribute()/argosHeadingChip() per resource.
 */
trait HasArgosEditHeading
{
    public function getHeading(): string|Htmlable
    {
        $value = $this->getRecord()->getAttribute($this->argosHeadingAttribute());
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        $name = (string) ($value ?? '');
        $html = '<span class="td-heading-name">'.e($name).'</span>';

        $chip = $this->argosHeadingChip();
        if ($chip !== null) {
            $html .= '<span class="td-heading-badge">'.Blade::render(
                '<x-argos.chip :icon="$icon">{{ $label }}</x-argos.chip>',
                ['icon' => $chip['icon'] ?? null, 'label' => $chip['label'] ?? ''],
            ).'</span>';
        }

        return new HtmlString($html);
    }

    protected function argosHeadingAttribute(): string
    {
        return 'name';
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        return null;
    }
}
