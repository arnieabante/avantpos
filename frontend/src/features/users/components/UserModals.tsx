import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, FormField, Input, Modal, PasswordInput, Select, useToast } from '@/components/ui'
import { useAccount } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { usersApi, usersKeys, type InviteInput, type NewUserInput, type UserInput } from '../api'
import type { TeamUser } from '../types'
import { BranchPicker } from './BranchPicker'

/** Roles the signed-in user may give out (never Owner, never more than they hold). */
function useAssignableRoles() {
  return useQuery({ queryKey: usersKeys.assignableRoles, queryFn: usersApi.assignableRoles, staleTime: 60_000 })
}

function branchError(errors: Record<string, string | undefined>): string | undefined {
  return errors.branch_ids ?? Object.entries(errors).find(([key]) => key.startsWith('branch_ids.'))?.[1]
}

export function InviteModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const { branches } = useAccount()
  const roles = useAssignableRoles()

  const form = useForm<InviteInput>({
    // A single-branch business needs no choice.
    initialValues: { email: '', name: '', role_id: '', branch_ids: branches.length === 1 ? [branches[0]!.id] : [] },
    validate: (v) => ({
      email: v.email.trim() ? undefined : 'Enter the e-mail address.',
      role_id: v.role_id ? undefined : 'Choose a role.',
      branch_ids: v.branch_ids.length > 0 ? undefined : 'Choose at least one branch.',
    }),
    onSubmit: async (values) => {
      await usersApi.invite({ ...values, email: values.email.trim() })
      await queryClient.invalidateQueries({ queryKey: ['invitations'] })
      toast.success('Invitation sent', `${values.email} will receive an e-mail to join. The link expires in 3 days.`)
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!form.submitting}
      title="Invite a team member"
      description="They will set their own password from the e-mail link."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="invite-form" loading={form.submitting}>
            Send invitation
          </Button>
        </>
      }
    >
      <form id="invite-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="E-mail address" required error={form.errors.email}>
            <Input {...form.field('email')} type="email" autoComplete="off" data-autofocus />
          </FormField>
          <FormField label="Name" hint="Optional" error={form.errors.name}>
            <Input {...form.field('name')} autoComplete="off" />
          </FormField>
        </div>
        <FormField label="Role" required error={form.errors.role_id}>
          <Select value={String(form.values.role_id)} onChange={(e) => form.setValue('role_id', e.target.value ? Number(e.target.value) : '')}>
            <option value="">{roles.isPending ? 'Loading…' : 'Choose a role…'}</option>
            {roles.data?.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
        </FormField>
        <BranchPicker value={form.values.branch_ids} onChange={(ids) => form.setValue('branch_ids', ids)} error={branchError(form.errors)} />
      </form>
    </Modal>
  )
}

