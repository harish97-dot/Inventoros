<script setup>
import { ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";

const props = defineProps({
    shipment: {
        type: Object,
        required: true,
    },
});

const scanCode = ref("");
const scanFeedback = ref(null);
const isSuccess = ref(false);
const pesoProductId = ref("");

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

const mapProduct = (productId) => {
    if (!productId) {
        return
    }

    router.post(
        route('peso.shipments.map-product', props.shipment.id),
        {
            product_id: productId,
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
        <h2 class="text-lg font-semibold text-text-primary">
            Product Mapping
        </h2>

        <p class="mt-1 text-sm text-text-tertiary">
            Map the PESO product in this shipment to an Inventoros product.
            This does not change the manufacturer barcodes.
        </p>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label
                for="peso-product-id"
                class="mb-1 block text-sm font-medium text-text-primary"
            >
                Inventoros Product ID
            </label>

            <input
                id="peso-product-id"
                v-model="pesoProductId"
                type="number"
                min="1"
                class="w-full rounded-md border border-border-default px-3 py-2"
                placeholder="Example: 1"
                @keydown.enter="mapProduct(Number(pesoProductId))"
            />
        </div>

        <button
            type="button"
            class="rounded-md bg-brand px-4 py-2 text-sm font-medium text-white"
            @click="mapProduct(Number(pesoProductId))"
        >
            Map Product
        </button>
    </div>
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
        <div class="bg-white rounded-lg shadow overflow-hidden">
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
                    <tr v-for="item in productSummary()" :key="item.product_name">
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
            <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 sticky top-0">
                        <tr>
                            <th class="text-left px-5 py-2">#</th>
                            <th class="text-left px-5 py-2">Barcode</th>
                            <th class="text-left px-5 py-2">Product</th>
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
