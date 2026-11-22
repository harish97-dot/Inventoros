<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    warehouses: {
        type: Array,
        default: () => [],
    },
});

const warehouseId = ref(
    props.warehouses.length > 0 ? props.warehouses[0].id : ''
);

const barcode = ref('');
const scanning = ref(false);
const message = ref('');
const messageType = ref('');
const receivedCount = ref(null);
const totalPackages = ref(null);
const shipmentStatus = ref(null);
const lastReceivedItem = ref(null);

const recentScans = ref([]);

const selectedWarehouse = computed(() =>
    props.warehouses.find(
        warehouse => Number(warehouse.id) === Number(warehouseId.value)
    )
);

const messageClass = computed(() => {
    if (messageType.value === 'success') {
        return 'border-green-200 bg-green-50 text-green-700';
    }

    if (messageType.value === 'warning') {
        return 'border-yellow-200 bg-yellow-50 text-yellow-700';
    }

    if (messageType.value === 'error') {
        return 'border-red-200 bg-red-50 text-red-700';
    }

    return 'border-blue-200 bg-blue-50 text-blue-700';
});

const clearMessage = () => {
    message.value = '';
    messageType.value = '';
};

const scanBarcode = async () => {
    const value = barcode.value.trim();

    if (!warehouseId.value) {
        message.value = 'Please select a godown.';
        messageType.value = 'error';
        return;
    }

    if (!value) {
        message.value = 'Scan a manufacturer barcode.';
        messageType.value = 'error';
        return;
    }

    scanning.value = true;
    clearMessage();

    try {
        const response = await fetch(route('receiving.scan'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content'),
            },
            body: JSON.stringify({
                warehouse_id: Number(warehouseId.value),
                barcode: value,
            }),
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || 'Barcode could not be processed.'
            );
        }

        message.value = data.message || 'Barcode processed.';
        lastReceivedItem.value = data.scan?.peso_item_id
            ? {
                barcode: data.scan.barcode,
                product_name: data.scan.product_name,
                original_quantity: data.scan.original_quantity,
                remaining_quantity: data.scan.remaining_quantity,
                base_unit: data.scan.base_unit,
            }
            : null;

        if (data.scan?.status === 'VERIFIED') {
            messageType.value = 'success';
        } else if (data.scan?.status === 'DUPLICATE') {
            messageType.value = 'warning';
        } else {
            messageType.value = 'warning';
        }

        receivedCount.value = data.received_count;
        totalPackages.value = data.total_packages;
        shipmentStatus.value = data.shipment_status;

        recentScans.value.unshift({
            barcode: data.scan?.barcode || value,
            status: data.scan?.status || 'UNKNOWN',
            scanned_at: data.scan?.scanned_at || new Date().toISOString(),
            peso_item_id: data.scan?.peso_item_id || null,
            product_name: data.scan?.product_name || null,
            original_quantity: data.scan?.original_quantity ?? null,
            remaining_quantity: data.scan?.remaining_quantity ?? null,
            base_unit: data.scan?.base_unit || null,
        });

        recentScans.value = recentScans.value.slice(0, 20);

        barcode.value = '';
    } catch (error) {
        message.value = error.message || 'Barcode scan failed.';
        messageType.value = 'error';
    } finally {
        scanning.value = false;

        setTimeout(() => {
            document
                .getElementById('barcode-input')
                ?.focus();
        }, 50);
    }
};

const formatTime = (value) => {
    if (!value) {
        return '';
    }

    try {
        return new Date(value).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    } catch {
        return value;
    }
};

const statusLabel = (status) => {
    switch (status) {
        case 'VERIFIED':
            return 'Received';

        case 'DUPLICATE':
            return 'Already Received';

        case 'RECEIVED_PENDING_VERIFICATION':
            return 'Pending Verification';

        default:
            return status;
    }
};

const statusClass = (status) => {
    switch (status) {
        case 'VERIFIED':
            return 'bg-green-100 text-green-700';

        case 'DUPLICATE':
            return 'bg-yellow-100 text-yellow-700';

        default:
            return 'bg-gray-100 text-gray-700';
    }
};
</script>

