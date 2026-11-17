import { Navigate, Outlet, createBrowserRouter, type RouteObject } from 'react-router'
import { AppLayout } from '@/components/layout/AppLayout'
import { AdminAuthProvider, AdminGuestOnly, RequireAdmin } from '@/features/admin/AdminAuth'
import { AdminLayout } from '@/features/admin/AdminLayout'
import { AuthLayout } from '@/features/auth/AuthLayout'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { GuestOnly, RequireAuth, RequirePermission } from '@/features/auth/guards'
import { SettingsIndex, SettingsLayout } from '@/features/settings/SettingsLayout'
import { REPORT_PERMISSIONS } from '@/features/reports/permissions'
const EXPENSE_PERMISSIONS = ['expenses.view', 'expenses.create', 'expenses.approve']
import { SETTINGS_PAGES } from '@/features/settings/settingsPages'
import { UsersLayout } from '@/features/users/UsersLayout'
import { NotFoundPage, RouteErrorPage } from './ErrorPages'

/** Code-split page: loaded on first visit. */
const page = (load: () => Promise<{ default: React.ComponentType }>) => async () => ({ Component: (await load()).default })

/** Code-split page shown only to users with any of the given permissions (UI gate; the API enforces). */
const guardedPage = (permissions: string[], load: () => Promise<{ default: React.ComponentType }>) => async () => {
  const Page = (await load()).default
  return {
    Component: () => (
      <RequirePermission permission={permissions}>
        <Page />
      </RequirePermission>
    ),
  }
}

const settingsPermissions = (to: string) => SETTINGS_PAGES.find((p) => p.to === to)?.permissions ?? []

const settingsRoutes: RouteObject[] = [
  { index: true, element: <SettingsIndex /> },
  { path: 'business', lazy: guardedPage(settingsPermissions('business'), () => import('@/features/settings/pages/BusinessProfilePage')) },
  { path: 'branches', lazy: guardedPage(settingsPermissions('branches'), () => import('@/features/settings/pages/BranchesPage')) },
  { path: 'terminals', lazy: guardedPage(settingsPermissions('terminals'), () => import('@/features/settings/pages/TerminalsPage')) },
  { path: 'taxes', lazy: guardedPage(settingsPermissions('taxes'), () => import('@/features/settings/pages/TaxRatesPage')) },
  { path: 'payment-methods', lazy: guardedPage(settingsPermissions('payment-methods'), () => import('@/features/settings/pages/PaymentMethodsPage')) },
  { path: 'discounts', lazy: guardedPage(settingsPermissions('discounts'), () => import('@/features/settings/pages/DiscountsPage')) },
  { path: 'receipts', lazy: guardedPage(settingsPermissions('receipts'), () => import('@/features/settings/pages/ReceiptTemplatesPage')) },
  { path: 'loyalty', lazy: guardedPage(settingsPermissions('loyalty'), () => import('@/features/settings/pages/LoyaltySettingsPage')) },
  { path: 'billing', lazy: guardedPage(settingsPermissions('billing'), () => import('@/features/billing/pages/BillingPage')) },
]

const customerAccess = ['customers.view', 'customers.manage', 'customers.credit_manage']
const customerRoutes: RouteObject[] = [
  { index: true, lazy: guardedPage(customerAccess, () => import('@/features/customers/pages/CustomersPage')) },
  { path: 'groups', lazy: guardedPage(['customers.manage'], () => import('@/features/customers/pages/CustomerGroupsPage')) },
  { path: ':customerId', lazy: guardedPage(customerAccess, () => import('@/features/customers/pages/CustomerPage')) },
]

const usersRoutes: RouteObject[] = [
  { index: true, lazy: guardedPage(['users.view', 'users.manage'], () => import('@/features/users/pages/TeamPage')) },
  { path: 'invitations', lazy: guardedPage(['users.invite'], () => import('@/features/users/pages/InvitationsPage')) },
  { path: 'roles', lazy: guardedPage(['roles.manage'], () => import('@/features/users/pages/RolesPage')) },
  { path: 'roles/:roleId', lazy: guardedPage(['roles.manage'], () => import('@/features/users/pages/RoleEditorPage')) },
]

