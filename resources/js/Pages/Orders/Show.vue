<script setup>
import { useId as useFieldId } from 'vue';
const fieldIdPrefix = useFieldId();
import DialogSurface from '@/Components/DialogSurface.vue';
import ActionError from '@/Components/ActionError.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PluginTabs from '@/Components/PluginTabs.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import SendDocumentModal from '@/Components/SendDocumentModal.vue';
import ShipmentsPanel from '@/Components/Shipping/ShipmentsPanel.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { displayCalendarDate, displayDateTime, todayIsoDate } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { invoiceState } from '@/lib/invoiceState';
import { approvalStatusLabel, approvalStatusVariant, orderSourceLabel, orderStatusLabel, orderStatusVariant } from '@/lib/orderLabels';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Pencil, Download, Eye, Undo2, Trash2, X, AlertTriangle, PackageOpen, Mail, Plus, RotateCcw, Wallet } from '@lucide/vue';

const { t, te } = useI18n();
const statusText = (status) => orderStatusLabel(status, { t, te });
const sourceText = (source) => orderSourceLabel(source, { t, te });
const approvalText = (status) => approvalStatusLabel(status, { t, te });

const { hasPermission, canVisit } = usePermissions();

const props = defineProps({
    order: Object,
    canApprove: Boolean,
    // False when the organization did not require approval for this order.
    approvalRequired: { type: Boolean, default: true },
    awaitingApproval: { type: Boolean, default: false },
    canRecordPayments: Boolean,
    paymentMethods: { type: Array, default: () => [] },
    pluginComponents: Object,
    shipments: { type: Array, default: null },
    shipping: { type: Object, default: null },
});

// Order amounts in the order's currency, grouped (see lib/money).
const money = (value) => formatMoney(value, props.order.currency);

const paymentStatusVariant = (s) =>
    ({ unpaid: 'warning', partial: 'info', paid: 'success', overpaid: 'brand', refunded: 'neutral' }[s] || 'neutral');

// Record payment / refund
const showPaymentModal = ref(false);
const paymentForm = useForm({
    type: 'payment',
    amount: '',
    method: 'card',
    reference: '',
    paid_at: todayIsoDate(),
    notes: '',
    allow_overpayment: false,
});

const openPaymentModal = (type) => {
    paymentForm.reset();
    paymentForm.clearErrors();
    paymentForm.type = type;
    // Default to what is left to settle: the balance for a payment, what was
    // paid for a refund.
    const suggested = type === 'refund' ? props.order.amount_paid : props.order.balance_due;
    paymentForm.amount = parseFloat(suggested) > 0 ? parseFloat(suggested).toFixed(2) : '';
    showPaymentModal.value = true;
};

const closePaymentModal = () => {
    showPaymentModal.value = false;
};

const submitPayment = () => {
    paymentForm.post(route('orders.payments.store', props.order.id), {
        preserveScroll: true,
        onSuccess: () => closePaymentModal(),
    });
};

// Void
const voidingPayment = ref(null);
const voidForm = useForm({ reason: '' });

const openVoidModal = (payment) => {
    voidForm.reset();
    voidForm.clearErrors();
    voidingPayment.value = payment;
};

