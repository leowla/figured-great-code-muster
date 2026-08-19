<script setup>
import axios from 'axios';
import Chart from 'chart.js/auto';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { shortDate } from '../format';

const classes = ref([]);
const records = ref([]);
const loading = ref(true);
const saving = ref(false);

// --- State ---
const activeRightTab = ref(null); // For right column source tabs
const activeLeftFilter = ref('all'); // For left column stock filtering

const movementForm = ref({
    stock_class_id: null,
    type: 'sale',
    quantity: null,
    note: '',
});

// 'table' shows the line-by-line tally, 'chart' shows movements vs. recorded
// closing as a bar per stock class.
const viewMode = ref('table');
const chartCanvases = {}; // stock class id -> <canvas> element
const chartInstances = {}; // stock class id -> Chart instance

// --- AI suggestion state ---
const suggestions = ref([]);
const skipped = ref([]);
const unresolved = ref([]);
const suggesting = ref(false);
const suggestError = ref('');
const savingSuggestionKey = ref(null);
const acceptingAll = ref(false);
const autoApply = ref(false);
const autoAppliedCount = ref(null);
const acceptedRecordIds = ref(new Set()); // paper trail record ids covered by an accepted suggestion

onMounted(load);
onBeforeUnmount(() => Object.values(chartInstances).forEach((chart) => chart.destroy()));

async function load() {
    const { data } = await axios.get('/api/stock');
    classes.value = data.classes;
    records.value = data.records;
    if (!movementForm.value.stock_class_id) {
        movementForm.value.stock_class_id = data.classes[0]?.id ?? null;
    }
    loading.value = false;
}

// Tally per class
function tally(stockClass) {
    const sum = (type) =>
        stockClass.movements.filter((m) => m.type === type).reduce((total, m) => total + m.quantity, 0);
    const calculated = stockClass.opening_count + sum('birth') + sum('purchase') - sum('death') - sum('sale');
    return {
        births: sum('birth'),
        purchases: sum('purchase'),
        deaths: sum('death'),
        sales: sum('sale'),
        calculated,
        difference: calculated - stockClass.closing_count,
    };
}

// Filtered classes for left column
const filteredClasses = computed(() => {
    if (activeLeftFilter.value === 'all') return classes.value;
    return classes.value.filter((c) => c.id === parseInt(activeLeftFilter.value));
});

const canSave = computed(
    () => movementForm.value.stock_class_id && movementForm.value.quantity > 0 && movementForm.value.type,
);

function setChartCanvas(stockClass, el) {
    if (el) {
        chartCanvases[stockClass.id] = el;
    }
}

// Redraw every visible stock class's chart: top bar is the calculated closing
// from keyed movements, bottom bar is the farmer's recorded closing.
function renderCharts() {
    for (const stockClass of filteredClasses.value) {
        const canvas = chartCanvases[stockClass.id];
        if (!canvas) continue;

        chartInstances[stockClass.id]?.destroy();
        chartInstances[stockClass.id] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: ['Keyed movements', 'Recorded closing'],
                datasets: [
                    {
                        data: [tally(stockClass).calculated, stockClass.closing_count],
                        backgroundColor: ['#296fdc', '#607d8b'],
                        borderRadius: 4,
                        barPercentage: 0.6,
                    },
                ],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } },
            },
        });
    }
}

watch(
    [filteredClasses, viewMode],
    async () => {
        if (viewMode.value !== 'chart') return;
        await nextTick();
        renderCharts();
    },
    { deep: true },
);

async function addMovement() {
    saving.value = true;
    try {
        const { data } = await axios.post('/api/stock-movements', movementForm.value);
        const targetClass = classes.value.find((c) => c.id === data.stock_class_id);
        if (targetClass) {
            targetClass.movements.push(data);
        }
        movementForm.value.quantity = null;
        movementForm.value.note = '';
    } finally {
        saving.value = false;
    }
}

async function removeMovement(stockClass, movement) {
    await axios.delete(`/api/stock-movements/${movement.id}`);
    stockClass.movements = stockClass.movements.filter((m) => m.id !== movement.id);
}

