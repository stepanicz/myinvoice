import { api } from './client'

export type RecurringFrequency = 'monthly' | 'quarterly' | 'yearly'
export type RecurringStatus = 'active' | 'paused' | 'ended'
export type RecurringInvoiceType = 'invoice' | 'proforma'

export interface RecurringTemplateItem {
  id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_snapshot?: number
  order_index: number
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
}

export interface RecurringTemplate {
  id: number
  supplier_id: number
  name: string
  client_id: number
  client_company_name?: string
  project_id: number | null
  project_name?: string | null
  invoice_type: RecurringInvoiceType
  currency_id: number
  currency?: string
  language: 'cs' | 'en'
  reverse_charge: boolean
  payment_due_days: number
  note_above_items: string | null
  note_below_items: string | null

  frequency: RecurringFrequency
  start_date: string
  end_date: string | null
  next_run_date: string
  status: RecurringStatus
  auto_send: boolean

  last_run_at: string | null
  last_invoice_id: number | null
  run_count: number

  items: RecurringTemplateItem[]
  created_at?: string
  updated_at?: string
}

export interface RecurringTemplatePayload {
  name: string
  client_id: number
  project_id?: number | null
  invoice_type?: RecurringInvoiceType
  currency_id: number
  language?: 'cs' | 'en'
  reverse_charge?: boolean
  payment_due_days?: number
  note_above_items?: string | null
  note_below_items?: string | null
  frequency: RecurringFrequency
  start_date: string
  end_date?: string | null
  next_run_date?: string
  status?: RecurringStatus
  auto_send?: boolean
  items: Array<Omit<RecurringTemplateItem, 'id' | 'vat_code' | 'vat_label_cs' | 'vat_label_en' | 'vat_rate_snapshot'>>
}

export interface RunNowResult {
  invoice_id: number
  varsymbol: string | null
  sent_to: string[]
  sent: boolean
  next_run_date: string
  ended: boolean
}

export const recurringApi = {
  list: (status?: RecurringStatus) =>
    api
      .get<{ items: RecurringTemplate[] }>('/recurring-invoices', { params: { status } })
      .then((r) => r.data.items),

  get: (id: number) => api.get<RecurringTemplate>(`/recurring-invoices/${id}`).then((r) => r.data),

  create: (payload: RecurringTemplatePayload) =>
    api.post<RecurringTemplate>('/recurring-invoices', payload).then((r) => r.data),

  update: (id: number, payload: Partial<RecurringTemplatePayload>) =>
    api.put<RecurringTemplate>(`/recurring-invoices/${id}`, payload).then((r) => r.data),

  delete: (id: number) =>
    api.delete<{ deleted: boolean }>(`/recurring-invoices/${id}`).then((r) => r.data),

  pause: (id: number) =>
    api.post<RecurringTemplate>(`/recurring-invoices/${id}/pause`).then((r) => r.data),
  resume: (id: number) =>
    api.post<RecurringTemplate>(`/recurring-invoices/${id}/resume`).then((r) => r.data),

  clone: (id: number) =>
    api.post<RecurringTemplate>(`/recurring-invoices/${id}/clone`).then((r) => r.data),

  runNow: (id: number) =>
    api.post<RunNowResult>(`/recurring-invoices/${id}/run-now`).then((r) => r.data),
}
