import { api } from '@/lib/api/client'
import type { Brand, BranchPrice, Category, Product, ProductListItem, ProductType, TaxClass, Unit, Variant } from './types'

const V1 = '/api/v1'

export interface ProductFilters {
  page?: number
  search?: string
  category_id?: number
  brand_id?: number
  status?: 'active' | 'inactive'
  type?: ProductType
}

/** Product document as sent to POST/PUT /products. Money stays a decimal string. */
export interface UnitInput {
  unit_id: number | ''
  conversion_factor: string
  selling_price: string
  wholesale_price: string
  is_sellable: boolean
  is_purchasable: boolean
  barcodes: string[]
}

export interface VariantInput {
  id?: number
  name: string
  sku: string
  cost_price?: string
  selling_price: string
  wholesale_price: string
  reorder_level: string
  is_active: boolean
  barcodes: string[]
  units: UnitInput[]
}

export interface ProductInput {
  name: string
  description: string
  category_id: number | ''
  brand_id: number | ''
  base_unit_id: number | ''
  type: ProductType
  tax_class: TaxClass
  sc_pwd_eligible: boolean
  track_inventory: boolean
  track_expiry: boolean
  allow_negative_stock: boolean
  meta: { generic_name: string; requires_prescription: boolean }
  variants: VariantInput[]
}

export const catalogKeys = {
  categories: ['product-categories'] as const,
  brands: ['brands'] as const,
  units: ['units'] as const,
  products: (filters: ProductFilters) => ['products', filters] as const,
  product: (id: number) => ['products', 'detail', id] as const,
}

const blankToNull = (v: string | number | '' | undefined) => (v === '' || v === undefined ? null : v)

/** Converts the form document to the API payload ("" → null, numbers as strings). */
export function toPayload(input: ProductInput, canSeeCost: boolean) {
  return {
    ...input,
    description: input.description.trim() || null,
    category_id: blankToNull(input.category_id),
    brand_id: blankToNull(input.brand_id),
    base_unit_id: blankToNull(input.base_unit_id),
    meta: { generic_name: input.meta.generic_name.trim() || null, requires_prescription: input.meta.requires_prescription || null },
    variants: input.variants.map((v) => {
      const { cost_price, ...rest } = v
      return {
        ...rest,
        // Users without products.view_cost may not send costs at all.
        ...(canSeeCost ? { cost_price: blankToNull(cost_price) } : {}),
        name: v.name.trim() || null,
        sku: v.sku.trim() || null,
        wholesale_price: blankToNull(v.wholesale_price),
        reorder_level: blankToNull(v.reorder_level),
        units: v.units.map((u) => ({
          ...u,
          unit_id: blankToNull(u.unit_id),
          selling_price: blankToNull(u.selling_price),
          wholesale_price: blankToNull(u.wholesale_price),
        })),
      }
    }),
  }
}

function clean<T extends object>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== null)) as Partial<T>
}

export const catalogApi = {
  /* Setup lists */
  categories: () => api.get<Category[]>(`${V1}/product-categories`),
  saveCategory: (data: { name: string; parent_id: number | null; color: string | null; sort_order: number; is_active: boolean }, id?: number) =>
    id ? api.put<Category>(`${V1}/product-categories/${id}`, data) : api.post<Category>(`${V1}/product-categories`, data),
  deleteCategory: (id: number) => api.delete(`${V1}/product-categories/${id}`),

  brands: () => api.get<Brand[]>(`${V1}/brands`),
  saveBrand: (name: string, id?: number) => (id ? api.put<Brand>(`${V1}/brands/${id}`, { name }) : api.post<Brand>(`${V1}/brands`, { name })),
  deleteBrand: (id: number) => api.delete(`${V1}/brands/${id}`),

  units: () => api.get<Unit[]>(`${V1}/units`),
  saveUnit: (data: { name: string; abbreviation: string; allows_decimal: boolean }, id?: number) =>
    id ? api.put<Unit>(`${V1}/units/${id}`, data) : api.post<Unit>(`${V1}/units`, data),
  deleteUnit: (id: number) => api.delete(`${V1}/units/${id}`),

  /* Products */
  products: (filters: ProductFilters) => api.getPage<ProductListItem>(`${V1}/products`, clean({ per_page: 25, ...filters })),
  product: (id: number) => api.get<Product>(`${V1}/products/${id}`),
  createProduct: (input: ProductInput, canSeeCost: boolean) => api.post<Product>(`${V1}/products`, toPayload(input, canSeeCost)),
  updateProduct: (id: number, input: ProductInput, canSeeCost: boolean) => api.put<Product>(`${V1}/products/${id}`, toPayload(input, canSeeCost)),
  productAction: (id: number, action: 'activate' | 'deactivate') => api.post<Product>(`${V1}/products/${id}/${action}`),
  deleteProduct: (id: number) => api.delete(`${V1}/products/${id}`),
  uploadImage: (id: number, file: File) => {
    const body = new FormData()
    body.append('image', file)
    return api.post<Product>(`${V1}/products/${id}/image`, body)
  },
  removeImage: (id: number) => api.send('delete', `${V1}/products/${id}/image`),
  setBranchPrices: (variantId: number, prices: BranchPrice[]) =>
    api.put<Variant>(`${V1}/product-variants/${variantId}/branch-prices`, {
      prices: prices.map((p) => ({ ...p, wholesale_price: p.wholesale_price || null })),
    }),
}