// --- AI: suggest movements from the paper trail ---
async function suggestMovements() {
    suggesting.value = true;
    suggestError.value = '';
    autoAppliedCount.value = null;
    try {
        const { data } = await axios.post('/api/stock/suggest-movements');
        suggestions.value = data.suggestions.map((s, i) => ({ ...s, key: i }));
        skipped.value = data.skipped;
        unresolved.value = data.unresolved;

        if (autoApply.value && suggestions.value.length) {
            const count = suggestions.value.length;
            await acceptAllSuggestions();
            autoAppliedCount.value = count;
        }
    } catch (e) {
        suggestError.value = e.response?.data?.error ?? e.message;
    } finally {
        suggesting.value = false;
    }
}

async function acceptSuggestion(suggestion) {
    savingSuggestionKey.value = suggestion.key;
    const { data } = await axios.post('/api/stock-movements', {
        stock_class_id: suggestion.stock_class_id,
        type: suggestion.type,
        quantity: suggestion.quantity,
        note: suggestion.note,
    });
    classes.value.find((c) => c.id === data.stock_class_id).movements.push(data);
    suggestions.value = suggestions.value.filter((s) => s.key !== suggestion.key);
    suggestion.record_ids.forEach((id) => acceptedRecordIds.value.add(id));
    savingSuggestionKey.value = null;
}

function dismissSuggestion(suggestion) {
    suggestions.value = suggestions.value.filter((s) => s.key !== suggestion.key);
}

async function acceptAllSuggestions() {
    acceptingAll.value = true;
    for (const suggestion of [...suggestions.value]) {
        await acceptSuggestion(suggestion);
    }
    acceptingAll.value = false;
}

// --- Group Records for Right Tabs ---
const groupedRecords = computed(() => {
    const groups = {};

    records.value.forEach((record) => {
        let key = record.source;
        if (key.includes('Sale docket')) key = 'Sale docket';
        else if (key.includes('Purchase docket')) key = 'Purchase docket';
        else if (key === 'Diary') key = 'Diary';
        else if (key === 'Text message') key = 'Text message';
        else if (key === 'Email') key = 'Email';

        if (!groups[key]) groups[key] = [];
        groups[key].push(record);
    });

    Object.keys(groups).forEach((key) => {
        groups[key].sort((a, b) => new Date(b.recorded_on) - new Date(a.recorded_on));
    });

    return groups;
});

watch(
    groupedRecords,
    (newGroups) => {
        const keys = Object.keys(newGroups).filter((k) => newGroups[k].length > 0);
        if (keys.length > 0 && !activeRightTab.value) {
            activeRightTab.value = keys[0];
        }
    },
    { immediate: true },
);

const visibleRecords = computed(() => {
    if (!activeRightTab.value) return [];
    return groupedRecords.value[activeRightTab.value] || [];
});

const sourceBadgeClass = {
    Diary: 'bg-fg-warning-15 text-fg-warning-text',
    'Sale docket': 'bg-fg-light-blue-15 text-fg-light-blue',
    'Text message': 'bg-fg-brown-15 text-fg-brown',
    'Purchase docket': 'bg-fg-green-15 text-fg-green-dark',
    Email: 'bg-fg-purple-15 text-fg-purple-dark',
};
</script>

