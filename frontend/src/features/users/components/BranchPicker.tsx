import { useAccount } from '@/features/auth/useAuth'

interface BranchPickerProps {
  value: number[]
  onChange: (ids: number[]) => void
  error?: string
  disabled?: boolean
}

/**
 * Checkbox list of the branches the signed-in user can assign: the active
 * branches they have access to (from the session).
 */
export function BranchPicker({ value, onChange, error, disabled }: BranchPickerProps) {
  const { branches } = useAccount()
  const toggle = (id: number, checked: boolean) => onChange(checked ? [...value, id] : value.filter((v) => v !== id))

  return (
    <fieldset disabled={disabled}>
      <legend className="mb-1.5 text-[0.8125rem] font-medium tracking-[0.02em] text-ink uppercase">
        Branches<span className="ml-0.5 text-[#ff3e1d]" aria-hidden="true">*</span>
      </legend>
      <div className="grid gap-2 rounded-md border border-line p-3 sm:grid-cols-2" aria-invalid={Boolean(error) || undefined}>
        {branches.map((branch) => (
          <label key={branch.id} className="flex items-center gap-2 text-sm text-ink">
            <input
              type="checkbox"
              className="size-4 accent-brand-500"
              checked={value.includes(branch.id)}
              onChange={(e) => toggle(branch.id, e.target.checked)}
            />
            {branch.name}
            <span className="text-xs text-ink-subtle">{branch.code}</span>
          </label>
        ))}
      </div>
      {error && <p className="mt-1.5 text-[0.8125rem] text-[#ff3e1d]">{error}</p>}
      <p className="mt-1.5 text-sm text-ink-subtle">Roles with “Access all branches” can work in every branch regardless.</p>
    </fieldset>
  )
}
