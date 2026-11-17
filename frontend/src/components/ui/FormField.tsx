import {
  cloneElement,
  forwardRef,
  isValidElement,
  useId,
  useState,
  type InputHTMLAttributes,
  type ReactElement,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { cn } from '@/lib/utils'

/* -------------------------------------------------------------------------- */
/* FormField: label + control + hint/error, wired for accessibility            */
/* -------------------------------------------------------------------------- */

interface FormFieldProps {
  label?: ReactNode
  hint?: ReactNode
  error?: string
  required?: boolean
  className?: string
  /** Label row extra (e.g. "Forgot password?" link). */
  labelAction?: ReactNode
  children: ReactElement<{ id?: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string; required?: boolean }>
}

export function FormField({ label, hint, error, required, className, labelAction, children }: FormFieldProps) {
  const generatedId = useId()
  const id = children.props.id ?? generatedId
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined

  return (
    <div className={cn('space-y-1.5', className)}>
      {(label || labelAction) && (
        <div className="flex items-center justify-between gap-2">
          {label && (
            <label htmlFor={id} className="text-[0.8125rem] font-medium tracking-[0.02em] text-ink uppercase">
              {label}
              {required && <span className="ml-0.5 text-red-600" aria-hidden="true">*</span>}
            </label>
          )}
          {labelAction}
        </div>
      )}
      {isValidElement(children)
        ? cloneElement(children, { id, 'aria-invalid': Boolean(error) || undefined, 'aria-describedby': describedBy, required })
        : children}
      {error ? (
        <p id={errorId} className="text-[0.8125rem] text-[#ff3e1d]">
          {error}
        </p>
      ) : hint ? (
        <p id={hintId} className="text-[0.8125rem] text-ink-subtle">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

/* -------------------------------------------------------------------------- */
/* Controls                                                                    */
/* -------------------------------------------------------------------------- */

const controlBase =
  'block w-full rounded-md border bg-surface text-[0.9375rem] text-ink-muted transition-[border-color,box-shadow] placeholder:text-ink-subtle ' +
  'focus:outline-none focus:shadow-focus disabled:cursor-not-allowed disabled:bg-[#eceef1] disabled:text-ink-subtle ' +
  'aria-invalid:border-[#ff3e1d] aria-invalid:focus:border-[#ff3e1d]'

const controlBorder = 'border-line focus:border-brand-500'

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  leftAddon?: ReactNode
  rightAddon?: ReactNode
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ className, leftAddon, rightAddon, ...props }, ref) {
  if (!leftAddon && !rightAddon) {
    return <input ref={ref} className={cn(controlBase, controlBorder, 'h-[2.375rem] px-3.5', className)} {...props} />
  }

  return (
    <div className="relative flex items-stretch">
      {leftAddon && (
        <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-ink-subtle">{leftAddon}</span>
      )}
      <input
        ref={ref}
        className={cn(controlBase, controlBorder, 'h-[2.375rem] px-3.5', leftAddon ? 'pl-10' : '', rightAddon ? 'pr-10' : '', className)}
        {...props}
      />
      {rightAddon && <span className="absolute inset-y-0 right-0 flex items-center pr-1">{rightAddon}</span>}
    </div>
  )
})

/** Text input with a fixed suffix box, e.g. subdomain + ".avantpos.ph". */
export const SuffixInput = forwardRef<HTMLInputElement, InputProps & { suffix: string }>(function SuffixInput(
  { suffix, className, ...props },
  ref,
) {
  return (
    <div className="flex">
      <input ref={ref} className={cn(controlBase, controlBorder, 'h-[2.375rem] rounded-r-none px-3', className)} {...props} />
      <span className="inline-flex items-center rounded-r-md border border-l-0 border-line bg-canvas px-3 text-sm text-ink-subtle">
        {suffix}
      </span>
    </div>
  )
})

export const PasswordInput = forwardRef<HTMLInputElement, Omit<InputProps, 'type' | 'rightAddon'>>(function PasswordInput(props, ref) {
  const [visible, setVisible] = useState(false)

  return (
    <Input
      ref={ref}
      type={visible ? 'text' : 'password'}
      rightAddon={
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          className="rounded-md p-2 text-ink-subtle hover:text-ink"
          aria-label={visible ? 'Hide password' : 'Show password'}
          aria-pressed={visible}
        >
          {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
        </button>
      }
      {...props}
    />
  )
})

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(function Select({ className, children, ...props }, ref) {
  return (
    <select ref={ref} className={cn(controlBase, controlBorder, 'h-[2.375rem] pr-8 pl-3.5', className)} {...props}>
      {children}
    </select>
  )
})

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea({ className, rows = 3, ...props }, ref) {
  return <textarea ref={ref} rows={rows} className={cn(controlBase, controlBorder, 'px-3.5 py-2', className)} {...props} />
})

interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: ReactNode
  description?: ReactNode
  error?: string
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox({ label, description, error, className, id, ...props }, ref) {
  const generatedId = useId()
  const inputId = id ?? generatedId

  return (
    <div className={className}>
      <div className="flex items-start gap-3">
        <input
          ref={ref}
          id={inputId}
          type="checkbox"
          aria-invalid={Boolean(error) || undefined}
          aria-describedby={error ? `${inputId}-error` : undefined}
          className="mt-0.5 size-[1.1rem] shrink-0 rounded accent-brand-500"
          {...props}
        />
        <label htmlFor={inputId} className="text-sm text-ink">
          {label}
          {description && <span className="mt-0.5 block text-ink-subtle">{description}</span>}
        </label>
      </div>
      {error && (
        <p id={`${inputId}-error`} className="mt-1.5 text-[0.8125rem] text-[#ff3e1d]">
          {error}
        </p>
      )}
    </div>
  )
})