const productRoutes: RouteObject[] = [
  { index: true, lazy: guardedPage(['products.view', 'products.manage'], () => import('@/features/catalog/pages/ProductsPage')) },
  { path: 'categories', lazy: guardedPage(['products.manage'], () => import('@/features/catalog/pages/CategoriesPage')) },
  { path: 'brands', lazy: guardedPage(['products.manage'], () => import('@/features/catalog/pages/BrandsPage')) },
  { path: 'units', lazy: guardedPage(['products.manage'], () => import('@/features/catalog/pages/UnitsPage')) },
  // "new" and ids; viewing needs products.view, saving is enforced by the API.
  { path: ':productId', lazy: guardedPage(['products.manage'], () => import('@/features/catalog/pages/ProductFormPage')) },
]

const inventoryView = ['inventory.view']
const inventoryRoutes: RouteObject[] = [
  { index: true, lazy: guardedPage(inventoryView, () => import('@/features/inventory/pages/StockPage')) },
  { path: 'items/:variantId', lazy: guardedPage(inventoryView, () => import('@/features/inventory/pages/StockItemPage')) },
  { path: 'movements', lazy: guardedPage(inventoryView, () => import('@/features/inventory/pages/MovementsPage')) },
  { path: 'adjustments', lazy: guardedPage(['inventory.view', 'inventory.adjust'], () => import('@/features/inventory/pages/AdjustmentsPage')) },
  { path: 'adjustments/:adjustmentId', lazy: guardedPage(['inventory.view', 'inventory.adjust'], () => import('@/features/inventory/pages/AdjustmentPage')) },
  { path: 'transfers', lazy: guardedPage(['inventory.view', 'inventory.transfer', 'inventory.receive_transfer'], () => import('@/features/inventory/pages/TransfersPage')) },
  { path: 'transfers/:transferId', lazy: guardedPage(['inventory.view', 'inventory.transfer', 'inventory.receive_transfer'], () => import('@/features/inventory/pages/TransferPage')) },
  { path: 'counts', lazy: guardedPage(['inventory.view', 'inventory.count'], () => import('@/features/inventory/pages/CountsPage')) },
  { path: 'counts/:countId', lazy: guardedPage(['inventory.view', 'inventory.count'], () => import('@/features/inventory/pages/CountPage')) },
  { path: 'valuation', lazy: guardedPage(['inventory.valuation'], () => import('@/features/inventory/pages/ValuationPage')) },
]

const poAccess = ['po.view', 'po.create', 'po.approve', 'po.receive']
const purchasingRoutes: RouteObject[] = [
  { index: true, element: <Navigate to="orders" replace /> },
  { path: 'orders', lazy: guardedPage(poAccess, () => import('@/features/purchasing/pages/PurchaseOrdersPage')) },
  { path: 'orders/:orderId', lazy: guardedPage(poAccess, () => import('@/features/purchasing/pages/PurchaseOrderPage')) },
  { path: 'receipts', lazy: guardedPage(['po.view', 'po.receive'], () => import('@/features/purchasing/pages/ReceiptsPage')) },
  { path: 'receipts/:receiptId', lazy: guardedPage(['po.view', 'po.receive'], () => import('@/features/purchasing/pages/ReceiptPage')) },
  { path: 'suppliers', lazy: guardedPage(['suppliers.view', 'suppliers.manage'], () => import('@/features/purchasing/pages/SuppliersPage')) },
  { path: 'bills', lazy: guardedPage(['supplier_payments.manage', 'reports.financial'], () => import('@/features/purchasing/pages/BillsPage')) },
  { path: 'payments', lazy: guardedPage(['supplier_payments.manage', 'reports.financial'], () => import('@/features/purchasing/pages/SupplierPaymentsPage')) },
  { path: 'suppliers/:supplierId/statement', lazy: guardedPage(['supplier_payments.manage', 'reports.financial'], () => import('@/features/purchasing/pages/SupplierStatementPage')) },
]

