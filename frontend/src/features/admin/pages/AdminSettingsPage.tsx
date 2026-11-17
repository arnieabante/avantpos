import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, Card, CardBody, CardHeader, Checkbox, FormField, Input, PageHeader, PageLoader, PasswordInput, Textarea, useToast } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { useAdminAuth } from '../useAdminAuth'
import { adminApi, adminKeys, type PlatformSettingsData } from '../api'

/** /admin/settings — platform settings and the admin's own two-factor sign-in. */
export default function AdminSettingsPage() {
  const { admin } = useAdminAuth()
  const q = useQuery({ queryKey: adminKeys.settings, queryFn: adminApi.settings })
  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  return (
    <div className="space-y-6">
      <PageHeader title="Settings" />
      <SecurityCard />
      <PlatformCard data={q.data} canEdit={admin?.role === 'super_admin'} />
    </div>
  )
}

function PlatformCard({ data, canEdit }: { data: PlatformSettingsData; canEdit: boolean }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [v, setV] = useState({
    bank_details: data.billing.bank_details ?? '',
    support_email: data.support.email ?? '',
    support_phone: data.support.phone ?? '',
    announcement: data.platform.announcement ?? '',
    registrations_open: data.platform.registrations_open,
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      queryClient.setQueryData(
        adminKeys.settings,
        await adminApi.saveSettings({
          billing: { bank_details: v.bank_details.trim() || null },
          support: { email: v.support_email.trim() || null, phone: v.support_phone.trim() || null },
          platform: { announcement: v.announcement.trim() || null, registrations_open: v.registrations_open },
        }),
      )
      toast.success('Settings saved')
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <Card>
      <CardHeader title="Platform" />
      <CardBody className="space-y-4">
        <ApiErrorAlert error={error} />
        <Alert tone="info" title="Payment gateway">
          Online payments use <strong>{data.gateways.active}</strong>. Xendit is {data.gateways.xendit_configured ? 'configured' : 'not configured'}; PayMongo is{' '}
          {data.gateways.paymongo_configured ? 'configured' : 'not configured'}. Keys are set in the server environment (BILLING_GATEWAY, XENDIT_*, PAYMONGO_*), never here.
        </Alert>
        <FormField label="Bank transfer details" hint="Shown to businesses paying by transfer (bank, account name and number, GCash number).">
          <Textarea rows={4} maxLength={2000} disabled={!canEdit} value={v.bank_details} onChange={(e) => setV({ ...v, bank_details: e.target.value })} />
        </FormField>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Support e-mail">
            <Input type="email" disabled={!canEdit} value={v.support_email} onChange={(e) => setV({ ...v, support_email: e.target.value })} />
          </FormField>
          <FormField label="Support phone">
            <Input disabled={!canEdit} value={v.support_phone} onChange={(e) => setV({ ...v, support_phone: e.target.value })} />
          </FormField>
        </div>
        <FormField label="Announcement" hint="Optional message for all businesses (e.g. scheduled maintenance).">
          <Input maxLength={500} disabled={!canEdit} value={v.announcement} onChange={(e) => setV({ ...v, announcement: e.target.value })} />
        </FormField>
        <Checkbox label="New businesses can sign up" disabled={!canEdit} checked={v.registrations_open} onChange={(e) => setV({ ...v, registrations_open: e.target.checked })} />
        {canEdit && (
          <div className="flex justify-end">
            <Button onClick={save} loading={busy}>Save settings</Button>
          </div>
        )}
      </CardBody>
    </Card>
  )
}

function SecurityCard() {
  const { admin, refresh } = useAdminAuth()
  const toast = useToast()
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const on = !!admin?.two_factor

  const run = async (action: () => Promise<unknown>, message: string) => {
    setBusy(true)
    setError(null)
    try {
      await action()
      await refresh()
      setSetup(null)
      setCode('')
      setPassword('')
      toast.success(message)
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <Card>
      <CardHeader title="Two-factor sign-in" description="Ask for a code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy) when you sign in." actions={<Badge tone={on ? 'success' : 'warning'}>{on ? 'On' : 'Off'}</Badge>} />
      <CardBody className="space-y-4">
        <ApiErrorAlert error={error} />
        {!on && !setup && (
          <Button leftIcon={<ShieldCheck className="size-4" />} loading={busy} onClick={async () => {
            setBusy(true)
            try {
              setSetup(await adminApi.twoFactorSetup())
            } catch (e) {
              setError(ApiError.from(e))
            }
            setBusy(false)
          }}>Turn on</Button>
        )}
        {!on && setup && (
          <div className="space-y-3 text-sm">
            <p>1. In your authenticator app, add an account with this key (or open the link on your phone):</p>
            <p className="rounded-md bg-[#f8f9fa] p-3 font-mono text-base tracking-widest break-all text-ink">{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</p>
            <a href={setup.otpauth_uri} className="text-brand-500 hover:underline">Open in authenticator app</a>
            <p>2. Enter the 6-digit code the app shows:</p>
            <div className="flex max-w-xs gap-2">
              <Input aria-label="Code" inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} />
              <Button loading={busy} disabled={code.length !== 6} onClick={() => run(() => adminApi.twoFactorConfirm(code), 'Two-factor sign-in is on')}>Confirm</Button>
            </div>
          </div>
        )}
        {on && (
          <div className="grid max-w-xl gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <FormField label="Password"><PasswordInput value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></FormField>
            <FormField label="Code"><Input inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} /></FormField>
            <Button variant="outline" loading={busy} disabled={!password || code.length !== 6} onClick={() => run(() => adminApi.twoFactorDisable(password, code), 'Two-factor sign-in is off')}>Turn off</Button>
          </div>
        )}
      </CardBody>
    </Card>
  )
}
