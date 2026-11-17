<?php

/*
|--------------------------------------------------------------------------
| Permission catalogue & default tenant roles
|--------------------------------------------------------------------------
|
| SINGLE SOURCE OF TRUTH for tenant permissions. `PermissionSeeder` syncs the
| `permissions` table from `groups`, and `TenantRoleProvisioner` creates the
| default roles for every new tenant from `roles`.
|
| Naming: "{module}.{action}". Never rename a permission without a data
| migration — role assignments reference it by name.
|
*/

return [

    'guard' => 'web',

    'groups' => [
        'business' => [
            'label' => 'Business',
            'permissions' => [
                'business.view' => 'View business profile',
                'business.manage' => 'Edit business profile',
                'settings.tax' => 'Manage tax settings',
                'settings.receipt' => 'Manage receipt templates',
                'settings.payment_methods' => 'Manage payment methods',
                'settings.discounts' => 'Manage discount presets',
            ],
        ],
        'branches' => [
            'label' => 'Branches & Terminals',
            'permissions' => [
                'branches.view' => 'View branches',
                'branches.manage' => 'Create and edit branches',
                'branches.access_all' => 'Access all branches',
                'terminals.manage' => 'Manage POS terminals and devices',
            ],
        ],
        'users' => [
            'label' => 'Users & Roles',
            'permissions' => [
                'users.view' => 'View users',
                'users.invite' => 'Invite users',
                'users.manage' => 'Edit and deactivate users',
                'roles.manage' => 'Manage roles and permissions',
            ],
        ],
        'catalog' => [
            'label' => 'Products',
            'permissions' => [
                'products.view' => 'View products',
                'products.manage' => 'Create and edit products',
                'products.view_cost' => 'View cost prices',
                'prices.manage' => 'Change selling prices',
            ],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'permissions' => [
                'inventory.view' => 'View stock levels and movements',
                'inventory.adjust' => 'Adjust stock',
                'inventory.transfer' => 'Create stock transfers',
                'inventory.receive_transfer' => 'Receive stock transfers',
                'inventory.count' => 'Perform stock counts',
                'inventory.valuation' => 'View inventory valuation',
            ],
        ],
        'purchasing' => [
            'label' => 'Suppliers & Purchasing',
            'permissions' => [
                'suppliers.view' => 'View suppliers',
                'suppliers.manage' => 'Manage suppliers',
                'po.view' => 'View purchase orders',
                'po.create' => 'Create purchase orders',
                'po.approve' => 'Approve purchase orders',
                'po.receive' => 'Receive goods',
                'supplier_payments.manage' => 'Record supplier bills and payments',
            ],
        ],
        'customers' => [
            'label' => 'Customers',
            'permissions' => [
                'customers.view' => 'View customers',
                'customers.manage' => 'Create and edit customers',
                'customers.credit_manage' => 'Manage customer credit and collections',
                'loyalty.adjust' => 'Adjust loyalty points',
            ],
        ],
        'pos' => [
            'label' => 'Point of Sale',
            'permissions' => [
                'pos.sell' => 'Use the POS',
                'pos.discount_line' => 'Apply item discounts',
                'pos.discount_transaction' => 'Apply transaction discounts',
                'pos.discount_override_limit' => 'Exceed discount limits',
                'pos.price_override' => 'Override selling price',
                'pos.sc_pwd' => 'Apply Senior Citizen / PWD discounts',
                'pos.credit_sale' => 'Sell on credit (charge to account)',
                'pos.hold' => 'Hold and resume transactions',
                'pos.cancel_cart' => 'Cancel an unpaid transaction',
            ],
        ],
        'sales' => [
            'label' => 'Sales',
            'permissions' => [
                'sales.view_own' => 'View own sales',
                'sales.view_all' => 'View all sales',
                'sales.void' => 'Void sales',
                'sales.refund' => 'Process returns and refunds',
                'sales.reprint' => 'Reprint receipts',
                'payments.confirm_manual' => 'Confirm digital payments manually',
                'sales.review_sync' => 'Review offline sync exceptions',
            ],
        ],
        'cash' => [
            'label' => 'Cash Management',
            'permissions' => [
                'cash.open_session' => 'Open a register session',
                'cash.cash_in_out' => 'Record cash in / cash out',
                'cash.close_session' => 'Close a register session',
                'cash.view_all_sessions' => 'View all register sessions',
                'cash.z_reading' => 'Generate X/Z readings',
            ],
        ],
        'expenses' => [
            'label' => 'Expenses',
            'permissions' => [
                'expenses.view' => 'View expenses',
                'expenses.create' => 'Record expenses',
                'expenses.approve' => 'Approve expenses',
            ],
        ],
        'reports' => [
            'label' => 'Reports',
            'permissions' => [
                'reports.sales' => 'View sales reports',
                'reports.inventory' => 'View inventory reports',
                'reports.financial' => 'View profit and cost reports',
                'reports.cashier' => 'View cashier reports',
                'reports.export' => 'Export reports',
            ],
        ],
        'billing' => [
            'label' => 'Subscription & Billing',
            'permissions' => [
                'billing.view' => 'View subscription and invoices',
                'billing.manage' => 'Change plan and pay invoices',
            ],
        ],
        'audit' => [
            'label' => 'Audit',
            'permissions' => [
                'audit.view' => 'View audit logs',
            ],
        ],
    ],

    /*
    | Default roles created for every tenant.
    | 'permissions' => '*' grants every permission (kept in sync automatically).
    | 'locked' roles cannot be edited or deleted by tenants.
    */
    'roles' => [
        'owner' => [
            'name' => 'Owner',
            'description' => 'Business owner with full access.',
            'locked' => true,
            'permissions' => '*',
        ],
        'business_admin' => [
            'name' => 'Business Administrator',
            'description' => 'Manages the whole business except ownership and billing changes.',
            'locked' => false,
            'permissions' => [
                'business.view', 'business.manage', 'settings.tax', 'settings.receipt',
                'settings.payment_methods', 'settings.discounts',
                'branches.view', 'branches.manage', 'branches.access_all', 'terminals.manage',
                'users.view', 'users.invite', 'users.manage', 'roles.manage',
                'products.view', 'products.manage', 'products.view_cost', 'prices.manage',
                'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive_transfer',
                'inventory.count', 'inventory.valuation',
                'suppliers.view', 'suppliers.manage', 'po.view', 'po.create', 'po.approve', 'po.receive',
                'supplier_payments.manage',
                'customers.view', 'customers.manage', 'customers.credit_manage', 'loyalty.adjust',
                'pos.sell', 'pos.discount_line', 'pos.discount_transaction', 'pos.discount_override_limit',
                'pos.price_override', 'pos.sc_pwd', 'pos.credit_sale', 'pos.hold', 'pos.cancel_cart',
                'sales.view_own', 'sales.view_all', 'sales.void', 'sales.refund', 'sales.reprint',
                'payments.confirm_manual', 'sales.review_sync',
                'cash.open_session', 'cash.cash_in_out', 'cash.close_session', 'cash.view_all_sessions', 'cash.z_reading',
                'expenses.view', 'expenses.create', 'expenses.approve',
                'reports.sales', 'reports.inventory', 'reports.financial', 'reports.cashier', 'reports.export',
                'billing.view', 'audit.view',
            ],
        ],
        'branch_manager' => [
            'name' => 'Branch Manager',
            'description' => 'Runs assigned branches: POS supervision, stock, cash and reports.',
            'locked' => false,
            'permissions' => [
                'business.view', 'branches.view', 'users.view', 'users.invite',
                'products.view', 'products.view_cost',
                'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive_transfer', 'inventory.count',
                'suppliers.view', 'po.view', 'po.create', 'po.receive',
                'customers.view', 'customers.manage', 'customers.credit_manage',
                'pos.sell', 'pos.discount_line', 'pos.discount_transaction', 'pos.discount_override_limit',
                'pos.price_override', 'pos.sc_pwd', 'pos.credit_sale', 'pos.hold', 'pos.cancel_cart',
                'sales.view_own', 'sales.view_all', 'sales.void', 'sales.refund', 'sales.reprint',
                'payments.confirm_manual', 'sales.review_sync',
                'cash.open_session', 'cash.cash_in_out', 'cash.close_session', 'cash.view_all_sessions', 'cash.z_reading',
                'expenses.view', 'expenses.create',
                'reports.sales', 'reports.inventory', 'reports.cashier', 'reports.export',
            ],
        ],
        'cashier' => [
            'name' => 'Cashier',
            'description' => 'Sells at the POS and manages their own register session.',
            'locked' => false,
            'permissions' => [
                'products.view', 'customers.view', 'customers.manage',
                'pos.sell', 'pos.discount_line', 'pos.sc_pwd', 'pos.hold', 'pos.cancel_cart',
                'sales.view_own', 'sales.reprint',
                'cash.open_session', 'cash.cash_in_out', 'cash.close_session',
            ],
        ],
        'inventory_manager' => [
            'name' => 'Inventory Manager',
            'description' => 'Manages products, stock, suppliers and purchasing.',
            'locked' => false,
            'permissions' => [
                'business.view', 'branches.view',
                'products.view', 'products.manage', 'products.view_cost',
                'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive_transfer',
                'inventory.count', 'inventory.valuation',
                'suppliers.view', 'suppliers.manage', 'po.view', 'po.create', 'po.receive',
                'reports.inventory', 'reports.export',
            ],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Views financials, manages expenses, supplier payments and customer credit.',
            'locked' => false,
            'permissions' => [
                'business.view', 'branches.view', 'branches.access_all',
                'products.view', 'products.view_cost',
                'inventory.view', 'inventory.valuation',
                'suppliers.view', 'po.view', 'supplier_payments.manage',
                'customers.view', 'customers.credit_manage',
                'sales.view_all', 'cash.view_all_sessions',
                'expenses.view', 'expenses.create', 'expenses.approve',
                'reports.sales', 'reports.inventory', 'reports.financial', 'reports.cashier', 'reports.export',
                'billing.view', 'audit.view',
            ],
        ],
    ],

];
