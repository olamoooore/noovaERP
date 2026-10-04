/**
 * Issue #637 — My Activities document links and display metadata.
 *
 * The backend returns the chatter subject as its fully-qualified Eloquent
 * class name; this module is the single frontend mapping from that class to
 * the document's detail route and human label, shared by the My Activities
 * page and the header badge.
 */

export interface ActivitySubject {
  type: string
  id: number
  label: string | null
}

export interface MyActivity {
  id: number
  activity_type: string
  summary: string
  note: string | null
  due_date: string | null
  status: string
  completed_at: string | null
  created_at: string | null
  subject: ActivitySubject
}

export interface MyActivitiesResponse {
  overdue: MyActivity[]
  today: MyActivity[]
  upcoming: MyActivity[]
  total: number
}

interface SubjectMeta {
  type: string
  label: string
  route: (id: number | string) => string
}

const SUBJECT_META: Record<string, SubjectMeta> = {
  'App\\Models\\Invoice': {
    type: 'invoice',
    label: 'Invoice',
    route: (id) => `/invoices/${id}`,
  },
  'App\\Models\\Quotation': {
    type: 'quotation',
    label: 'Quotation',
    route: (id) => `/quotations/${id}`,
  },
  'Modules\\Sales\\Models\\SalesOrder': {
    type: 'sales_order',
    label: 'Sales order',
    route: (id) => `/sales/orders/${id}`,
  },
  'Modules\\Sales\\Models\\DeliveryOrder': {
    type: 'delivery_order',
    label: 'Delivery order',
    route: (id) => `/delivery-orders/${id}`,
  },
  'App\\Models\\CustomerCreditNote': {
    type: 'customer_credit_note',
    label: 'Credit note',
    route: (id) => `/accounting/customers/credit-notes/${id}`,
  },
}

export function activitySubjectMeta(subjectType: string): SubjectMeta | null {
  return SUBJECT_META[subjectType] ?? null
}

/** Detail-page route for the activity's document, or null when unmapped. */
export function activitySubjectRoute(activity: MyActivity): string | null {
  const meta = SUBJECT_META[activity.subject?.type]
  if (!meta) return null
  return meta.route(activity.subject.id)
}

/** Document number when available, otherwise "<Type> #<id>". */
export function activitySubjectLabel(activity: MyActivity): string {
  const meta = SUBJECT_META[activity.subject?.type]
  if (activity.subject?.label) return activity.subject.label
  if (meta) return `${meta.label} #${activity.subject.id}`
  return `#${activity.subject?.id ?? ''}`
}

const ACTIVITY_TYPE_META: Record<string, { label: string; icon: string }> = {
  follow_up: { label: 'Follow up', icon: 'solar:chat-round-line-bold-duotone' },
  call: { label: 'Call', icon: 'solar:phone-calling-bold-duotone' },
  meeting: { label: 'Meeting', icon: 'solar:users-group-rounded-bold-duotone' },
  todo: { label: 'To-do', icon: 'solar:checklist-minimalistic-bold-duotone' },
  reminder: { label: 'Reminder', icon: 'solar:bell-bold-duotone' },
}

export function activityTypeMeta(activityType: string): { label: string; icon: string } {
  return (
    ACTIVITY_TYPE_META[activityType] ?? {
      label: activityType,
      icon: 'solar:clipboard-list-bold-duotone',
    }
  )
}
