import { useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { ArrowLeft, ImagePlus, Lock, Plus, Store, Trash2, X } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  CardBody,
  CardHeader,
  Checkbox,
  FormField,
  Input,
  PageHeader,
  PageLoader,
  Select,
  Textarea,
  useConfirm,
  useToast,
} from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { catalogApi, catalogKeys, type ProductInput, type UnitInput, type VariantInput } from '../api'
import { BarcodeInput } from '../components/BarcodeInput'
import { BranchPricesModal } from '../components/BranchPricesModal'
import { emptyProduct, emptyUnit, emptyVariant, fromProduct, validateProduct } from '../productForm'
import { TAX_CLASS_LABELS, type Category, type Product, type TaxClass, type Unit, type Variant } from '../types'

const MAX_IMAGE_BYTES = 4 * 1024 * 1024

/** /products/new and /products/:productId */
export default function ProductFormPage() {
  const { productId } = useParams()
  const isNew = productId === 'new'
  const id = Number(productId)

  const units = useQuery({ queryKey: catalogKeys.units, queryFn: catalogApi.units, staleTime: 60_000 })
  const categories = useQuery({ queryKey: catalogKeys.categories, queryFn: catalogApi.categories, staleTime: 60_000 })
  const brands = useQuery({ queryKey: catalogKeys.brands, queryFn: catalogApi.brands, staleTime: 60_000 })
  const product = useQuery({ queryKey: catalogKeys.product(id), queryFn: () => catalogApi.product(id), enabled: !isNew && Number.isFinite(id) })

  const loading = units.isPending || categories.isPending || brands.isPending || (!isNew && product.isPending)
  if (loading) return <PageLoader />

  const failed = units.error ?? categories.error ?? brands.error ?? product.error
  if (failed) return <ApiErrorAlert error={ApiError.from(failed)} />

  return (
    <ProductForm
      key={product.data?.updated_at ?? 'new'}
      product={isNew ? null : (product.data ?? null)}
      units={units.data ?? []}
      categories={categories.data ?? []}
      brands={brands.data ?? []}
    />
  )
}

interface FormProps {
  product: Product | null
  units: Unit[]
  categories: Category[]
  brands: Array<{ id: number; name: string }>
}

