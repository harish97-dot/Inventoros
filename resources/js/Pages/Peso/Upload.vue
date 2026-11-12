<script setup>
import { ref } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";

const props = defineProps({
    shipments: {
        type: Array,
        default: () => [],
    },
});

const selectedFile = ref(null);

const form = useForm({
    re12_pdf: null,
});

const handleFileChange = (event) => {
    const file = event.target.files[0] ?? null;
    selectedFile.value = file;
    form.re12_pdf = file;
};

const submit = () => {
    if (!form.re12_pdf) return;
    form.post(route("peso.upload.store"), {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Upload PESO RE-12" />

    <div class="p-6 max-w-6xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">PESO Consignments & RE-12 Inbound</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Upload manufacturer RE-12 passes and monitor incoming godown shipments.
                </p>
            </div>
            <Link
                :href="route('dashboard')"
                class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50 text-sm"
            >
                ← Dashboard
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Upload Box -->
            <div class="bg-white rounded-lg shadow p-6 lg:col-span-1">
                <h2 class="text-lg font-medium mb-3">Upload RE-12 PDF</h2>
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase text-gray-500 mb-2">
                            Select Pass Document
                        </label>
                        <input
                            type="file"
                            accept=".pdf,application/pdf"
                            @change="handleFileChange"
                            class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-gray-300 rounded-md p-1"
                        />
                        <p v-if="form.errors.re12_pdf" class="text-xs text-red-600 mt-1">
                            {{ form.errors.re12_pdf }}
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="!form.re12_pdf || form.processing"
                        class="w-full py-2.5 px-4 rounded-md bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm transition disabled:opacity-50"
                    >
                        {{ form.processing ? "Parsing Pass..." : "Ingest RE-12 Consignment" }}
                    </button>
                </form>
            </div>

            <!-- Ingested Consignments List -->
            <div class="bg-white rounded-lg shadow lg:col-span-2 overflow-hidden">
                <div class="p-5 border-b flex justify-between items-center">
                    <h2 class="text-lg font-semibold">Consignments on Record</h2>
                    <span class="text-xs text-gray-400 font-mono">({{ shipments.length }} passes)</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="text-left px-5 py-3">Pass No</th>
                                <th class="text-left px-5 py-3">Godown</th>
                                <th class="text-center px-5 py-3">Cartons (Rec / Exp)</th>
                                <th class="text-left px-5 py-3">Status</th>
                                <th class="text-right px-5 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="s in shipments" :key="s.id" class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-mono font-medium text-xs text-blue-700">
                                    {{ s.re12_number }}
                                </td>
                                <td class="px-5 py-3 text-gray-700">
                                    {{ s.warehouse?.name ?? 'Godown' }}
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="font-bold text-green-700">{{ s.received_items_count }}</span>
                                    <span class="text-gray-400"> / </span>
                                    <span class="font-bold text-gray-800">{{ s.total_packages || s.total_items_count }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="px-2 py-0.5 rounded text-xs capitalize"
                                        :class="s.status === 'received' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'"
                                    >
                                        {{ (s.status ?? '').replaceAll('_', ' ') }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <Link
                                        :href="route('peso.shipments.show', s.id)"
                                        class="text-blue-600 hover:text-blue-800 font-medium text-xs"
                                    >
                                        Manage / Check-in →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="shipments.length === 0">
                                <td colspan="5" class="px-5 py-6 text-center text-gray-400">
                                    No PESO shipments uploaded yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
