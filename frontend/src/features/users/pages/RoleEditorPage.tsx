import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Lock } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, ButtonLink, Card, CardBody, CardFooter, CardHeader, FormField, Input, PageLoader, Textarea, useConfirm, useToast } from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { usersApi, usersKeys, type RoleInput } from '../api'
import type { PermissionGroup, Role } from '../types'

/** /users/roles/new and /users/roles/:id — role name plus a grouped permission matrix. */
export default function RoleEditorPage() {
  const { roleId } = useParams()
  const isNew = roleId === 'new'
  const id = Number(roleId)

  const permissions = useQuery({ queryKey: usersKeys.permissions, queryFn: usersApi.permissions, staleTime: Infinity })
  const role = useQuery({ queryKey: usersKeys.role(id), queryFn: () => usersApi.role(id), enabled: !isNew && Number.isFinite(id) })

  if (permissions.isPending || (!isNew && role.isPending)) return <PageLoader />
  if (permissions.isError) return <ApiErrorAlert error={ApiError.from(permissions.error)} />
  if (!isNew && role.isError) return <ApiErrorAlert error={ApiError.from(role.error)} />

  return <RoleForm groups={permissions.data} role={isNew ? null : (role.data ?? null)} />
}

function RoleForm({ groups, role }: { groups: PermissionGroup[]; role: Role | null }) {
  const account = useAccount()
  const { refresh } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const held = new Set(account.permissions)
  const locked = Boolean(role?.is_locked)

  const form = useForm<RoleInput>({
    initialValues: { name: role?.name ?? '', description: role?.description ?? '', permissions: role?.permissions ?? [] },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter a role name.',
      permissions: v.permissions.length > 0 ? undefined : 'Choose at least one permission.',
    }),
    onSubmit: async (values) => {
      if (role) await usersApi.updateRole(role.id, values)
      else await usersApi.createRole(values)
      await queryClient.invalidateQueries({ queryKey: usersKeys.roles })
      // Your own permissions may have changed (if you hold this role).
      await refresh()
      toast.success(role ? 'Role saved' : 'Role created', role ? 'Users with this role get the new permissions right away.' : undefined)
      navigate('/users/roles')
    },
  })

  const selected = new Set(form.values.permissions)
  const original = new Set(role?.permissions ?? [])
  // You can only grant permissions you hold; ones the role already has stay as they are.
  const canToggle = (name: string) => !locked && (held.has(name) || original.has(name))
  const setPermissions = (next: Set<string>) => form.setValue('permissions', [...next].sort())

  function toggle(name: string, on: boolean) {
    const next = new Set(selected)
    if (on) next.add(name)
    else next.delete(name)
    setPermissions(next)
  }

  function toggleGroup(group: PermissionGroup, on: boolean) {
    const next = new Set(selected)
    group.permissions.filter((p) => canToggle(p.name)).forEach((p) => (on ? next.add(p.name) : next.delete(p.name)))
    setPermissions(next)
  }

  async function remove() {
    if (!role) return
    const ok = await confirm({ title: `Delete the role "${role.name}"?`, description: 'This cannot be undone.', confirmLabel: 'Delete role', danger: true })
    if (!ok) return
    try {
      await usersApi.deleteRole(role.id)
      await queryClient.invalidateQueries({ queryKey: usersKeys.roles })
      toast.success('Role deleted')
      navigate('/users/roles')
    } catch (error) {
      toast.error(ApiError.from(error).message)
    }
  }

  return (
    <form onSubmit={form.handleSubmit} noValidate className="space-y-6">
      <ButtonLink to="/users/roles" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Back to roles
      </ButtonLink>

      {locked && (
        <Alert tone="info" title="Locked role">
          The {role?.name} role always has every permission and cannot be changed.
        </Alert>
      )}
      <ApiErrorAlert error={form.formError} />

      <Card>
        <CardHeader title={role ? role.name : 'New role'} description={role?.is_system ? 'Default role — you can adjust its permissions.' : undefined} />
        <CardBody className="grid gap-4 sm:grid-cols-2">
          <FormField label="Role name" required error={form.errors.name}>
            <Input {...form.field('name')} disabled={locked} />
          </FormField>
          <FormField label="Description" error={form.errors.description}>
            <Textarea {...form.field('description')} rows={1} disabled={locked} maxLength={255} />
          </FormField>
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Permissions"
          description={`${selected.size} selected. Greyed-out permissions are ones you do not have yourself, so you cannot grant them.`}
        />
        {form.errors.permissions && <p className="px-5 pt-3 text-sm text-red-600">{form.errors.permissions}</p>}
        <CardBody className="grid gap-4 md:grid-cols-2">
          {groups.map((group) => {
            const toggleable = group.permissions.filter((p) => canToggle(p.name))
            const allOn = toggleable.length > 0 && toggleable.every((p) => selected.has(p.name))
            return (
              <fieldset key={group.key} className="rounded-lg border border-line">
                <legend className="sr-only">{group.label}</legend>
                <div className="flex items-center justify-between gap-2 border-b border-line-soft bg-canvas px-3 py-2">
                  <span className="text-sm font-semibold text-ink">{group.label}</span>
                  {toggleable.length > 0 && (
                    <button type="button" className="text-xs font-medium text-brand-500 hover:underline" onClick={() => toggleGroup(group, !allOn)}>
                      {allOn ? 'Clear all' : 'Select all'}
                    </button>
                  )}
                </div>
                <div className="space-y-1.5 px-3 py-2.5">
                  {group.permissions.map((p) => {
                    const enabled = canToggle(p.name)
                    return (
                      <label key={p.name} className={enabled ? 'flex items-start gap-2 text-sm text-ink' : 'flex items-start gap-2 text-sm text-ink-subtle'}>
                        <input
                          type="checkbox"
                          className="mt-0.5 size-4 shrink-0 accent-brand-500"
                          checked={selected.has(p.name)}
                          disabled={!enabled}
                          onChange={(e) => toggle(p.name, e.target.checked)}
                        />
                        <span>
                          {p.label}
                          {!enabled && !locked && <Lock className="ml-1 inline size-3" aria-label="You do not have this permission" />}
                        </span>
                      </label>
                    )
                  })}
                </div>
              </fieldset>
            )
          })}
        </CardBody>
        {!locked && (
          <CardFooter className="justify-between">
            <div>
              {role && !role.is_system && (
                <Button variant="ghost" className="text-red-600 hover:bg-red-50 hover:text-red-700" onClick={remove} disabled={form.submitting}>
                  Delete role
                </Button>
              )}
            </div>
            <div className="flex gap-2">
              <ButtonLink to="/users/roles" variant="secondary">
                Cancel
              </ButtonLink>
              <Button type="submit" loading={form.submitting}>
                {role ? 'Save role' : 'Create role'}
              </Button>
            </div>
          </CardFooter>
        )}
      </Card>
    </form>
  )
}
