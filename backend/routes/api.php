<?php

use App\Http\Controllers\Admin\V1\AdminTeamController;
use App\Http\Controllers\Admin\V1\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\V1\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\V1\PlatformController;
use App\Http\Controllers\Admin\V1\TenantController as AdminTenantController;
use App\Http\Controllers\Api\V1\Access\PermissionController;
use App\Http\Controllers\Api\V1\Access\RoleController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\PinController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Branches\BranchController;
use App\Http\Controllers\Api\V1\Business\BusinessController;
use App\Http\Controllers\Api\V1\Cash\CashSessionController;
use App\Http\Controllers\Api\V1\Catalog\CatalogSetupController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\Customers\CustomerGroupController;
use App\Http\Controllers\Api\V1\Customers\LoyaltySettingsController;
use App\Http\Controllers\Api\V1\Expenses\ExpenseController;
use App\Http\Controllers\Api\V1\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\V1\Inventory\StockController;
use App\Http\Controllers\Api\V1\Inventory\StockCountController;
use App\Http\Controllers\Api\V1\Inventory\StockTransferController;
use App\Http\Controllers\Api\V1\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Api\V1\Purchasing\PayablesController;
use App\Http\Controllers\Api\V1\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Purchasing\PurchasingItemController;
use App\Http\Controllers\Api\V1\Purchasing\SupplierController;
use App\Http\Controllers\Api\V1\Reference\PsgcController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\Sales\HeldCartController;
use App\Http\Controllers\Api\V1\Sales\PosController;
use App\Http\Controllers\Api\V1\Sales\RegisterReadingController;
use App\Http\Controllers\Api\V1\Sales\SaleController;
use App\Http\Controllers\Api\V1\Settings\DiscountPresetController;
use App\Http\Controllers\Api\V1\Settings\PaymentMethodController;
use App\Http\Controllers\Api\V1\Settings\ReceiptTemplateController;
use App\Http\Controllers\Api\V1\Settings\TaxRateController;
use App\Http\Controllers\Api\V1\Support\SupportTicketController;
use App\Http\Controllers\Api\V1\Terminal\TerminalSessionController;
use App\Http\Controllers\Api\V1\Terminals\CashRegisterController;
use App\Http\Controllers\Api\V1\Terminals\TerminalController;
use App\Http\Controllers\Api\V1\Terminals\TerminalDeviceController;
use App\Http\Controllers\Api\V1\Users\InvitationController;
use App\Http\Controllers\Api\V1\Users\UserController;
use App\Http\Controllers\Billing\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant API — /api/v1
|--------------------------------------------------------------------------
|
| Middleware conventions:
|   auth:sanctum   session cookie (SPA) or bearer token (native app / device)
|   tenant         resolve tenant from the authenticated actor (REQUIRED for tenant data)
|   actor.user     human user only (":verified" also requires a verified e-mail)
|   actor.terminal registered POS device token only
|   branch         validate X-Branch-Id (":required" to make it mandatory)
|   permission:x   spatie permission check (team = tenant)
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ---------------------------------------------------------------- Public
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', RegisterController::class)->name('register');
            Route::post('login', [SessionController::class, 'login'])->name('login');
            Route::post('token', [SessionController::class, 'issueToken'])->name('token');
            Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
            Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
            Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
            Route::post('invitations/{token}/accept', [InvitationAcceptanceController::class, 'accept'])->name('invitations.accept');
        });

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->whereNumber('id')
            ->name('verification.verify');
    });

    // Payment gateway notifications (verified by token / signature, not by a session)
    Route::post('billing/webhooks/{gateway}', WebhookController::class)
        ->whereIn('gateway', ['xendit', 'paymongo'])->middleware('throttle:120,1')->name('billing.webhooks');

    // Logout deliberately skips tenant resolution: a user whose business was
    // suspended, or who is on the wrong subdomain, must still be able to end the session.
    Route::post('auth/logout', [SessionController::class, 'logout'])
        ->middleware(['auth:sanctum', 'actor.user', 'throttle:api'])
        ->name('auth.logout');

    // ------------------------------------------------- Authenticated tenant users
    Route::middleware(['auth:sanctum', 'tenant', 'actor.user', 'password.changed', 'subscription.writable', 'branch', 'throttle:api'])->group(function () {

        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [SessionController::class, 'me'])->name('me');
            Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,1')->name('verification.send');
            Route::put('pin', [PinController::class, 'update'])->name('pin.update');
            Route::post('override', [PinController::class, 'override'])
                ->middleware(['actor.user:verified', 'throttle:pin'])->name('override');
        });

        // Everything below requires a verified e-mail address.
        Route::middleware('actor.user:verified')->group(function () {

            // Roles & permissions
            Route::middleware('permission:roles.manage')->group(function () {
                Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
                Route::apiResource('roles', RoleController::class)->whereNumber('role');
            });

            // Own profile & password
            Route::put('auth/profile', [ProfileController::class, 'update'])->name('auth.profile.update');
            Route::put('auth/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('auth.password.change');

            // Users of the business (visibility narrowed to own branches in UserManagementService)
            Route::get('roles/assignable', [UserController::class, 'assignableRoles'])
                ->middleware('permission:users.invite|users.manage')->name('roles.assignable');
            Route::middleware('permission:users.view|users.manage')->group(function () {
                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
            });
            Route::middleware('permission:users.manage')->group(function () {
                Route::post('users', [UserController::class, 'store'])->name('users.store');
                Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
                Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
                Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
                Route::delete('users/{user}/pin', [UserController::class, 'clearPin'])->name('users.pin.destroy');
                Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
                    ->middleware('throttle:6,1')->name('users.password-reset');
            });

            // User invitations
            Route::middleware('permission:users.invite')->group(function () {
                Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
                Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
                Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
                Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
            });

            // Catalog: categories, brands, units, products
            Route::middleware('permission:products.view|products.manage')->group(function () {
                Route::get('product-categories', [CatalogSetupController::class, 'categories'])->name('product-categories.index');
                Route::get('brands', [CatalogSetupController::class, 'brands'])->name('brands.index');
                Route::get('units', [CatalogSetupController::class, 'units'])->name('units.index');
                Route::get('products', [ProductController::class, 'index'])->name('products.index');
                Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
                Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
            });
            Route::middleware('permission:products.manage')->group(function () {
                Route::post('product-categories', [CatalogSetupController::class, 'storeCategory'])->name('product-categories.store');
                Route::put('product-categories/{category}', [CatalogSetupController::class, 'updateCategory'])->name('product-categories.update');
                Route::delete('product-categories/{category}', [CatalogSetupController::class, 'destroyCategory'])->name('product-categories.destroy');
                Route::post('brands', [CatalogSetupController::class, 'storeBrand'])->name('brands.store');
                Route::put('brands/{brand}', [CatalogSetupController::class, 'updateBrand'])->name('brands.update');
                Route::delete('brands/{brand}', [CatalogSetupController::class, 'destroyBrand'])->name('brands.destroy');
                Route::post('units', [CatalogSetupController::class, 'storeUnit'])->name('units.store');
                Route::put('units/{unit}', [CatalogSetupController::class, 'updateUnit'])->name('units.update');
                Route::delete('units/{unit}', [CatalogSetupController::class, 'destroyUnit'])->name('units.destroy');

                Route::post('products', [ProductController::class, 'store'])->name('products.store');
                Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
                Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');
                Route::post('products/{product}/activate', [ProductController::class, 'activate'])->name('products.activate');
                Route::post('products/{product}/deactivate', [ProductController::class, 'deactivate'])->name('products.deactivate');
                Route::post('products/{product}/image', [ProductController::class, 'uploadImage'])->name('products.image.store');
                Route::delete('products/{product}/image', [ProductController::class, 'deleteImage'])->name('products.image.destroy');
            });
            Route::put('product-variants/{productVariant}/branch-prices', [ProductController::class, 'branchPrices'])
                ->middleware('permission:prices.manage')->name('product-variants.branch-prices');

            // Cash register shifts (branch selected in the app)
            Route::middleware(['feature:pos,POS', 'branch:required'])->group(function () {
                Route::get('pos/terminals', [CashSessionController::class, 'terminals'])
                    ->middleware('permission:pos.sell|cash.open_session|cash.view_all_sessions')->name('pos.terminals');
                Route::middleware('permission:cash.open_session|cash.view_all_sessions')->group(function () {
                    Route::get('cash-sessions', [CashSessionController::class, 'index'])->name('cash-sessions.index');
                    Route::get('cash-sessions/{cashSession}', [CashSessionController::class, 'show'])->whereNumber('cashSession')->name('cash-sessions.show');
                });
                // Selling
                Route::middleware('permission:pos.sell')->group(function () {
                    Route::get('pos/setup', [PosController::class, 'setup'])->name('pos.setup');
                    Route::get('pos/items', [PosController::class, 'items'])->name('pos.items');
                    Route::get('pos/items/scan', [PosController::class, 'scan'])->name('pos.items.scan');
                    Route::get('pos/catalog', [PosController::class, 'catalog'])->name('pos.catalog');
                    Route::post('pos/sync', [PosController::class, 'sync'])->name('pos.sync');
                    Route::post('pos/quote', [SaleController::class, 'quote'])->name('pos.quote');
                    Route::post('pos/sales', [SaleController::class, 'store'])->name('pos.sales.store');
                });
                Route::middleware('permission:pos.hold')->group(function () {
                    Route::get('pos/held-carts', [HeldCartController::class, 'index'])->name('pos.held-carts.index');
                    Route::post('pos/held-carts', [HeldCartController::class, 'store'])->name('pos.held-carts.store');
                    Route::post('pos/held-carts/{heldCart}/resume', [HeldCartController::class, 'resume'])->name('pos.held-carts.resume');
                    Route::delete('pos/held-carts/{heldCart}', [HeldCartController::class, 'destroy'])->name('pos.held-carts.destroy');
                });
                Route::middleware('permission:sales.view_own|sales.view_all')->group(function () {
                    Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
                    Route::get('sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');
                });
                Route::post('sales/{sale}/review-sync', [SaleController::class, 'reviewSync'])->whereNumber('sale')
                    ->middleware('permission:sales.review_sync')->name('sales.review-sync');
                Route::post('sales/{sale}/reprint', [SaleController::class, 'reprint'])->whereNumber('sale')
                    ->middleware('permission:sales.reprint')->name('sales.reprint');
                // Voids and refunds: by permission, or by a cashier with a supervisor's PIN approval.
                Route::middleware('permission:pos.sell|sales.void|sales.refund')->group(function () {
                    Route::get('sales/lookup', [SaleController::class, 'lookup'])->name('sales.lookup');
                    Route::post('sales/{sale}/void', [SaleController::class, 'void'])->whereNumber('sale')->name('sales.void');
                    Route::post('sales/{sale}/refunds', [SaleController::class, 'refund'])->whereNumber('sale')->name('sales.refunds.store');
                });

                // X / Z readings
                Route::middleware('permission:cash.z_reading|cash.view_all_sessions')->group(function () {
                    Route::get('pos/readings', [RegisterReadingController::class, 'index'])->name('pos.readings.index');
                    Route::get('pos/readings/{reading}', [RegisterReadingController::class, 'show'])->whereNumber('reading')->name('pos.readings.show');
                });
                Route::post('pos/z-readings', [RegisterReadingController::class, 'z'])->middleware('permission:cash.z_reading')->name('pos.z-readings.store');
                Route::post('cash-sessions/{cashSession}/x-reading', [RegisterReadingController::class, 'x'])
                    ->middleware('permission:cash.z_reading|cash.close_session')->name('cash-sessions.x-reading');

                Route::post('cash-sessions', [CashSessionController::class, 'store'])->middleware('permission:cash.open_session')->name('cash-sessions.store');
                Route::post('cash-sessions/{cashSession}/movements', [CashSessionController::class, 'movement'])
                    ->middleware('permission:cash.cash_in_out')->name('cash-sessions.movements');
                Route::post('cash-sessions/{cashSession}/close', [CashSessionController::class, 'close'])
                    ->middleware('permission:cash.close_session')->name('cash-sessions.close');
            });

            // Customers, groups, accounts ("utang") and loyalty
            Route::middleware('feature:customers,Customers')->group(function () {
                Route::middleware('permission:customers.view|customers.manage|pos.sell')->group(function () {
                    Route::get('customers/lookup', [CustomerController::class, 'lookup'])->name('customers.lookup');
                    Route::get('customer-groups', [CustomerGroupController::class, 'index'])->name('customer-groups.index');
                });
                Route::middleware('permission:customers.view|customers.manage|customers.credit_manage')->group(function () {
                    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
                    Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger'])->name('customers.ledger');
                    Route::get('customers/{customer}/loyalty', [CustomerController::class, 'loyaltyHistory'])->name('customers.loyalty');
                });
                Route::middleware('permission:customers.manage')->group(function () {
                    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
                    Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
                    Route::post('customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');
                    Route::post('customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('customers.deactivate');
                    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->whereNumber('customer')->name('customers.destroy');
                    Route::post('customer-groups', [CustomerGroupController::class, 'store'])->name('customer-groups.store');
                    Route::put('customer-groups/{customerGroup}', [CustomerGroupController::class, 'update'])->name('customer-groups.update');
                    Route::delete('customer-groups/{customerGroup}', [CustomerGroupController::class, 'destroy'])->name('customer-groups.destroy');
                });
                Route::middleware(['permission:customers.credit_manage', 'feature:credit_sales,Customer credit'])->group(function () {
                    Route::post('customers/{customer}/opening-balance', [CustomerController::class, 'openingBalance'])->name('customers.opening-balance');
                    Route::post('customers/{customer}/payments', [CustomerController::class, 'payment'])->name('customers.payments');
                    Route::post('customers/{customer}/balance-adjustments', [CustomerController::class, 'adjustBalance'])->name('customers.balance-adjustments');
                });
                Route::post('customers/{customer}/loyalty-adjustments', [CustomerController::class, 'adjustPoints'])
                    ->middleware(['permission:loyalty.adjust', 'feature:loyalty,Loyalty points'])->name('customers.loyalty-adjustments');

                Route::get('loyalty-settings', [LoyaltySettingsController::class, 'show'])
                    ->middleware('permission:settings.discounts|customers.view|customers.manage')->name('loyalty-settings.show');
                Route::put('loyalty-settings', [LoyaltySettingsController::class, 'update'])
                    ->middleware(['permission:settings.discounts', 'feature:loyalty,Loyalty points'])->name('loyalty-settings.update');
            });

            // Expenses (plan feature "expenses")
            Route::middleware('feature:expenses,Expenses')->group(function () {
                Route::middleware('permission:expenses.view|expenses.create|expenses.approve')->group(function () {
                    Route::get('expense-categories', [ExpenseController::class, 'categories'])->name('expense-categories.index');
                    Route::get('expenses/options', [ExpenseController::class, 'options'])->name('expenses.options');
                    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
                    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->name('expenses.show');
                    Route::get('expenses/{expense}/attachments/{attachment}', [ExpenseController::class, 'download'])->name('expenses.attachments.download');
                });
                Route::middleware('permission:expenses.create|expenses.approve')->group(function () {
                    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
                    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('expenses.update');
                    Route::post('expenses/{expense}/submit', [ExpenseController::class, 'submit'])->name('expenses.submit');
                    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('expenses.destroy');
                    Route::post('expenses/{expense}/attachments', [ExpenseController::class, 'attach'])->name('expenses.attachments.store');
                    Route::delete('expenses/{expense}/attachments/{attachment}', [ExpenseController::class, 'detach'])->name('expenses.attachments.destroy');
                });
                Route::middleware('permission:expenses.approve')->group(function () {
                    Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
                    Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->name('expenses.reject');
                    Route::post('expense-categories', [ExpenseController::class, 'saveCategory'])->name('expense-categories.store');
                    Route::put('expense-categories/{category}', [ExpenseController::class, 'saveCategory'])->name('expense-categories.update');
                });
            });

            // Support tickets to AvantPOS (any signed-in user)
            Route::prefix('support')->name('support.')->group(function () {
                Route::get('tickets', [SupportTicketController::class, 'index'])->name('tickets.index');
                Route::post('tickets', [SupportTicketController::class, 'store'])->name('tickets.store');
                Route::get('tickets/{ticket}', [SupportTicketController::class, 'show'])->name('tickets.show');
                Route::post('tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])->name('tickets.reply');
            });

            // The business's AvantPOS subscription (always reachable, even when expired)
            Route::prefix('billing')->name('billing.')->group(function () {
                Route::middleware('permission:billing.view|billing.manage')->group(function () {
                    Route::get('/', [BillingController::class, 'show'])->name('show');
                    Route::get('invoices/{invoice}', [BillingController::class, 'invoiceShow'])->name('invoices.show');
                });
                Route::middleware('permission:billing.manage')->group(function () {
                    Route::post('checkout', [BillingController::class, 'checkout'])->name('checkout');
                    Route::post('invoices/{invoice}/pay', [BillingController::class, 'pay'])->name('invoices.pay');
                    Route::post('invoices/{invoice}/manual-payment', [BillingController::class, 'manualPayment'])->name('invoices.manual-payment');
                    Route::post('cancel', [BillingController::class, 'cancel'])->name('cancel');
                    Route::post('resume', [BillingController::class, 'resume'])->name('resume');
                });
            });

            // Reports & dashboard (each report checks its own permission and plan feature)
            Route::middleware('permission:reports.sales|reports.inventory|reports.financial|reports.cashier|po.view|expenses.view|supplier_payments.manage')->group(function () {
                Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
                Route::get('reports/dashboard', [ReportController::class, 'dashboard'])->name('reports.dashboard');
                Route::get('reports/{report}', [ReportController::class, 'show'])->where('report', '[a-z-]+')->name('reports.show');
            });

            // Suppliers & purchasing (plan feature "purchasing")
            Route::middleware('feature:purchasing,Purchasing')->group(function () {
                // Accounts payable: supplier bills, payments, statements
                Route::middleware('permission:supplier_payments.manage|reports.financial')->group(function () {
                    Route::get('supplier-bills', [PayablesController::class, 'bills'])->name('supplier-bills.index');
                    Route::get('supplier-bills/billable-receipts', [PayablesController::class, 'billableReceipts'])->name('supplier-bills.billable');
                    Route::get('supplier-bills/{bill}', [PayablesController::class, 'showBill'])->whereNumber('bill')->name('supplier-bills.show');
                    Route::get('supplier-payments', [PayablesController::class, 'payments'])->name('supplier-payments.index');
                    Route::get('supplier-payments/methods', [PayablesController::class, 'paymentMethods'])->name('supplier-payments.methods');
                    Route::get('suppliers/{supplier}/statement', [PayablesController::class, 'statement'])->name('suppliers.statement');
                });
                Route::middleware('permission:supplier_payments.manage')->group(function () {
                    Route::post('supplier-bills', [PayablesController::class, 'storeBill'])->name('supplier-bills.store');
                    Route::post('supplier-bills/{bill}/void', [PayablesController::class, 'voidBill'])->name('supplier-bills.void');
                    Route::post('supplier-payments', [PayablesController::class, 'storePayment'])->name('supplier-payments.store');
                    Route::post('supplier-payments/{payment}/void', [PayablesController::class, 'voidPayment'])->name('supplier-payments.void');
                });

                Route::get('suppliers/options', [SupplierController::class, 'options'])
                    ->middleware('permission:suppliers.view|suppliers.manage|po.create|po.receive')->name('suppliers.options');
                Route::middleware('permission:suppliers.view|suppliers.manage')->group(function () {
                    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
                    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
                });
                Route::middleware('permission:suppliers.manage')->group(function () {
                    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
                    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
                    Route::post('suppliers/{supplier}/activate', [SupplierController::class, 'activate'])->name('suppliers.activate');
                    Route::post('suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate'])->name('suppliers.deactivate');
                    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->name('suppliers.destroy');
                });

                // Orders and receipts belong to the branch selected in the app.
                Route::middleware('branch:required')->group(function () {
                    Route::get('purchasing/items', PurchasingItemController::class)
                        ->middleware('permission:po.create|po.receive')->name('purchasing.items');

                    Route::middleware('permission:po.view|po.create|po.approve|po.receive')->group(function () {
                        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
                        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder')->name('purchase-orders.show');
                    });
                    Route::middleware('permission:po.create')->group(function () {
                        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
                        Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder')->name('purchase-orders.update');
                        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
                        Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');
                    });
                    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
                        ->middleware('permission:po.approve')->name('purchase-orders.approve');

                    Route::middleware('permission:po.view|po.receive')->group(function () {
                        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
                        Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->whereNumber('goodsReceipt')->name('goods-receipts.show');
                    });
                    Route::middleware('permission:po.receive')->group(function () {
                        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
                        Route::post('goods-receipts/{goodsReceipt}/reverse', [GoodsReceiptController::class, 'reverse'])->name('goods-receipts.reverse');
                    });
                });
            });

            // Inventory — always in the branch selected in the app (X-Branch-Id)
            Route::middleware('branch:required')->group(function () {
                Route::prefix('inventory')->name('inventory.')->group(function () {
                    Route::middleware('permission:inventory.view')->group(function () {
                        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
                        Route::get('stock/{productVariant}', [StockController::class, 'show'])->whereNumber('productVariant')->name('stock.show');
                        Route::get('movements', [StockController::class, 'movements'])->name('movements');
                        Route::get('alerts', [StockController::class, 'alerts'])->name('alerts');
                        Route::get('expiring', [StockController::class, 'expiring'])->name('expiring');
                    });
                    Route::get('destinations', [StockController::class, 'destinations'])
                        ->middleware('permission:inventory.transfer')->name('destinations');
                    Route::get('picker', [StockController::class, 'picker'])
                        ->middleware('permission:inventory.adjust|inventory.transfer|inventory.count')->name('picker');
                    Route::get('valuation', [StockController::class, 'valuation'])
                        ->middleware('permission:inventory.valuation')->name('valuation');
                });

                Route::middleware('permission:inventory.view|inventory.adjust')->group(function () {
                    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
                    Route::get('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->whereNumber('stockAdjustment')->name('stock-adjustments.show');
                });
                Route::middleware('permission:inventory.adjust')->group(function () {
                    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store');
                    Route::put('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->whereNumber('stockAdjustment')->name('stock-adjustments.update');
                    Route::post('stock-adjustments/{stockAdjustment}/post', [StockAdjustmentController::class, 'post'])->name('stock-adjustments.post');
                    Route::post('stock-adjustments/{stockAdjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('stock-adjustments.cancel');
                });

                Route::middleware('permission:inventory.view|inventory.transfer|inventory.receive_transfer')->group(function () {
                    Route::get('stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index');
                    Route::get('stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->whereNumber('stockTransfer')->name('stock-transfers.show');
                });
                Route::middleware('permission:inventory.transfer')->group(function () {
                    Route::post('stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
                    Route::put('stock-transfers/{stockTransfer}', [StockTransferController::class, 'update'])->whereNumber('stockTransfer')->name('stock-transfers.update');
                    Route::post('stock-transfers/{stockTransfer}/dispatch', [StockTransferController::class, 'send'])->name('stock-transfers.dispatch');
                    Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');
                });
                Route::post('stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])
                    ->middleware('permission:inventory.receive_transfer')->name('stock-transfers.receive');

                Route::middleware('permission:inventory.view|inventory.count')->group(function () {
                    Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
                    Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show'])->whereNumber('stockCount')->name('stock-counts.show');
                    Route::get('stock-counts/{stockCount}/items', [StockCountController::class, 'items'])->name('stock-counts.items');
                });
                Route::middleware('permission:inventory.count')->group(function () {
                    Route::post('stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
                    Route::put('stock-counts/{stockCount}/entries', [StockCountController::class, 'record'])->name('stock-counts.entries');
                    Route::post('stock-counts/{stockCount}/finalize', [StockCountController::class, 'finalize'])->name('stock-counts.finalize');
                    Route::post('stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel'])->name('stock-counts.cancel');
                });
            });

            // PSGC address lookups (global reference data)
            Route::prefix('ph')->name('ph.')->group(function () {
                Route::get('regions', [PsgcController::class, 'regions'])->name('regions');
                Route::get('provinces', [PsgcController::class, 'provinces'])->name('provinces');
                Route::get('cities', [PsgcController::class, 'cities'])->name('cities');
                Route::get('barangays', [PsgcController::class, 'barangays'])->name('barangays');
            });

            // Business profile (always the user's own business: no id in the URL)
            Route::get('business', [BusinessController::class, 'show'])->middleware('permission:business.view|business.manage')->name('business.show');
            Route::middleware('permission:business.manage')->group(function () {
                Route::put('business', [BusinessController::class, 'update'])->name('business.update');
                Route::post('business/logo', [BusinessController::class, 'uploadLogo'])->name('business.logo.store');
                Route::delete('business/logo', [BusinessController::class, 'deleteLogo'])->name('business.logo.destroy');
            });

            // Branches
            Route::middleware('permission:branches.view|branches.manage')->group(function () {
                Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
                Route::get('branches/{branch}', [BranchController::class, 'show'])->whereNumber('branch')->name('branches.show');
            });
            Route::middleware('permission:branches.manage')->group(function () {
                Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
                Route::put('branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch')->name('branches.update');
                Route::post('branches/{branch}/activate', [BranchController::class, 'activate'])->name('branches.activate');
                Route::post('branches/{branch}/deactivate', [BranchController::class, 'deactivate'])->name('branches.deactivate');
                Route::post('branches/{branch}/make-main', [BranchController::class, 'makeMain'])->name('branches.make-main');
            });

            // POS terminals, devices and cash drawers
            Route::middleware('permission:terminals.manage')->group(function () {
                Route::apiResource('terminals', TerminalController::class)->except('destroy')->whereNumber('terminal');
                Route::post('terminals/{terminal}/activate', [TerminalController::class, 'activate'])->name('terminals.activate');
                Route::post('terminals/{terminal}/deactivate', [TerminalController::class, 'deactivate'])->name('terminals.deactivate');
                Route::post('terminals/{terminal}/retire', [TerminalController::class, 'retire'])->name('terminals.retire');
                Route::post('terminals/{terminal}/device', [TerminalDeviceController::class, 'store'])->name('terminals.device.store');
                Route::delete('terminals/{terminal}/device', [TerminalDeviceController::class, 'destroy'])->name('terminals.device.destroy');

                Route::apiResource('cash-registers', CashRegisterController::class)->only(['index', 'store', 'update'])->whereNumber('cash_register');
                Route::post('cash-registers/{cash_register}/activate', [CashRegisterController::class, 'activate'])->name('cash-registers.activate');
                Route::post('cash-registers/{cash_register}/deactivate', [CashRegisterController::class, 'deactivate'])->name('cash-registers.deactivate');
            });

            // Business configuration: anyone who can view the business may read it.
            Route::get('tax-rates', [TaxRateController::class, 'index'])->middleware('permission:settings.tax|business.view')->name('tax-rates.index');
            Route::middleware('permission:settings.tax')->group(function () {
                Route::post('tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
                Route::put('tax-rates/{tax_rate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
                Route::post('tax-rates/{tax_rate}/make-default', [TaxRateController::class, 'makeDefault'])->name('tax-rates.make-default');
            });

            Route::get('payment-methods', [PaymentMethodController::class, 'index'])->middleware('permission:settings.payment_methods|business.view')->name('payment-methods.index');
            Route::middleware('permission:settings.payment_methods')->group(function () {
                Route::post('payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
                Route::put('payment-methods/{payment_method}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
            });

            Route::get('discount-presets', [DiscountPresetController::class, 'index'])->middleware('permission:settings.discounts|business.view')->name('discount-presets.index');
            Route::middleware('permission:settings.discounts')->group(function () {
                Route::post('discount-presets', [DiscountPresetController::class, 'store'])->name('discount-presets.store');
                Route::put('discount-presets/{discount_preset}', [DiscountPresetController::class, 'update'])->name('discount-presets.update');
            });

            Route::get('receipt-templates', [ReceiptTemplateController::class, 'index'])->middleware('permission:settings.receipt|business.view')->name('receipt-templates.index');
            Route::middleware('permission:settings.receipt')->group(function () {
                Route::post('receipt-templates', [ReceiptTemplateController::class, 'store'])->name('receipt-templates.store');
                Route::put('receipt-templates/{receipt_template}', [ReceiptTemplateController::class, 'update'])->name('receipt-templates.update');
                Route::post('receipt-templates/{receipt_template}/make-default', [ReceiptTemplateController::class, 'makeDefault'])->name('receipt-templates.make-default');
                Route::delete('receipt-templates/{receipt_template}', [ReceiptTemplateController::class, 'destroy'])->name('receipt-templates.destroy');
            });
        });
    });

    // ------------------------------------------------- Registered POS devices
    Route::prefix('terminal')->name('terminal.')
        ->middleware(['auth:sanctum', 'tenant', 'actor.terminal', 'branch', 'throttle:sync'])
        ->group(function () {
            Route::get('ping', [TerminalSessionController::class, 'ping'])->name('ping');
            // Phase 5: catalog pull, offline sales sync, session sync...
        });
});

/*
|--------------------------------------------------------------------------
| Platform admin API — /api/admin/v1 (separate "platform" guard)
|--------------------------------------------------------------------------
*/

Route::prefix('admin/v1')->name('api.admin.v1.')->group(function () {
    Route::post('auth/login', [AdminAuthController::class, 'login'])->middleware('throttle:auth')->name('auth.login');

    Route::middleware(['auth:platform', 'admin.active', 'password.changed:platform', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AdminAuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
        Route::put('auth/password', [AdminAuthController::class, 'changePassword'])->middleware('throttle:6,1')->name('auth.password');
        Route::post('auth/2fa/setup', [AdminAuthController::class, 'twoFactorSetup'])->name('auth.2fa.setup');
        Route::post('auth/2fa/confirm', [AdminAuthController::class, 'twoFactorConfirm'])->name('auth.2fa.confirm');
        Route::post('auth/2fa/disable', [AdminAuthController::class, 'twoFactorDisable'])->name('auth.2fa.disable');

        // Every admin can see the overview, businesses, plans, payments and tickets.
        Route::get('dashboard', AdminDashboardController::class)->name('dashboard');
        Route::get('tenants', [AdminTenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenant}', [AdminTenantController::class, 'show'])->whereNumber('tenant')->name('tenants.show');
        Route::get('plans', [PlatformController::class, 'plans'])->name('plans.index');
        Route::get('payments', [PlatformController::class, 'payments'])->name('payments.index');
        Route::get('invoices', [PlatformController::class, 'invoices'])->name('invoices.index');
        Route::get('tickets', [PlatformController::class, 'tickets'])->name('tickets.index');
        Route::get('tickets/{ticket}', [PlatformController::class, 'showTicket'])->whereNumber('ticket')->name('tickets.show');
        Route::get('settings', [PlatformController::class, 'settings'])->name('settings.show');

        Route::middleware('admin.role:support')->group(function () {
            Route::get('activity', [PlatformController::class, 'activity'])->name('activity.index');
            Route::post('tickets/{ticket}/reply', [PlatformController::class, 'replyTicket'])->name('tickets.reply');
            Route::patch('tickets/{ticket}', [PlatformController::class, 'updateTicket'])->name('tickets.update');
        });
        Route::middleware('admin.role:billing')->group(function () {
            Route::post('plans', [PlatformController::class, 'savePlan'])->name('plans.store');
            Route::put('plans/{plan}', [PlatformController::class, 'savePlan'])->name('plans.update');
            Route::post('payments/{payment}/verify', [PlatformController::class, 'verifyPayment'])->name('payments.verify');
            Route::post('payments/{payment}/reject', [PlatformController::class, 'rejectPayment'])->name('payments.reject');
            Route::put('tenants/{tenant}/subscription', [AdminTenantController::class, 'updateSubscription'])->name('tenants.subscription');
        });
        Route::middleware('admin.role')->group(function () { // super admins only
            Route::post('tenants/{tenant}/suspend', [AdminTenantController::class, 'suspend'])->name('tenants.suspend');
            Route::post('tenants/{tenant}/activate', [AdminTenantController::class, 'activate'])->name('tenants.activate');
            Route::put('settings', [PlatformController::class, 'saveSettings'])->name('settings.update');

            // Admin team: AvantPOS staff accounts and their roles.
            Route::get('admins', [AdminTeamController::class, 'index'])->name('admins.index');
            Route::post('admins', [AdminTeamController::class, 'store'])->name('admins.store');
            Route::patch('admins/{admin}', [AdminTeamController::class, 'update'])->whereNumber('admin')->name('admins.update');
            Route::post('admins/{admin}/password', [AdminTeamController::class, 'resetPassword'])->whereNumber('admin')->name('admins.password');
            Route::post('admins/{admin}/reset-two-factor', [AdminTeamController::class, 'resetTwoFactor'])->whereNumber('admin')->name('admins.two-factor');
        });
    });
});
