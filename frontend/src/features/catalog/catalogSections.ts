/** Pages of the Catalog sidebar group and who may open them (UI gate; the API enforces). */
export const CATALOG_SECTIONS = [
  { to: '/products', label: 'Products', permissions: ['products.view', 'products.manage'], end: true },
  { to: '/products/categories', label: 'Categories', permissions: ['products.manage'], end: false },
  { to: '/products/brands', label: 'Brands', permissions: ['products.manage'], end: false },
  { to: '/products/units', label: 'Units', permissions: ['products.manage'], end: false },
] as const