<template>
    <div>
        <div class="mb-4">
            <h2 class="text-lg font-semibold">Stock reconciliation — Kahikatea Downs</h2>
            <p class="text-sm text-fg-mid-grey">
                Key stock movements in from the raw records (right) until each tally matches the farmer's recorded
                closing count. Stock year 1 Jul 2025 – 30 Jun 2026.
            </p>
        </div>

        <p v-if="loading" class="text-fg-light-grey">Loading…</p>

        <div v-else>
            <!-- AI: suggest movements from the paper trail -->
            <div class="mb-4 rounded border border-fg-muted-grey bg-white p-4">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold">AI: suggest movements from the paper trail</h3>
                        <p class="text-xs text-fg-mid-grey">
                            Reads every diary entry, docket and text message and proposes the movements still
                            missing — flagging duplicates and corrections rather than double-counting them.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <button
                            class="rounded px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                            :class="
                                autoApply
                                    ? 'bg-fg-danger hover:bg-fg-danger-dark'
                                    : 'bg-fg-main-blue hover:bg-fg-main-blue-hover'
                            "
                            :disabled="suggesting"
                            @click="suggestMovements"
                        >
                            {{ suggesting ? 'Reading records…' : autoApply ? 'Suggest & apply movements' : 'Suggest movements' }}
                        </button>
                        <label class="flex items-center gap-1.5 text-xs text-fg-mid-grey">
                            <input v-model="autoApply" type="checkbox" class="rounded border-fg-muted-grey" />
                            Apply automatically, skip review
                        </label>
                    </div>
                </div>

                <p v-if="suggestError" class="rounded bg-fg-danger-9 p-3 text-sm text-fg-danger-dark">{{ suggestError }}</p>

                <p v-if="autoAppliedCount" class="rounded bg-fg-positive-15 p-3 text-sm text-fg-positive-dark">
                    Applied {{ autoAppliedCount }} movement(s) automatically — no review step.
                </p>

                <div v-if="suggestions.length" class="space-y-2">
                    <div class="flex justify-end">
                        <button
                            class="text-xs font-medium text-fg-main-blue hover:underline disabled:opacity-50"
                            :disabled="acceptingAll"
                            @click="acceptAllSuggestions"
                        >
                            Accept all {{ suggestions.length }}
                        </button>
                    </div>
                    <div
                        v-for="suggestion in suggestions"
                        :key="suggestion.key"
                        class="flex items-start justify-between gap-3 rounded bg-fg-super-pale-grey p-2 text-sm"
                    >
                        <div>
                            <span class="font-medium">{{ suggestion.stock_class }}</span> —
                            <span class="capitalize">{{ suggestion.type }}</span> × {{ suggestion.quantity.toLocaleString() }}
                            <span v-if="suggestion.note" class="text-fg-light-grey"> — {{ suggestion.note }}</span>
                            <p v-if="suggestion.reasoning" class="mt-0.5 text-xs text-fg-light-grey">{{ suggestion.reasoning }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button
                                class="text-xs font-medium text-fg-main-blue hover:underline disabled:opacity-50"
                                :disabled="savingSuggestionKey === suggestion.key || acceptingAll"
                                @click="acceptSuggestion(suggestion)"
                            >
                                Accept
                            </button>
                            <button
                                class="text-xs font-medium text-fg-light-grey hover:underline disabled:opacity-50"
                                :disabled="savingSuggestionKey === suggestion.key || acceptingAll"
                                @click="dismissSuggestion(suggestion)"
                            >
                                Dismiss
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="unresolved.length" class="mt-2 space-y-1 rounded bg-fg-warning-15 p-2 text-xs text-fg-warning-text">
                    <p v-for="(u, i) in unresolved" :key="i">
                        <span class="font-medium">{{ u.stock_class }}:</span> {{ u.reason }}
                    </p>
                </div>

                <details v-if="skipped.length" class="mt-2">
                    <summary class="cursor-pointer text-xs text-fg-light-grey">
                        {{ skipped.length }} record(s) the AI skipped (duplicates, corrections, or no movement)
                    </summary>
                    <ul class="mt-1 space-y-1 text-xs text-fg-light-grey">
                        <li v-for="(s, i) in skipped" :key="i">{{ s.reason }}</li>
                    </ul>
                </details>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
                <!-- LEFT COLUMN: Key Form + Filter + Tallies -->
                <div class="lg:col-span-6 space-y-4">
                    <!-- New movement form -->
                    <div class="key-movement-form-container rounded border border-fg-muted-grey bg-white p-4">
                        <h3 class="mb-2 text-sm font-semibold">Key in a movement</h3>
                        <div class="flex flex-wrap items-end gap-2">
                            <div>
                                <label class="block text-xs font-medium text-fg-mid-grey">Stock class</label>
                                <select
                                    v-model="movementForm.stock_class_id"
                                    class="rounded border border-fg-muted-grey px-2 py-1 text-sm"
                                >
                                    <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-fg-mid-grey">Type</label>
                                <select
                                    v-model="movementForm.type"
                                    class="rounded border border-fg-muted-grey px-2 py-1 text-sm"
                                >
                                    <option value="birth">Birth</option>
                                    <option value="purchase">Purchase</option>
                                    <option value="death">Death</option>
                                    <option value="sale">Sale</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-fg-mid-grey">Quantity</label>
                                <input
                                    v-model.number="movementForm.quantity"
                                    type="number"
                                    min="1"
                                    class="w-24 rounded border border-fg-muted-grey px-2 py-1 text-right text-sm"
                                />
                            </div>
                            <div class="grow">
                                <label class="block text-xs font-medium text-fg-mid-grey">Note (source record)</label>
                                <input
                                    v-model="movementForm.note"
                                    placeholder="e.g. docket S-40102"
                                    class="w-full rounded border border-fg-muted-grey px-2 py-1 text-sm"
                                />
                            </div>
                            <button
                                class="rounded bg-fg-main-blue px-4 py-1.5 text-sm font-medium text-white hover:bg-fg-main-blue-hover disabled:opacity-50"
                                :disabled="!canSave || saving"
                                @click="addMovement"
                            >
                                Add
                            </button>
                        </div>
                    </div>

                    <!-- Left Side Filter Bar (Above Tallies) -->
                    <div class="rounded-lg border border-fg-muted-grey bg-white p-1 flex items-center gap-1 overflow-x-auto">
                        <button
                            @click="activeLeftFilter = 'all'"
                            class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors whitespace-nowrap"
                            :class="activeLeftFilter === 'all' ? 'bg-fg-main-blue text-white shadow-sm' : 'text-fg-mid-grey hover:bg-fg-super-pale-grey'"
                        >
                            All Classes
                        </button>
                        <div class="w-px h-4 bg-fg-muted-grey mx-1"></div>
                        <button
                            v-for="c in classes"
                            :key="c.id"
                            @click="activeLeftFilter = c.id"
                            class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors whitespace-nowrap"
                            :class="activeLeftFilter === c.id ? 'bg-fg-main-blue text-white shadow-sm' : 'text-fg-mid-grey hover:bg-fg-super-pale-grey'"
                        >
                            {{ c.name }}
                        </button>
                    </div>

                    <!-- Table / chart toggle -->
                    <div class="flex items-center gap-1 text-sm">
                        <button
                            class="rounded px-3 py-1 font-medium"
                            :class="viewMode === 'table' ? 'bg-fg-main-blue text-white' : 'text-fg-mid-grey hover:bg-fg-pale-grey'"
                            @click="viewMode = 'table'"
                        >
                            Table
                        </button>
                        <button
                            class="rounded px-3 py-1 font-medium"
                            :class="viewMode === 'chart' ? 'bg-fg-main-blue text-white' : 'text-fg-mid-grey hover:bg-fg-pale-grey'"
                            @click="viewMode = 'chart'"
                        >
                            Chart
                        </button>
                    </div>

                    <!-- Tally table per stock class (Filtered) -->
                    <div
                        v-for="stockClass in filteredClasses"
                        :key="stockClass.id"
                        class="rounded border border-fg-muted-grey bg-white p-4"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="font-semibold">{{ stockClass.name }}</h3>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="
                                    tally(stockClass).difference === 0
                                        ? 'bg-fg-positive-15 text-fg-positive-dark'
                                        : 'bg-fg-danger-15 text-fg-danger-dark'
                                "
                            >
                                {{
                                    tally(stockClass).difference === 0
                                        ? 'reconciled'
                                        : `out by ${tally(stockClass).difference > 0 ? '+' : ''}${tally(stockClass).difference}`
                                }}
                            </span>
                        </div>

                        <div v-if="viewMode === 'chart'" class="h-24">
                            <canvas :ref="(el) => setChartCanvas(stockClass, el)"></canvas>
                        </div>

                        <table v-else class="w-full text-sm">
                            <tbody>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">Opening (1 Jul 2025)</td>
                                    <td class="py-1 text-right font-mono">{{ stockClass.opening_count.toLocaleString() }}</td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">+ Births</td>
                                    <td class="py-1 text-right font-mono">{{ tally(stockClass).births.toLocaleString() }}</td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">+ Purchases</td>
                                    <td class="py-1 text-right font-mono">{{ tally(stockClass).purchases.toLocaleString() }}</td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">− Deaths</td>
                                    <td class="py-1 text-right font-mono">{{ tally(stockClass).deaths.toLocaleString() }}</td>
                                </tr>
                                <tr class="border-t border-fg-pale-grey">
                                    <td class="py-1 text-fg-mid-grey">− Sales</td>
                                    <td class="py-1 text-right font-mono">{{ tally(stockClass).sales.toLocaleString() }}</td>
                                </tr>
                                <tr class="border-t border-fg-muted-grey font-medium">
                                    <td class="py-1">= Calculated closing</td>
                                    <td class="py-1 text-right font-mono">{{ tally(stockClass).calculated.toLocaleString() }}</td>
                                </tr>
                                <tr>
                                    <td class="py-1 text-fg-mid-grey">Recorded closing (tally book)</td>
                                    <td class="py-1 text-right font-mono">{{ stockClass.closing_count.toLocaleString() }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <details v-if="stockClass.movements.length" class="mt-2">
                            <summary class="cursor-pointer text-xs text-fg-light-grey">
                                {{ stockClass.movements.length }} movement(s) entered
                            </summary>
                            <ul class="mt-1 space-y-1">
                                <li
                                    v-for="movement in stockClass.movements"
                                    :key="movement.id"
                                    class="flex items-center justify-between rounded bg-fg-super-pale-grey px-2 py-1 text-xs"
                                >
                                    <span>
                                        <span class="font-medium capitalize">{{ movement.type }}</span>
                                        × {{ movement.quantity.toLocaleString() }}
                                        <span v-if="movement.note" class="text-fg-light-grey">— {{ movement.note }}</span>
                                    </span>
                                    <button
                                        class="ml-2 text-fg-light-grey hover:text-fg-danger"
                                        title="Delete movement"
                                        @click="removeMovement(stockClass, movement)"
                                    >
                                        ✕
                                    </button>
                                </li>
                            </ul>
                        </details>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Tabs -->
                <div class="lg:col-span-6 flex flex-col h-full">
                    <h3 class="mb-2 text-sm font-semibold">The paper trail</h3>

                    <!-- Tabs Navigation Bar -->
                    <div class="inline-flex h-9 items-center justify-center rounded-lg bg-fg-muted-grey/30 p-1 text-fg-mid-grey mb-4 w-full shrink-0">
                        <button
                            v-for="(recs, type) in groupedRecords"
                            :key="type"
                            v-show="recs.length > 0"
                            @click="activeRightTab = type"
                            class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50"
                            :class="activeRightTab === type ? 'bg-white text-fg-dark-grey shadow-sm' : 'hover:text-fg-dark-grey'"
                        >
                            {{ type }}
                            <span class="ml-1.5 rounded-full bg-fg-muted-grey px-1.5 py-0.5 text-[10px] font-bold text-fg-light-grey">
                                {{ recs.length }}
                            </span>
                        </button>
                    </div>

                    <!-- Tab Content Area -->
                    <div class="rounded-lg border border-fg-muted-grey bg-white flex-grow overflow-hidden flex flex-col">
                        <div class="overflow-y-auto flex-grow custom-scrollbar">
                            <div v-if="visibleRecords.length === 0" class="p-8 text-center text-fg-light-grey">
                                No records found for this category.
                            </div>

                            <ul v-else class="divide-y divide-fg-pale-grey">
                                <li
                                    v-for="record in visibleRecords"
                                    :key="record.id"
                                    class="px-4 py-3 text-sm transition-colors"
                                    :class="acceptedRecordIds.has(record.id) ? 'bg-fg-positive-15' : 'hover:bg-fg-super-pale-grey'"
                                >
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="flex items-center gap-1.5">
                                            <span
                                                v-if="acceptedRecordIds.has(record.id)"
                                                class="text-fg-positive-dark"
                                                title="Movement accepted"
                                            >
                                                ✓
                                            </span>
                                            <span class="text-xs font-mono text-fg-light-grey">{{ shortDate(record.recorded_on) }}</span>
                                        </span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            :class="sourceBadgeClass[record.source] || 'bg-gray-100 text-gray-600'"
                                        >
                                            {{ record.source }}
                                        </span>
                                    </div>
                                    <p class="leading-snug text-fg-dark-grey">{{ record.body }}</p>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #e5e7eb; border-radius: 20px; }
</style>