export const routes: RouteObject[] = [
  {
    errorElement: <RouteErrorPage />,
    children: [
      /* ------------------------------------------------ Tenant app */
      {
        element: (
          <AuthProvider>
            <Outlet />
          </AuthProvider>
        ),
        children: [
          {
            element: <GuestOnly />,
            children: [
              {
                element: <AuthLayout />,
                children: [
                  { path: 'login', lazy: page(() => import('@/features/auth/pages/LoginPage')) },
                  { path: 'register', lazy: page(() => import('@/features/auth/pages/RegisterPage')) },
                  { path: 'forgot-password', lazy: page(() => import('@/features/auth/pages/ForgotPasswordPage')) },
                  { path: 'reset-password', lazy: page(() => import('@/features/auth/pages/ResetPasswordPage')) },
                ],
              },
            ],
          },
          // Opened from e-mails: reachable whether signed in or not.
          {
            element: <AuthLayout />,
            children: [
              { path: 'verify-email', lazy: page(() => import('@/features/auth/pages/VerifyEmailPage')) },
              { path: 'accept-invitation', lazy: page(() => import('@/features/auth/pages/AcceptInvitationPage')) },
            ],
          },
          {
            element: <RequireAuth />,
            children: [
              {
                element: <AppLayout />,
                children: [
                  { index: true, lazy: page(() => import('@/features/dashboard/DashboardPage')) },
                  { path: 'account', lazy: page(() => import('@/features/account/AccountPage')) },
                  { path: 'products', children: productRoutes },
                  { path: 'inventory', children: inventoryRoutes },
                  { path: 'purchasing', children: purchasingRoutes },
                  { path: 'customers', children: customerRoutes },
                  { path: 'pos', lazy: guardedPage(['pos.sell', 'cash.open_session'], () => import('@/features/cash/pages/RegisterPage')) },
                  { path: 'support', lazy: page(() => import('@/features/support/pages/SupportPage')) },
                  { path: 'support/:ticketId', lazy: page(() => import('@/features/support/pages/SupportPage')) },
                  { path: 'expenses', lazy: guardedPage(EXPENSE_PERMISSIONS, () => import('@/features/expenses/pages/ExpensesPage')) },
                  { path: 'expenses/:expenseId', lazy: guardedPage(EXPENSE_PERMISSIONS, () => import('@/features/expenses/pages/ExpensePage')) },
                  { path: 'reports', lazy: guardedPage(REPORT_PERMISSIONS, () => import('@/features/reports/pages/ReportsPage')) },
                  { path: 'reports/:reportKey', lazy: guardedPage(REPORT_PERMISSIONS, () => import('@/features/reports/pages/ReportPage')) },
                  { path: 'readings', lazy: guardedPage(['cash.z_reading', 'cash.view_all_sessions'], () => import('@/features/sales/pages/ReadingsPage')) },
                  { path: 'sales', lazy: guardedPage(['sales.view_own', 'sales.view_all'], () => import('@/features/sales/pages/SalesPage')) },
                  { path: 'sales/:saleId', lazy: guardedPage(['sales.view_own', 'sales.view_all'], () => import('@/features/sales/pages/SalePage')) },
                  { path: 'cash-sessions', lazy: guardedPage(['cash.open_session', 'cash.view_all_sessions'], () => import('@/features/cash/pages/CashSessionsPage')) },
                  { path: 'cash-sessions/:sessionId', lazy: guardedPage(['cash.open_session', 'cash.view_all_sessions'], () => import('@/features/cash/pages/CashSessionPage')) },
                  { path: 'users', element: <UsersLayout />, children: usersRoutes },
                  { path: 'settings', element: <SettingsLayout />, children: settingsRoutes },
                ],
              },
            ],
          },
        ],
      },

      /* ------------------------------------------------ Platform admin */
      {
        path: 'admin',
        element: (
          <AdminAuthProvider>
            <Outlet />
          </AdminAuthProvider>
        ),
        children: [
          {
            element: <AdminGuestOnly />,
            children: [{ path: 'login', lazy: page(() => import('@/features/admin/AdminLoginPage')) }],
          },
          {
            element: <RequireAdmin />,
            children: [
              {
                element: <AdminLayout />,
                children: [
                  { index: true, lazy: page(() => import('@/features/admin/AdminDashboardPage')) },
                  { path: 'tenants', lazy: page(() => import('@/features/admin/pages/AdminTenantsPage')) },
                  { path: 'tenants/:tenantId', lazy: page(() => import('@/features/admin/pages/AdminTenantPage')) },
                  { path: 'billing', lazy: page(() => import('@/features/admin/pages/AdminBillingPage')) },
                  { path: 'plans', lazy: page(() => import('@/features/admin/pages/AdminPlansPage')) },
                  { path: 'tickets', lazy: page(() => import('@/features/admin/pages/AdminTicketsPage')) },
                  { path: 'tickets/:ticketId', lazy: page(() => import('@/features/admin/pages/AdminTicketsPage')) },
                  { path: 'activity', lazy: page(() => import('@/features/admin/pages/AdminActivityPage')) },
                  { path: 'settings', lazy: page(() => import('@/features/admin/pages/AdminSettingsPage')) },
                ],
              },
            ],
          },
        ],
      },

      { path: '*', element: <NotFoundPage /> },
    ],
  },
]

export function createRouter() {
  return createBrowserRouter(routes)
}
