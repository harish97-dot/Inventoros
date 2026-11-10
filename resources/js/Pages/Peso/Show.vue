<script setup>
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    shipment: {
        type: Object,
        required: true,
    },
});

const statusLabel = (status) => {
    return String(status ?? '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
};

const productSummary = () => {
    const groups = {};

    for (const item of props.shipment.items ?? []) {
        const key = `${item.product_name}|${item.size_code ?? ''}`;

        if (!groups[key]) {
            groups[key] = {
                product_name: item.product_name,
                size_code: item.size_code,
                expected: 0,
                received: 0,
            };
        }

        groups[key].expected++;

        if (item.status === 'received') {
            groups[key].received++;
        }
    }

    return Object.values(groups);
};
</script>

<template>
    <Head title="PESO RE-12" />

    <div class="p-6 max-w-6xl mx-auto">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">

            <div>
                <h1 class="text-2xl font-semibold">
                    PESO RE-12
                </h1>

                <p class="text-sm text-gray-500 mt-1">
                    Shipment manifest and barcode receiving status
                </p>
            </div>

            <div class="flex items-center gap-3">

                <Link
                    :href="route('dashboard')"
                    class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50"
                >
                    ← Dashboard
                </Link>

                <Link
                    :href="route('peso.upload')"
                    class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50"
                >
                    Upload Another RE-12
                </Link>

            </div>

        </div>

        <!-- Shipment Information -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">

            <h2 class="text-lg font-semibold mb-4">
                Shipment Information
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">

                <div>
                    <div class="text-xs text-gray-500">
                        RE-12 Number
                    </div>

                    <div class="font-medium mt-1">
                        {{ shipment.re12_number }}
                    </div>
                </div>

                <div>
                    <div class="text-xs text-gray-500">
                        PESO Date
                    </div>

                    <div class="font-medium mt-1">
                        {{ shipment.peso_date }}
                    </div>
                </div>

                <div>
                    <div class="text-xs text-gray-500">
                        Licence
                    </div>

                    <div class="font-medium mt-1">
                        {{ shipment.licence_number }}
                    </div>
                </div>

                <div>
                    <div class="text-xs text-gray-500">
                        Godown
                    </div>

                    <div class="font-medium mt-1">
                        {{ shipment.warehouse?.name ?? 'Not mapped' }}
                    </div>
                </div>

            </div>

        </div>

        <!-- Status -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

            <div class="bg-white rounded-lg shadow p-5">

                <div class="text-sm text-gray-500">
                    Total Packages
                </div>

                <div class="text-3xl font-semibold mt-1">
                    {{ shipment.total_packages }}
                </div>

            </div>

            <div class="bg-white rounded-lg shadow p-5">

                <div class="text-sm text-gray-500">
                    Received
                </div>

                <div class="text-3xl font-semibold mt-1">
                    {{ shipment.received_packages }}
                </div>

            </div>

            <div class="bg-white rounded-lg shadow p-5">

                <div class="text-sm text-gray-500">
                    Status
                </div>

                <div class="mt-2">

                    <span
                        class="inline-flex px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-800"
                    >
                        {{ statusLabel(shipment.status) }}
                    </span>

                </div>

            </div>

        </div>

        <!-- Product Summary -->
        <div class="bg-white rounded-lg shadow mb-6">

            <div class="p-5 border-b">
                <h2 class="text-lg font-semibold">
                    Products
                </h2>
            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-sm">

                    <thead class="bg-gray-50">

                        <tr>

                            <th class="text-left px-5 py-3">
                                Product
                            </th>

                            <th class="text-left px-5 py-3">
                                Size Code
                            </th>

                            <th class="text-right px-5 py-3">
                                Expected
                            </th>

                            <th class="text-right px-5 py-3">
                                Received
                            </th>

                            <th class="text-right px-5 py-3">
                                Pending
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <tr
                            v-for="item in productSummary()"
                            :key="`${item.product_name}-${item.size_code}`"
                            class="border-t"
                        >

                            <td class="px-5 py-3 font-medium">
                                {{ item.product_name }}
                            </td>

                            <td class="px-5 py-3">
                                {{ item.size_code || '-' }}
                            </td>

                            <td class="px-5 py-3 text-right">
                                {{ item.expected }}
                            </td>

                            <td class="px-5 py-3 text-right">
                                {{ item.received }}
                            </td>

                            <td class="px-5 py-3 text-right">
                                {{ item.expected - item.received }}
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- Barcode Summary -->
        <div class="bg-white rounded-lg shadow">

            <div class="p-5 border-b">

                <h2 class="text-lg font-semibold">
                    Expected Barcodes
                </h2>

                <p class="text-sm text-gray-500 mt-1">
                    These are the actual manufacturer barcodes extracted
                    from the uploaded PESO document.
                </p>

            </div>

            <div class="overflow-x-auto max-h-96">

                <table class="w-full text-sm">

                    <thead class="bg-gray-50 sticky top-0">

                        <tr>

                            <th class="text-left px-5 py-3">
                                #
                            </th>

                            <th class="text-left px-5 py-3">
                                Product
                            </th>

                            <th class="text-left px-5 py-3">
                                Size
                            </th>

                            <th class="text-left px-5 py-3">
                                Barcode
                            </th>

                            <th class="text-left px-5 py-3">
                                Status
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <tr
                            v-for="(item, index) in shipment.items"
                            :key="item.id"
                            class="border-t"
                        >

                            <td class="px-5 py-3">
                                {{ index + 1 }}
                            </td>

                            <td class="px-5 py-3">
                                {{ item.product_name }}
                            </td>

                            <td class="px-5 py-3">
                                {{ item.size_code || '-' }}
                            </td>

                            <td class="px-5 py-3 font-mono text-xs">
                                {{ item.barcode }}
                            </td>

                            <td class="px-5 py-3">

                                <span
                                    v-if="item.status === 'received'"
                                    class="px-2 py-1 rounded bg-green-100 text-green-800"
                                >
                                    Received
                                </span>

                                <span
                                    v-else
                                    class="px-2 py-1 rounded bg-yellow-100 text-yellow-800"
                                >
                                    Expected
                                </span>

                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

    </div>
</template>