<script setup>
import { computed, ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";

const props = defineProps({
    shipment: {
        type: Object,
        required: true,
    },
    products: { type: Array, default: () => [] },
    mappings: { type: Object, default: () => ({}) },
});

const scanCode = ref("");
const scanFeedback = ref(null);
const isSuccess = ref(false);
const barcodeQuantities = ref(Object.fromEntries(
    (props.shipment.items ?? []).map((item) => [
        item.id,
        ['manual', 'pdf'].includes(item.quantity_source) ? Number(item.original_quantity) : '',
    ])
));
const groupMappings = ref({});

const productGroups = computed(() => {
    const groups = new Map();
    for (const item of props.shipment.items ?? []) {
        const key = JSON.stringify([item.product_name ?? '', item.size_code ?? '']);
        if (!groups.has(key)) {
            groups.set(key, {
                key,
                name: item.product_name ?? 'Unknown product',
                sizeCode: item.size_code ?? '',
                items: [],
            });
        }
        groups.get(key).items.push(item);
    }
    return [...groups.values()];
});

for (const group of productGroups.value) {
    const mapped = group.items.find((item) => item.product_id)?.product_id ?? props.mappings[group.key]?.product_id;
    if (mapped) groupMappings.value[group.key] = String(mapped);
}

const productForGroup = (group) =>
    props.products.find((product) => Number(product.id) === Number(groupMappings.value[group.key])) ?? null;
const productsForGroup = (group) => {
    const approved = props.mappings[group.key];
    if (!approved) return props.products;
    if (approved.product_id) return props.products.filter((product) => Number(product.id) === Number(approved.product_id));
    return props.products.filter((product) => product.name === approved.internal_product_name);
};

const mappingIsValid = computed(() => productGroups.value.some((group) => !!productForGroup(group)) && productGroups.value.every((group) => {
    const product = productForGroup(group);
    if (!product) return true;
    if (!product.partial_barcode_sales) return true;

    const minimum = Math.max(1, Number(product.minimum_sale_quantity) || 1);
    const increment = Math.max(1, Number(product.sale_increment) || 1);
    return group.items.every((item) => {
        const quantity = Number(barcodeQuantities.value[item.id]);
        return Number.isInteger(quantity) && quantity >= minimum && (quantity - minimum) % increment === 0;
    });
}));

const receiveAll = () => {
    if (!confirm("Are you sure you want to mark all cartons as received into magazine stock?")) return;
    router.post(route("peso.shipments.receive-all", props.shipment.id));
};

const handleBarcodeScan = async () => {
    const code = scanCode.value.trim().toUpperCase();

    if (!code) return;

    try {
        const response = await fetch("/receiving/scan", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN":
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute("content") || "",
                "Accept": "application/json",
            },
            body: JSON.stringify({
                warehouse_id: props.shipment.warehouse_id,
                barcode: code,
            }),
        });

        const data = await response.json();

        isSuccess.value = response.ok;
        scanFeedback.value =
            data.message ||
            (response.ok
                ? "Barcode received successfully."
                : "Barcode scan failed.");

        if (response.ok) {
            const item = props.shipment.items.find(
                (i) => i.barcode === code
            );

            if (item) {
                item.status = "received";
                item.received_at = new Date().toISOString();
            }

            props.shipment.received_packages =
                data.received_count ??
                props.shipment.received_packages + 1;

            if (
                props.shipment.received_packages >=
                props.shipment.total_packages
            ) {
                props.shipment.status = "received";
            }
        }
    } catch (error) {
        isSuccess.value = false;
        scanFeedback.value = "Network or server error.";
    }

    scanCode.value = "";
};

const productSummary = () => {
    const groups = {};
    for (const item of props.shipment.items ?? []) {
        const key = `${item.product_name}|${item.size_code ?? ""}`;
        if (!groups[key]) {
            groups[key] = {
                product_name: item.product_name,
                size_code: item.size_code,
                expected: 0,
                received: 0,
            };
        }
        groups[key].expected++;
        if (item.status === "received") {
            groups[key].received++;
        }
    }
    return Object.values(groups)
};

const mapProducts = () => {
    if (!mappingIsValid.value) return;

    const itemProductIds = {};
    for (const group of productGroups.value) {
        for (const item of group.items) {
            itemProductIds[item.id] = groupMappings.value[group.key] ? Number(groupMappings.value[group.key]) : null;
        }
    }
    router.post(
        route('peso.shipments.map-product', props.shipment.id),
        {
            item_product_ids: itemProductIds,
            barcode_quantities: barcodeQuantities.value,
        }
    )
}
</script>

