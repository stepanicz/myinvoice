<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { recurringApi, type RecurringTemplatePayload } from '@/api/recurring'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Currency, type VatRate, type Unit } from '@/api/codebooks'
import { useToast } from '@/composables/useToast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const templateId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const submitting = ref(false)
const loading = ref(true)
const fieldErrors = ref<Record<string, string>>({})

const clients = ref<Client[]>([])
const currencies = ref<Currency[]>([])
const vatRates = ref<VatRate[]>([])
const units = ref<Unit[]>([])

const form = ref<RecurringTemplatePayload>({
  name: '',
  client_id: 0,
  project_id: null,
  invoice_type: 'invoice',
  currency_id: 0,
  language: 'cs',
  reverse_charge: false,
  payment_due_days: 14,
  note_above_items: '',
  note_below_items: '',
  frequency: 'monthly',
  start_date: new Date().toISOString().slice(0, 10),
  end_date: null,
  next_run_date: '',
  status: 'active',
  auto_send: true,
  items: [
    { description: '', quantity: 1, unit: 'ks', unit_price_without_vat: 0, vat_rate_id: 0, order_index: 0 },
  ],
})

onMounted(async () => {
  loading.value = true
  try {
    const [cs, curs, vs, us] = await Promise.all([
      clientsApi.list({ archived: false, per_page: 200 }),
      codebooksApi.currencies(),
      codebooksApi.vatRates(),
      codebooksApi.units(),
    ])
    clients.value = cs.data
    currencies.value = curs
    vatRates.value = vs
    units.value = us

    if (form.value.currency_id === 0) {
      const def = currencies.value.find(c => c.is_default) || currencies.value[0]
      if (def) form.value.currency_id = def.id
    }
    const defaultVat = vatRates.value.find(v => v.is_default) || vatRates.value[0]
    if (defaultVat && form.value.items[0].vat_rate_id === 0) {
      form.value.items[0].vat_rate_id = defaultVat.id
    }

    if (isEdit.value && templateId.value) {
      const tpl = await recurringApi.get(templateId.value)
      form.value = {
        name: tpl.name,
        client_id: tpl.client_id,
        project_id: tpl.project_id,
        invoice_type: tpl.invoice_type,
        currency_id: tpl.currency_id,
        language: tpl.language,
        reverse_charge: tpl.reverse_charge,
        payment_due_days: tpl.payment_due_days,
        note_above_items: tpl.note_above_items || '',
        note_below_items: tpl.note_below_items || '',
        frequency: tpl.frequency,
        start_date: tpl.start_date,
        end_date: tpl.end_date,
        next_run_date: tpl.next_run_date,
        status: tpl.status,
        auto_send: tpl.auto_send,
        items: tpl.items.map(it => ({
          description: it.description,
          quantity: it.quantity,
          unit: it.unit,
          unit_price_without_vat: it.unit_price_without_vat,
          vat_rate_id: it.vat_rate_id,
          order_index: it.order_index,
        })),
      }
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Načtení selhalo.')
  } finally {
    loading.value = false
  }
})

function addItem() {
  const defaultVat = vatRates.value.find(v => v.is_default) || vatRates.value[0]
  form.value.items.push({
    description: '',
    quantity: 1,
    unit: 'ks',
    unit_price_without_vat: 0,
    vat_rate_id: defaultVat?.id ?? 0,
    order_index: form.value.items.length,
  })
}

function removeItem(idx: number) {
  form.value.items.splice(idx, 1)
  form.value.items.forEach((it, i) => { it.order_index = i })
}

const totalPreview = computed(() => {
  return form.value.items.reduce((sum, it) => {
    const vat = vatRates.value.find(v => v.id === it.vat_rate_id)
    const rate = vat?.rate_percent ?? 0
    const subtotal = (it.quantity || 0) * (it.unit_price_without_vat || 0)
    return sum + subtotal * (1 + rate / 100)
  }, 0)
})

const currencyCode = computed(() => {
  const c = currencies.value.find(c => c.id === form.value.currency_id)
  return c?.code ?? 'CZK'
})

async function submit() {
  fieldErrors.value = {}
  submitting.value = true
  try {
    if (!form.value.next_run_date) {
      form.value.next_run_date = form.value.start_date
    }
    if (isEdit.value && templateId.value) {
      await recurringApi.update(templateId.value, form.value)
      toast.success('Šablona uložena.')
    } else {
      const tpl = await recurringApi.create(form.value)
      toast.success('Šablona vytvořena.')
      router.push(`/recurring/${tpl.id}/edit`)
      return
    }
    router.push('/recurring')
  } catch (e: any) {
    const data = e?.response?.data?.error
    if (data?.fields) {
      fieldErrors.value = data.fields
    }
    toast.error(data?.message || 'Uložení selhalo.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <RouterLink to="/recurring" class="text-sm text-violet-700 hover:underline">← Zpět na seznam</RouterLink>
    </div>
    <h1 class="text-2xl font-semibold mb-6">
      {{ isEdit ? 'Upravit pravidelnou fakturu' : 'Nová pravidelná faktura' }}
    </h1>

    <div v-if="loading" class="text-center py-8 text-gray-500">Načítám…</div>

    <form v-else @submit.prevent="submit" class="space-y-6 max-w-5xl">
      <!-- Základní info -->
      <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-4">
        <h2 class="text-lg font-medium border-b border-gray-100 pb-2">Základní údaje</h2>
        <div class="grid grid-cols-2 gap-4">
          <div class="col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Název šablony</label>
            <input
              v-model="form.name"
              type="text"
              class="w-full rounded-lg border-gray-300"
              :class="{ 'border-red-500': fieldErrors.name }"
              placeholder="např. Měsíční pronájem — Acme s.r.o."
              required
            />
            <p v-if="fieldErrors.name" class="text-xs text-red-600 mt-1">{{ fieldErrors.name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Klient</label>
            <select
              v-model="form.client_id"
              class="w-full rounded-lg border-gray-300"
              :class="{ 'border-red-500': fieldErrors.client_id }"
              required
            >
              <option :value="0" disabled>— vyber —</option>
              <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.company_name }}</option>
            </select>
            <p v-if="fieldErrors.client_id" class="text-xs text-red-600 mt-1">{{ fieldErrors.client_id }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Měna</label>
            <select v-model="form.currency_id" class="w-full rounded-lg border-gray-300" required>
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} ({{ c.label }})</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Typ dokladu</label>
            <select v-model="form.invoice_type" class="w-full rounded-lg border-gray-300">
              <option value="invoice">Faktura</option>
              <option value="proforma">Proforma</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Splatnost (dny)</label>
            <input v-model.number="form.payment_due_days" type="number" min="0" max="365" class="w-full rounded-lg border-gray-300" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Jazyk faktury</label>
            <select v-model="form.language" class="w-full rounded-lg border-gray-300">
              <option value="cs">Česky</option>
              <option value="en">English</option>
            </select>
          </div>

          <div class="flex items-center gap-2 pt-6">
            <input v-model="form.reverse_charge" type="checkbox" id="rc" class="rounded" />
            <label for="rc" class="text-sm">Reverse-charge (přenesená daňová povinnost)</label>
          </div>
        </div>
      </div>

      <!-- Frekvence -->
      <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-4">
        <h2 class="text-lg font-medium border-b border-gray-100 pb-2">Pravidelnost</h2>
        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Frekvence</label>
            <select v-model="form.frequency" class="w-full rounded-lg border-gray-300">
              <option value="monthly">měsíčně</option>
              <option value="quarterly">čtvrtletně</option>
              <option value="yearly">ročně</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">První vystavení</label>
            <input v-model="form.start_date" type="date" class="w-full rounded-lg border-gray-300" required />
            <p v-if="fieldErrors.start_date" class="text-xs text-red-600 mt-1">{{ fieldErrors.start_date }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Konec (volitelné)</label>
            <input v-model="form.end_date" type="date" class="w-full rounded-lg border-gray-300" />
            <p v-if="fieldErrors.end_date" class="text-xs text-red-600 mt-1">{{ fieldErrors.end_date }}</p>
          </div>
          <div v-if="isEdit">
            <label class="block text-sm font-medium text-gray-700 mb-1">Příští vystavení</label>
            <input v-model="form.next_run_date" type="date" class="w-full rounded-lg border-gray-300" />
            <p class="text-xs text-gray-500 mt-1">Další faktura se vystaví v tento den (nebo později pokud cron neběžel).</p>
          </div>
          <div class="flex items-center gap-2 pt-6">
            <input v-model="form.auto_send" type="checkbox" id="auto-send" class="rounded" />
            <label for="auto-send" class="text-sm">Automaticky odeslat e-mailem klientovi po vystavení</label>
          </div>
        </div>
      </div>

      <!-- Položky -->
      <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
        <div class="flex items-center justify-between border-b border-gray-100 pb-2">
          <h2 class="text-lg font-medium">Položky</h2>
          <span class="text-sm text-gray-500">
            Celkem (s DPH, dle aktuálních sazeb): <strong>{{ totalPreview.toFixed(2) }} {{ currencyCode }}</strong>
          </span>
        </div>

        <p class="text-xs text-gray-600 bg-amber-50 border border-amber-200 rounded p-2">
          💡 V popisu lze použít proměnné (vyhodnotí se k datu vystavení faktury):<br>
          <code>(MMMM)/(YYYY)</code> = např. <code>05/2026</code> ·
          <code>(MMMM-1)/(YYYY)</code> = předchozí měsíc/rok s přechodem přes Nový rok (např. <code>12/2026</code> u faktury z 1.1.2027) ·
          <code>(MMMM+1)/(YYYY)</code> = následující měsíc ·
          <code>(YYYY-1)</code> = předchozí rok<br>
          Alternativně curly placeholdery: <code>{{ '{MM}' }}</code>, <code>{{ '{YYYY}' }}</code>, <code>{{ '{prev:MM}' }}</code>, <code>{{ '{next:MM}' }}</code>, <code>{{ '{period}' }}</code>.
        </p>

        <table class="w-full text-sm">
          <thead class="text-gray-700">
            <tr class="border-b border-gray-200">
              <th class="text-left py-2 pr-2">Popis</th>
              <th class="text-right px-2 w-20">Množství</th>
              <th class="text-left px-2 w-20">Jednotka</th>
              <th class="text-right px-2 w-32">Cena bez DPH</th>
              <th class="text-left px-2 w-32">DPH</th>
              <th class="w-10"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(it, idx) in form.items" :key="idx" class="border-b border-gray-100">
              <td class="py-2 pr-2">
                <textarea
                  v-model="it.description"
                  rows="1"
                  class="w-full rounded border-gray-300 text-sm"
                  placeholder="např. Pronájem za {MMMM} {YYYY}"
                />
              </td>
              <td class="px-2">
                <input v-model.number="it.quantity" type="number" step="0.01" class="w-full rounded border-gray-300 text-sm text-right" />
              </td>
              <td class="px-2">
                <select v-model="it.unit" class="w-full rounded border-gray-300 text-sm">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                </select>
              </td>
              <td class="px-2">
                <input v-model.number="it.unit_price_without_vat" type="number" step="0.01" class="w-full rounded border-gray-300 text-sm text-right" />
              </td>
              <td class="px-2">
                <select v-model.number="it.vat_rate_id" class="w-full rounded border-gray-300 text-sm">
                  <option v-for="v in vatRates" :key="v.id" :value="v.id">{{ v.label_cs }} ({{ v.rate_percent }}%)</option>
                </select>
              </td>
              <td class="text-center">
                <button
                  type="button"
                  @click="removeItem(idx)"
                  :disabled="form.items.length <= 1"
                  class="text-red-600 hover:text-red-800 disabled:opacity-30"
                  title="Odstranit"
                >×</button>
              </td>
            </tr>
          </tbody>
        </table>

        <button
          type="button"
          @click="addItem"
          class="text-sm text-violet-700 hover:underline"
        >+ Přidat položku</button>
      </div>

      <!-- Poznámky -->
      <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
        <h2 class="text-lg font-medium border-b border-gray-100 pb-2">Poznámky (volitelné)</h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Text nad položkami</label>
          <textarea v-model="form.note_above_items" rows="2" class="w-full rounded-lg border-gray-300 text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Text pod položkami</label>
          <textarea v-model="form.note_below_items" rows="2" class="w-full rounded-lg border-gray-300 text-sm" />
        </div>
      </div>

      <!-- Submit -->
      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="submitting"
          class="px-6 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium disabled:opacity-50"
        >
          {{ submitting ? 'Ukládám…' : (isEdit ? 'Uložit změny' : 'Vytvořit šablonu') }}
        </button>
        <RouterLink to="/recurring" class="px-6 py-2 text-gray-700 hover:bg-gray-100 rounded-lg">Zrušit</RouterLink>
      </div>
    </form>
  </div>
</template>