const submitVoid = () => {
    voidForm.post(route('orders.payments.void', [props.order.id, voidingPayment.value.id]), {
        preserveScroll: true,
        onSuccess: () => { voidingPayment.value = null; },
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';

const showDeleteModal = ref(false);

// Invoice emailing
const showInvoiceModal = ref(false);
const invoice = computed(() => invoiceState(props.order));
const invoiceStatus = computed(() => ({
    queued: { label: t('documentEmail.invoiceQueued'), variant: 'warning' },
    sent: { label: t('documentEmail.invoiceSent'), variant: 'success' },
    issued: { label: t('documentEmail.invoiceIssued'), variant: 'info' },
}[invoice.value.badge] ?? null));
const deleteForm = useForm({});
const deleting = computed(() => deleteForm.processing);
watch(showDeleteModal, () => deleteForm.clearErrors());

// Approval functionality
const showApprovalModal = ref(false);
const approvalAction = ref('approve');
const approvalForm = useForm({ notes: '' });
const processing = computed(() => approvalForm.processing);

const openApprovalModal = (action) => {
    approvalAction.value = action;
    approvalForm.reset();
    approvalForm.clearErrors();
    showApprovalModal.value = true;
};

const submitApproval = () => {
    if (processing.value) return;
    const routeName = approvalAction.value === 'approve' ? 'orders.approve' : 'orders.reject';

    approvalForm.post(route(routeName, props.order.id), {
        preserveScroll: true,
        onSuccess: () => { showApprovalModal.value = false; },
    });
};

const statusVariant = orderStatusVariant;
const loadingBarcode = ref('');
const loadingMessage = ref('');
const loadingError = ref('');
const loadingProcessing = ref(false);
const loadedBarcodes = ref([]);

/**
 * Load manufacturer barcodes that were already assigned to this order.
 *
 * This is required because the barcode assignments are stored in the
 * database and must be restored when the Order Show page is refreshed.
 */
const loadExistingBarcodes = async () => {
    try {
        const response = await fetch(
            `/orders/${encodeURIComponent(props.order.id)}/loading-barcodes`,
            {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            }
        );

        const contentType = response.headers.get('content-type') || '';
        const data = contentType.includes('application/json')
            ? await response.json()
            : null;

        if (!response.ok || !data?.success) {
            throw new Error(
                data?.message || `Could not load assigned barcodes (HTTP ${response.status}).`
            );
        }

        loadedBarcodes.value = (data.barcodes || []).map((item) => ({
            barcode: item.barcode,
            peso_item_id: item.peso_item_id,
            order_item_id: item.order_item_id,
            scanned_at: item.scanned_at
                ? new Date(item.scanned_at).toLocaleTimeString()
                : '',
        }));

        console.log(
            'Existing loading barcodes loaded:',
            loadedBarcodes.value
        );
    } catch (error) {
        console.error(
            'Failed to load existing loading barcodes:',
            error
        );

        loadedBarcodes.value = [];
    }
};

/**
 * Load existing barcode assignments when the Order Show page mounts.
 *
 * Keep this as the ONLY onMounted call for loading barcodes.
 */
onMounted(() => {
    loadExistingBarcodes();
});

/**
 * Order items with the actual number of manufacturer barcodes
 * already assigned to each order item.
 */
const loadingOrderItems = computed(() => {
    const items = props.order.items || [];

    return items.map((item) => {
        const loadedCount = loadedBarcodes.value.filter(
            (barcode) =>
                Number(barcode.order_item_id) === Number(item.id)
        ).length;

        return {
            ...item,
            loaded_count: loadedCount,
        };
    });
});

const scanLoadingBarcode = async () => {
    const barcode = loadingBarcode.value.trim();

    if (!barcode || loadingProcessing.value) {
        return;
    }

    loadingProcessing.value = true;
    loadingMessage.value = '';
    loadingError.value = '';

    try {
        const response = await fetch('/loading/scan', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                warehouse_id: props.order.warehouse_id,
                order_item_id: props.order.items[0]?.id,
                barcode,
            }),
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Barcode could not be assigned.');
        }

        loadedBarcodes.value.unshift({
            barcode: data.barcode,
            peso_item_id: data.peso_item_id,
            order_item_id: data.order_item_id,
            scanned_at: new Date().toLocaleTimeString(),
        });

        loadingMessage.value = `Barcode ${data.barcode} assigned successfully.`;
        loadingBarcode.value = '';
    } catch (error) {
        loadingError.value = error.message || 'Barcode could not be assigned.';
    } finally {
        loadingProcessing.value = false;
    }
};

const deleteOrder = () => {
    if (deleting.value) return;
    deleteForm.delete(route('orders.destroy', props.order.id), {
        preserveScroll: true,
        onSuccess: () => { showDeleteModal.value = false; },
    });
};

const formatDate = (date) => {
    if (!date) return '-';
    return displayDateTime(date);
};

// A payment is recorded on a day (stored as UTC midnight): format it as that
// calendar day, not as an instant, or it shows a day early west of UTC.
const formatPaymentDate = (date) =>
    displayCalendarDate(date);

// order_date names a calendar day; formatting it as an instant showed the
// day before for anyone west of UTC.
const formatOrderDate = (date, long = false) =>
    displayCalendarDate(date);
</script>

<template>
    <Head :title="t('orders.show.orderNumber', { number: order.order_number })" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('orders.index')" class="text-text-tertiary hover:text-text-primary">{{ t('orders.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">#{{ order.order_number }}</span>
            </div>
        </template>

        <PageHeader
            :title="t('orders.show.orderNumber', { number: order.order_number })"
            :description="t('orders.show.createdOn', { date: formatOrderDate(order.order_date) })"
        >
            <template #actions>
                <PluginSlot slot="actions" :components="pluginComponents?.actions" />
                <Badge :variant="statusVariant(order.status)" size="sm" dot>{{ statusText(order.status) }}</Badge>
                <Badge v-if="approvalRequired && order.approval_status" :variant="approvalStatusVariant(order.approval_status)" size="sm" dot>{{ approvalText(order.approval_status) }}</Badge>
                <Badge v-if="order.payment_status" :variant="paymentStatusVariant(order.payment_status)" size="sm" dot>{{ t(`payments.status.${order.payment_status}`) }}</Badge>
                <Button
                    v-if="hasPermission('view_orders')"
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('orders.invoice.download', order.id)"
                >
                    <Download :size="14" />
                    {{ t('orders.show.downloadInvoice') }}
                </Button>
                <Button
                    v-if="hasPermission('view_orders')"
                    variant="secondary"
                    size="sm"
                    as="a"
                    :href="route('orders.invoice.preview', order.id)"
                    target="_blank"
                >
                    <Eye :size="14" />
                    {{ t('orders.show.previewInvoice') }}
                </Button>
                <Button
                    v-if="hasPermission('edit_orders')"
                    variant="secondary"
                    size="sm"
                    @click="showInvoiceModal = true"
                >
                    <Mail :size="14" />
                    {{ t('documentEmail.emailInvoice') }}
                </Button>
                <Button
                    v-if="hasPermission('manage_returns')"
                    variant="secondary"
                    size="sm"
                    as="Link"
                    :href="route('returns.create', { order_id: order.id })"
                >
                    <Undo2 :size="14" />
                    {{ t('orders.show.createReturn') }}
                </Button>
                <Button
                    v-if="hasPermission('edit_orders')"
                    variant="default"
                    size="sm"
                    as="Link"
                    :href="route('orders.edit', order.id)"
                >
                    <Pencil :size="14" />
                    {{ t('orders.show.editOrder') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('orders.index')">
                    <ArrowLeft :size="14" />
                    {{ t('orders.show.backToOrders') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <div v-if="awaitingApproval" class="mt-4 flex items-start gap-2 rounded-lg border border-status-warning/20 bg-status-warning-soft p-3">
            <AlertTriangle :size="16" class="mt-0.5 shrink-0 text-status-warning" />
            <p class="text-sm text-status-warning">{{ t('orders.approval.blocksShipping') }}</p>
        </div>

        <!-- Plugin tabs: none registered renders the core content unchanged -->
        <PluginTabs :components="pluginComponents?.tabs">
            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- Left Column: Order Items & Details -->
                <div class="space-y-4 lg:col-span-2">
                    <!-- Order Items -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderItems') }}</h3></div>
                        <div class="p-5">
                            <div v-if="order.items && order.items.length > 0" class="space-y-3">
                                <div
                                    v-for="(item, index) in order.items"
                                    :key="index"
                                    class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg border border-border-subtle bg-surface-canvas p-4"
                                >
                                    <div class="min-w-0 basis-full xl:flex-1 xl:basis-0">
                                        <p class="font-medium text-text-primary">{{ item.product_name }}</p>
                                        <p v-if="item.variant_title" class="text-xs text-text-secondary">{{ t('orders.create.variant') }}: {{ item.variant_title }}</p>
                                        <p class="text-xs text-text-tertiary">SKU: {{ item.sku }}</p>
                                        <p v-if="parseFloat(item.discount_amount) > 0" class="text-xs text-text-secondary">
                                            {{ t('discounts.discount') }}<template v-if="item.discount_type === 'percent'"> ({{ parseFloat(item.discount_value) }}%)</template>: -{{ money(item.discount_amount) }}
                                        </p>
                                        <Link
                                            v-if="item.product && canVisit('products.show')"
                                            :href="route('products.show', item.product_id)"
                                            class="mt-1 inline-block text-xs text-brand hover:underline"
                                        >
                                            {{ t('orders.show.viewProduct') }}
                                        </Link>
                                    </div>

                                    <div class="ms-auto text-end">
                                        <p class="text-xs text-text-tertiary">{{ t('common.quantity') }}</p>
                                        <p class="font-medium tabular-nums text-text-primary">{{ item.quantity }}</p>
                                    </div>

                                    <div class="text-end">
                                        <p class="text-xs text-text-tertiary">{{ t('orders.show.unitPrice') }}</p>
                                        <p class="font-medium tabular-nums text-text-primary">{{ money(item.unit_price) }}</p>
                                    </div>

                                    <div class="min-w-[100px] text-end">
                                        <p class="text-xs text-text-tertiary">{{ t('common.total') }}</p>
                                        <p class="font-semibold tabular-nums text-text-primary">
                                            {{ money(item.total || item.subtotal || (item.quantity * item.unit_price)) }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                                <PackageOpen :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('orders.show.noItems') }}</p>
                            </div>
                        </div>
                    </Card>
                    <!-- Loading / Manufacturer Barcode Scanning -->
<Card
    v-if="order.items && order.items.length > 0"
    :padded="false"
>
    <div class="px-5 pt-5">
        <h3 class="text-sm font-semibold text-text-primary">
            Loading / Dispatch
        </h3>

        <p class="mt-1 text-xs text-text-tertiary">
            Scan the actual manufacturer barcode when the physical box is loaded.
        </p>
    </div>

    <div class="p-5">
        <div class="rounded-lg border border-border-subtle bg-surface-canvas p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-text-primary">
                        {{ order.items[0].product_name }}
                    </p>

                    <p class="mt-1 text-xs text-text-secondary">
                        Ordered quantity:
                        <span class="font-semibold">{{ order.items[0].quantity }}</span>
                    </p>

                    <p class="mt-1 text-xs text-text-secondary">
                        Loaded:
                        <span class="font-semibold">{{ loadedBarcodes.length }}</span>
                        /
                        <span class="font-semibold">{{ order.items[0].quantity }}</span>
                    </p>
                </div>

                <Badge
                    :variant="loadedBarcodes.length >= Number(order.items[0].quantity) ? 'success' : 'warning'"
                    size="sm"
                    dot
                >
                    {{ loadedBarcodes.length >= Number(order.items[0].quantity) ? 'Complete' : 'Pending' }}
                </Badge>
            </div>

            <div class="mt-4">
                <label
                    for="loading_barcode"
                    class="mb-1 block text-sm font-medium text-text-secondary"
                >
                    Scan manufacturer barcode
                </label>

                <input
                    id="loading_barcode"
                    v-model="loadingBarcode"
                    type="text"
                    autocomplete="off"
                    autofocus
                    placeholder="Scan barcode..."
                    class="h-11 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                    :disabled="loadingProcessing || loadedBarcodes.length >= Number(order.items[0].quantity)"
                    @keyup.enter="scanLoadingBarcode"
                />

                <p class="mt-2 text-xs text-text-tertiary">
                    Use the physical box barcode. Stock is not reduced again during loading.
                </p>
            </div>

            <div
                v-if="loadingMessage"
                class="mt-3 rounded-md border border-status-success/20 bg-status-success-soft px-3 py-2 text-sm text-status-success"
            >
                {{ loadingMessage }}
            </div>

            <div
                v-if="loadingError"
                class="mt-3 rounded-md border border-status-danger/20 bg-status-danger-soft px-3 py-2 text-sm text-status-danger"
            >
                {{ loadingError }}
            </div>
        </div>

        <div v-if="loadedBarcodes.length > 0" class="mt-4">
            <p class="mb-2 text-xs font-medium text-text-secondary">
                Loaded barcodes
            </p>

            <div class="divide-y divide-border-subtle rounded-lg border border-border-subtle">
                <div
                    v-for="item in loadedBarcodes"
                    :key="item.peso_item_id"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="truncate font-mono text-xs text-text-primary">
                            {{ item.barcode }}
                        </p>

                        <p class="mt-1 text-xs text-text-tertiary">
                            Scanned {{ item.scanned_at }}
                        </p>
                    </div>

                    <Badge variant="success" size="sm">
                        Loaded
                    </Badge>
                </div>
            </div>
        </div>
    </div>
</Card>
<!-- Approval Status -->
<Card
    v-if="approvalRequired && order.approval_status"
    :padded="false"
>
    <div class="p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-text-primary">
                    Approval Status
                </h3>

                <p class="mt-1 text-xs text-text-secondary">
                    Order approval is the final dispatch step.
                </p>
            </div>

            <Badge
                :variant="approvalStatusVariant(order.approval_status)"
                size="sm"
                dot
            >
                {{ approvalText(order.approval_status) }}
            </Badge>
        </div>

        <div
            v-if="order.approval_status === 'pending'"
            class="mt-4 rounded-lg border border-border-subtle bg-surface-canvas p-4"
        >
            <p class="text-sm text-text-secondary">
                {{
                    loadedBarcodes.length >= Number(order.items?.[0]?.quantity || 0)
                        ? 'All required manufacturer barcodes have been loaded.'
                        : `Load all required manufacturer barcodes before approving this order.`
                }}
            </p>

            <div class="mt-3 flex items-center justify-between gap-3">
                <div class="text-sm font-medium text-text-primary">
                    Loaded:
                    {{ loadedBarcodes.length }}
                    /
                    {{ order.items?.[0]?.quantity || 0 }}
                </div>

                <Button
                    v-if="canApprove"
                    variant="default"
                    :disabled="
                        processing ||
                        loadedBarcodes.length !== Number(order.items?.[0]?.quantity || 0)
                    "
                    @click="openApprovalModal('approve')"
                >
                    Approve Order
                </Button>
            </div>

            <p
                v-if="loadedBarcodes.length !== Number(order.items?.[0]?.quantity || 0)"
                class="mt-2 text-xs text-status-warning"
            >
                Approval is available only after all required barcodes are loaded.
            </p>
        </div>
    </div>
</Card>
                    <!-- Payments (only present for users with view_payments) -->
                    <Card v-if="order.payments !== undefined" :padded="false">
                        <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-text-primary">{{ t('payments.title') }}</h3>
                                <Badge v-if="order.payment_status" :variant="paymentStatusVariant(order.payment_status)" size="sm" dot>{{ t(`payments.status.${order.payment_status}`) }}</Badge>
                            </div>
                            <div v-if="canRecordPayments" class="flex flex-wrap items-center gap-2">
                                <Button
                                    v-if="parseFloat(order.amount_paid) > 0"
                                    variant="secondary"
                                    size="sm"
                                    @click="openPaymentModal('refund')"
                                >
                                    <RotateCcw :size="14" />
                                    {{ t('payments.recordRefund') }}
                                </Button>
                                <Button
                                    v-if="order.status !== 'cancelled'"
                                    variant="default"
                                    size="sm"
                                    @click="openPaymentModal('payment')"
                                >
                                    <Plus :size="14" />
                                    {{ t('payments.recordPayment') }}
                                </Button>
                            </div>
                        </div>
                        <div class="p-5">
                            <p v-if="order.status === 'cancelled' && canRecordPayments" class="mb-3 text-xs text-text-tertiary">{{ t('payments.cancelledNotice') }}</p>
                            <p v-if="order.payment_status === 'untracked'" class="mb-3 text-xs text-text-tertiary">{{ t('payments.untrackedNotice') }}</p>

                            <ul v-if="order.payments.length > 0" class="divide-y divide-border-subtle rounded-lg border border-border-subtle">
                                <li
                                    v-for="payment in order.payments"
                                    :key="payment.id"
                                    :class="['flex flex-wrap items-center gap-3 px-4 py-3', payment.voided_at ? 'opacity-60' : '']"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span :class="['text-sm font-medium text-text-primary', payment.voided_at ? 'line-through' : '']">
                                                {{ payment.type === 'refund' ? t('payments.typeRefund') : t('payments.typePayment') }}, {{ payment.method_label }}
                                            </span>
                                            <Badge v-if="payment.voided_at" variant="neutral" size="sm">{{ t('payments.voided') }}</Badge>
                                        </div>
                                        <p class="text-xs text-text-tertiary">
                                            {{ formatPaymentDate(payment.paid_at) }}
                                            <template v-if="payment.reference"> · {{ payment.reference }}</template>
                                            <template v-if="payment.recorded_by"> · {{ t('payments.recordedBy', { name: payment.recorded_by.name }) }}</template>
                                        </p>
                                        <p v-if="payment.notes" class="mt-1 text-xs text-text-secondary">{{ payment.notes }}</p>
                                        <p v-if="payment.voided_at && payment.void_reason" class="mt-1 text-xs text-text-tertiary">{{ t('payments.voided') }}: {{ payment.void_reason }}</p>
                                    </div>
                                    <span :class="['text-sm font-semibold tabular-nums', payment.type === 'refund' ? 'text-status-danger' : 'text-status-success', payment.voided_at ? 'line-through' : '']">
                                        {{ payment.type === 'refund' ? '-' : '' }}{{ money(payment.amount) }}
                                    </span>
                                    <button
                                        v-if="canRecordPayments && !payment.voided_at"
                                        type="button"
                                        class="rounded-md px-2 py-1 text-xs text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger ds-focus-ring"
                                        @click="openVoidModal(payment)"
                                    >
                                        {{ t('payments.void') }}
                                    </button>
                                </li>
                            </ul>
                            <div v-else class="flex flex-col items-center gap-2 py-6 text-center">
                                <Wallet :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('payments.noPayments') }}</p>
                            </div>
                        </div>
                    </Card>

                    <!-- Shipments (null without view_shipments) -->
                    <ShipmentsPanel v-if="shipping" :order="order" :shipments="shipments || []" :shipping="shipping" />

                    <!-- Customer Information -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.customerInfo') }}</h3></div>
                        <div class="p-5">
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-xs text-text-tertiary">{{ t('orders.show.customerName') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ order.customer_name }}</dd>
                                </div>

                                <div v-if="order.customer_email">
                                    <dt class="text-xs text-text-tertiary">{{ t('common.email') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">
                                        <a :href="`mailto:${order.customer_email}`" class="text-brand hover:underline">
                                            {{ order.customer_email }}
                                        </a>
                                    </dd>
                                </div>

                                <div v-if="order.customer_address">
                                    <dt class="text-xs text-text-tertiary">{{ t('orders.show.shippingAddress') }}</dt>
                                    <dd class="mt-1 whitespace-pre-line text-sm text-text-primary">{{ order.customer_address }}</dd>
                                </div>
                            </dl>
                        </div>
                    </Card>

                    <!-- Order Timeline -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderTimeline') }}</h3></div>
                        <div class="p-5">
                            <div class="space-y-4">
                                <div class="flex items-start gap-3">
                                    <div class="mt-2 h-2 w-2 shrink-0 rounded-full bg-status-success"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-text-primary">{{ t('orders.show.orderCreated') }}</p>
                                        <p class="text-xs text-text-tertiary">{{ formatOrderDate(order.order_date, true) }}</p>
                                    </div>
                                </div>

                                <div v-if="order.shipped_at" class="flex items-start gap-3">
                                    <div class="mt-2 h-2 w-2 shrink-0 rounded-full bg-brand"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-text-primary">{{ t('orders.show.orderShipped') }}</p>
                                        <p class="text-xs text-text-tertiary">{{ formatDate(order.shipped_at) }}</p>
                                    </div>
                                </div>

                                <div v-if="order.delivered_at" class="flex items-start gap-3">
                                    <div class="mt-2 h-2 w-2 shrink-0 rounded-full bg-status-success"></div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-text-primary">{{ t('orders.show.orderDelivered') }}</p>
                                        <p class="text-xs text-text-tertiary">{{ formatDate(order.delivered_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Notes -->
                    <Card v-if="order.notes" :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.internalNotes') }}</h3></div>
                        <div class="p-5">
                            <p class="whitespace-pre-line text-sm text-text-secondary">{{ order.notes }}</p>
                        </div>
                    </Card>
                </div>

                <!-- Right Column: Summary & Actions -->
                <div class="space-y-4">
                    <!-- Plugin Slot: Sidebar -->
                    <PluginSlot slot="sidebar" :components="pluginComponents?.sidebar" />

                    <!-- Order Summary -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderSummary') }}</h3></div>
                        <div class="p-5">
                            <dl class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-text-secondary">{{ t('common.subtotal') }}</dt>
                                    <dd class="font-medium tabular-nums text-text-primary">{{ money(order.subtotal) }}</dd>
                                </div>

                                <div v-if="parseFloat(order.line_discount_total) > 0" class="flex justify-between text-sm">
                                    <dt class="text-text-secondary">{{ t('discounts.lineDiscounts') }}</dt>
                                    <dd class="font-medium tabular-nums text-text-primary">-{{ money(order.line_discount_total) }}</dd>
                                </div>

                                <div v-if="parseFloat(order.order_discount_amount) > 0" class="flex justify-between text-sm">
                                    <dt class="text-text-secondary">
                                        {{ t('discounts.orderDiscount') }}<template v-if="order.discount_type === 'percent'"> ({{ parseFloat(order.discount_value) }}%)</template>
                                    </dt>
                                    <dd class="font-medium tabular-nums text-text-primary">-{{ money(order.order_discount_amount) }}</dd>
                                </div>

                                <div class="flex justify-between text-sm">
                                    <dt class="text-text-secondary">{{ t('common.tax') }}</dt>
                                    <dd class="font-medium tabular-nums text-text-primary">{{ money(order.tax || 0) }}</dd>
                                </div>

                                <div class="flex justify-between text-sm">
                                    <dt class="text-text-secondary">{{ t('common.shipping') }}</dt>
                                    <dd class="font-medium tabular-nums text-text-primary">{{ money(order.shipping || 0) }}</dd>
                                </div>

                                <div class="border-t border-border-subtle pt-3 flex items-center justify-between">

                                        <dt class="text-sm font-semibold text-text-primary">{{ t('common.total') }}</dt>
                                        <dd class="text-xl font-bold tabular-nums text-brand">{{ money(order.total) }}</dd>

                                </div>

                                <template v-if="order.amount_paid !== undefined && order.payment_status !== 'untracked'">
                                    <div class="flex justify-between text-sm">
                                        <dt class="text-text-secondary">{{ t('payments.amountPaid') }}</dt>
                                        <dd class="font-medium tabular-nums text-text-primary">{{ money(order.amount_paid) }}</dd>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <dt class="font-semibold text-text-primary">{{ t('payments.balanceDue') }}</dt>
                                        <dd class="font-semibold tabular-nums text-text-primary">{{ money(order.balance_due) }}</dd>
                                    </div>
                                </template>
                            </dl>
                        </div>
                    </Card>

                    <!-- Order Details -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.orderDetails') }}</h3></div>
                        <div class="p-5">
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-xs text-text-tertiary">{{ t('orders.show.orderNumber2') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ order.order_number }}</dd>
                                </div>

                                <div v-if="order.channel_order_number">
                                    <dt class="text-xs text-text-tertiary">{{ t('orders.channelOrderNumber') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ order.channel_order_number }}</dd>
                                </div>

                                <div>
                                    <dt class="text-xs text-text-tertiary">{{ t('orders.source') }}</dt>
                                    <dd class="mt-1">
                                        <Badge variant="brand" size="sm">{{ sourceText(order.source) }}</Badge>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs text-text-tertiary">{{ t('common.status') }}</dt>
                                    <dd class="mt-1">
                                        <Badge :variant="statusVariant(order.status)" size="sm" dot>{{ statusText(order.status) }}</Badge>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs text-text-tertiary">{{ t('purchaseOrders.orderDate') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ formatOrderDate(order.order_date) }}</dd>
                                </div>

                                <div v-if="order.currency">
                                    <dt class="text-xs text-text-tertiary">{{ t('common.currency') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ order.currency }}</dd>
                                </div>
                            </dl>
                        </div>
                    </Card>

                    <!-- Invoice -->
                    <Card :padded="false">
                        <div class="flex items-center justify-between px-5 pt-5">
                            <h3 class="text-sm font-semibold text-text-primary">{{ t('documentEmail.invoice') }}</h3>
                            <Badge v-if="invoiceStatus" :variant="invoiceStatus.variant" size="sm" dot>{{ invoiceStatus.label }}</Badge>
                        </div>
                        <div class="p-5">
                            <dl v-if="order.invoice_number" class="space-y-4">
                                <div>
                                    <dt class="text-xs text-text-tertiary">{{ t('documentEmail.invoiceNumber') }}</dt>
                                    <dd class="mt-1 text-sm font-medium text-text-primary">{{ order.invoice_number }}</dd>
                                </div>
                            </dl>
                            <p v-if="invoice.note === 'queued'" class="mt-3 text-xs text-text-secondary">
                                {{ t('documentEmail.queuedOn', { to: order.invoice_sent_to, date: formatDate(order.invoice_queued_at) }) }}
                            </p>
                            <p v-else-if="invoice.note === 'sent'" class="mt-3 text-xs text-text-secondary">
                                {{ t('documentEmail.sentOn', { to: order.invoice_sent_to, date: formatDate(order.invoice_sent_at) }) }}
                            </p>
                            <p v-else-if="invoice.note === 'notEmailed'" class="mt-3 text-xs text-text-tertiary">{{ t('orderInvoice.notEmailed') }}</p>
                            <p v-else class="text-xs text-text-tertiary">{{ t('documentEmail.invoiceNotIssued') }}</p>
                        </div>
                    </Card>

                    <!-- Approval Status -->
                   <Card v-if="order.approval_status" :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.approvalStatus') }}</h3></div>
                        <div class="p-5">
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-xs text-text-tertiary">{{ t('common.status') }}</dt>
                                    <dd class="mt-1">
                                        <Badge :variant="approvalStatusVariant(order.approval_status)" size="sm" dot>{{ approvalText(order.approval_status) }}</Badge>
                                    </dd>
                                </div>

                                <div v-if="order.creator">
                                    <dt class="text-xs text-text-tertiary">{{ t('orders.show.createdBy') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ order.creator.name }}</dd>
                                </div>

                                <div v-if="order.approver">
                                    <dt class="text-xs text-text-tertiary">{{ order.approval_status === 'approved' ? t('orders.show.approved') : t('orders.show.rejected') }} {{ t('orders.show.by') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ order.approver.name }}</dd>
                                </div>

                                <div v-if="order.approved_at">
                                    <dt class="text-xs text-text-tertiary">{{ t('orders.show.decisionDate') }}</dt>
                                    <dd class="mt-1 text-sm text-text-primary">{{ formatDate(order.approved_at) }}</dd>
                                </div>

                                <div v-if="order.approval_notes">
                                    <dt class="text-xs text-text-tertiary">{{ t('common.notes') }}</dt>
                                    <dd class="mt-1 text-sm text-text-secondary">{{ order.approval_notes }}</dd>
                                </div>
                            </dl>

                            <!-- Approval Actions -->
                            <div v-if="canApprove && order.approval_status === 'pending' && order.status !== 'cancelled'" class="mt-4 space-y-2 border-t border-border-subtle pt-4">
                                <Button variant="default" class="w-full" @click="openApprovalModal('approve')">
                                    {{ t('orders.show.approveOrder') }}
                                </Button>
                                <Button variant="danger" class="w-full" @click="openApprovalModal('reject')">
                                    {{ t('orders.show.rejectOrder') }}
                                </Button>
                            </div>
                        </div>
                    </Card>

                    <!-- Actions -->
                    <Card v-if="hasPermission('delete_orders')" :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.dangerZone') }}</h3></div>
                        <div class="p-5">
                            <Button variant="danger" class="w-full" @click="showDeleteModal = true">
                                {{ t('orders.show.deleteOrder') }}
                            </Button>
                            <p class="mt-2 text-xs text-text-tertiary">
                                {{ t('orders.show.deleteWarning') }}
                            </p>
                        </div>
                    </Card>
                </div>
            </div>
        </PluginTabs>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />

        <SendDocumentModal
            :show="showInvoiceModal"
            :title="t('documentEmail.emailInvoiceTitle')"
            :description="t('documentEmail.emailInvoiceDescription', { number: order.order_number })"
            :action="route('orders.invoice.email', order.id)"
            :default-to="order.customer_email || ''"
            :attachment-name="order.invoice_number ? `${order.invoice_number}.pdf` : ''"
            :submit-label="t('documentEmail.emailInvoice')"
            @close="showInvoiceModal = false"
        />

        <!-- Record payment / refund modal -->
        <DialogSurface :show="!!(showPaymentModal)" @close="closePaymentModal">
            <div v-if="showPaymentModal" class="fixed inset-0 z-50 flex items-center justify-center" @click="closePaymentModal">
                <div class="fixed inset-0 bg-black/50"></div>

                <form class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop @submit.prevent="submitPayment">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ paymentForm.type === 'refund' ? t('payments.recordRefund') : t('payments.recordPayment') }}
                        </h3>
                        <button type="button" class="text-text-tertiary transition-colors hover:text-text-primary" @click="closePaymentModal">
                            <X :size="18" />
                        </button>
                    </div>

                    <p v-if="paymentForm.errors.order" class="mb-3 text-sm text-status-danger">{{ paymentForm.errors.order }}</p>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="payment_amount" :class="fieldLabel">{{ t('payments.amount') }}</label>
                                <input id="payment_amount" v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :class="fieldInput" required />
                            </div>
                            <div>
                                <label for="payment_method" :class="fieldLabel">{{ t('payments.method') }}</label>
                                <select id="payment_method" v-model="paymentForm.method" :class="fieldInput" required>
                                    <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option>
                                </select>
                            </div>
                        </div>
                        <p v-if="paymentForm.errors.amount" :class="fieldError">{{ paymentForm.errors.amount }}</p>
                        <p v-if="paymentForm.errors.method" :class="fieldError">{{ paymentForm.errors.method }}</p>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="payment_paid_at" :class="fieldLabel">{{ t('payments.paidAt') }}</label>
                                <input id="payment_paid_at" v-model="paymentForm.paid_at" type="date" :class="fieldInput" />
                            </div>
                            <div>
                                <label for="payment_reference" :class="fieldLabel">{{ t('payments.reference') }}</label>
                                <input id="payment_reference" v-model="paymentForm.reference" type="text" maxlength="255" :placeholder="t('payments.referencePlaceholder')" :class="fieldInput" />
                            </div>
                        </div>
                        <p v-if="paymentForm.errors.paid_at" :class="fieldError">{{ paymentForm.errors.paid_at }}</p>
                        <p v-if="paymentForm.errors.reference" :class="fieldError">{{ paymentForm.errors.reference }}</p>

                        <div>
                            <label for="payment_notes" :class="fieldLabel">{{ t('payments.notes') }}</label>
                            <textarea id="payment_notes" v-model="paymentForm.notes" rows="2" maxlength="2000" class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"></textarea>
                        </div>

                        <label v-if="paymentForm.type === 'payment'" class="flex items-start gap-2 text-sm text-text-secondary">
                            <input v-model="paymentForm.allow_overpayment" type="checkbox" class="mt-0.5 rounded border-border-subtle ds-focus-ring" />
                            <span>{{ t('payments.allowOverpayment') }}</span>
                        </label>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="secondary" :disabled="paymentForm.processing" @click="closePaymentModal">{{ t('common.cancel') }}</Button>
                        <Button type="submit" variant="default" :loading="paymentForm.processing" :disabled="paymentForm.processing">{{ t('payments.save') }}</Button>
                    </div>
                </form>
            </div>
        </DialogSurface>

        <!-- Void payment modal -->
        <DialogSurface :show="!!(voidingPayment)" @close="voidingPayment = null">
            <div v-if="voidingPayment" class="fixed inset-0 z-50 flex items-center justify-center" @click="voidingPayment = null">
                <div class="fixed inset-0 bg-black/50"></div>

                <form class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop @submit.prevent="submitVoid">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">{{ t('payments.voidTitle') }}</h3>
                        <button type="button" class="text-text-tertiary transition-colors hover:text-text-primary" @click="voidingPayment = null">
                            <X :size="18" />
                        </button>
                    </div>
                    <p class="mb-4 text-sm text-text-secondary">
                        {{ t('payments.voidConfirm', {
                            type: voidingPayment.type === 'refund' ? t('payments.typeRefund').toLowerCase() : t('payments.typePayment').toLowerCase(),
                            amount: money(voidingPayment.amount),
                        }) }}
                    </p>
                    <label for="void_reason" :class="fieldLabel">{{ t('payments.voidReason') }}</label>
                    <input id="void_reason" v-model="voidForm.reason" type="text" maxlength="255" :class="fieldInput" />
                    <p v-if="voidForm.errors.payment" :class="fieldError">{{ voidForm.errors.payment }}</p>
                    <p v-if="voidForm.errors.reason" :class="fieldError">{{ voidForm.errors.reason }}</p>

                    <div class="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="secondary" :disabled="voidForm.processing" @click="voidingPayment = null">{{ t('common.cancel') }}</Button>
                        <Button type="submit" variant="danger" :loading="voidForm.processing" :disabled="voidForm.processing">{{ t('payments.void') }}</Button>
                    </div>
                </form>
            </div>
        </DialogSurface>

        <!-- Delete Confirmation Modal -->
        <DialogSurface :show="!!(showDeleteModal)" @close="showDeleteModal = false">
            <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center" @click="showDeleteModal = false">
                <div class="fixed inset-0 bg-black/50"></div>

                <div class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg" @click.stop>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-text-primary">
                            {{ t('orders.show.deleteOrder') }}
                        </h3>
                        <button
                            type="button"
                            :aria-label="t('common.close')"
                            @click="showDeleteModal = false"
                            class="text-text-tertiary transition-colors hover:text-text-primary"
                        >
                            <X :size="18" />
                        </button>
                    </div>

                    <div class="mb-6">
                        <p class="mb-4 text-sm text-text-secondary">
                            {{ t('orders.show.confirmDelete', { number: order.order_number }) }}
                        </p>
                        <div class="rounded-lg border border-status-warning/20 bg-status-warning-soft p-4">
                            <div class="flex items-start gap-3">
                                <AlertTriangle :size="20" class="mt-0.5 shrink-0 text-status-warning" />
                                <div class="text-sm text-status-warning">
                                    <p class="mb-1 font-semibold">{{ t('orders.show.cannotUndo') }}</p>
                                    <p>{{ t('orders.show.stockRestored') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <ActionError :errors="deleteForm.errors" />
                    <div class="flex justify-end gap-3">
                        <Button variant="secondary" @click="showDeleteModal = false" :disabled="deleting">
                            {{ t('common.cancel') }}
                        </Button>
                        <Button variant="danger" :loading="deleting" :disabled="deleting" @click="deleteOrder">
                            <span v-if="deleting">{{ t('common.deleting') }}</span>
                            <span v-else>{{ t('orders.show.deleteOrder') }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </DialogSurface>

<!-- Approval Modal -->
<DialogSurface
    :show="!!showApprovalModal"
    @close="showApprovalModal = false"
>
    <div
        v-if="showApprovalModal"
        class="fixed inset-0 z-50 flex items-center justify-center"
        @click="showApprovalModal = false"
    >
        <div class="fixed inset-0 bg-black/50"></div>

        <div
            class="relative mx-4 w-full max-w-md rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg"
            @click.stop
        >
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-base font-semibold text-text-primary">
                    Approve Order
                </h3>

                <button
                    type="button"
                    aria-label="Close"
                    @click="showApprovalModal = false"
                    class="text-text-tertiary transition-colors hover:text-text-primary"
                >
                    <X :size="18" />
                </button>
            </div>

            <div class="mb-6">
                <p class="mb-4 text-sm text-text-secondary">
                    Approve and dispatch order
                    <strong>{{ order.order_number }}</strong>?
                </p>

                <div
                    class="rounded-lg border border-status-success/20 bg-status-success-soft p-3 text-sm"
                >
                    <p class="font-medium text-status-success">
                        Loaded: {{ loadedBarcodes.length }} /
                        {{ order.items?.[0]?.quantity || 0 }}
                    </p>

                    <p class="mt-1 text-xs text-text-secondary">
                        Stock will not be deducted again during approval.
                    </p>
                </div>

                <div class="mt-4">
                    <label
                        :for="fieldIdPrefix + '-approval-notes'"
                        class="mb-1 block text-sm font-medium text-text-secondary"
                    >
                        Approval notes
                    </label>

                    <textarea
                        :id="fieldIdPrefix + '-approval-notes'"
                        v-model="approvalForm.notes"
                        rows="3"
                        class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                        placeholder="Optional approval notes..."
                    ></textarea>
                </div>
            </div>

            <ActionError :errors="approvalForm.errors" />

            <div class="flex justify-end gap-3">
                <Button
                    variant="secondary"
                    :disabled="processing"
                    @click="showApprovalModal = false"
                >
                    Cancel
                </Button>

                <Button
                    variant="default"
                    :loading="processing"
                    :disabled="
                        processing ||
                        loadedBarcodes.length !== Number(order.items?.[0]?.quantity || 0)
                    "
                    @click="submitApproval"
                >
                    <span v-if="processing">Approving...</span>
                    <span v-else>Approve & Dispatch</span>
                </Button>
            </div>
        </div>
    </div>
</DialogSurface>        
    </AppLayout>
</template>
