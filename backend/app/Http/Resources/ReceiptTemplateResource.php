<?php

namespace App\Http\Resources;

use App\Models\ReceiptTemplate;
use Illuminate\Http\Request;

/**
 * @property ReceiptTemplate $resource
 */
class ReceiptTemplateResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'paper' => $this->resource->paper,
            'header_text' => $this->resource->header_text,
            'footer_text' => $this->resource->footer_text,
            'show_logo' => $this->resource->show_logo,
            'layout' => $this->resource->resolvedLayout(),
            'is_default' => $this->resource->is_default,
        ];
    }
}
