```vue
<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
    if (!form.re12_pdf) {
        return;
    }

    form.post(route('peso.upload.store'), {
        forceFormData: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Upload PESO RE-12" />

    <div class="p-6 max-w-4xl mx-auto">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-semibold">
                    Upload PESO RE-12
                </h1>

                <p class="text-sm text-gray-500 mt-1">
                    Upload the PESO RE-12 PDF. The system will automatically
                    detect the licence and assign the correct godown.
                </p>
            </div>

            <Link
                :href="route('dashboard')"
                class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50"
            >
                ← Dashboard
            </Link>
        </div>

        <!-- Upload Form -->
        <div class="bg-white rounded-lg shadow p-6">
            <form @submit.prevent="submit">

                <div class="mb-5">
                    <label
                        for="re12_pdf"
                        class="block text-sm font-medium mb-2"
                    >
                        RE-12 PDF
                    </label>

                    <input
                        id="re12_pdf"
                        type="file"
                        accept="application/pdf,.pdf"
                        @change="handleFileChange"
                        class="block w-full border rounded-lg p-2"
                    />

                    <p
                        v-if="selectedFile"
                        class="text-sm text-gray-600 mt-2"
                    >
                        Selected:
                        <strong>{{ selectedFile.name }}</strong>
                    </p>

                    <p
                        v-if="form.errors.re12_pdf"
                        class="text-sm text-red-600 mt-2"
                    >
                        {{ form.errors.re12_pdf }}
                    </p>
                </div>

                <div
                    v-if="form.processing"
                    class="text-sm text-gray-600 mb-4"
                >
                    Processing PESO PDF...
                </div>

                <button
                    type="submit"
                    :disabled="form.processing || !form.re12_pdf"
                    class="px-5 py-2 rounded-lg bg-blue-600 text-white disabled:opacity-50"
                >
                    {{ form.processing ? 'Importing...' : 'Upload & Import' }}
                </button>

            </form>
        </div>

        <!-- Information -->
        <div class="mt-6 bg-gray-50 border rounded-lg p-4">
            <h2 class="font-medium mb-2">
                What happens after upload?
            </h2>

            <ul class="text-sm text-gray-600 space-y-1">
                <li>✓ RE-12 number is extracted</li>
                <li>✓ PESO date is extracted</li>
                <li>✓ Consignee licence is detected</li>
                <li>✓ Licence is mapped to the correct godown</li>
                <li>✓ Product and actual manufacturer barcodes are extracted</li>
                <li>✓ Barcode count is verified against the PDF package count</li>
                <li>✓ Expected stock is created for receiving</li>
            </ul>
        </div>

    </div>
</template>
```