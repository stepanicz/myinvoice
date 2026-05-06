<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { recurringApi, type RecurringTemplate, type RecurringStatus } from '@/api/recurring'
import { formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const items = ref<RecurringTemplate[]>([])
const loading = ref(false)
const statusFilter = ref<RecurringStatus | ''>('')
const busyId = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    items.value = await recurringApi.list(statusFilter.value || undefined)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Nepodařilo se načíst pravidelné faktury.')
  } finally {
    loading.value = false
  }
}

async function pause(id: number) {
  busyId.value = id
  try {
    await recurringApi.pause(id)
    toast.success('Šablona pozastavena.')
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Pauza selhala.')
  } finally {
    busyId.value = null
  }
}

async function resume(id: number) {
  busyId.value = id
  try {
    await recurringApi.resume(id)
    toast.success('Šablona aktivována.')
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Aktivace selhala.')
  } finally {
    busyId.value = null
  }
}

async function runNow(id: number) {
  if (!confirm('Vystavit fakturu z této šablony nyní? (Datum vystavení = dnes)')) return
  busyId.value = id
  try {
    const r = await recurringApi.runNow(id)
    if (r.sent) {
      toast.success(`Faktura ${r.varsymbol ?? '#' + r.invoice_id} vystavena a odeslána (${r.sent_to.join(', ')}).`)
    } else {
      toast.success(`Faktura ${r.varsymbol ?? '#' + r.invoice_id} vystavena (e-mail nebyl odeslán).`)
    }
    if (r.ended) {
      toast.warning('Šablona dosáhla data ukončení a byla automaticky uzavřena.')
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Vystavení selhalo.')
  } finally {
    busyId.value = null
  }
}

async function remove(id: number, name: string) {
  if (!confirm(`Smazat šablonu "${name}"? Vystavené faktury zůstanou nedotčené.`)) return
  busyId.value = id
  try {
    await recurringApi.delete(id)
    toast.success('Šablona smazána.')
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Smazání selhalo.')
  } finally {
    busyId.value = null
  }
}

function frequencyLabel(f: string): string {
  return f === 'monthly' ? 'měsíčně' : f === 'quarterly' ? 'čtvrtletně' : f === 'yearly' ? 'ročně' : f
}

function statusLabel(s: string): string {
  return s === 'active' ? 'aktivní' : s === 'paused' ? 'pozastaveno' : s === 'ended' ? 'ukončeno' : s
}

function statusBadgeClass(s: string): string {
  return s === 'active'
    ? 'bg-green-100 text-green-800'
    : s === 'paused'
      ? 'bg-yellow-100 text-yellow-800'
      : 'bg-gray-200 text-gray-700'
}

const itemsTotal = computed(() => items.value.length)

onMounted(load)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">Pravidelné faktury</h1>
      <RouterLink
        to="/recurring/new"
        class="inline-flex items-center gap-1.5 px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium rounded-lg transition-colors"
      >
        + Nová šablona
      </RouterLink>
    </div>

    <div class="mb-4 flex items-center gap-3">
      <label class="text-sm text-gray-700">Stav:</label>
      <select
        v-model="statusFilter"
        @change="load"
        class="rounded-lg border-gray-300 text-sm"
      >
        <option value="">všechny</option>
        <option value="active">aktivní</option>
        <option value="paused">pozastavené</option>
        <option value="ended">ukončené</option>
      </select>
      <span class="text-sm text-gray-500 ml-auto">{{ itemsTotal }} šablon</span>
    </div>

    <div v-if="loading" class="text-center py-8 text-gray-500">Načítám…</div>

    <div v-else-if="items.length === 0" class="text-center py-12 bg-white rounded-lg border border-gray-200">
      <p class="text-gray-600 mb-4">Zatím žádné pravidelné faktury.</p>
      <RouterLink
        to="/recurring/new"
        class="inline-flex items-center gap-1.5 px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium rounded-lg"
      >
        Vytvořit první šablonu
      </RouterLink>
    </div>

    <div v-else class="bg-white rounded-lg border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-700 border-b border-gray-200">
          <tr>
            <th class="text-left px-4 py-3 font-medium">Název</th>
            <th class="text-left px-4 py-3 font-medium">Klient</th>
            <th class="text-left px-4 py-3 font-medium">Frekvence</th>
            <th class="text-left px-4 py-3 font-medium">Příští vystavení</th>
            <th class="text-left px-4 py-3 font-medium">Stav</th>
            <th class="text-right px-4 py-3 font-medium">Vystaveno</th>
            <th class="text-right px-4 py-3 font-medium">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="t in items"
            :key="t.id"
            class="border-b border-gray-100 last:border-b-0 hover:bg-gray-50"
          >
            <td class="px-4 py-3">
              <RouterLink :to="`/recurring/${t.id}/edit`" class="text-violet-700 hover:underline font-medium">
                {{ t.name }}
              </RouterLink>
              <div class="text-xs text-gray-500 mt-0.5">
                {{ t.invoice_type === 'proforma' ? 'Proforma' : 'Faktura' }} ·
                {{ t.auto_send ? 'auto-odeslání' : 'jen vystavení' }}
              </div>
            </td>
            <td class="px-4 py-3">
              {{ t.client_company_name }}
              <div v-if="t.project_name" class="text-xs text-gray-500">{{ t.project_name }}</div>
            </td>
            <td class="px-4 py-3">{{ frequencyLabel(t.frequency) }}</td>
            <td class="px-4 py-3">
              {{ formatDate(t.next_run_date) }}
              <div v-if="t.end_date" class="text-xs text-gray-500">do {{ formatDate(t.end_date) }}</div>
            </td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" :class="statusBadgeClass(t.status)">
                {{ statusLabel(t.status) }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              {{ t.run_count }}×
              <div v-if="t.last_run_at" class="text-xs text-gray-500">posl. {{ formatDate(t.last_run_at) }}</div>
            </td>
            <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
              <button
                v-if="t.status === 'active'"
                @click="runNow(t.id)"
                :disabled="busyId === t.id"
                class="text-xs px-2 py-1 rounded border border-violet-300 text-violet-700 hover:bg-violet-50 disabled:opacity-50"
                title="Vystavit nyní"
              >
                ▶ Vystavit
              </button>
              <button
                v-if="t.status === 'active'"
                @click="pause(t.id)"
                :disabled="busyId === t.id"
                class="text-xs px-2 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              >
                ⏸ Pauza
              </button>
              <button
                v-if="t.status === 'paused'"
                @click="resume(t.id)"
                :disabled="busyId === t.id"
                class="text-xs px-2 py-1 rounded border border-green-300 text-green-700 hover:bg-green-50 disabled:opacity-50"
              >
                ▶ Aktivovat
              </button>
              <button
                @click="remove(t.id, t.name)"
                :disabled="busyId === t.id"
                class="text-xs px-2 py-1 rounded border border-red-300 text-red-700 hover:bg-red-50 disabled:opacity-50"
              >
                Smazat
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
      <p class="font-medium mb-1">💡 Jak to funguje</p>
      <p>Cron běží 1× denně okolo 5:00 ráno. Pokud má šablona <code>příští vystavení ≤ dnes</code> a je aktivní, vytvoří se nová faktura podle šablony, vystaví se (přidělí varsymbol) a (pokud je auto-odeslání) odešle e-mailem klientovi. <code>Příští vystavení</code> se posune o jednu periodu.</p>
      <p class="mt-2"><strong>Proměnné v popiscích položek a poznámkách:</strong>
        <code>(MMMM)/(YYYY)</code> = měsíc/rok faktury (<code>05/2026</code>),
        <code>(MMMM-1)/(YYYY)</code> = předchozí měsíc/rok (s přechodem přes leden — <code>12/2026</code>),
        <code>(YYYY-1)</code> = předchozí rok.
        Pro pokročilé i curly syntaxe <code>{{ '{prev:MM}' }}</code>, <code>{{ '{period}' }}</code>.
      </p>
    </div>
  </div>
</template>