<template>
    <Head :title="`RE-12 - ${shipment.re12_number}`" />

    <div class="p-6 max-w-6xl mx-auto space-y-6">
        <!-- Top Bar -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Consignment: {{ shipment.re12_number }}</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Godown: <span class="font-medium text-gray-700">{{ shipment.warehouse?.name ?? "Godown" }}</span> |
                    Licence: <span class="font-mono text-xs">{{ shipment.licence_number }}</span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <Link
                    :href="route('peso.upload')"
                    class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50 text-sm"
                >
                    ← All Consignments
                </Link>
                <button
                    v-if="shipment.status !== 'received'"
                    @click="receiveAll"
                    class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium text-sm transition shadow-sm"
                >
                    ✓ Receive All Into Stock
                </button>
            </div>
        </div>

        <!-- Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-xs uppercase text-gray-400 font-semibold tracking-wider">Total Cartons</div>
                <div class="text-3xl font-bold mt-1 text-gray-800">{{ shipment.total_packages }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-xs uppercase text-gray-400 font-semibold tracking-wider">Confirmed In Godown</div>
                <div class="text-3xl font-bold mt-1 text-green-600">{{ shipment.received_packages }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-xs uppercase text-gray-400 font-semibold tracking-wider">Status</div>
                <div class="mt-2">
                    <span
                        class="px-3 py-1 rounded-full text-xs font-semibold capitalize"
                        :class="shipment.status === 'received' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'"
                    >
                        {{ (shipment.status ?? '').replaceAll('_', ' ') }}
                    </span>
                </div>
            </div>
        </div>
        <!-- Product Mapping -->
        <div class="rounded-lg border border-border-default bg-white p-5">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-text-primary">Product mapping and quantity confirmation</h2>
                <p class="mt-1 text-sm text-text-tertiary">
                    Map each PDF product and size group to an existing product. Barcode text stays unchanged.
                    This parser does not provide a verified physical quantity per barcode, so partial-product quantities require staff confirmation.
                </p>
            </div>

            <div class="space-y-5">
                <section v-for="group in productGroups" :key="group.key" class="rounded-md border border-border-default p-4">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-semibold">{{ group.name }} <span v-if="group.sizeCode" class="text-text-tertiary">· {{ group.sizeCode }}</span></h3>
                        <span class="text-xs text-text-tertiary">{{ group.items.length }} barcode{{ group.items.length === 1 ? '' : 's' }}</span>
                    </div>
                    <p v-if="props.mappings[group.key]?.product_id === null && props.mappings[group.key] && !productsForGroup(group).length" class="mb-2 rounded bg-amber-50 p-2 text-sm text-amber-800">
                        Confirmed internal classification: {{ props.mappings[group.key].internal_product_name }}. That inventory product is not yet in the catalog; this group remains unmapped and cannot be received into stock.
                    </p>
                    <p v-else-if="props.mappings[group.key]?.product_id === null && props.mappings[group.key]" class="mb-2 rounded bg-amber-50 p-2 text-sm text-amber-800">
                        Confirmed internal classification: {{ props.mappings[group.key].internal_product_name }}. Select that exact internal product to activate this mapping.
                    </p>
                    <p v-else-if="!props.mappings[group.key] && !group.items.some((item) => item.product_id)" class="mb-2 text-sm text-status-warning">No exact manufacturer name + code mapping exists. Leave this group unmapped until confirmed.</p>

                    <label class="mb-1 block text-sm font-medium" :for="`product-${group.key}`">Existing inventory product</label>
                    <select
                        :id="`product-${group.key}`"
                        v-model="groupMappings[group.key]"
                        class="w-full rounded-md border border-border-default px-3 py-2"
                        :disabled="!!props.mappings[group.key] && !productsForGroup(group).length"
                    >
                        <option value="">Select a product</option>
                        <option v-for="product in productsForGroup(group)" :key="product.id" :value="String(product.id)">
                            {{ product.name }}{{ product.sku ? ` · ${product.sku}` : '' }}
                        </option>
                    </select>

                    <p v-if="productForGroup(group)" class="mt-2 text-sm text-text-tertiary">
                        Inventory unit: <strong>{{ productForGroup(group).base_inventory_unit }}</strong>
                        <template v-if="productForGroup(group).partial_barcode_sales">
                            · Minimum {{ productForGroup(group).minimum_sale_quantity }}
                            · increment {{ productForGroup(group).sale_increment }}
                        </template>
                        <template v-else> · one barcode is one package stock unit</template>
                    </p>

                    <div v-if="productForGroup(group)?.partial_barcode_sales" class="mt-4 space-y-3">
                        <div v-for="item in group.items" :key="item.id" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-center">
                            <span class="break-all font-mono text-xs">{{ item.barcode }}</span>
                            <label class="text-sm">
                                Original quantity ({{ productForGroup(group).base_inventory_unit }})
                                <input
                                    v-model.number="barcodeQuantities[item.id]"
                                    type="number"
                                    :min="productForGroup(group).minimum_sale_quantity"
                                    :step="productForGroup(group).sale_increment"
                                    class="mt-1 w-full rounded border border-border-default px-2 py-1 text-right"
                                    :aria-label="`Original quantity for ${item.barcode}`"
                                />
                            </label>
                            <span class="text-xs text-status-warning">{{ item.quantity_source === 'pdf' ? 'PDF extracted' : 'Manual confirmation' }}</span>
                        </div>
                    </div>

                    <div v-else-if="productForGroup(group)" class="mt-3 space-y-1 text-xs text-text-tertiary">
                        <p v-for="item in group.items" :key="item.id" class="break-all font-mono">
                            {{ item.barcode }} · quantity: 1 package ({{ item.quantity_source === 'package' ? 'package count' : 'whole-package rule' }})
                        </p>
                    </div>
                </section>
            </div>

            <button
                type="button"
                class="mt-5 rounded-md bg-brand px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!mappingIsValid"
                @click="mapProducts"
            >
                Save product mapping and quantities
            </button>
        </div>

        <!-- Handheld Barcode Scanner Tool -->
        <div class="bg-white rounded-lg shadow p-5 border-l-4 border-blue-600">
            <h2 class="text-sm font-semibold uppercase text-gray-600 tracking-wider mb-2">
                Carton Check-in Scanner
            </h2>
            <div class="flex gap-3">
                <input
                    v-model="scanCode"
                    @keydown.enter.prevent="handleBarcodeScan"
                    type="text"
                    placeholder="Scan 27-character L1 carton barcode..."
                    class="flex-1 font-mono text-sm border-gray-300 rounded-md shadow-sm p-2"
                />
                <button
                    @click="handleBarcodeScan"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm rounded-md"
                >
                    Check In
                </button>
            </div>
            <p
                v-if="scanFeedback"
                class="text-xs font-semibold mt-2"
                :class="isSuccess ? 'text-green-600' : 'text-red-600'"
            >
                {{ scanFeedback }}
            </p>
        </div>

        <!-- Product Summary Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden overflow-x-auto">
            <div class="p-5 border-b font-semibold text-gray-700">Declared Products Summary</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-left px-5 py-3">Product</th>
                        <th class="text-left px-5 py-3">Size</th>
                        <th class="text-right px-5 py-3">Expected</th>
                        <th class="text-right px-5 py-3">Received</th>
                        <th class="text-right px-5 py-3">Pending</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="item in productSummary()" :key="`${item.product_name}-${item.size_code}`">
                        <td class="px-5 py-3 font-medium">{{ item.product_name }}</td>
                        <td class="px-5 py-3">{{ item.size_code || '—' }}</td>
                        <td class="px-5 py-3 text-right font-bold">{{ item.expected }}</td>
                        <td class="px-5 py-3 text-right text-green-700 font-bold">{{ item.received }}</td>
                        <td class="px-5 py-3 text-right text-gray-500">{{ item.expected - item.received }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Barcode List -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="p-5 border-b font-semibold text-gray-700">L1 Carton Matrix</div>
            <div class="max-h-96 overflow-x-auto overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 sticky top-0">
                        <tr>
                            <th class="text-left px-5 py-2">#</th>
                            <th class="text-left px-5 py-2">Barcode</th>
                            <th class="text-left px-5 py-2">Product</th>
                            <th class="text-right px-5 py-2">Original quantity / source</th>
                            <th class="text-right px-5 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-mono text-xs">
                        <tr
                            v-for="(it, idx) in shipment.items"
                            :key="it.id"
                            :class="it.status === 'received' ? 'bg-green-50' : ''"
                        >
                            <td class="px-5 py-2 text-gray-400">{{ idx + 1 }}</td>
                            <td class="px-5 py-2 font-bold">{{ it.barcode }}</td>
                            <td class="px-5 py-2 text-gray-600 font-sans text-sm">{{ it.product_name }}</td>
                            <td class="px-5 py-2 text-right font-sans">
                                <template v-if="it.quantity_source === 'pdf'">
                                    {{ it.original_quantity }} {{ it.base_unit }} · PDF extracted
                                </template>
                                <template v-else-if="it.quantity_source === 'manual'">
                                    {{ it.original_quantity }} {{ it.base_unit }} · manual confirmation
                                </template>
                                <template v-else-if="it.quantity_source === 'package'">
                                    1 package · package count
                                </template>
                                <template v-else-if="it.product_id && it.product?.partial_barcode_sales">
                                    Quantity confirmation required
                                </template>
                                <template v-else-if="it.product_id">
                                    1 package · legacy whole-package mapping
                                </template>
                                <template v-else>Awaiting product mapping</template>
                            </td>
                            <td class="px-5 py-2 text-right">
                                <span
                                    class="px-2 py-0.5 rounded font-sans text-xs capitalize"
                                    :class="it.status === 'received' ? 'bg-green-200 text-green-900' : 'bg-gray-100 text-gray-600'"
                                >
                                    {{ it.status }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
