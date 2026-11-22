<script setup>
import { Head, Link } from "@inertiajs/vue3";

const props = defineProps({
    shipments: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head title="PESO - Uploaded RE-12" />

    <div class="p-6 max-w-7xl mx-auto space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-text-primary">
                    PESO - Uploaded RE-12
                </h1>

                <p class="mt-1 text-sm text-text-tertiary">
                    View uploaded RE-12 consignments and their receiving status.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <Link
                    :href="route('dashboard')"
                    class="rounded-md border border-border-default px-4 py-2 text-sm font-medium text-text-primary hover:bg-surface-sunken"
                >
                    Dashboard
                </Link>

                <Link
                    :href="route('peso.upload')"
                    class="rounded-md bg-brand px-4 py-2 text-sm font-medium text-white"
                >
                    + Upload RE-12
                </Link>
            </div>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

            <div class="rounded-lg border border-border-default bg-white p-5">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-tertiary">
                    Uploaded RE-12
                </div>

                <div class="mt-2 text-3xl font-bold text-text-primary">
                    {{ shipments.length }}
                </div>
            </div>

            <div class="rounded-lg border border-border-default bg-white p-5">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-tertiary">
                    Total Barcodes
                </div>

                <div class="mt-2 text-3xl font-bold text-text-primary">
                    {{
                        shipments.reduce(
                            (total, s) => total + Number(s.total_items_count ?? 0),
                            0
                        )
                    }}
                </div>
            </div>

            <div class="rounded-lg border border-border-default bg-white p-5">
                <div class="text-xs font-semibold uppercase tracking-wider text-text-tertiary">
                    Pending Receiving
                </div>

                <div class="mt-2 text-3xl font-bold text-status-warning">
                    {{
                        shipments.reduce(
                            (total, s) => total + Number(s.pending_items_count ?? 0),
                            0
                        )
                    }}
                </div>
            </div>

        </div>

        <!-- RE-12 table -->
        <div class="overflow-hidden rounded-lg border border-border-default bg-white">

            <div class="border-b border-border-default px-5 py-4">
                <h2 class="font-semibold text-text-primary">
                    Uploaded RE-12 Consignments
                </h2>
            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-sm">

                    <thead class="bg-surface-sunken text-text-tertiary">
                        <tr>
                            <th class="px-5 py-3 text-left">RE-12</th>
                            <th class="px-5 py-3 text-left">Date</th>
                            <th class="px-5 py-3 text-left">Licence</th>
                            <th class="px-5 py-3 text-left">Godown</th>
                            <th class="px-5 py-3 text-right">Total</th>
                            <th class="px-5 py-3 text-right">Received</th>
                            <th class="px-5 py-3 text-right">Pending</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-right">Action</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border-default">

                        <tr
                            v-for="shipment in shipments"
                            :key="shipment.id"
                            class="hover:bg-surface-sunken"
                        >
                            <td class="px-5 py-4 font-mono text-xs font-medium text-brand">
                                {{ shipment.re12_number }}
                            </td>

                            <td class="px-5 py-4">
                                {{ shipment.peso_date ?? "—" }}
                            </td>

                            <td class="px-5 py-4 font-mono text-xs">
                                {{ shipment.licence_number ?? "—" }}
                            </td>

                            <td class="px-5 py-4">
                                {{ shipment.warehouse?.name ?? "—" }}
                            </td>

                            <td class="px-5 py-4 text-right font-semibold">
                                {{ shipment.total_items_count ?? shipment.total_packages }}
                            </td>

                            <td class="px-5 py-4 text-right font-semibold text-status-success">
                                {{ shipment.received_items_count ?? 0 }}
                            </td>

                            <td class="px-5 py-4 text-right font-semibold text-status-warning">
                                {{ shipment.pending_items_count ?? 0 }}
                            </td>

                            <td class="px-5 py-4">

                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        Number(shipment.pending_items_count ?? 0) === 0
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-yellow-100 text-yellow-800'
                                    "
                                >
                                    {{
                                        Number(shipment.pending_items_count ?? 0) === 0
                                            ? "Received"
                                            : "Pending Receiving"
                                    }}
                                </span>

                            </td>

                            <td class="px-5 py-4 text-right">

                                <Link
                                    :href="route('peso.shipments.show', shipment.id)"
                                    class="font-medium text-brand hover:underline"
                                >
                                    View →
                                </Link>

                            </td>
                        </tr>

                        <tr v-if="shipments.length === 0">
                            <td
                                colspan="9"
                                class="px-5 py-10 text-center text-text-tertiary"
                            >
                                No RE-12 consignments uploaded yet.
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>
        </div>

    </div>
</template>
