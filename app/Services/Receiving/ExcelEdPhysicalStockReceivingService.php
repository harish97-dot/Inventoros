<?php

declare(strict_types=1);

namespace App\Services\Receiving;

use App\Models\Inventory\ExcelEdProductProfile;
use App\Models\Inventory\ExcelEdStockMovement;
use App\Models\Inventory\ExcelEdStockPack;
use App\Models\Inventory\ExcelEdStockUnit;
use App\Models\PesoItem;
use App\Models\PesoProductMapping;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLicence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Receives physical EXCEL/ED BOX and LOOSE stock without touching normal stock ledgers. */
final class ExcelEdPhysicalStockReceivingService
{
    /**
     * Receive one BOX or LOOSE unit and all of its manually confirmed packs.
     *
     * Trusted internal interface: the organization and actor must come from
     * server-side authenticated/authorized context, never request fields.
     * A future endpoint should pass $actor->organization_id (the active
     * organization) and $actor from authentication.
     * The caller must generate one UUID per attempt and retain it unchanged
     * until a response confirms success; network retries reuse that UUID.
     *
     * Required keys: receipt_uuid, product_profile_id, stock_type,
     * warehouse_id, licence_id, packs. BOX additionally requires main_barcode.
     * A PESO item can be supplied at unit level as an idempotency/source key;
     * pack-level source_peso_item_id may override the inherited source.
     * Each pack requires manufacturer_name, mtr and quantity. Manufacturer
     * code is required for BOX and optional for explicitly selected LOOSE stock.
     */
    public function receive(int $organizationId, User $actor, array $input): ExcelEdStockUnit
    {
        return DB::transaction(function () use ($organizationId, $actor, $input): ExcelEdStockUnit {
            if ($organizationId < 1 || (int) $actor->organization_id !== $organizationId) {
                $this->invalid('organization_id', 'The authenticated actor must be active in the receiving organization.');
            }

            $receiptUuid = $input['receipt_uuid'] ?? null;
            if (! is_string($receiptUuid) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $receiptUuid)) {
                $this->invalid('receipt_uuid', 'A valid receipt UUID is required for every physical receipt.');
            }
            $receiptUuid = strtolower($receiptUuid);

            $profileId = $this->positiveInteger($input['product_profile_id'] ?? null, 'product_profile_id');
            $warehouseId = $this->positiveInteger($input['warehouse_id'] ?? null, 'warehouse_id');
            $licenceId = $this->positiveInteger($input['licence_id'] ?? null, 'licence_id');
            $stockType = strtoupper((string) ($input['stock_type'] ?? ''));
            if (! in_array($stockType, ['BOX', 'LOOSE'], true)) {
                $this->invalid('stock_type', 'Stock type must be BOX or LOOSE.');
            }

            $profile = ExcelEdProductProfile::query()
                ->where('organization_id', $organizationId)
                ->with('product')
                ->lockForUpdate()
                ->find($profileId);
            if ($profile === null || ! in_array($profile->product_type, ['EXCEL', 'ED'], true) || $profile->product === null) {
                $this->invalid('product_profile_id', 'Select a valid EXCEL or ED internal product for this organization.');
            }

            $warehouse = Warehouse::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($warehouseId);
            if ($warehouse === null) {
                $this->invalid('warehouse_id', 'Select an active warehouse belonging to this organization.');
            }

            $licence = WarehouseLicence::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($licenceId);
            if ($licence === null) {
                $this->invalid('licence_id', 'Select an active licence belonging to the receiving warehouse.');
            }

            $unitSourceId = $this->nullablePositiveInteger($input['source_peso_item_id'] ?? null, 'source_peso_item_id');
            $unitSource = $unitSourceId === null
                ? null
                : $this->sourceItem($unitSourceId, $organizationId, $warehouseId, 'source_peso_item_id');

            $barcode = $input['main_barcode'] ?? null;
            if ($barcode !== null && ! is_string($barcode)) {
                $this->invalid('main_barcode', 'The outer barcode must be text.');
            }
            if ($stockType === 'BOX' && trim((string) $barcode) === '') {
                $this->invalid('main_barcode', 'A BOX requires its physical outer barcode.');
            }

            $rawPacks = $input['packs'] ?? null;
            if (! is_array($rawPacks) || $rawPacks === []) {
                $this->invalid('packs', 'At least one manually confirmed pack is required.');
            }
            if ($stockType === 'LOOSE' && count($rawPacks) !== 1) {
                $this->invalid('packs', 'A LOOSE stock unit must contain exactly one pack.');
            }

            $actorId = (int) $actor->getKey();

            // Resolve and validate every explicit pack value before creating any rows.
            $packs = [];
            foreach (array_values($rawPacks) as $index => $packInput) {
                if (! is_array($packInput)) {
                    $this->invalid("packs.{$index}", 'Each pack must be an object of manually confirmed values.');
                }

                $manufacturer = $packInput['manufacturer_name'] ?? null;
                if (! is_string($manufacturer) || trim($manufacturer) === '') {
                    $this->invalid("packs.{$index}.manufacturer_name", 'Manufacturer name is required.');
                }
                $manufacturerCode = $packInput['manufacturer_code'] ?? null;
                if ($manufacturerCode !== null && (! is_string($manufacturerCode) || trim($manufacturerCode) === '')) {
                    $this->invalid("packs.{$index}.manufacturer_code", 'Manufacturer code must be non-empty text when supplied.');
                }
                if ($stockType === 'BOX' && $manufacturerCode === null) {
                    $this->invalid("packs.{$index}.manufacturer_code", 'A BOX pack requires its manufacturer code.');
                }

                $mtr = $this->positiveInteger($packInput['mtr'] ?? null, "packs.{$index}.mtr");
                $quantity = $this->positiveInteger($packInput['quantity'] ?? null, "packs.{$index}.quantity");
                $packSourceId = $this->nullablePositiveInteger(
                    $packInput['source_peso_item_id'] ?? $unitSourceId,
                    "packs.{$index}.source_peso_item_id"
                );
                if ($packSourceId !== null) {
                    $this->sourceItem($packSourceId, $organizationId, $warehouseId, "packs.{$index}.source_peso_item_id");
                }

                $packIdentifier = $packInput['pack_identifier'] ?? null;
                if ($packIdentifier !== null && ! is_string($packIdentifier)) {
                    $this->invalid("packs.{$index}.pack_identifier", 'Pack identifier must be text when supplied.');
                }

                $mapping = $this->resolveExactMapping(
                    $organizationId,
                    (int) $profile->product_id,
                    $manufacturer,
                    $manufacturerCode,
                    $stockType,
                    $packInput['peso_product_mapping_id'] ?? null,
                    "packs.{$index}.peso_product_mapping_id",
                );

                $packs[] = [
                    'manufacturer_name' => $manufacturer,
                    'manufacturer_code' => $manufacturerCode,
                    'pack_identifier' => $packIdentifier,
                    'mtr' => $mtr,
                    'quantity' => $quantity,
                    'source_peso_item_id' => $packSourceId,
                    'peso_product_mapping_id' => $mapping?->id,
                    // Receipt quantities are explicit staff input, never PDF-derived.
                    'quantity_source' => 'manual',
                ];
            }

            $unit = new ExcelEdStockUnit;
            $unit->organization_id = $organizationId;
            $unit->receipt_uuid = $receiptUuid;
            $unit->excel_ed_product_profile_id = $profile->id;
            $unit->stock_type = $stockType;
            $unit->main_barcode = $barcode;
            $unit->warehouse_id = $warehouseId;
            $unit->receiving_warehouse_id = $warehouseId;
            $unit->receiving_licence_id = $licence->id;
            $unit->receiving_licence_number = $licence->licence_number;
            $unit->source_peso_item_id = $unitSource?->id;
            $unit->received_at = now();
            $unit->status = 'active';
            // Physical receipt is complete once the transaction commits. A
            // linked PESO source is only a reference until reconciliation has
            // independently verified it, so every new receipt starts pending.
            $unit->physical_receipt_status = 'received';
            $unit->re12_verification_status = 'pending';

            // Stable keys permit safe retry for an external PESO item or BOX barcode.
            $existingByUuid = ExcelEdStockUnit::query()
                ->where('organization_id', $organizationId)
                ->where('receipt_uuid', $receiptUuid)
                ->with('packs')
                ->lockForUpdate()
                ->first();
            $existingBySource = $unitSourceId === null ? null : ExcelEdStockUnit::query()
                ->where('organization_id', $organizationId)
                ->where('source_peso_item_id', $unitSourceId)
                ->with('packs')
                ->lockForUpdate()
                ->first();
            $existingByBarcode = $barcode === null ? null : ExcelEdStockUnit::query()
                ->where('organization_id', $organizationId)
                ->where('main_barcode', $barcode)
                ->with('packs')
                ->lockForUpdate()
                ->first();

            $existingMatches = collect([$existingByUuid, $existingBySource, $existingByBarcode])
                ->filter()
                ->unique(fn (ExcelEdStockUnit $existing): int => (int) $existing->getKey())
                ->values();
            if ($existingMatches->isNotEmpty()) {
                if ($existingMatches->count() > 1) {
                    $this->invalid('receipt_uuid', 'The receipt UUID, PESO source, or barcode identify different existing receipts.');
                }
                $existing = $existingMatches->first();
                if (! $this->sameReceipt($existing, $unit, $packs)) {
                    $this->invalid('receipt', 'This receipt identifier was already used with different warehouse, product, or pack details.');
                }

                return $existing;
            }

            try {
                $unit->save();
            } catch (\Illuminate\Database\QueryException $exception) {
                // Unique indexes are the final concurrency guard. A competing
                // transaction may have committed after the preflight lookup.
                $racedReceipt = ExcelEdStockUnit::query()
                    ->where('organization_id', $organizationId)
                    ->where(function (Builder $query) use ($receiptUuid, $unitSourceId, $barcode): void {
                        $query->where('receipt_uuid', $receiptUuid);
                        if ($unitSourceId !== null) {
                            $query->orWhere('source_peso_item_id', $unitSourceId);
                        }
                        if ($barcode !== null) {
                            $query->orWhere('main_barcode', $barcode);
                        }
                    })
                    ->with('packs')
                    ->lockForUpdate()
                    ->first();

                if ($racedReceipt === null) {
                    throw $exception;
                }
                if (! $this->sameReceipt($racedReceipt, $unit, $packs)) {
                    $this->invalid('receipt', 'A concurrent receipt used this UUID, barcode, or PESO source with conflicting details.');
                }

                return $racedReceipt;
            }

            foreach ($packs as $packInput) {
                $pack = new ExcelEdStockPack;
                $pack->organization_id = $organizationId;
                $pack->physical_stock_unit_id = $unit->id;
                $pack->excel_ed_product_profile_id = $profile->id;
                $pack->product_id = $profile->product_id;
                $pack->peso_product_mapping_id = $packInput['peso_product_mapping_id'];
                $pack->source_peso_item_id = $packInput['source_peso_item_id'];
                $pack->manufacturer_name = $packInput['manufacturer_name'];
                $pack->manufacturer_code = $packInput['manufacturer_code'];
                $pack->pack_identifier = $packInput['pack_identifier'];
                $pack->mtr = $packInput['mtr'];
                $pack->original_quantity = $packInput['quantity'];
                $pack->remaining_quantity = 0;
                $pack->quantity_source = 'manual';
                $pack->save();

                $pack->recordBalanceMovement(
                    movementType: 'RECEIPT',
                    quantity: $packInput['quantity'],
                    quantityAfter: $packInput['quantity'],
                    userId: $actorId,
                    reason: 'Excel/ED physical stock receipt',
                    warehouseId: $warehouseId,
                    referenceType: $unitSource === null ? ExcelEdStockUnit::class : PesoItem::class,
                    referenceId: $unitSource?->id ?? $unit->id,
                );
            }

            return $unit->load(['productProfile.product', 'packs.movements']);
        }, 5);
    }

    /** Available physical quantity in Nos for one internal product, Mtr and warehouse. */
    public function availableStock(int $organizationId, int $productId, int $mtr, int $warehouseId): int
    {
        return (int) ExcelEdStockPack::query()
            ->where('organization_id', $organizationId)
            ->where('product_id', $productId)
            ->where('mtr', $mtr)
            ->where('remaining_quantity', '>', 0)
            ->whereHas('physicalStockUnit', function (Builder $query) use ($organizationId, $warehouseId): void {
                $query->where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('status', 'active');
            })
            ->sum('remaining_quantity');
    }

    private function resolveExactMapping(
        int $organizationId,
        int $productId,
        string $manufacturer,
        ?string $manufacturerCode,
        string $stockType,
        mixed $providedMappingId,
        string $errorKey,
    ): ?PesoProductMapping {
        $providedId = $this->nullablePositiveInteger($providedMappingId, $errorKey);

        if ($manufacturerCode === null) {
            if ($providedId !== null) {
                $this->invalid($errorKey, 'A mapping cannot be supplied without a manufacturer code.');
            }
            if ($stockType !== 'LOOSE') {
                $this->invalid($errorKey, 'A BOX pack requires an exact manufacturer name and code mapping.');
            }

            return null;
        }

        $mapping = PesoProductMapping::query()
            ->where('organization_id', $organizationId)
            ->where('manufacturer_name_key', PesoProductMapping::exactNameKey($manufacturer))
            ->where('re12_code', $manufacturerCode)
            ->first();

        if (
            $mapping === null
            || $mapping->manufacturer_name !== $manufacturer
            || (int) $mapping->product_id !== $productId
            || ($providedId !== null && (int) $mapping->id !== $providedId)
        ) {
            $this->invalid($errorKey, 'The exact manufacturer name and code must map to this internal product in this organization.');
        }

        return $mapping;
    }

    private function sourceItem(int $sourceId, int $organizationId, int $warehouseId, string $errorKey): PesoItem
    {
        $item = PesoItem::query()
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->find($sourceId);

        if ($item === null || ($item->warehouse_id !== null && (int) $item->warehouse_id !== $warehouseId)) {
            $this->invalid($errorKey, 'The PESO source must belong to this organization and receiving warehouse.');
        }

        return $item;
    }

    private function sameReceipt(ExcelEdStockUnit $existing, ExcelEdStockUnit $requested, array $requestedPacks): bool
    {
        if (
            (int) $existing->organization_id !== (int) $requested->organization_id
            || (int) $existing->excel_ed_product_profile_id !== (int) $requested->excel_ed_product_profile_id
            || $existing->stock_type !== $requested->stock_type
            || $existing->receipt_uuid !== $requested->receipt_uuid
            || $existing->main_barcode !== $requested->main_barcode
            || (int) $existing->warehouse_id !== (int) $requested->warehouse_id
            || (int) $existing->receiving_warehouse_id !== (int) $requested->receiving_warehouse_id
            || (int) $existing->receiving_licence_id !== (int) $requested->receiving_licence_id
            || $existing->receiving_licence_number !== $requested->receiving_licence_number
            || (int) $existing->source_peso_item_id !== (int) $requested->source_peso_item_id
        ) {
            return false;
        }

        $stored = $existing->packs->map(fn (ExcelEdStockPack $pack): array => [
            'manufacturer_name' => $pack->manufacturer_name,
            'manufacturer_code' => $pack->manufacturer_code,
            'pack_identifier' => $pack->pack_identifier,
            'mtr' => (int) $pack->mtr,
            'quantity' => (int) $pack->original_quantity,
            'source_peso_item_id' => $pack->source_peso_item_id === null ? null : (int) $pack->source_peso_item_id,
            'peso_product_mapping_id' => $pack->peso_product_mapping_id === null ? null : (int) $pack->peso_product_mapping_id,
            'quantity_source' => $pack->quantity_source,
        ])->all();
        $normalize = static function (array $rows): array {
            usort($rows, static fn (array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));

            return $rows;
        };

        return $normalize($stored) === $normalize($requestedPacks);
    }

    private function positiveInteger(mixed $value, string $key): int
    {
        if (! $this->isWholeNumber($value) || (float) $value < 1 || (float) $value > 4294967295) {
            $this->invalid($key, 'Value must be a positive whole number within the supported range.');
        }

        return (int) $value;
    }

    private function nullablePositiveInteger(mixed $value, string $key): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveInteger($value, $key);
    }

    private function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1);
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
