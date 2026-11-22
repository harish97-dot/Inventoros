<script setup>
import { Head, Link } from "@inertiajs/vue3";

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head title="PESO - Pending Receiving" />

    <div class="p-6 max-w-7xl mx-auto space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-text-primary">
                    PESO - Pending Receiving
                </h1>

                <p class="mt-1 text-sm text-text-tertiary">
                    Manufacturer barcodes uploaded in RE-12 but not yet physically received.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Link
                    :href="route('receiving.index')"
                    class="rounded-md bg-brand px-4 py-2 text-sm font-medium text-white"
                >
                    Open Receiving Scanner
                </Link>
                <Link
                    :href="route('peso.index')"
                    class="rounded-md border border-border-default bg-white px-4 py-2 text-sm font-medium"
                >
                    ← Uploaded RE-12
                </Link>
            </div>
        </div>

        <!-- Count -->
        <div class="rounded-lg border border-border-default bg-white p-5">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-tertiary">
                Barcodes Awaiting Receiving
            </div>

            <div class="mt-2 text-3xl font-bold text-status-warning">
                {{ items.length }}
            </div>
        </div>

        <!-- Pending barcodes -->
        <div class="overflow-hidden rounded-lg border border-border-default bg-white">

            <div class="border-b border-border-default px-5 py-4">
                <h2 class="font-semibold text-text-primary">
                    Expected / Not Yet Received
                </h2>
            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-sm">

                    <thead class="bg-surface-sunken text-text-tertiary">
                        <tr>
                            <th class="px-5 py-3 text-left">Barcode</th>
                            <th class="px-5 py-3 text-left">Product</th>
                            <th class="px-5 py-3 text-left">Original / Available</th>
                            <th class="px-5 py-3 text-left">Size</th>
                            <th class="px-5 py-3 text-left">RE-12</th>
                            <th class="px-5 py-3 text-left">Godown</th>
                            <th class="px-5 py-3 text-left">Licence</th>
                            <th class="px-5 py-3 text-left">Status</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border-default">

                        <tr
                            v-for="item in items"
                            :key="item.id"
                            class="hover:bg-surface-sunken"
                        >

                            <td class="px-5 py-4">
                                <span class="font-mono text-xs font-medium">
                                    {{ item.barcode }}
                                </span>
                            </td>

                            <td class="px-5 py-4 font-medium">
                                {{ item.product?.name ?? item.product_name }}
                            </td>

                            <td class="px-5 py-4">
                                <template v-if="!item.product_id">
                                    <span class="text-status-warning">Product mapping required</span>
                                </template>
                                <template v-else-if="item.product?.partial_barcode_sales">
                                    {{ item.original_quantity }} / {{ item.remaining_quantity }} {{ item.base_unit || item.product.base_inventory_unit }}
                                    <span class="block text-xs text-text-tertiary">{{ item.quantity_source === 'pdf' ? 'PDF extracted' : 'Manual confirmation' }}</span>
                                </template>
                                <span v-else>1 package · whole-package stock</span>
                            </td>

                            <td class="px-5 py-4">
                                {{ item.size_code ?? "—" }}
                            </td>

                            <td class="px-5 py-4 font-mono text-xs text-brand">
                                {{ item.shipment?.re12_number ?? "—" }}
                            </td>

                            <td class="px-5 py-4">
                                {{ item.warehouse?.name ?? "—" }}
                            </td>

                            <td class="px-5 py-4 font-mono text-xs">
                                {{ item.shipment?.licence_number ?? "—" }}
                            </td>

                            <td class="px-5 py-4">
                                <span class="rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-800">
                                    Expected
                                </span>
                            </td>

                        </tr>

                        <tr v-if="items.length === 0">
                            <td
                                colspan="8"
                                class="px-5 py-10 text-center"
                            >
                                <div class="text-lg font-semibold text-status-success">
                                    ✓ All PESO barcodes received
                                </div>

                                <div class="mt-1 text-sm text-text-tertiary">
                                    There are currently no expected barcodes waiting for receiving.
                                </div>
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>
        </div>

    </div>
</template>