/** Owner / administrator adds a user directly with a starting password (no e-mail needed to join). */
export function AddUserModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const { branches } = useAccount()
  const roles = useAssignableRoles()

  const form = useForm<NewUserInput>({
    initialValues: {
      name: '', email: '', phone: '', role_id: '', password: '', password_confirmation: '',
      branch_ids: branches.length === 1 ? [branches[0]!.id] : [],
    },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter the name.',
      email: v.email.trim() ? undefined : 'Enter the e-mail address they will sign in with.',
      role_id: v.role_id ? undefined : 'Choose a role.',
      branch_ids: v.branch_ids.length > 0 ? undefined : 'Choose at least one branch.',
      password: v.password ? undefined : 'Enter a starting password.',
      password_confirmation: v.password_confirmation === v.password ? undefined : 'The passwords do not match.',
    }),
    onSubmit: async (values) => {
      const user = await usersApi.create(values)
      await queryClient.invalidateQueries({ queryKey: ['users'] })
      await queryClient.invalidateQueries({ queryKey: usersKeys.roles })
      toast.success('User added', `${user.name} can now sign in with ${user.email} and the password you set.`)
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!form.submitting}
      size="lg"
      title="Add a user"
      description="Create the account yourself and give them the starting password. They can change it under My account."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="add-user-form" loading={form.submitting}>
            Add user
          </Button>
        </>
      }
    >
      <form id="add-user-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Name" required error={form.errors.name}>
            <Input {...form.field('name')} autoComplete="off" data-autofocus />
          </FormField>
          <FormField label="Mobile number" hint="Optional" error={form.errors.phone}>
            <Input {...form.field('phone')} type="tel" inputMode="tel" autoComplete="off" placeholder="0917 123 4567" />
          </FormField>
        </div>
        <FormField label="E-mail address" required hint="Used to sign in" error={form.errors.email}>
          <Input {...form.field('email')} type="email" autoComplete="off" />
        </FormField>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Starting password" required error={form.errors.password}>
            <PasswordInput {...form.field('password')} autoComplete="new-password" />
          </FormField>
          <FormField label="Confirm password" required error={form.errors.password_confirmation}>
            <PasswordInput {...form.field('password_confirmation')} autoComplete="new-password" />
          </FormField>
        </div>
        <FormField label="Role" required error={form.errors.role_id}>
          <Select value={String(form.values.role_id)} onChange={(e) => form.setValue('role_id', e.target.value ? Number(e.target.value) : '')}>
            <option value="">{roles.isPending ? 'Loading…' : 'Choose a role…'}</option>
            {roles.data?.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
        </FormField>
        <BranchPicker value={form.values.branch_ids} onChange={(ids) => form.setValue('branch_ids', ids)} error={branchError(form.errors)} />
      </form>
    </Modal>
  )
}

export function EditUserModal({ user, isSelf, onClose }: { user: TeamUser; isSelf: boolean; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const roles = useAssignableRoles()
  const accessLocked = user.is_owner || isSelf

  const form = useForm<UserInput>({
    initialValues: { name: user.name, phone: user.phone ?? '', role_id: user.role?.id ?? '', branch_ids: user.branches.map((b) => b.id) },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter the name.',
      role_id: accessLocked || v.role_id ? undefined : 'Choose a role.',
      branch_ids: accessLocked || v.branch_ids.length > 0 ? undefined : 'Choose at least one branch.',
    }),
    onSubmit: async (values) => {
      // The owner's and your own access cannot be changed: send only contact details then.
      const payload = accessLocked ? { name: values.name, phone: values.phone } : values
      await usersApi.update(user.id, payload)
      await queryClient.invalidateQueries({ queryKey: ['users'] })
      await queryClient.invalidateQueries({ queryKey: usersKeys.roles })
      toast.success('User saved')
      onClose()
    },
  })

  // The current role may not be assignable by this user (e.g. a stronger role): keep it visible.
  const roleOptions = roles.data ?? []
  const currentMissing = user.role && !roleOptions.some((r) => r.id === user.role!.id)

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!form.submitting}
      title={`Edit ${user.name}`}
      description={user.email}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="user-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="user-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Name" required error={form.errors.name}>
            <Input {...form.field('name')} />
          </FormField>
          <FormField label="Mobile number" error={form.errors.phone}>
            <Input {...form.field('phone')} type="tel" placeholder="09XX XXX XXXX" />
          </FormField>
        </div>

        {accessLocked ? (
          <Alert tone="info">
            {user.is_owner ? 'The owner always has full access to every branch.' : 'You cannot change your own role or branches. Ask another administrator.'}
          </Alert>
        ) : (
          <>
            <FormField label="Role" required error={form.errors.role_id}>
              <Select value={String(form.values.role_id)} onChange={(e) => form.setValue('role_id', e.target.value ? Number(e.target.value) : '')}>
                {currentMissing && <option value={user.role!.id}>{user.role!.name}</option>}
                {roleOptions.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                  </option>
                ))}
              </Select>
            </FormField>
            <BranchPicker value={form.values.branch_ids} onChange={(ids) => form.setValue('branch_ids', ids)} error={branchError(form.errors)} />
          </>
        )}
      </form>
    </Modal>
  )
}
