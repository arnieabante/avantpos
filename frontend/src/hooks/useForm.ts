import { useCallback, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { ApiError } from '@/lib/api/errors'

type Values = object
/** Field errors: client checks use form keys; server errors may name any field. */
type Errors<T> = Partial<Record<(keyof T & string) | (string & {}), string>>

interface UseFormOptions<T extends Values> {
  initialValues: T
  /** Optional instant client-side checks; the server remains the source of truth. */
  validate?: (values: T) => Errors<T>
  onSubmit: (values: T) => Promise<void> | void
}

/**
 * Small controlled-form helper:
 *  - `field('email')` binds value/onChange/error to an input,
 *  - Laravel 422 responses are mapped onto fields automatically,
 *  - any other ApiError is exposed as `formError` (shown in an Alert).
 */
export function useForm<T extends Values>({ initialValues, validate, onSubmit }: UseFormOptions<T>) {
  const [values, setValues] = useState<T>(initialValues)
  const [errors, setErrors] = useState<Errors<T>>({})
  const [formError, setFormError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const submittingRef = useRef(false)
  const initialRef = useRef(initialValues)

  const setValue = useCallback(<K extends keyof T>(name: K, value: T[K]) => {
    setValues((current) => ({ ...current, [name]: value }))
    setErrors((current) => (current[name as keyof T & string] ? { ...current, [name]: undefined } : current))
  }, [])

  const field = useCallback(
    <K extends keyof T & string>(name: K) => ({
      name,
      value: String(values[name] ?? ''),
      onChange: (event: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
        setValue(name, event.target.value as T[K]),
    }),
    [values, setValue],
  )

  const checkbox = useCallback(
    <K extends keyof T & string>(name: K) => ({
      name,
      checked: Boolean(values[name]),
      onChange: (event: ChangeEvent<HTMLInputElement>) => setValue(name, event.target.checked as T[K]),
    }),
    [values, setValue],
  )

  const handleSubmit = useCallback(
    async (event?: FormEvent) => {
      event?.preventDefault()
      if (submittingRef.current) return

      setFormError(null)
      const clientErrors = validate?.(values) ?? {}
      if (Object.values(clientErrors).some(Boolean)) {
        setErrors(clientErrors)
        return
      }

      submittingRef.current = true
      setSubmitting(true)
      try {
        await onSubmit(values)
      } catch (error) {
        const apiError = ApiError.from(error)
        if (apiError.isValidation && Object.keys(apiError.errors).length > 0) {
          const fieldErrors: Record<string, string> = {}
          for (const [key, messages] of Object.entries(apiError.errors)) {
            if (messages[0]) fieldErrors[key] = messages[0]
          }
          setErrors(fieldErrors as Errors<T>)
          // Surface errors for fields the form does not render (defensive).
          const unknown = Object.keys(fieldErrors).filter((key) => !(key in values))
          if (unknown.length > 0) setFormError(apiError)
        } else {
          setFormError(apiError)
        }
      } finally {
        submittingRef.current = false
        setSubmitting(false)
      }
    },
    [values, validate, onSubmit],
  )

  const reset = useCallback((next?: T) => {
    setValues(next ?? initialRef.current)
    setErrors({})
    setFormError(null)
  }, [])

  return { values, setValue, errors, setErrors, formError, setFormError, submitting, field, checkbox, handleSubmit, reset }
}