<template>
    <Head title="Receiving" />

    <div class="min-h-screen bg-surface-sunken">
        <div class="mx-auto max-w-5xl px-4 py-6 md:px-6">
            <!-- Header -->
            <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-text-primary">
                        Receiving
                    </h1>

                    <p class="mt-1 text-sm text-text-secondary">
                        Scan physical manufacturer barcodes when boxes enter the godown.
                    </p>
                </div>

                <a
                    :href="route('dashboard')"
                    class="inline-flex items-center justify-center rounded-md border border-border-subtle bg-surface-canvas px-4 py-2 text-sm font-medium text-text-primary"
                >
                    ← Dashboard
                </a>
            </div>

            <!-- Godown selection -->
            <div class="mb-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                <label class="mb-2 block text-sm font-medium text-text-primary">
                    Godown
                </label>

                <select
                    v-model="warehouseId"
                    class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary md:max-w-md"
                >
                    <option
                        v-for="warehouse in warehouses"
                        :key="warehouse.id"
                        :value="warehouse.id"
                    >
                        {{ warehouse.name }}
                    </option>
                </select>

                <p
                    v-if="selectedWarehouse"
                    class="mt-2 text-xs text-text-tertiary"
                >
                    Receiving into: {{ selectedWarehouse.name }}
                </p>
            </div>

            <!-- Scan box -->
            <div class="rounded-lg border border-border-subtle bg-surface-canvas p-4 md:p-6">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-text-primary">
                        Scan Manufacturer Barcode
                    </h2>

                    <p class="mt-1 text-sm text-text-secondary">
                        Scan the exact barcode printed on the physical box.
                    </p>
                </div>

                <form @submit.prevent="scanBarcode">
                    <div class="flex flex-col gap-3 md:flex-row">
                        <input
                            id="barcode-input"
                            v-model="barcode"
                            type="text"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            placeholder="Scan manufacturer barcode"
                            class="min-h-12 flex-1 rounded-md border border-border-subtle bg-surface-canvas px-4 text-base font-mono text-text-primary outline-none focus:ring-2 focus:ring-primary-500"
                            :disabled="scanning"
                            autofocus
                        />

                        <button
                            type="submit"
                            :disabled="scanning"
                            class="min-h-12 rounded-md bg-primary-600 px-6 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ scanning ? 'Processing...' : 'Receive Barcode' }}
                        </button>
                    </div>
                </form>

                <!-- Result -->
                <div
                    v-if="message"
                    class="mt-4 rounded-md border px-4 py-3 text-sm"
                    :class="messageClass"
                >
                    {{ message }}
                </div>

                <div v-if="lastReceivedItem" class="mt-3 rounded-md border border-border-subtle bg-surface-sunken p-3 text-sm">
                    <p class="break-all font-mono font-medium text-text-primary">{{ lastReceivedItem.barcode }}</p>
                    <p class="mt-1 text-text-secondary">Product: <strong>{{ lastReceivedItem.product_name || 'Not mapped yet' }}</strong></p>
                    <p v-if="lastReceivedItem.original_quantity !== null" class="mt-1 text-text-secondary">
                        Original: <strong>{{ lastReceivedItem.original_quantity }} {{ lastReceivedItem.base_unit }}</strong>
                        <span class="mx-1">·</span>
                        Available: <strong>{{ lastReceivedItem.remaining_quantity }} {{ lastReceivedItem.base_unit }}</strong>
                    </p>
                    <p v-else class="mt-1 text-xs text-text-tertiary">Quantity will be shown after the PESO barcode is mapped to a product.</p>
                </div>

                <p class="mt-3 text-xs text-text-tertiary">
                    A verified barcode is received into stock once. Order loading and approval do not deduct it again.
                </p>
            </div>

            <!-- Shipment progress -->
            <div
                v-if="totalPackages !== null"
                class="mt-4 grid gap-4 md:grid-cols-3"
            >
                <div class="rounded-lg border border-border-subtle bg-surface-canvas p-4">
                    <p class="text-xs text-text-tertiary">
                        Expected
                    </p>

                    <p class="mt-1 text-2xl font-semibold text-text-primary">
                        {{ totalPackages }}
                    </p>

                    <p class="text-xs text-text-secondary">
                        packages
                    </p>
                </div>

                <div class="rounded-lg border border-border-subtle bg-surface-canvas p-4">
                    <p class="text-xs text-text-tertiary">
                        Received
                    </p>

                    <p class="mt-1 text-2xl font-semibold text-text-primary">
                        {{ receivedCount }}
                    </p>

                    <p class="text-xs text-text-secondary">
                        packages
                    </p>
                </div>

                <div class="rounded-lg border border-border-subtle bg-surface-canvas p-4">
                    <p class="text-xs text-text-tertiary">
                        Pending
                    </p>

                    <p class="mt-1 text-2xl font-semibold text-text-primary">
                        {{ Math.max(0, totalPackages - receivedCount) }}
                    </p>

                    <p class="text-xs text-text-secondary">
                        packages
                    </p>
                </div>
            </div>

            <!-- Recent scans -->
            <div class="mt-4 rounded-lg border border-border-subtle bg-surface-canvas">
                <div class="border-b border-border-subtle px-4 py-4">
                    <h2 class="text-base font-semibold text-text-primary">
                        Recent Scans
                    </h2>
                </div>

                <div
                    v-if="recentScans.length === 0"
                    class="px-4 py-10 text-center text-sm text-text-tertiary"
                >
                    No scans in this session yet.
                </div>

                <div v-else class="divide-y divide-border-subtle">
                    <div
                        v-for="scan in recentScans"
                        :key="`${scan.barcode}-${scan.scanned_at}`"
                        class="flex flex-col gap-2 px-4 py-4 md:flex-row md:items-center md:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="break-all font-mono text-sm text-text-primary">
                                {{ scan.barcode }}
                            </p>

                            <p v-if="scan.product_name" class="mt-1 text-xs text-text-secondary">{{ scan.product_name }}</p>
                            <p v-if="scan.original_quantity !== null" class="mt-1 text-xs text-text-tertiary">
                                Original {{ scan.original_quantity }} {{ scan.base_unit }} · Available {{ scan.remaining_quantity }} {{ scan.base_unit }}
                            </p>

                            <p class="mt-1 text-xs text-text-tertiary">
                                {{ formatTime(scan.scanned_at) }}
                            </p>
                        </div>

                        <span
                            class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="statusClass(scan.status)"
                        >
                            {{ statusLabel(scan.status) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