function ProductForm({ product, units, categories, brands }: FormProps) {
  const account = useAccount()
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const canSeeCost = can('products.view_cost')
  const canChangePrices = can('prices.manage')
  const features = account.subscription?.plan.features ?? {}
  const isPharmacy = account.tenant.business_type === 'pharmacy'
  const [hasVariants, setHasVariants] = useState(() => (product?.variants.length ?? 0) > 1)
  const [pendingImage, setPendingImage] = useState<File | null>(null)
  const [branchPricesFor, setBranchPricesFor] = useState<Variant | null>(null)

  const defaultUnit = units.find((u) => u.abbreviation === 'pc')?.id ?? units[0]?.id ?? ''

  const form = useForm<ProductInput>({
    initialValues: product ? fromProduct(product) : emptyProduct(defaultUnit),
    validate: validateProduct,
    onSubmit: async (values) => {
      if (product) {
        const saved = await catalogApi.updateProduct(product.id, values, canSeeCost)
        queryClient.setQueryData(catalogKeys.product(saved.id), saved)
        await queryClient.invalidateQueries({ queryKey: ['products'], exact: false, refetchType: 'none' })
        toast.success('Product saved')
      } else {
        let created = await catalogApi.createProduct(values, canSeeCost)
        if (pendingImage) {
          try {
            created = await catalogApi.uploadImage(created.id, pendingImage)
          } catch (error) {
            toast.warning('Product saved, but the image could not be uploaded', ApiError.from(error).message)
          }
        }
        await queryClient.invalidateQueries({ queryKey: ['products'] })
        // Open the saved product without fetching it again.
        queryClient.setQueryData(catalogKeys.product(created.id), created)
        toast.success('Product created')
        navigate(`/products/${created.id}`, { replace: true })
      }
    },
  })

  const v = form.values
  const err = (path: string) => form.errors[path as keyof typeof form.errors]
  const setVariants = (variants: VariantInput[]) => form.setValue('variants', variants)
  const updateVariant = (index: number, patch: Partial<VariantInput>) =>
    setVariants(v.variants.map((variant, i) => (i === index ? { ...variant, ...patch } : variant)))

  const baseUnit = units.find((u) => u.id === v.base_unit_id)
  const isService = v.type === 'service'
  const topLevel = categories.filter((c) => c.parent_id === null)

  async function toggleVariants(on: boolean) {
    if (on) {
      setHasVariants(true)
      const first = v.variants[0] ?? emptyVariant()
      setVariants([{ ...first, name: first.name || 'Regular' }, ...v.variants.slice(1)])
      return
    }
    if (v.variants.length > 1) {
      const ok = await confirm({
        title: 'Keep only the first variant?',
        description: 'The other variants will be removed when you save. Variants with stock or sales history cannot be removed.',
        confirmLabel: 'Keep first only',
        danger: true,
      })
      if (!ok) return
    }
    setHasVariants(false)
    setVariants([{ ...v.variants[0]!, name: '' }])
  }

  async function changeStatus(action: 'activate' | 'deactivate' | 'delete') {
    if (!product) return
    if (action === 'delete') {
      const ok = await confirm({
        title: `Delete ${product.name}?`,
        description: 'Only products that were never stocked, bought or sold can be deleted. Otherwise deactivate it.',
        confirmLabel: 'Delete product',
        danger: true,
      })
      if (!ok) return
    }
    try {
      if (action === 'delete') {
        await catalogApi.deleteProduct(product.id)
        await queryClient.invalidateQueries({ queryKey: ['products'] })
        toast.success('Product deleted')
        navigate('/products', { replace: true })
        return
      }
      const saved = await catalogApi.productAction(product.id, action)
      queryClient.setQueryData(catalogKeys.product(saved.id), saved)
      toast.success(action === 'activate' ? 'Product activated' : 'Product deactivated')
    } catch (error) {
      toast.error(ApiError.from(error).message)
    }
  }

  return (
    <form onSubmit={form.handleSubmit} noValidate className="space-y-6">
      <ButtonLink to="/products" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Back to products
      </ButtonLink>

      <PageHeader
        title={product ? product.name : 'New product'}
        description={product ? (product.is_active ? undefined : 'This product is inactive and cannot be sold.') : 'Add an item you sell or a service you offer.'}
        actions={
          product && (
            <>
              {product.is_active ? (
                <Button variant="secondary" onClick={() => changeStatus('deactivate')}>
                  Deactivate
                </Button>
              ) : (
                <Button variant="outline" onClick={() => changeStatus('activate')}>
                  Activate
                </Button>
              )}
              <Button variant="ghost" className="text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" leftIcon={<Trash2 className="size-4" />} onClick={() => changeStatus('delete')}>
                Delete
              </Button>
            </>
          )
        }
      />

      <ApiErrorAlert error={form.formError} />

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          {/* -------------------------------------------------- Details */}
          <Card>
            <CardHeader title="Product details" />
            <CardBody className="grid gap-4 sm:grid-cols-2">
              <FormField label="Product name" required error={err('name')} className="sm:col-span-2">
                <Input {...form.field('name')} placeholder="e.g. Coke Mismo 290ml" />
              </FormField>
              <FormField label="Category" error={err('category_id')}>
                <Select value={String(v.category_id)} onChange={(e) => form.setValue('category_id', e.target.value ? Number(e.target.value) : '')}>
                  <option value="">No category</option>
                  {topLevel.map((parent) => [
                    <option key={parent.id} value={parent.id}>
                      {parent.name}
                    </option>,
                    ...categories
                      .filter((c) => c.parent_id === parent.id)
                      .map((child) => (
                        <option key={child.id} value={child.id}>
                          {'  — '}
                          {child.name}
                        </option>
                      )),
                  ])}
                </Select>
              </FormField>
              <FormField label="Brand" error={err('brand_id')}>
                <Select value={String(v.brand_id)} onChange={(e) => form.setValue('brand_id', e.target.value ? Number(e.target.value) : '')}>
                  <option value="">No brand</option>
                  {brands.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </Select>
              </FormField>
              <FormField label="Description" hint="Optional" error={err('description')} className="sm:col-span-2">
                <Textarea {...form.field('description')} rows={2} />
              </FormField>
              {isPharmacy && (
                <>
                  <FormField label="Generic name" error={err('meta.generic_name')}>
                    <Input value={v.meta.generic_name} onChange={(e) => form.setValue('meta', { ...v.meta, generic_name: e.target.value })} />
                  </FormField>
                  <Checkbox
                    className="self-end pb-2"
                    label="Requires a prescription"
                    checked={v.meta.requires_prescription}
                    onChange={(e) => form.setValue('meta', { ...v.meta, requires_prescription: e.target.checked })}
                  />
                </>
              )}
            </CardBody>
          </Card>

          {/* -------------------------------------------------- Variants & prices */}
          <Card>
            <CardHeader
              title="Prices and codes"
              description={hasVariants ? 'Each variant has its own SKU, barcodes, prices and stock.' : undefined}
              actions={
                <Checkbox
                  label="Has variants (sizes, colours…)"
                  checked={hasVariants}
                  onChange={(e) => void toggleVariants(e.target.checked)}
                />
              }
            />
            <CardBody className="space-y-4">
              {!canChangePrices && product && (
                <Alert tone="info">You can edit this product, but changing existing prices needs the “Change selling prices” permission.</Alert>
              )}
              {v.variants.map((variant, i) => (
                <VariantEditor
                  key={variant.id ?? `new-${i}`}
                  index={i}
                  variant={variant}
                  saved={product?.variants.find((sv) => sv.id === variant.id) ?? null}
                  showName={hasVariants}
                  canRemove={hasVariants && v.variants.length > 1}
                  canSeeCost={canSeeCost}
                  pricesLocked={Boolean(variant.id) && !canChangePrices}
                  units={units}
                  baseUnit={baseUnit}
                  unitsAllowed={!isService && Boolean(features.unit_conversions)}
                  branchPricesAllowed={canChangePrices && account.branches.length > 1}
                  err={err}
                  onChange={(patch) => updateVariant(i, patch)}
                  onRemove={() => setVariants(v.variants.filter((_, j) => j !== i))}
                  onBranchPrices={setBranchPricesFor}
                />
              ))}
              {hasVariants && (
                <Button variant="outline" size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setVariants([...v.variants, emptyVariant()])}>
                  Add variant
                </Button>
              )}
            </CardBody>
          </Card>
        </div>

        <div className="space-y-6">
          <ImageCard product={product} pending={pendingImage} onPending={setPendingImage} />

          {/* -------------------------------------------------- Settings */}
          <Card>
            <CardHeader title="Selling and stock" />
            <CardBody className="space-y-4">
              <FormField label="Type" error={err('type')}>
                <Select {...form.field('type')}>
                  <option value="standard">Product (has stock)</option>
                  <option value="service">Service (no stock)</option>
                </Select>
              </FormField>
              <FormField label="Base unit" required hint="The unit stock is counted in." error={err('base_unit_id')}>
                <Select value={String(v.base_unit_id)} onChange={(e) => form.setValue('base_unit_id', e.target.value ? Number(e.target.value) : '')}>
                  <option value="">Choose…</option>
                  {units.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.name} ({u.abbreviation})
                    </option>
                  ))}
                </Select>
              </FormField>
              <FormField label="Tax" error={err('tax_class')}>
                <Select value={v.tax_class} onChange={(e) => form.setValue('tax_class', e.target.value as TaxClass)}>
                  {Object.entries(TAX_CLASS_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </Select>
              </FormField>
              <div className="space-y-3 pt-1">
                <Checkbox
                  {...form.checkbox('sc_pwd_eligible')}
                  label="Senior Citizen / PWD discount applies"
                  description="Turn off for items not covered by the 20% discount."
                />
                {!isService && (
                  <>
                    <Checkbox {...form.checkbox('track_inventory')} label="Track stock" description="Count stock per branch for this product." />
                    {v.track_inventory && <Checkbox {...form.checkbox('allow_negative_stock')} label="Allow selling when out of stock" />}
                    {features.batch_expiry ? (
                      v.track_inventory && <Checkbox {...form.checkbox('track_expiry')} label="Track batches and expiry dates" description="Oldest-expiring stock is sold first." />
                    ) : (
                      <p className="flex items-center gap-1.5 text-[0.8125rem] text-ink-subtle">
                        <Lock className="size-3.5" aria-hidden="true" /> Batch and expiry tracking is available on higher plans.
                      </p>
                    )}
                  </>
                )}
              </div>
            </CardBody>
          </Card>
        </div>
      </div>

      <div className="sticky bottom-0 z-20 -mx-4 flex justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
        <ButtonLink to="/products" variant="secondary">
          Cancel
        </ButtonLink>
        <Button type="submit" loading={form.submitting}>
          {product ? 'Save product' : 'Create product'}
        </Button>
      </div>

      {branchPricesFor && <BranchPricesModal variant={branchPricesFor} productId={product!.id} onClose={() => setBranchPricesFor(null)} />}
    </form>
  )
}

