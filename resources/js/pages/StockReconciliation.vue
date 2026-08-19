<script setup>
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { shortDate } from '../format';

const classes = ref([]);
const records = ref([]);
const loading = ref(true);
const saving = ref(false);

// --- State ---
const activeRightTab = ref(null); // For right column source tabs
const activeLeftFilter = ref('all'); // NEW: For left column stock filtering

const movementForm = ref({
    stock_class_id: null,
    type: 'sale',
    quantity: null,
    note: '',
});

// --- AI Paper Trail Parser State ---
const parsing = ref(false);
const suggestions = ref([]);

onMounted(load);

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
    return classes.value.filter(c => c.id === parseInt(activeLeftFilter.value));
});

const canSave = computed(
    () => movementForm.value.stock_class_id && movementForm.value.quantity > 0 && movementForm.value.type,
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

// --- AI Methods ---
async function parsePaperTrail() {
    parsing.value = true;
    suggestions.value = [];

    try {
        const unprocessedRecords = records.value.filter(
            (r) => !r.source.toLowerCase().includes('docket') && !r.processed
        );

        const payload = {
            records: unprocessedRecords.map((r) => ({
                id: r.id,
                recorded_on: r.recorded_on,
                source: r.source,
                body: r.body
            }))
        };

        const { data } = await axios.post('/api/ai/parse-paper-trail', payload);

        if (data.error) {
            throw new Error(data.error);
        }

        suggestions.value = data.suggestions || [];
    } catch (error) {
        console.error('AI parsing failed:', error);
        alert('Could not analyze paper trail: ' + (error.response?.data?.error || error.message));
    } finally {
        parsing.value = false;
    }
}

function acceptSuggestion(suggestion) {
    movementForm.value.stock_class_id = suggestion.stock_class_id;
    movementForm.value.type = suggestion.type;
    movementForm.value.quantity = suggestion.quantity;
    movementForm.value.note = `[AI] ${suggestion.source_note}`;

    suggestions.value = suggestions.value.filter((s) => s.id !== suggestion.id);

    setTimeout(() => {
        document.querySelector('.key-movement-form-container')?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    }, 100);
}

// --- Group Records for Right Tabs ---
const groupedRecords = computed(() => {
    const groups = {};

    records.value.forEach(record => {
        let key = record.source;
        if (key.includes('Sale docket')) key = 'Sale docket';
        else if (key.includes('Purchase docket')) key = 'Purchase docket';
        else if (key === 'Diary') key = 'Diary';
        else if (key === 'Text message') key = 'Text message';
        else if (key === 'Email') key = 'Email';
        
        if (!groups[key]) groups[key] = [];
        groups[key].push(record);
    });

    Object.keys(groups).forEach(key => {
        groups[key].sort((a, b) => new Date(b.recorded_on) - new Date(a.recorded_on));
    });

    return groups;
});

watch(groupedRecords, (newGroups) => {
    const keys = Object.keys(newGroups).filter(k => newGroups[k].length > 0);
    if (keys.length > 0 && !activeRightTab.value) {
        activeRightTab.value = keys[0];
    }
}, { immediate: true });

const visibleRecords = computed(() => {
    if (!activeRightTab.value) return [];
    return groupedRecords.value[activeRightTab.value] || [];
});

const sourceBadgeClass = {
    Diary: 'bg-fg-warning-15 text-fg-warning-text',
    'Sale docket': 'bg-fg-light-blue-15 text-fg-light-blue',
    'Text message': 'bg-fg-brown-15 text-fg-brown',
    'Purchase docket': 'bg-fg-green-15 text-fg-green-dark',
    Email: 'bg-fg-purple-15 text-fg-purple-dark'
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

        <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            
            <!-- LEFT COLUMN: Key Form + Filter + Tallies -->
            <div class="lg:col-span-6 space-y-4">
                
                <!-- New movement form (Moved Above Tabs) -->
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

                    <table class="w-full text-sm">
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

                <!-- AI Suggestions Panel -->
                <div v-if="suggestions.length > 0" class="rounded border border-fg-main-blue bg-white p-4 shadow-sm">
                    <h3 class="mb-3 text-sm font-semibold flex items-center gap-2 text-fg-main-blue">
                        🤖 Suggested Missing Movements
                        <span class="text-xs font-normal text-fg-mid-grey">({{ suggestions.length }} found)</span>
                    </h3>

                    <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                        <div
                            v-for="s in suggestions"
                            :key="s.id"
                            class="border rounded-lg p-3 bg-fg-super-pale-grey transition-all"
                            :class="{ 'border-yellow-400 bg-yellow-50': s.confidence === 'low' }"
                        >
                            <div class="flex justify-between items-start mb-2">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium capitalize">{{ s.type }}</span>
                                    <span class="text-fg-mid-grey">×</span>
                                    <span class="font-bold text-base">{{ s.quantity }}</span>
                                    <span class="text-fg-mid-grey">{{
                                        classes.find((c) => c.id === s.stock_class_id)?.name
                                    }}</span>
                                </div>
                                <span
                                    class="text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap"
                                    :class="
                                        s.confidence === 'high'
                                            ? 'bg-fg-positive-15 text-fg-positive-dark'
                                            : 'bg-fg-warning-15 text-fg-warning-text'
                                    "
                                >
                                    {{ s.confidence === 'high' ? '✓ Confident' : '⚠ Estimated' }}
                                </span>
                            </div>

                            <p class="text-xs text-fg-mid-grey italic mb-2 border-l-2 border-fg-muted-grey pl-2 leading-relaxed">
                                "{{ s.source_note }}"
                            </p>

                            <div v-if="s.reasoning" class="text-[10px] text-fg-light-grey mb-2 bg-white/60 p-1.5 rounded">
                                <strong>Why:</strong> {{ s.reasoning }}
                            </div>

                            <div class="flex gap-2">
                                <button
                                    @click="acceptSuggestion(s)"
                                    class="flex-1 bg-fg-main-blue hover:bg-fg-main-blue-hover text-white px-3 py-1.5 rounded text-xs font-medium transition-colors"
                                >
                                    ✓ Add to Tally
                                </button>
                                <button
                                    @click="suggestions = suggestions.filter((x) => x.id !== s.id)"
                                    class="text-fg-light-grey hover:text-fg-danger px-3 py-1.5 text-xs transition-colors"
                                >
                                    ✕ Ignore
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Action Button + Tabs -->
            <div class="lg:col-span-6 flex flex-col h-full">
                
                <!-- Find Missing Movements Button -->
                <div class="mb-4">
                    <button
                        @click="parsePaperTrail"
                        :disabled="parsing"
                        class="w-full rounded-lg border-2 border-dashed border-fg-muted-grey bg-white px-4 py-3 text-sm font-medium text-fg-mid-grey hover:bg-fg-super-pale-grey hover:border-fg-main-blue disabled:opacity-50 flex items-center justify-center gap-2 transition-colors shadow-sm"
                    >
                        <span v-if="parsing" class="animate-spin text-lg"></span>
                        <span v-else class="text-lg">🔍</span>
                        {{ parsing ? 'Analyzing Paper Trail...' : 'Find Missing Movements from Records' }}
                    </button>
                </div>

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
                                class="px-4 py-3 text-sm hover:bg-fg-super-pale-grey transition-colors"
                            >
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-mono text-fg-light-grey">{{ shortDate(record.recorded_on) }}</span>
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
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #e5e7eb; border-radius: 20px; }
</style>