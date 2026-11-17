import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { Link, type LinkProps } from 'react-router'
import { cn } from '@/lib/utils'
import { Spinner } from './Spinner'

export type ButtonVariant = 'primary' | 'outline' | 'secondary' | 'ghost' | 'danger' | 'link'
export type ButtonSize = 'sm' | 'md' | 'lg' | 'xl'

const variants: Record<ButtonVariant, string> = {
  // Solid primary with a coloured shadow.
  primary: 'bg-brand-500 text-white shadow-primary hover:bg-brand-600 active:bg-brand-700 disabled:opacity-60 disabled:shadow-none',
  // Primary outline (fills on hover).
  outline: 'border border-brand-500 text-brand-500 hover:bg-brand-500 hover:text-white hover:shadow-primary disabled:opacity-60',
  // Soft grey "label" button (Cancel, secondary actions).
  secondary: 'bg-[#ebeef0] text-secondary hover:bg-secondary hover:text-white disabled:opacity-60',
  ghost: 'text-ink-muted hover:bg-brand-500/10 hover:text-brand-500 active:bg-brand-500/15 disabled:text-ink-subtle',
  danger: 'bg-[#ff3e1d] text-white shadow-[0_2px_4px_0_rgb(255_62_29_/_0.4)] hover:bg-[#e6381a] disabled:opacity-60 disabled:shadow-none',
  link: 'text-brand-500 underline-offset-4 hover:underline disabled:text-ink-subtle px-0! h-auto!',
}

const sizes: Record<ButtonSize, string> = {
  sm: 'h-8 px-3.5 text-[0.8125rem] gap-1.5',
  md: 'h-[2.375rem] px-5 text-[0.9375rem] gap-2',
  lg: 'h-12 px-6 text-base gap-2',
  // Touch-friendly size for the POS screen.
  xl: 'h-14 px-7 text-lg gap-3',
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  loading?: boolean
  leftIcon?: ReactNode
  rightIcon?: ReactNode
  fullWidth?: boolean
}

function buttonClasses(variant: ButtonVariant, size: ButtonSize, fullWidth?: boolean, className?: string) {
  return cn(
    'inline-flex shrink-0 items-center justify-center rounded-md font-medium tracking-[0.01em] whitespace-nowrap transition-all duration-150 disabled:cursor-not-allowed',
    variants[variant],
    sizes[size],
    fullWidth && 'w-full',
    className,
  )
}

/** A router link that looks like a button (never nest <button> inside <a>). */
export function ButtonLink({
  variant = 'primary',
  size = 'md',
  fullWidth,
  className,
  leftIcon,
  rightIcon,
  children,
  ...props
}: LinkProps & { variant?: ButtonVariant; size?: ButtonSize; fullWidth?: boolean; leftIcon?: ReactNode; rightIcon?: ReactNode }) {
  return (
    <Link className={buttonClasses(variant, size, fullWidth, className)} {...props}>
      {leftIcon}
      {children}
      {rightIcon}
    </Link>
  )
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading = false, leftIcon, rightIcon, fullWidth, className, children, disabled, type = 'button', ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={buttonClasses(variant, size, fullWidth, className)}
      {...props}
    >
      {loading ? <Spinner size="sm" className="text-current" /> : leftIcon}
      {children}
      {!loading && rightIcon}
    </button>
  )
})

export interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Accessible name — required because the button has no visible text. */
  label: string
  size?: 'sm' | 'md'
}

export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
  { label, size = 'md', className, children, type = 'button', ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      aria-label={label}
      title={label}
      className={cn(
        'inline-flex items-center justify-center rounded-md text-ink-muted transition-colors hover:bg-brand-500/10 hover:text-brand-500 disabled:opacity-50',
        size === 'sm' ? 'size-8' : 'size-10',
        className,
      )}
      {...props}
    >
      {children}
    </button>
  )
})