/* ------------------------------------------------------------------ Variant */

interface VariantEditorProps {
  index: number
  variant: VariantInput
  saved: Variant | null
  showName: boolean
  canRemove: boolean
  canSeeCost: boolean
  pricesLocked: boolean
  units: Unit[]
  baseUnit: Unit | undefined
  unitsAllowed: boolean
  branchPricesAllowed: boolean
  err: (path: string) => string | undefined
  onChange: (patch: Partial<VariantInput>) => void
  onRemove: () => void
  onBranchPrices: (variant: Variant) => void
}

function VariantEditor(props: VariantEditorProps) {
  const { index: i, variant, saved, showName, canRemove, canSeeCost, pricesLocked, units, baseUnit, unitsAllowed, err, onChange } = props
  const p = `variants.${i}`
  const priceLock = pricesLocked ? <Lock className="size-3.5 text-ink-subtle" aria-label="Locked" /> : undefined
  const branchOverrides = saved?.branch_prices?.length ?? 0

  const updateUnit = (u: number, patch: Partial<UnitInput>) => onChange({ units: variant.units.map((unit, j) => (j === u ? { ...unit, ...patch } : unit)) })

  return (
    <div className={showName ? 'rounded-lg border border-line-soft p-4' : ''}>
      {showName && (
        <div className="mb-4 flex items-start gap-3">
          <FormField label={`Variant ${i + 1}`} required error={err(`${p}.name`)} className="flex-1">
            <Input value={variant.name} onChange={(e) => onChange({ name: e.target.value })} placeholder="e.g. Small, Red / XL" />
          </FormField>
          <Checkbox className="mt-8" label="Active" checked={variant.is_active} onChange={(e) => onChange({ is_active: e.target.checked })} />
          {canRemove && (
            <button type="button" onClick={props.onRemove} className="mt-7 rounded-md p-2 text-ink-subtle hover:bg-[#ffe0db] hover:text-[#ff3e1d]" aria-label={`Remove variant ${i + 1}`}>
              <X className="size-4" />
            </button>
          )}
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <FormField label="SKU" hint={variant.id ? undefined : 'Leave blank to generate'} error={err(`${p}.sku`)}>
          <Input value={variant.sku} onChange={(e) => onChange({ sku: e.target.value.toUpperCase() })} maxLength={60} />
        </FormField>
        <FormField label="Selling price" required error={err(`${p}.selling_price`)}>
          <Input value={variant.selling_price} onChange={(e) => onChange({ selling_price: e.target.value })} inputMode="decimal" leftAddon="₱" rightAddon={priceLock && <span className="pr-3">{priceLock}</span>} disabled={pricesLocked} />
        </FormField>
        <FormField label="Wholesale price" hint="Optional" error={err(`${p}.wholesale_price`)}>
          <Input value={variant.wholesale_price} onChange={(e) => onChange({ wholesale_price: e.target.value })} inputMode="decimal" leftAddon="₱" disabled={pricesLocked} />
        </FormField>
        {canSeeCost ? (
          <FormField label="Cost" hint="Updated by goods receipts" error={err(`${p}.cost_price`)}>
            <Input value={variant.cost_price ?? ''} onChange={(e) => onChange({ cost_price: e.target.value })} inputMode="decimal" leftAddon="₱" />
          </FormField>
        ) : (
          <div />
        )}
        <FormField label="Barcodes" error={err(`${p}.barcodes`) ?? firstError(err, `${p}.barcodes`)} className="sm:col-span-2 lg:col-span-3">
          <BarcodeInput value={variant.barcodes} onChange={(barcodes) => onChange({ barcodes })} invalid={Boolean(firstError(err, `${p}.barcodes`))} />
        </FormField>
        <FormField label="Reorder level" hint={baseUnit ? `In ${baseUnit.name.toLowerCase()}s` : undefined} error={err(`${p}.reorder_level`)}>
          <Input value={variant.reorder_level} onChange={(e) => onChange({ reorder_level: e.target.value })} inputMode="decimal" />
        </FormField>
      </div>

      {saved && props.branchPricesAllowed && (
        <div className="mt-3 flex items-center gap-2">
          <Button size="sm" variant="ghost" leftIcon={<Store className="size-4" />} onClick={() => props.onBranchPrices(saved)}>
            Branch prices
          </Button>
          {branchOverrides > 0 && <Badge tone="brand">{branchOverrides} override{branchOverrides === 1 ? '' : 's'}</Badge>}
        </div>
      )}

      {/* Selling units */}
      {unitsAllowed ? (
        <div className="mt-5">
          <p className="mb-2 text-[0.8125rem] font-medium tracking-[0.02em] text-ink uppercase">Other selling units</p>
          {variant.units.length === 0 && (
            <p className="mb-2 text-sm text-ink-subtle">
              Sell or buy this item by the pack, box, dozen or meter too, each with its own price and barcode.
            </p>
          )}
          <div className="space-y-3">
            {variant.units.map((unit, u) => {
              const up = `${p}.units.${u}`
              return (
                <div key={u} className="grid gap-3 rounded-md bg-canvas p-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                  <FormField label="Unit" error={err(`${up}.unit_id`)}>
                    <Select value={String(unit.unit_id)} onChange={(e) => updateUnit(u, { unit_id: e.target.value ? Number(e.target.value) : '' })}>
                      <option value="">Choose…</option>
                      {units.filter((x) => x.id !== baseUnit?.id).map((x) => (
                        <option key={x.id} value={x.id}>
                          {x.name}
                        </option>
                      ))}
                    </Select>
                  </FormField>
                  <FormField label={`${baseUnit?.abbreviation ?? 'Base'} per unit`} error={err(`${up}.conversion_factor`)}>
                    <Input value={unit.conversion_factor} onChange={(e) => updateUnit(u, { conversion_factor: e.target.value })} inputMode="decimal" placeholder="e.g. 12" />
                  </FormField>
                  <FormField label="Price" hint="Blank = base price × quantity" error={err(`${up}.selling_price`)}>
                    <Input value={unit.selling_price} onChange={(e) => updateUnit(u, { selling_price: e.target.value })} inputMode="decimal" leftAddon="₱" disabled={pricesLocked} />
                  </FormField>
                  <FormField label="Barcodes" error={firstError(err, `${up}.barcodes`)}>
                    <BarcodeInput value={unit.barcodes} max={5} onChange={(barcodes) => updateUnit(u, { barcodes })} invalid={Boolean(firstError(err, `${up}.barcodes`))} />
                  </FormField>
                  <button
                    type="button"
                    onClick={() => onChange({ units: variant.units.filter((_, j) => j !== u) })}
                    className="self-start rounded-md p-2 text-ink-subtle hover:bg-[#ffe0db] hover:text-[#ff3e1d] lg:mt-7"
                    aria-label="Remove selling unit"
                  >
                    <X className="size-4" />
                  </button>
                </div>
              )
            })}
          </div>
          <Button size="sm" variant="ghost" className="mt-2" leftIcon={<Plus className="size-4" />} onClick={() => onChange({ units: [...variant.units, emptyUnit()] })}>
            Add selling unit
          </Button>
        </div>
      ) : null}
    </div>
  )
}

/** First error among "path.0", "path.1"... (barcode lists). */
function firstError(err: (path: string) => string | undefined, prefix: string): string | undefined {
  for (let i = 0; i < 10; i++) {
    const message = err(`${prefix}.${i}`)
    if (message) return message
  }
  return undefined
}

/* ------------------------------------------------------------------ Image */

function ImageCard({ product, pending, onPending }: { product: Product | null; pending: File | null; onPending: (file: File | null) => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const preview = pending ? URL.createObjectURL(pending) : product?.image_url

  async function choose(file: File) {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) return toast.error('Use a PNG, JPG or WebP image.')
    if (file.size > MAX_IMAGE_BYTES) return toast.error('The image must be 4 MB or smaller.')
    if (!product) return onPending(file) // uploaded right after the product is created

    setBusy(true)
    try {
      queryClient.setQueryData(catalogKeys.product(product.id), await catalogApi.uploadImage(product.id, file))
      toast.success('Image updated')
    } catch (error) {
      toast.error(ApiError.from(error).message)
    } finally {
      setBusy(false)
    }
  }

  async function remove() {
    if (!product) return onPending(null)
    setBusy(true)
    try {
      await catalogApi.removeImage(product.id)
      await queryClient.invalidateQueries({ queryKey: catalogKeys.product(product.id) })
    } catch (error) {
      toast.error(ApiError.from(error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardBody>
        <div className="flex aspect-square items-center justify-center overflow-hidden rounded-lg border border-dashed border-line bg-canvas">
          {preview ? <img src={preview} alt="Product" className="size-full object-contain" /> : <ImagePlus className="size-10 text-line" aria-hidden="true" />}
        </div>
        <input
          ref={input}
          type="file"
          accept="image/png,image/jpeg,image/webp"
          className="hidden"
          aria-label="Product image"
          onChange={(e) => {
            const file = e.target.files?.[0]
            e.target.value = ''
            if (file) void choose(file)
          }}
        />
        <div className="mt-3 flex flex-wrap gap-2">
          <Button variant="outline" size="sm" className="min-w-0 flex-1" loading={busy} onClick={() => input.current?.click()}>
            {preview ? 'Change image' : 'Add image'}
          </Button>
          {preview && (
            <Button variant="secondary" size="sm" className="shrink-0" onClick={remove} disabled={busy}>
              Remove
            </Button>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
