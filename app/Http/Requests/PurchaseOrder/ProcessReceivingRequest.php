<?php

declare(strict_types=1);

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the receiving form for a purchase order (web surface).
 */
final class ProcessReceivingRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array',

            'items.*.id' => 'required|exists:purchase_order_items,id',

            'items.*.quantity_to_receive' => [
                'required',
                'integer',
                'min:0',
            ],

            /*
             * Physical manufacturer barcodes scanned during receiving.
             *
             * These are stored as the ProductSerial serial_number.
             * We do NOT generate or modify these values.
             */
            'items.*.barcodes' => [
                'nullable',
                'array',
            ],

            'items.*.barcodes.*' => [
                'required',
                'string',
                'distinct',
            ],
        ];
    }
}